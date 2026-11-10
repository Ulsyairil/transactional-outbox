<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Laravel;

use Illuminate\Support\ServiceProvider;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\Outbox;
use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\OutboxFactory;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Publisher\CallbackPublisher;
use TransactionalOutbox\Core\Publisher\Psr14Publisher;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;

final class TransactionalOutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/transactional-outbox.php', 'transactional-outbox');

        $this->app->singleton(Outbox::class, function ($app): Outbox {
            $config = (array) $app['config']->get('transactional-outbox', []);
            $pdo = $this->pdo($app, $config);

            return OutboxFactory::fromConnection($pdo, $config);
        });

        $this->app->singleton(PdoOutboxStore::class, function ($app): PdoOutboxStore {
            $config = (array) $app['config']->get('transactional-outbox', []);

            return OutboxFactory::storeFrom($this->pdo($app, $config), $config);
        });

        $this->app->singleton(Publisher::class, function ($app): Publisher {
            $config = (array) $app['config']->get('transactional-outbox', []);
            $publisher = $config['publisher'] ?? null;

            if ($publisher instanceof Publisher) {
                return $publisher;
            }

            if (is_string($publisher) && $publisher !== '') {
                $resolved = $app->make($publisher);

                if (!$resolved instanceof Publisher) {
                    throw new \InvalidArgumentException(sprintf(
                        '%s must implement %s.',
                        $publisher,
                        Publisher::class,
                    ));
                }

                return $resolved;
            }

            // Default: hand the event to the Laravel event bus.
            return new Psr14Publisher(static function (object $event) use ($app): void {
                $app['events']->dispatch($event);
            });
        });

        $this->app->singleton(DatabaseRelay::class, function ($app): DatabaseRelay {
            $config = (array) $app['config']->get('transactional-outbox', []);

            return OutboxFactory::relayFrom(
                $app->make(PdoOutboxStore::class),
                $app->make(Publisher::class),
                $config,
            );
        });

        $this->app->alias(Outbox::class, 'transactional-outbox');
        $this->app->alias(DatabaseRelay::class, 'transactional-outbox.relay');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/transactional-outbox.php' => $this->app->configPath('transactional-outbox.php'),
        ], 'transactional-outbox');

        if ($this->app->runningInConsole()) {
            $this->commands([RelayCommand::class]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pdo($app, array $config): \PDO
    {
        $connection = $config['connection'] ?? null;

        return $app['db']->connection($connection)->getPdo();
    }
}
