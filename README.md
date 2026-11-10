# Transactional Outbox

[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4?logo=php&logoColor=white)](composer.json)
[![Packagist Version](https://img.shields.io/packagist/v/ulsyairil/transactional-outbox.svg)](https://packagist.org/packages/ulsyairil/transactional-outbox)
[![Packagist Downloads](https://img.shields.io/packagist/dt/ulsyairil/transactional-outbox.svg)](https://packagist.org/packages/ulsyairil/transactional-outbox)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-87%20passing-brightgreen.svg)](#testing)
[![External calls](https://img.shields.io/badge/external%20calls-zero-success.svg)](#why-local)

Store events in the **same database transaction** as your business writes, then
deliver them afterwards. Framework-agnostic core with thin adapters for
**Laravel**, **CodeIgniter 4**, **Symfony**, **Yii 2** and **CakePHP**. The core
is plain PSR-4 PHP with zero runtime dependencies.

```
app write ──tx──> orders + outbox_messages   (one transaction, one commit)
                       │
                 relay worker
                       │
                 broker / webhook / bus
```

## Contents

- [Why](#why)
- [Requirements](#requirements)
- [Install](#install)
- [Quick start](#quick-start)
- [How it works](#how-it-works)
- [Delivery guarantees](#delivery-guarantees)
- [Configuration](#configuration)
- [Laravel](#laravel)
- [CodeIgniter 4](#codeigniter-4)
- [Symfony](#symfony)
- [Yii 2](#yii-2)
- [CakePHP](#cakephp)
- [Standalone relay](#standalone-relay)
- [Publishers](#publishers)
- [Custom publisher](#custom-publisher)
- [Testing](#testing)
- [Security notes and limitations](#security-notes-and-limitations)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)

## Why

- **No lost events.** The message row and the business write commit together.
  Either both land or neither does.
- **No dual write.** Publishing inside the request means the broker can be
  down when the transaction commits. The outbox moves that failure out of the
  request path.
- **No runtime dependency.** Plain PDO, plain PHP. No broker client, no queue
  package, no framework.
- **Works offline.** The relay talks to your broker, not to a third party. The
  package itself makes no external calls.

## Requirements

- PHP `>= 8.1`
- `ext-pdo`, `ext-json`
- A database your application already uses: SQLite, MySQL or PostgreSQL

Framework adapters are optional and installed only if you use them:

| Framework | Suggested package | Smoke-tested against |
|---|---|---|
| Laravel | `illuminate/support` | 12.70 |
| CodeIgniter 4 | `codeigniter4/framework` | 4.7 |
| Symfony | `symfony/http-kernel` | 7.4 |
| Yii 2 | `yiisoft/yii2` | 2.0 |
| CakePHP | `cakephp/cakephp` | 5.4 |

## Install

```bash
composer require ulsyairil/transactional-outbox
```

Nothing else is required for the core. Add the framework-specific wiring from
the sections below.

## Quick start

```php
<?php

use TransactionalOutbox\Core\OutboxFactory;
use TransactionalOutbox\Core\OutboxMessage;

$pdo = new PDO('sqlite:' . __DIR__ . '/app.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$outbox = OutboxFactory::fromConnection($pdo);

$outbox->transaction(
    function () use ($pdo): void {
        $pdo->exec("INSERT INTO orders (id, total) VALUES (1, 100)");
    },
    fn (): OutboxMessage => OutboxMessage::create(
        eventType: 'order.created',
        payload: ['order_id' => 1],
        aggregateType: 'order',
        aggregateId: '1',
    ),
);

// The order row and the message are committed together. If the insert fails,
// no message is left behind.
```

Then run the relay:

```bash
vendor/bin/outbox-relay --dsn=sqlite:/app/app.sqlite
```

## How it works

Three tables:

| Table | Purpose |
|---|---|
| `outbox_messages` | Pending messages, their attempts and their schedule |
| `outbox_batches` | One row per relay cycle, for tracing a delivery |
| `outbox_dead_letters` | Messages that exhausted their retries |

The relay claims due messages, publishes them one by one, and records the
outcome:

- **Published** → `published_at` is set, the message leaves the pending count.
- **Failed** → `attempts` goes up, `available_at` moves into the future.
- **Failed too often** → the row moves to the dead letter table, payload intact.

A claimed message is locked with `locked_at`. If the worker dies mid-cycle the
lock expires after `claim_timeout_seconds` and another worker picks the message
up. Nothing is lost when a worker crashes.

Each cycle writes one row to `outbox_batches`, so a delivery can be traced back
to the cycle that sent it:

```sql
SELECT id, message_count, created_at, completed_at
FROM outbox_batches
ORDER BY created_at DESC
LIMIT 10;
```

## Delivery guarantees

**At-least-once.** A message that reaches the broker but fails to be recorded
as published is delivered again on the next cycle.

Make the consumer idempotent, or use a deduplication key. A key that already
exists is not stored twice:

```php
$outbox->publish(
    eventType: 'order.created',
    payload: ['order_id' => 1],
    deduplicationKey: 'order:1:created',
);
```

The key is also forwarded to the publisher as the
`X-Outbox-Deduplication-Key` header.

Ordering is not guaranteed across cycles. Within one cycle, messages are
delivered in `available_at` order.

## Configuration

Every adapter funnels into `OutboxConfig::fromArray()`, so the config shape and
behaviour are identical across frameworks.

```php
use TransactionalOutbox\Core\OutboxConfig;

$config = OutboxConfig::fromArray([
    'table' => 'outbox_messages',
    'batch_table' => 'outbox_batches',
    'dead_letter_table' => 'outbox_dead_letters',
    'max_attempts' => 5,          // delivery attempts before dead-lettering
    'base_delay_seconds' => 10,   // exponential backoff: base * 2^(attempt-1)
    'max_delay_seconds' => 3600,  // backoff cap
    'batch_size' => 100,          // messages claimed per cycle
    'claim_timeout_seconds' => 300, // lock expiry, so a dead worker frees its work
    'auto_install' => true,       // create the tables on boot
]);
```

Invalid config fails loudly: unknown keys raise an `OutboxException`.

## Laravel

The service provider is auto-discovered.

```php
use TransactionalOutbox\Core\Outbox;

// config/transactional-outbox.php
'connection' => env('OUTBOX_CONNECTION'), // must be the same connection your writes use
'outbox' => [
    'max_attempts' => 5,
    'batch_size' => 100,
],
```

```php
use TransactionalOutbox\Adapter\Laravel\Facades\OutboxFacade as Outbox;
use TransactionalOutbox\Core\OutboxMessage;

Outbox::publish('order.created', ['order_id' => 1]);
```

Deliver the messages:

```bash
php artisan outbox:relay           # one cycle
php artisan outbox:relay --forever # keep running
```

By default the relay dispatches on the Laravel event bus. Point it somewhere
else with a `Publisher`:

```php
'publisher' => \App\Outbox\MyPublisher::class,
```

## CodeIgniter 4

```php
// app/Config/Outbox.php
class Outbox extends \TransactionalOutbox\Adapter\CodeIgniter4\Config\Outbox
{
    public string $connection = 'default';
}
```

```php
use TransactionalOutbox\Adapter\CodeIgniter4\Outbox;

Outbox::make()->outbox()->publish('order.created', ['order_id' => 1]);
```

```bash
php spark outbox:relay
php spark outbox:relay forever
```

Run one cycle after every request by calling `Outbox::boot()` from
`app/Config/Events.php` and setting `autoRelay = true`.

## Symfony

```yaml
# config/packages/transactional_outbox.yaml
transactional_outbox:
    pdo: doctrine.dbal.default_connection
    dispatcher: event_dispatcher
    max_attempts: 5
    batch_size: 100
```

```php
use TransactionalOutbox\Core\Outbox;

public function __construct(private readonly Outbox $outbox)
{
}

$this->outbox->publish('order.created', ['order_id' => 1]);
```

```bash
php bin/console outbox:relay
php bin/console outbox:relay --forever
```

The bundle takes no Doctrine or Messenger dependency: the PDO connection and
the dispatcher are resolved through the container, so the application decides
which ones are used.

## Yii 2

```php
// config/params.php
return [
    'outbox' => [
        'maxAttempts' => 5,
        'batchSize' => 100,
    ],
];
```

```php
use TransactionalOutbox\Adapter\Yii\Outbox;

Outbox::make()->outbox()->publish('order.created', ['order_id' => 1]);
```

```bash
php yii outbox/relay
php yii outbox/relay forever
php yii outbox/status
```

The settings are read from `Yii::$app->params['outbox']`, or from a component
named `outbox` that returns an `OutboxConfig`.

## CakePHP

```php
// config/transactional_outbox.php
return [
    'connection' => 'default',
    'maxAttempts' => 5,
    'batchSize' => 100,
];
```

```php
use TransactionalOutbox\Adapter\CakePHP\Outbox;

Outbox::make()->outbox()->publish('order.created', ['order_id' => 1]);
```

```bash
bin/cake outbox:relay
bin/cake outbox:relay --forever
```

## Standalone relay

The `outbox-relay` binary drains the outbox without any framework.

```bash
vendor/bin/outbox-relay --dsn=sqlite:/app/app.sqlite
vendor/bin/outbox-relay --dsn=mysql:host=127.0.0.1;dbname=app --user=app --password=secret
vendor/bin/outbox-relay --dsn=pgsql:host=127.0.0.1;dbname=app --forever
```

| Option | Meaning |
|---|---|
| `--dsn` | PDO DSN, required |
| `--user`, `--password` | Credentials |
| `--once` | Run one cycle and exit (default) |
| `--forever` | Keep running |
| `--sleep` | Seconds between cycles, default 1 |
| `--max-attempts` | Delivery attempts before dead-lettering, default 5 |
| `--batch-size` | Messages claimed per cycle, default 100 |
| `--publisher` | `log` (default) or `callback:<file>` |

With `--publisher=callback:<file>` the file must return a callable that takes
an `OutboxMessage`:

```php
<?php

use TransactionalOutbox\Core\OutboxMessage;

return static function (OutboxMessage $message): void {
    // $message->eventType, $message->payload
};
```

## Publishers

| Class | Delivers to |
|---|---|
| `Publisher\Psr14Publisher` | Any PSR-14 dispatcher, or a framework bus |
| `Publisher\HttpPublisher` | An HTTP endpoint |
| `Publisher\CallbackPublisher` | Any callable |
| `Publisher\LogPublisher` | A stream, one JSON line per message |

`Psr14Publisher` is duck-typed: any object with a `dispatch()` method works.

```php
use TransactionalOutbox\Core\Publisher\Psr14Publisher;

$publisher = Psr14Publisher::fromDispatcher($eventDispatcher);
```

`HttpPublisher` takes any transport, so the package stays free of an HTTP
dependency:

```php
use TransactionalOutbox\Core\Publisher\HttpPublisher;

$publisher = new HttpPublisher(
    static fn (OutboxMessage $message, array $headers): array => [
        'status' => 200,
    ],
);
```

For a PSR-18 client, pass a stream factory:

```php
$publisher = HttpPublisher::fromPsr18(
    $client,
    'https://example.test/events',
    static fn (string $body) => $psr17Factory->createStream($body),
);
```

## Custom publisher

```php
use TransactionalOutbox\Core\Exception\PublishFailedException;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher;

final class BrokerPublisher implements Publisher
{
    public function publish(OutboxMessage $message): void
    {
        try {
            $this->broker->publish($message->eventType, $message->payload);
        } catch (\Throwable $e) {
            // Throwing schedules a retry. The message is dead-lettered once
            // max_attempts is reached.
            throw new PublishFailedException($e->getMessage(), $message->eventType, 0, $e);
        }
    }
}
```

The publisher must be stateless between calls. The relay is the only place
that keeps track of attempts.

## Testing

```bash
composer install
composer test
```

The suite covers the store, the relay, the atomicity guarantee and every
adapter: Laravel through a real container, Symfony through a real DI
container, CodeIgniter 4 and CakePHP through a plain PDO connection.

MySQL and PostgreSQL are covered too. The test is skipped unless a DSN is
configured:

```bash
OUTBOX_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=outbox' \
OUTBOX_TEST_MYSQL_USER=root \
OUTBOX_TEST_MYSQL_PASSWORD=secret \
OUTBOX_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;dbname=outbox' \
OUTBOX_TEST_PGSQL_USER=postgres \
OUTBOX_TEST_PGSQL_PASSWORD=secret \
vendor/bin/phpunit
```

Run the matrix on PHP 8.1 to 8.4 with Docker:

```bash
composer matrix
```

## Security notes and limitations

- **Payloads are not encrypted.** They sit in the database in plain JSON. Use
  database-level encryption or an encrypted volume if the payload holds
  sensitive data.
- **The relay is at-least-once.** A consumer that is not idempotent can see the
  same message twice. Use a deduplication key.
- **Ordering is not guaranteed.** Two messages for the same aggregate can
  arrive in the other order. Add a sequence number to the payload if the order
  matters.
- **The dead letter table grows.** Prune it, or move the rows somewhere you
  can inspect them.
- **Rule-based, not a broker.** The package does not replace RabbitMQ or
  Kafka. It replaces the "publish inside the request" part.

## Roadmap

- [ ] Inbox pattern (consumer side deduplication)
- [ ] Snapshot and replay of a dead letter table
- [ ] Per-tenant lanes, so one tenant cannot starve another
- [ ] Metrics hooks for Prometheus
- [ ] Signed batches for tamper evidence

Ideas and PRs are welcome. Keep the core dependency-free and the adapters thin.

## Contributing

- Bug report: use the issue template, include versions + minimal reproduction.
- New publisher: include positive and negative samples.
- Security issue: **do not** open a public issue, follow [SECURITY.md](SECURITY.md).
- Pull request: run `composer test` and the matrix, add tests for every new
  path, keep publishers stateless between calls.

Labels: `bug`, `enhancement`, `publisher-request`, `adapter`,
`needs-triage`, `good first issue`, `security`.

## License

MIT. See [LICENSE](LICENSE).
