<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Unit;

use TransactionalOutbox\Core\Exception\SchemaException;
use TransactionalOutbox\Core\Storage\SqlDialect;
use TransactionalOutbox\Tests\TestCase;

final class SqlDialectTest extends TestCase
{
    public function testDetectsSqlite(): void
    {
        self::assertTrue(SqlDialect::detect($this->pdo())->isSqlite());
    }

    public function testRejectsAnUnknownDriver(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Unsupported PDO driver');

        SqlDialect::forDriver('oci');
    }

    public function testQuotesIdentifiersPerEngine(): void
    {
        self::assertSame('`outbox`', SqlDialect::forDriver('mysql')->quote('outbox'));
        self::assertSame('"outbox"', SqlDialect::forDriver('sqlite')->quote('outbox'));
        self::assertSame('"outbox"', SqlDialect::forDriver('pgsql')->quote('outbox'));
    }

    public function testEscapesQuotesInsideIdentifiers(): void
    {
        self::assertSame('"a""b"', SqlDialect::forDriver('sqlite')->quote('a"b'));
        self::assertSame('`a``b`', SqlDialect::forDriver('mysql')->quote('a`b'));
    }

    public function testPrimaryKeyDefinitionPerEngine(): void
    {
        self::assertStringContainsString('AUTOINCREMENT', SqlDialect::forDriver('sqlite')->primaryKeyDefinition());
        self::assertStringContainsString('AUTO_INCREMENT', SqlDialect::forDriver('mysql')->primaryKeyDefinition());
        self::assertStringContainsString('BIGSERIAL', SqlDialect::forDriver('pgsql')->primaryKeyDefinition());
    }

    /**
     * MySQL rejects an auto-increment column that is not a key:
     * "there can be only one auto column and it must be defined as a key".
     */
    public function testMysqlAutoIncrementColumnIsAKey(): void
    {
        self::assertStringContainsString(
            'PRIMARY KEY',
            SqlDialect::forDriver('mysql')->primaryKeyDefinition(),
        );
    }

    public function testInsertIgnoreDuplicateSuffixPerEngine(): void
    {
        self::assertStringContainsString(
            'ON DUPLICATE KEY UPDATE',
            SqlDialect::forDriver('mysql')->insertIgnoreDuplicateSuffix(),
        );
        self::assertStringContainsString(
            'ON CONFLICT',
            SqlDialect::forDriver('sqlite')->insertIgnoreDuplicateSuffix(),
        );
        self::assertStringContainsString(
            'ON CONFLICT',
            SqlDialect::forDriver('pgsql')->insertIgnoreDuplicateSuffix(),
        );
    }

    public function testForUpdateOnlyOnRealRowLocks(): void
    {
        self::assertSame('', SqlDialect::forDriver('sqlite')->forUpdateSuffix());
        self::assertStringContainsString('SKIP LOCKED', SqlDialect::forDriver('mysql')->forUpdateSuffix());
        self::assertStringContainsString('SKIP LOCKED', SqlDialect::forDriver('pgsql')->forUpdateSuffix());
    }

    public function testTimestampExpressionPerEngine(): void
    {
        self::assertStringContainsString('strftime', SqlDialect::forDriver('sqlite')->currentTimestampExpression());
        self::assertStringContainsString('UNIX_TIMESTAMP', SqlDialect::forDriver('mysql')->currentTimestampExpression());
        self::assertStringContainsString('EXTRACT', SqlDialect::forDriver('pgsql')->currentTimestampExpression());
    }
}
