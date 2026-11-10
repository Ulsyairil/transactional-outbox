<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use PDO;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;
use TransactionalOutbox\Tests\TestCase;

/**
 * Two workers must never publish the same message twice.
 *
 * The claim locks the rows, so the second worker skips what the first one
 * took. That is what makes the relay safe to run on more than one host.
 */
final class ConcurrentRelayTest extends TestCase
{
    public function testTwoWorkersDoNotPublishTheSameMessage(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'outbox-concurrency-');

        $pdoA = new PDO('sqlite:' . $file);
        $pdoA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdoB = new PDO('sqlite:' . $file);
        $pdoB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $config = OutboxConfig::fromArray();

        $storeA = new PdoOutboxStore($pdoA, $config, $this->clock());
        $storeA->install();

        $storeA->store(OutboxMessage::create('order.created', ['id' => 1]));
        $storeA->store(OutboxMessage::create('order.created', ['id' => 2]));

        $publishedA = [];
        $publishedB = [];

        $relayA = new DatabaseRelay(
            $storeA,
            $this->publisher($publishedA),
            $config,
            $this->clock(),
        );

        $relayB = new DatabaseRelay(
            new PdoOutboxStore($pdoB, $config, $this->clock()),
            $this->publisher($publishedB),
            $config,
            $this->clock(),
        );

        $resultA = $relayA->run();
        $resultB = $relayB->run();

        $total = $resultA->published + $resultB->published;

        self::assertSame(2, $total, 'Each message must be published exactly once.');
        self::assertCount(2, array_merge($publishedA, $publishedB));

        unlink($file);
    }

    public function testALockedMessageIsNotClaimedTwice(): void
    {
        $pdo = $this->pdo();
        $clock = $this->clock();
        $config = OutboxConfig::fromArray();

        $store = new PdoOutboxStore($pdo, $config, $clock);
        $store->install();
        $store->store(OutboxMessage::create('order.created'));

        $first = $store->claim(10, 10);
        $second = $store->claim(10, 10);

        self::assertCount(1, $first);
        self::assertCount(0, $second);
    }

    /**
     * @param list<string> $published
     */
    private function publisher(array &$published): Publisher
    {
        return new class ($published) implements Publisher {
            /**
             * @param list<string> $published
             */
            public function __construct(private array &$published)
            {
            }

            public function publish(OutboxMessage $message): void
            {
                $this->published[] = $message->eventType;
            }
        };
    }
}
