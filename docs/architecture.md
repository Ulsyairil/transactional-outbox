# Architecture

This document explains how the package works, and why it is built this way. For
usage, see the [README](../README.md).

## Goals

- Store an event in the same database transaction as the business write that
  produced it.
- Deliver it later, with retries, without losing it and without delivering it
  twice to the same worker at the same time.
- Stay framework-agnostic: one core, five thin adapters.
- Stay dependency-free: plain PDO, plain PHP.

## Non-goals

- Replacing a message broker. The package removes the "publish inside the
  request" step; RabbitMQ, Kafka and friends still exist.
- Exactly-once delivery. The guarantee is at-least-once.
- Global ordering. Messages for the same aggregate can arrive out of order.
- A durable queue with a pluggable broker client. The publisher is the
  extension point.
- Schema migrations for your application tables. The package owns three tables
  and nothing else.

## The problem it solves

Publishing inside a request is a dual write. Two systems, one logical
operation:

```
BEGIN
  INSERT INTO orders ...      -- database A
  PUBLISH order.created       -- broker B
COMMIT
```

Three things go wrong:

1. The broker call succeeds, then the transaction rolls back. The consumer sees
   an event for an order that does not exist.
2. The transaction commits, then the broker call fails. The order exists, the
   event is gone.
3. The broker is slow. The request waits for a network round trip it does not
   need.

The outbox removes the second system from the transaction. The event becomes a
row in the same database:

```
BEGIN
  INSERT INTO orders ...
  INSERT INTO outbox_messages ...
COMMIT

-- later, in a worker:
SELECT ... FROM outbox_messages
PUBLISH order.created
UPDATE outbox_messages SET published_at = ...
```

Now there is one system in the transaction. The commit is atomic, and the
delivery happens outside the request path.

## Layers

```
src/Core/                 framework-agnostic, zero runtime dependencies
  Outbox.php              application entry point
  OutboxFactory.php       builds the stack from a plain array
  OutboxConfig.php        immutable configuration, fromArray()
  OutboxMessage.php       the message value object
  OutboxStore.php         persistence port
  Publisher.php           delivery port
  Relay.php               one relay cycle
  DatabaseRelay.php       the relay implementation
  Clock.php / SystemClock.php
  Storage/                the PDO implementation
    PdoOutboxStore.php
    SchemaInstaller.php
    SqlDialect.php
  Publisher/              built-in publishers
    Psr14Publisher.php  HttpPublisher.php
    CallbackPublisher.php  LogPublisher.php  OutboxEvent.php
  Exception/              OutboxException and friends

src/Adapter/{Laravel,CodeIgniter4,Symfony,Yii,CakePHP}/
                           thin wiring, one directory per framework
```

The core never imports a framework class. The adapters never reimplement core
behaviour. The dependency direction is one-way: adapters depend on the core,
never the other way around.

## The three tables

### `outbox_messages`

| Column | Type | Meaning |
|---|---|---|
| `id` | auto increment | primary key |
| `event_type` | `VARCHAR(191)` | the event name, for example `order.created` |
| `aggregate_type` | `VARCHAR(191)` nullable | what the event is about, for example `order` |
| `aggregate_id` | `VARCHAR(191)` nullable | the id of that thing |
| `deduplication_key` | `VARCHAR(191)` nullable | unique key that collapses duplicates |
| `payload` | `TEXT` | JSON body |
| `headers` | `TEXT` | JSON object of transport headers |
| `attempts` | `INT` | delivery attempts so far |
| `last_error` | `TEXT` nullable | the message of the last failure |
| `available_at` | `BIGINT` | epoch seconds; the message is due at or after this |
| `created_at` | `BIGINT` | epoch seconds |
| `published_at` | `BIGINT` nullable | set once the message was delivered |
| `batch_id` | `VARCHAR(36)` nullable | the relay cycle that owns the message |
| `locked_at` | `BIGINT` nullable | when the message was claimed |
| `locked_by` | `VARCHAR(64)` nullable | which worker claimed it |

Payloads are JSON, not serialised PHP objects. The row must stay readable by
another process, another language, and by a human during an incident.

A `NULL` deduplication key means "no deduplication". The unique index on
`deduplication_key` therefore has to ignore `NULL`s. PostgreSQL and SQLite do
that by default; MySQL does not, which is why the column is capped at 191
characters and the engine is picked with `utf8mb4` in mind.

### `outbox_batches`

| Column | Type | Meaning |
|---|---|---|
| `id` | `VARCHAR(36)` | UUIDv4 of the relay cycle |
| `message_count` | `INT` | how many messages the cycle claimed |
| `created_at` | `BIGINT` | epoch seconds |
| `completed_at` | `BIGINT` nullable | epoch seconds, set when the cycle ends |

One row per cycle. A delivery can be traced back to the cycle that sent it,
and a cycle can be inspected after the fact.

### `outbox_dead_letters`

A copy of the message with the payload intact, plus `failed_at` and the final
`last_error`. The original row is deleted, so the pending count drops and the
queue stops retrying.

The dead letter table is not pruned automatically. It is your archive; keep it
as long as you need to inspect it, and prune it on your own schedule.

