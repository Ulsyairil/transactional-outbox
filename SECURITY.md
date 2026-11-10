# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x | yes |
| 0.x | no |

## Reporting a vulnerability

**Do not open a public issue.** Report it privately through GitHub's
[security advisory form](https://github.com/ulsyairil/transactional-outbox/security/advisories/new),
or by email to the maintainer.

Include:

- the version you use
- the database engine
- a minimal reproduction
- what an attacker gains

You can expect an acknowledgement within 72 hours and a fix or a mitigation
plan within 14 days. Credit is given in the release notes unless you prefer
otherwise.

## Threat model

This package stores events in your database and delivers them to a publisher.

**In scope**

- The outbox tables must not let one tenant read another tenant's messages.
- The relay must not deliver a message to the wrong publisher.
- A failed delivery must not leave a message in a state where it is silently
  dropped.

**Out of scope**

- Encryption of the payload. Payloads are stored as plain JSON in your
  database; encrypt at the database or volume level if that is a requirement.
- Authentication of the publisher endpoint. Sign your own requests.
- Exactly-once delivery. The package guarantees at-least-once.
- The security of the broker you publish to.
- SQL injection through the configured table names. The names are quoted as
  identifiers, but they come from your configuration, not from user input.

## Handling guidance

- Keep the deduplication key when a consumer is not idempotent.
- Rotate the credentials of every endpoint the relay talks to.
- Prune the dead letter table. It keeps the payload of every failed message.
