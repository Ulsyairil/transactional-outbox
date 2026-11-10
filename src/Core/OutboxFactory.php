<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core;

use PDO;
use TransactionalOutbox\Core\Exception\OutboxException;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;

/**
 * Builds the outbox stack from a plain array.
 *
 * Every adapter (Laravel config, CI4 config, Symfony config) funnels into
 * this class, so behaviour stays identical across frameworks.
 */
final class OutboxFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function configFrom(array $config = []): OutboxConfig
    {
        return OutboxConfig::fromArray($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function storeFrom(PDO $pdo, array $config = [], ?Clock $clock = null): PdoOutboxStore
    {
        $outboxConfig = self::configFrom($config);

        return new PdoOutboxStore($pdo, $outboxConfig, $clock ?? new SystemClock());
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConnection(
        PDO $pdo,
        array $config = [],
        ?Clock $clock = null,
    ): Outbox {
        $outboxConfig = self::configFrom($config);
        $store = new PdoOutboxStore($pdo, $outboxConfig, $clock ?? new SystemClock());

        if ($outboxConfig->autoInstall) {
            $store->install();
        }

        return new Outbox($store, $pdo);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function relayFrom(
        OutboxStore $store,
        Publisher $publisher,
        array $config = [],
        ?Clock $clock = null,
    ): DatabaseRelay {
        $outboxConfig = self::configFrom($config);

        return new DatabaseRelay($store, $publisher, $outboxConfig, $clock ?? new SystemClock());
    }
}
