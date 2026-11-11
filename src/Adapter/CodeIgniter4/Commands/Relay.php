<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\CodeIgniter4\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use TransactionalOutbox\Adapter\CodeIgniter4\Outbox;

final class Relay extends BaseCommand
{
    protected $group = 'Database';
    protected $name = 'outbox:relay';
    protected $description = 'Deliver pending outbox messages.';
    protected $usage = 'outbox:relay [once|forever]';

    /**
     * @var array<string, string>
     */
    protected $arguments = [
        'action' => 'once (default) or forever',
    ];

    public function run(array $params): void
    {
        $action = $params[0] ?? 'once';

        try {
            $outbox = Outbox::make();
            $forever = $action === 'forever';

            do {
                $result = $outbox->relay()->run();

                CLI::write(json_encode($result->toArray(), JSON_UNESCAPED_SLASHES));

                if (! $forever) {
                    break;
                }

                sleep(1);
            } while (true);
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
        }
    }
}
