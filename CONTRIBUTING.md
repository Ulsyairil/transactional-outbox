# Contributing

Thanks for taking the time to contribute. This package is small on purpose: a
dependency-free core plus thin framework adapters. Most contributions keep it
that way.

## Ground rules

- **The core stays dependency-free.** No runtime packages in `require`. Only
  `php`, `ext-pdo` and `ext-json`. A new dependency needs a very good reason
  and a discussion in an issue first.
- **Adapters stay thin.** An adapter wires the framework to the core. It must
  not reimplement relay logic, storage logic or configuration parsing.
- **Behaviour is identical across frameworks.** Every adapter funnels into
  `OutboxConfig::fromArray()` and the same core classes. A behaviour that only
  exists in one adapter is a bug.
- **The package makes no external calls.** The relay talks to whatever broker
  the publisher points at, but the package itself never calls home.

## Workflow

1. Fork and create a topic branch: `git checkout -b fix/short-description`.
2. Write a test for the new behaviour, including the failure case.
3. Run the suite: `composer test`.
4. Run static analysis: `composer analyse`.
5. Run the matrix when you touched the core or an adapter:
   `composer matrix` (PHP 8.1 to 8.4, Docker).
6. Run the linter the way CI does:
   `find src tests bin examples -name '*.php' -print0 | xargs -0 -n1 php -l`
7. Open a PR against `main` and fill in the pull request template.

## Code style

- `declare(strict_types=1);` in every file.
- PSR-4 for namespaces, PSR-12 for formatting.
- `final` classes by default; interfaces over abstract base classes.
- Immutable value objects. No public setters.
- Constructor property promotion, with `readonly` where the value never
  changes.
- Exceptions live in `TransactionalOutbox\Core\Exception` and extend
  `OutboxException`, which extends `RuntimeException`.
- Comments explain the reason, not the mechanic. A comment that repeats the
  code is noise.

## Test requirements

- Every new code path needs a test.
- Tests must be deterministic: no sleeping, no network, no wall-clock
  assertions. Use `TransactionalOutbox\Tests\FakeClock` for time.
- Adapter tests use a real framework container where one is affordable
  (Laravel, Symfony), and a plain PDO connection where bootstrapping the
  framework would add nothing (CodeIgniter 4, CakePHP).
- The MySQL and PostgreSQL tests are skipped unless a DSN is configured. CI
  sets them. See the README for the exact variables.
- Integration tests for the same behaviour on several engines belong in
  `tests/Integration/DatabaseEngineTest.php`.

## Adding a publisher

A publisher implements `TransactionalOutbox\Core\Publisher`:

```php
public function publish(OutboxMessage $message): void;
```

Rules:

- **Stateless between calls.** The relay owns the attempt counter, the
  schedule and the dead letter state.
- **Throw on failure.** A thrown exception schedules a retry with exponential
  backoff. Returning normally means the message was delivered.
- **Never swallow an error.** A publisher that returns normally after a failed
  delivery silently drops the message.
- Include one positive and one negative sample in the pull request.

## Adding a framework adapter

The adapter is expected to provide:

- a config class or file whose values map onto the core config keys,
- a way to resolve the `PDO` connection and the `Publisher`,
- a console command that runs the relay,
- a test that proves the wiring works.

New config keys are a breaking change for every existing adapter. Add them to
`OutboxConfig::fromArray()` and to all five adapters in the same commit.

## Commit messages

Use Conventional Commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`,
`chore:`, `perf:`. One-line subject, blank line, then the body if the change
needs explaining.

## Breaking changes

- Bump the major version (1.0 to 2.0).
- Document the change in `CHANGELOG.md` under `## [Unreleased]` -> `### Breaking`.
- Explain the migration path in the pull request description.

## Reporting a bug

Open an issue with the template, and include:

- the package version, the PHP version and the framework version,
- the database engine,
- a minimal reproduction,
- the expected and the observed result.

## Reporting a security issue

Do not open a public issue. Follow [SECURITY.md](SECURITY.md).

## Labels

`bug`, `enhancement`, `publisher-request`, `adapter`, `docs`, `needs-triage`,
`good first issue`, `security`.
