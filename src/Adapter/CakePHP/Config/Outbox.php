<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\CakePHP\Config;

/**
 * Copy to config/transactional_outbox.php and edit there.
 */
class Outbox
{
    /**
     * CakePHP datasource connection name. Empty = default.
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
     * Publisher instance or null for the built-in log publisher.
     */
    public mixed $publisher = null;

    /**
     * Reuse an existing PDO connection instead of taking it from the datasource.
     */
    public mixed $pdo = null;
}