## Claiming

Claiming is the part that has to be correct with several workers running.

```sql
SELECT id FROM outbox_messages
WHERE published_at IS NULL
  AND available_at <= :now
  AND (locked_at IS NULL OR locked_at <= :expired)
ORDER BY available_at ASC, id ASC
LIMIT :batch
FOR UPDATE SKIP LOCKED;   -- MySQL and PostgreSQL only
```

Then the claimed rows are stamped in the same transaction:

```sql
UPDATE outbox_messages
SET locked_at = :now, locked_by = :worker, batch_id = :batch
WHERE id IN (...);
```

Three properties come out of this:

- **No double delivery.** `FOR UPDATE SKIP LOCKED` makes a second worker skip
  the rows the first one locked, instead of waiting for them.
- **No lost work.** If a worker dies mid-cycle, its lock stays behind. The
  `locked_at <= :expired` condition releases it after `claim_timeout_seconds`,
  and another worker picks the message up.
- **The lock is released before the publish.** The claim runs in its own
  transaction. The publisher is called outside it, so a slow broker never
  holds a database lock.

On SQLite there is a single writer, so the write transaction that stamps the
rows already serialises the claim. `SKIP LOCKED` is not used there because
SQLite does not have it.

If a transaction is already open on the connection, the claim joins it instead
of opening its own. That keeps the claim safe to call from inside a business
transaction without a nested-transaction error.

## The relay cycle

```
1. claim   -> due messages, stamped with a batch id and a lock
2. record  -> one row in outbox_batches
3. publish -> one message at a time
4. on success: published_at is set, the lock is cleared
   on failure: attempts goes up, available_at moves into the future
   on the last attempt: the row moves to outbox_dead_letters
5. complete -> completed_at is set on the batch row
```

### Backoff

Exponential, based on the attempt number:

```
delay = base_delay_seconds * 2^(attempt - 1)
delay = min(delay, max_delay_seconds)
```

With the defaults: 10s, 20s, 40s, 80s, then capped at 3600s. The attempt
count is incremented when the failure is recorded, so the first retry waits
`base_delay_seconds`.

### Dead lettering

A message is dead-lettered when the attempt that just failed reaches
`max_attempts`. The row is copied into `outbox_dead_letters` with the payload
and the final error, then deleted from `outbox_messages`.

## Atomicity

`Outbox::transaction()` is the shortcut that most applications need:

```php
$outbox->transaction(
    fn () => $orders->create($data),
    fn () => OutboxMessage::create('order.created', ['order_id' => $id]),
);
```

The work runs first, then the message is written, then the transaction
commits. If either the work or the message write throws, the transaction rolls
back and neither survives.

If the caller already owns a transaction, the helper joins it and leaves the
commit to the caller.

The requirement is strict: **the outbox connection and the business
connection must be the same connection.** Two separate connections mean two
separate transactions, and the guarantee is gone. Every adapter exposes the
connection as a config value for exactly this reason.

## Delivery guarantee

At-least-once.

A message that reaches the broker but fails to be recorded as published is
delivered again on the next cycle. The window is small, but it exists.

Three ways to deal with it:

1. **Deduplication key.** The key is unique in the table, so the outbox does
   not store the same logical event twice. It is also forwarded to the
   publisher as `X-Outbox-Deduplication-Key`.
2. **Idempotent consumer.** The consumer stores the message id it has already
   processed and skips the second copy. This is the inbox pattern; it is on
   the roadmap.
3. **Accept it.** For many events, a duplicate is harmless. Know which case
   you are in.

Ordering is not guaranteed. Within one cycle, messages are delivered in
`available_at` order, but a message that failed and was rescheduled moves
behind the newer ones.

## Publishers

A publisher is the only thing that talks to the outside world.

```php
interface Publisher
{
    public function publish(OutboxMessage $message): void;
}
```

Returning normally means the message was delivered. Throwing means it was
not, and the relay schedules a retry.

Built-in publishers:

| Class | Delivers to |
|---|---|
| `Psr14Publisher` | any object with a `dispatch()` method |
| `HttpPublisher` | an HTTP endpoint, through a transport callable or a PSR-18 client |
| `CallbackPublisher` | any callable |
| `LogPublisher` | a stream, one JSON line per message |

`Psr14Publisher` and `HttpPublisher` are duck-typed on purpose. The package
must not depend on a concrete event bus or a concrete HTTP client, so the
adapter that brings the framework in is the one that knows the type.

## Configuration

`OutboxConfig::fromArray()` is the single place where configuration is
interpreted. Unknown keys raise an `OutboxException` instead of being
ignored, so a typo in a config file fails at boot rather than at 3am.

| Key | Default | Meaning |
|---|---|---|
| `table` | `outbox_messages` | the messages table |
| `batch_table` | `outbox_batches` | the batches table |
| `dead_letter_table` | `outbox_dead_letters` | the dead letter table |
| `max_attempts` | `5` | attempts before dead-lettering |
| `base_delay_seconds` | `10` | first retry delay |
| `max_delay_seconds` | `3600` | backoff cap |
| `batch_size` | `100` | messages claimed per cycle |
| `claim_timeout_seconds` | `300` | lock expiry |
| `auto_install` | `true` | create the tables on boot |
| `options` | `[]` | free-form passthrough for adapters |

