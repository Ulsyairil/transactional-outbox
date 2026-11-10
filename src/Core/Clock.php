<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core;

interface Clock
{
    /**
     * Current UNIX timestamp in seconds.
     */
    public function now(): int;
}
