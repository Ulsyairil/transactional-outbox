<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Yii\Commands;

use TransactionalOutbox\Adapter\Yii\Outbox;
use yii\console\Controller;
use yii\console\ExitCode;

final class RelayController extends Controller
{
    /**
     * Deliver pending outbox messages once.
     */
    public function actionIndex(): int
    {
        $outbox = Outbox::make();

        $result = $outbox->relay()->run();

        $this->stdout(json_encode($result->toArray(), JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Keep delivering until the process is stopped.
     */
    public function actionForever(): int
    {
        $outbox = Outbox::make();

        while (true) {
            $result = $outbox->relay()->run();

            $this->stdout(json_encode($result->toArray(), JSON_UNESCAPED_SLASHES) . PHP_EOL);

            sleep(1);
        }
    }

    /**
     * Show the outbox counters.
     */
    public function actionStatus(): int
    {
        $outbox = Outbox::make()->outbox();

        $this->stdout(json_encode($outbox->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return ExitCode::OK;
    }
}
