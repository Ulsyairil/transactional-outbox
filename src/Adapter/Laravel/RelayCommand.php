<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Laravel;

use Illuminate\Console\Command;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\Outbox;

final class RelayCommand extends Command
{
    protected $signature = 'outbox:relay
        {--limit=0 : Maximum number of messages per cycle, 0 = use the configured batch size}
        {--forever : Keep running instead of exiting after one cycle}
        {--sleep=1 : Seconds to wait between cycles in --forever mode}';

    protected $description = 'Deliver pending outbox messages.';

    public function handle(DatabaseRelay $relay, Outbox $outbox): int
    {
        $limit = (int) $this->option('limit');
        $forever = (bool) $this->option('forever');
        $sleep = max(1, (int) $this->option('sleep'));

        do {
            $result = $relay->run();

            if ($this->output->isVerbose()) {
                $this->line(json_encode($result->toArray(), JSON_UNESCAPED_SLASHES));
            }

            if (! $forever) {
                break;
            }

            sleep($sleep);
        } while (true);

        $this->info(json_encode([
            'result' => $result->toArray(),
            'pending' => $outbox->pendingCount(),
            'dead_lettered' => $outbox->deadLetterCount(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
