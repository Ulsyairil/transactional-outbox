# GitHub metadata

Copy-paste text for the repository page and the first release. Keep this file
in sync with `composer.json` and `CHANGELOG.md`.

## About

> Transactional Outbox for PHP: store events in the same database transaction
> as your business writes, then relay them with retries, backoff and dead
> letters. Zero-dependency core, PDO on SQLite/MySQL/PostgreSQL, thin adapters
> for Laravel, CodeIgniter 4, Symfony, Yii 2 and CakePHP.

Character count: 280. GitHub allows 350.

## Topics

```
php
transactional-outbox
outbox-pattern
event-driven
distributed-systems
reliability
messaging
queue
laravel
codeigniter4
symfony
yii2
cakephp
pdo
sqlite
mysql
postgresql
php-library
```

## Release: v1.0.0

Title: `v1.0.0 - Transactional Outbox for PHP`

```markdown
First stable release. Store an event in the same database transaction as the
business write that produced it, then deliver it from a worker with retries,
exponential backoff and a dead letter table.

## Highlights

- **Atomic writes.** `Outbox::transaction()` commits the business row and the
  outbox row together, or neither.
- **PDO storage.** One implementation for SQLite, MySQL and PostgreSQL.
- **Crash-safe claiming.** Locks expire after `claim_timeout_seconds`, so a
  worker that dies mid-cycle does not strand messages.
- **Multiple workers.** `FOR UPDATE SKIP LOCKED` on MySQL and PostgreSQL.
  SQLite serialises through its single writer.
- **Deduplication keys.** Unique in the table and forwarded to the publisher
  as `X-Outbox-Deduplication-Key`.
- **Batch tracking.** One `outbox_batches` row per relay cycle.
- **Five adapters.** Laravel, CodeIgniter 4, Symfony, Yii 2 and CakePHP all
  map onto the same core config.
- **No external calls.** The package never phones home.

## Install

```bash
composer require ulsyairil/transactional-outbox
```

Requires PHP >= 8.1 with `ext-pdo` and `ext-json`.

## Quick start

```php
use TransactionalOutbox\Core\Outbox;
use TransactionalOutbox\Core\OutboxMessage;

$outbox = Outbox::forConnection($pdo);

$outbox->transaction(
    fn () => $pdo->exec('INSERT INTO orders (id, total) VALUES (1, 100)'),
    fn () => OutboxMessage::create('order.created', ['order_id' => 1]),
);
```

Run the relay:

```bash
vendor/bin/outbox-relay --dsn="sqlite:/path/app.sqlite"
```

Or through the framework adapter:

| Framework | Command |
|---|---|
| Laravel | `php artisan outbox:relay` |
| CodeIgniter 4 | `php spark outbox:relay` |
| Symfony | `php bin/console outbox:relay` |
| Yii 2 | `php yii outbox/relay` |
| CakePHP | `bin/cake outbox:relay` |

## Delivery guarantee

At-least-once. A message that reaches the publisher but fails to be recorded
as published is delivered again on the next cycle. Use the deduplication key
or an idempotent consumer when duplicates matter.

## Tests

94 tests, 220 assertions, on PHP 8.1 through 8.5. MySQL and PostgreSQL engine
tests run in CI against real service containers.

## Links

- [README](https://github.com/ulsyairil/transactional-outbox#readme)
- [Architecture](https://github.com/ulsyairil/transactional-outbox/blob/main/docs/architecture.md)
- [Changelog](https://github.com/ulsyairil/transactional-outbox/blob/main/CHANGELOG.md)
- [Security policy](https://github.com/ulsyairil/transactional-outbox/blob/main/SECURITY.md)
```

## Settings that are easy to forget

- Repository description: the About block above.
- Topics: the list above, all lowercase and hyphenated.
- Website: leave empty until there is a docs site; the README covers it.
- Releases: paste the body above under the `v1.0.0` tag.
- Social preview: 1280x640 image; title `Transactional Outbox`, subtitle
  `Atomic events for PHP`.
