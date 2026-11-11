<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\CakePHP;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;
use TransactionalOutbox\Adapter\CakePHP\Command\RelayCommand;
use TransactionalOutbox\Adapter\CakePHP\Config\Outbox as OutboxConfig;

/**
 * Registers the outbox on a CakePHP application.
 *
 * Load it as a plugin in config/bootstrap.php:
 *
 *   $this->addPlugin('TransactionalOutbox/Adapter/CakePHP');
 *
 * or by pointing the application at the class:
 *
 *   (new \TransactionalOutbox\Adapter\CakePHP\OutboxPlugin())->bootstrap($app);
 *
 * The plugin only normalises the config and registers the console command. The
 * outbox itself is created lazily by the factory, so nothing touches the
 * database until the first message is written.
 */
class OutboxPlugin extends BasePlugin
{
    /**
     * Normalises the config array into an OutboxConfig instance.
     *
     * CakePHP 5.0 declares PluginApplicationInterface as generic, later 5.x
     * releases and 4.x do not. The subject type is irrelevant here, so the
     * widest type that fits every supported release is used.
     *
     * @param PluginApplicationInterface<object> $app
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        $config = Configure::read(Outbox::CONFIG_KEY);

        if (! is_array($config)) {
            return;
        }

        Configure::write(Outbox::CONFIG_KEY, Outbox::settingsFromArray($config));
    }

    public function routes(RouteBuilder $routes): void
    {
    }

    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue;
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
