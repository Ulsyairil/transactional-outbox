<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core;

use TransactionalOutbox\Core\Exception\OutboxException;

/**
 * Immutable outbox configuration.
 *
 * All adapters funnel into this object, so behaviour stays identical across
 * frameworks. Only the database driver differs.
 */
final class OutboxConfig
{
    /**
     * @param array<string, mixed> $options
     */
    private function __construct(
        public readonly string $table,
        public readonly string $batchTable,
        public readonly string $deadLetterTable,
        public readonly int $maxAttempts,
        public readonly int $baseDelaySeconds,
        public readonly int $maxDelaySeconds,
        public readonly int $batchSize,
        public readonly int $claimTimeoutSeconds,
        public readonly bool $autoInstall,
        public readonly array $options,
    ) {
        if ($this->table === '' || $this->batchTable === '' || $this->deadLetterTable === '') {
            throw new OutboxException('Outbox table names must not be empty.');
        }

        if ($this->maxAttempts < 1) {
            throw new OutboxException('max_attempts must be >= 1.');
        }

        if ($this->baseDelaySeconds < 0 || $this->maxDelaySeconds < 0) {
            throw new OutboxException('Retry delays must not be negative.');
        }

        if ($this->maxDelaySeconds > 0 && $this->maxDelaySeconds < $this->baseDelaySeconds) {
            throw new OutboxException('max_delay_seconds must be >= base_delay_seconds.');
        }

        if ($this->batchSize < 1) {
            throw new OutboxException('batch_size must be >= 1.');
        }

        if ($this->claimTimeoutSeconds < 1) {
            throw new OutboxException('claim_timeout_seconds must be >= 1.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config = []): self
    {
        $config = isset($config['outbox']) && is_array($config['outbox'])
            ? $config['outbox']
            : $config;

        $known = [
            'table',
            'batch_table',
            'dead_letter_table',
            'max_attempts',
            'base_delay_seconds',
            'max_delay_seconds',
            'batch_size',
            'claim_timeout_seconds',
            'auto_install',
            'options',
        ];

        $unknown = array_diff(array_keys($config), $known);

        if ($unknown !== []) {
            throw new OutboxException(sprintf(
                'Unknown outbox option(s): %s. Known: %s.',
                implode(', ', $unknown),
                implode(', ', $known),
            ));
        }

        return new self(
            table: (string) ($config['table'] ?? 'outbox_messages'),
            batchTable: (string) ($config['batch_table'] ?? 'outbox_batches'),
            deadLetterTable: (string) ($config['dead_letter_table'] ?? 'outbox_dead_letters'),
            maxAttempts: (int) ($config['max_attempts'] ?? 5),
            baseDelaySeconds: (int) ($config['base_delay_seconds'] ?? 10),
            maxDelaySeconds: (int) ($config['max_delay_seconds'] ?? 3600),
            batchSize: (int) ($config['batch_size'] ?? 100),
            claimTimeoutSeconds: (int) ($config['claim_timeout_seconds'] ?? 300),
            autoInstall: (bool) ($config['auto_install'] ?? true),
            options: (array) ($config['options'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'batch_table' => $this->batchTable,
            'dead_letter_table' => $this->deadLetterTable,
            'max_attempts' => $this->maxAttempts,
            'base_delay_seconds' => $this->baseDelaySeconds,
            'max_delay_seconds' => $this->maxDelaySeconds,
            'batch_size' => $this->batchSize,
            'claim_timeout_seconds' => $this->claimTimeoutSeconds,
            'auto_install' => $this->autoInstall,
            'options' => $this->options,
        ];
    }
}