Each adapter maps its own config file onto these keys and calls the same
factory. The `ConfigShapeTest` in the test suite asserts that all five
adapters produce the same core config.

## SQL dialects

`SqlDialect` holds the three things that differ between SQLite, MySQL and
PostgreSQL:

| Concern | SQLite | MySQL | PostgreSQL |
|---|---|---|---|
| primary key | `INTEGER PRIMARY KEY AUTOINCREMENT` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` | `BIGSERIAL PRIMARY KEY` |
| ignore duplicate | `ON CONFLICT ... DO NOTHING` | `ON DUPLICATE KEY UPDATE` | `ON CONFLICT ... DO NOTHING` |
| row lock | none, single writer | `FOR UPDATE SKIP LOCKED` | `FOR UPDATE SKIP LOCKED` |
| identifier quoting | `"name"` | `` `name` `` | `"name"` |
| index creation | `IF NOT EXISTS` supported | checked through `information_schema` | `IF NOT EXISTS` supported |

Everything else is plain SQL shared by all three engines.

## Adapters

An adapter does three things and nothing else:

1. Resolves the `PDO` connection and the `Publisher`.
2. Maps the framework's config onto `OutboxConfig`.
3. Registers a console command that runs the relay.

| Framework | Entry point | Relay command |
|---|---|---|
| Laravel | `TransactionalOutboxServiceProvider` | `php artisan outbox:relay` |
| CodeIgniter 4 | `TransactionalOutbox\Adapter\CodeIgniter4\Outbox` | `php spark outbox:relay` |
| Symfony | `TransactionalOutboxBundle` | `php bin/console outbox:relay` |
| Yii 2 | `TransactionalOutbox\Adapter\Yii\Outbox` | `php yii outbox/relay` |
| CakePHP | `TransactionalOutbox\Adapter\CakePHP\OutboxPlugin` | `bin/cake outbox:relay` |

Laravel registers the services in the container and publishes a config file.
Symfony resolves the connection and the dispatcher through the container, so
the bundle needs no Doctrine or Messenger dependency. CodeIgniter 4, Yii 2 and
CakePHP expose a small factory that reads the framework config and returns a
ready-to-use outbox.

## Schema management

`auto_install` is `true` by default, so the tables appear on first use. That
suits development and small applications.

For production, turn it off and manage the schema yourself:

```sql
-- see src/Core/Storage/SchemaInstaller.php for the exact DDL
CREATE TABLE outbox_messages ( ... );
```

`install()` is idempotent, so calling it on every boot is safe but does issue
`CREATE TABLE IF NOT EXISTS` statements. On a busy application, manage the
schema with migrations and set `auto_install` to `false`.

The package does not migrate your application tables, and it does not care
which table your events refer to. `aggregate_type` and `aggregate_id` are plain
strings.

## Observability

What the package gives you today:

- `Outbox::status()`: pending count, dead letter count, table name, driver.
- `Outbox::pendingCount()` and `Outbox::deadLetterCount()`.
- `RelayResult`: published, failed, dead-lettered, skipped, duration.
- `outbox_batches`: one row per relay cycle, for tracing a delivery.

Useful queries:

```sql
-- how far behind is the queue
SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL;

-- what keeps failing
SELECT event_type, last_error, COUNT(*)
FROM outbox_messages
WHERE attempts > 0
GROUP BY event_type, last_error
ORDER BY COUNT(*) DESC;

-- oldest message still waiting
SELECT id, event_type, created_at FROM outbox_messages
WHERE published_at IS NULL
ORDER BY created_at ASC LIMIT 10;
```

Metrics hooks for Prometheus are on the roadmap.

## Failure modes

| Failure | What happens |
|---|---|
| Broker is down | the message is retried with backoff, then dead-lettered |
| Worker crashes mid-cycle | the lock expires after `claim_timeout_seconds` and the message is retried |
| Database is down | the relay throws, the process exits non-zero, the supervisor restarts it |
| Payload is not valid JSON | `store()` throws, the transaction rolls back |
| Publisher silently drops the message | the relay records it as published. This is why publishers must throw on failure |

## Design decisions

**PDO instead of a framework query builder.** The core must run without a
framework. PDO is the lowest common denominator and it is already there.

**Plain SQL instead of an ORM.** The schema is three tables with a handful of
columns. An ORM would add a dependency and hide exactly the locking
behaviour that matters.

**JSON payloads instead of serialised PHP.** A serialised PHP object is tied
to a PHP version and to the class definitions of the moment it was written. A
row that outlives a deploy should still be readable.

**A publisher interface instead of broker clients.** Every broker has its own
client, its own configuration and its own release cycle. One interface and one
duck-typed adapter per transport is enough.

**Adapters as directories, not separate packages.** Five small adapters in one
package keeps the version numbers aligned and the install simple. Split them
when one of them needs a life of its own.
