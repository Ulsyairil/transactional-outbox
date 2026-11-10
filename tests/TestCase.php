<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests;

use PDO;
use TransactionalOutbox\Core\Clock;

/**
 * Shared test helpers.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * In-memory SQLite connection with the same settings a production
     * connection would use.
     */
    protected function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    protected function clock(int $now = 1_700_000_000): FakeClock
    {
        return new FakeClock($now);
    }
}
