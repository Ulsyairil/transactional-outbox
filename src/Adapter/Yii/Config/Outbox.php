<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Yii\Config;

/**
 * Copy to config/Outbox.php and edit there.
 *
 * Deliberately not a yii\base\BaseObject, so the settings can be read without
 * a bootstrapped Yii application.
 */
class Outbox
{
    /**
     * PDO DSN. Empty = use the application database.
     */
    public string $dsn = '';

    public string $username = '';

    public string $password = '';

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
     * Publisher instance, component name, or null for the built-in log publisher.
     */
    public mixed $publisher = null;

    /**
     * Reuse an existing PDO connection instead of opening one.
     */
    public mixed $pdo = null;
}
