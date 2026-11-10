<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\CakePHP\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use TransactionalOutbox\Adapter\CakePHP\Outbox;

final class RelayCommand extends Command
{
    protected static $defaultName = 'outbox:relay';

    protected static $defaultDescription = 'Deliver pending outbox messages.';

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription(self::$defaultDescription)
            ->addOption('forever', [
                'boolean' => true,
                'help' => 'Keep running instead of exiting after one cycle.',
            ])
            ->addOption('sleep', [
                'help' => 'Seconds to wait between cycles.',
                'default' => '1',
            ]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $outbox = Outbox::make();
        $forever = (bool) $args->getOption('forever');
        $sleep = max(1, (int) $args->getOption('sleep'));

        do {
            $result = $outbox->relay()->run();

            $io->out(json_encode($result->toArray(), JSON_UNESCAPED_SLASHES));

            if (! $forever) {
                break;
            }

            sleep($sleep);
        } while (true);

        $io->out(json_encode([
            'pending' => $outbox->outbox()->pendingCount(),
            'dead_lettered' => $outbox->outbox()->deadLetterCount(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return static::CODE_SUCCESS;
    }
}
