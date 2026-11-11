# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **MySQL schema install.** The auto-increment column is now declared as a
  primary key. MySQL rejects an `AUTO_INCREMENT` column that is not a key
  with "Incorrect table definition; there can be only one auto column and it
  must be defined as a key".
- **Index names are table-scoped.** The dedup, pending, batch and event index
  names are derived from the table name. PostgreSQL and SQLite scope index
  names per schema, so a fixed name made a second outbox table fail to
  install and left the deduplication index missing, which broke the
  `ON CONFLICT` upsert.
- **Symfony 6.4 compatibility.** The configuration tree no longer chains
  through `end()`, whose return type differs between Symfony 6.4 and 7.x.
- **PHPStan on the lowest dependency set.** The Symfony command no longer
  declares a native type on `$defaultName` and `$defaultDescription`, the
  CodeIgniter command documents its `$arguments` shape, and the CakePHP plugin
  documents its generic subject type.

### Changed

- **CI matrix inputs.** `ramsey/composer-install` expects
  `dependency-versions` with the values `lowest` and `highest`. The previous
  `dependency-version` input with `prefer-lowest` and `prefer-stable` values
  was ignored, so both jobs ran a plain update.
- **Composer policy for CodeIgniter 4.** Composer 2.11 blocks versions with
  active security advisories, and every CodeIgniter 4 release that still
  supports PHP 8.1 carries an advisory. `config.policy.advisories.ignore` in
  `composer.json` scopes the exemption to that package, so PHP 8.1 stays
  installable without disabling the check for everything else.

### Planned

- Inbox pattern for consumer-side deduplication
- Snapshot and replay of the dead letter table
- Per-tenant lanes
- Prometheus metrics hooks
- Signed batches for tamper evidence

## [1.0.0] - 2026-11-10

First stable release.

### Added

- **Atomic outbox writes.** `Outbox::transaction()` stores the event in the
  same database transaction as the business write. Either both commit or
  neither does.
- **PDO storage.** `PdoOutboxStore` runs on SQLite, MySQL and PostgreSQL
  through a small dialect layer.
- **Relay worker.** `DatabaseRelay` claims due messages, publishes them, and
  records the outcome. Exponential backoff, configurable attempt limit, and a
  dead letter table keep failed messages recoverable.
- **Crash-safe claiming.** A claimed message is locked; a lock left behind by
  a dead worker expires after `claim_timeout_seconds` and is reclaimed.
- **Multiple workers.** Rows are claimed with `FOR UPDATE SKIP LOCKED` on MySQL
  and PostgreSQL, so several relay processes never deliver the same message.
- **Deduplication keys.** A message with an existing key is not stored twice,
  and the key is forwarded to the publisher as
  `X-Outbox-Deduplication-Key`.
- **Batch tracking.** Each relay cycle writes one row to `outbox_batches`, so a
  delivery can be traced back to the cycle that sent it.
- **Publishers.** PSR-14 dispatcher, HTTP endpoint, raw callable, and stream
  logger. The core has no HTTP or queue dependency.
- **Framework adapters.** Laravel, CodeIgniter 4, Symfony, Yii 2 and CakePHP,
  all funnelling into the same config shape.
- **Standalone binary.** `vendor/bin/outbox-relay` drains the outbox without a
  framework.
- **Tests.** 80 tests covering the store, the relay, atomicity, concurrency,
  the three database engines, and every adapter.

[Unreleased]: https://github.com/ulsyairil/transactional-outbox/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ulsyairil/transactional-outbox/releases/tag/v1.0.0
