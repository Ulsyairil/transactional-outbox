<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\CodeIgniter4\Config;

/**
 * Copy to app/Config/Outbox.php and edit there.
 *
 * Deliberately not a CodeIgniter\Config\BaseConfig, so the settings can be
 * read without a bootstrapped application.
 */
class Outbox
{
    /**
     * Database connection that holds the outbox tables. Empty = default.
     */
    public string $connection = '';

    public string $table = 'outbox_messages';

    public string $batchTable = 'outbox_batches';

    public string $deadLetterTable = 'outbox_dead_letters';

    public int $maxAttempts = 5;

    public int $baseDelaySeconds = 10;

    public int $maxDelaySeconds = 3600;

    public int $batchSize = 100;

    public int $claimTimeoutSeconds = 300;

    public bool $autoInstall = true;

    /**
     * Publisher instance, callable, or null for the built-in log publisher.
     */
    public mixed $publisher = null;

    /**
     * Reuse an existing PDO connection instead of opening one from the
     * database config.
     */
    public mixed $pdo = null;

    /**
     * Run one relay cycle on the post_system event.
     */
    public bool $autoRelay = false;
}
