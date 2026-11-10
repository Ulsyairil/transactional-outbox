<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Unit;

use InvalidArgumentException;
use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\Exception\OutboxException;
use TransactionalOutbox\Tests\TestCase;

final class OutboxConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = OutboxConfig::fromArray();

        self::assertSame('outbox_messages', $config->table);
        self::assertSame('outbox_batches', $config->batchTable);
        self::assertSame('outbox_dead_letters', $config->deadLetterTable);
        self::assertSame(5, $config->maxAttempts);
        self::assertSame(10, $config->baseDelaySeconds);
        self::assertSame(3600, $config->maxDelaySeconds);
        self::assertSame(100, $config->batchSize);
        self::assertSame(300, $config->claimTimeoutSeconds);
        self::assertTrue($config->autoInstall);
    }

    public function testReadsNestedOutboxKey(): void
    {
        $config = OutboxConfig::fromArray([
            'outbox' => ['max_attempts' => 9, 'table' => 'events'],
        ]);

        self::assertSame(9, $config->maxAttempts);
        self::assertSame('events', $config->table);
    }

    public function testRejectsUnknownKeys(): void
    {
        $this->expectException(OutboxException::class);
        $this->expectExceptionMessage('Unknown outbox option(s): nope');

        OutboxConfig::fromArray(['nope' => 1]);
    }

    public function testRejectsEmptyTableName(): void
    {
        $this->expectException(OutboxException::class);

        OutboxConfig::fromArray(['table' => '']);
    }

    public function testRejectsMaxAttemptsBelowOne(): void
    {
        $this->expectException(OutboxException::class);
        $this->expectExceptionMessage('max_attempts must be >= 1');

        OutboxConfig::fromArray(['max_attempts' => 0]);
    }

    public function testRejectsBatchSizeBelowOne(): void
    {
        $this->expectException(OutboxException::class);

        OutboxConfig::fromArray(['batch_size' => 0]);
    }

    public function testRejectsMaxDelayBelowBaseDelay(): void
    {
        $this->expectException(OutboxException::class);
        $this->expectExceptionMessage('max_delay_seconds must be >= base_delay_seconds');

        OutboxConfig::fromArray(['base_delay_seconds' => 60, 'max_delay_seconds' => 10]);
    }

    public function testToArrayRoundTrips(): void
    {
        $config = OutboxConfig::fromArray(['max_attempts' => 3]);
        $again = OutboxConfig::fromArray($config->toArray());

        self::assertSame($config->toArray(), $again->toArray());
    }
}
