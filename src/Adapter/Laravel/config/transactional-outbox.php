<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Transactional Outbox
    |--------------------------------------------------------------------------
    |
    | Messages are stored in the same database transaction as your business
    | writes. A relay worker delivers them afterwards.
    |
    */

    /*
     * Database connection that holds the outbox tables. Null = default.
     * The connection must be the same one your business writes use,
     * otherwise the outbox cannot be atomic.
     */
    'connection' => env('OUTBOX_CONNECTION'),

    /*
     * How the relay delivers a message:
     *  - null            -> dispatch on the Laravel event bus
     *  - a class name    -> resolved from the container, must implement Publisher
     *  - a Publisher instance
     */
    'publisher' => null,

    /*
     * Core outbox settings. Same shape in every adapter.
     */
    'outbox' => [
        'table' => 'outbox_messages',
        'batch_table' => 'outbox_batches',
        'dead_letter_table' => 'outbox_dead_letters',

        /*
         * Delivery attempts before a message is dead-lettered.
         */
        'max_attempts' => 5,

        /*
         * Exponential backoff: base * 2^(attempt-1), capped at max_delay_seconds.
         */
        'base_delay_seconds' => 10,
        'max_delay_seconds' => 3600,

        /*
         * Messages claimed per relay cycle.
         */
        'batch_size' => 100,

        /*
         * A claimed message is released again after this many seconds, so a
         * worker that dies mid-cycle does not block the queue forever.
         */
        'claim_timeout_seconds' => 300,

        /*
         * Create the outbox tables on boot. Turn off when you manage the
         * schema with migrations.
         */
        'auto_install' => true,
    ],
];
