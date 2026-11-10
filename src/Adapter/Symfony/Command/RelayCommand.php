<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\Outbox;

final class RelayCommand extends Command
{
    protected static $defaultName = 'outbox:relay';

    protected static $defaultDescription = 'Deliver pending outbox messages.';

    public function __construct(
        private readonly DatabaseRelay $relay,
        private readonly Outbox $outbox,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('forever', null, InputOption::VALUE_NONE, 'Keep running instead of exiting after one cycle')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait between cycles', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $forever = (bool) $input->getOption('forever');
        $sleep = max(1, (int) $input->getOption('sleep'));

        do {
            $result = $this->relay->run();

            $output->writeln(json_encode($result->toArray(), JSON_UNESCAPED_SLASHES));

            if (! $forever) {
                break;
            }

            sleep($sleep);
        } while (true);

        $output->writeln(json_encode([
            'pending' => $this->outbox->pendingCount(),
            'dead_lettered' => $this->outbox->deadLetterCount(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
