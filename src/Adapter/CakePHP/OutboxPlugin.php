<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\CakePHP;

use Cake\Console\CommandCollection;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\BaseApplication;
use TransactionalOutbox\Adapter\CakePHP\Command\RelayCommand;
use TransactionalOutbox\Adapter\CakePHP\Config\Outbox as OutboxConfig;

/**
 * Registers the outbox services on a CakePHP application.
 *
 * Add to config/bootstrap.php:
 *   (new TransactionalOutbox\Adapter\CakePHP\OutboxPlugin())->bootstrap();
 */
final class OutboxPlugin implements PluginApplicationInterface
{
    public function bootstrap(PluginApplicationInterface $app): void
    {
        $config = Configure::read(Outbox::CONFIG_KEY);

        if (! is_array($config)) {
            return;
        }

        $settings = new OutboxConfig();

        foreach (get_object_vars($settings) as $property => $default) {
            if (! array_key_exists($property, $config)) {
                continue;
            }

            $value = $config[$property];

            $settings->{$property} = match (true) {
                is_bool($default) => (bool) $value,
                is_int($default) => (int) $value,
                is_string($default) => (string) $value,
                default => $value,
            };
        }

        Configure::write(Outbox::CONFIG_KEY, $settings);
    }

    public function middleware(BaseApplication $app): void
    {
    }

    public function routes(\Cake\Routing\RouteBuilder $routes): void
    {
    }

    public function services(ContainerInterface $container): void
    {
        $container->add(Outbox::class);
    }

    public function console(CommandCollection $commands): CommandCollection
    {
        return $commands->add('outbox:relay', RelayCommand::class);
    }
}
