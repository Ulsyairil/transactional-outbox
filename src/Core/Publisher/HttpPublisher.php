<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core\Publisher;

use TransactionalOutbox\Core\Exception\PublishFailedException;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher;

/**
 * Posts the message to an HTTP endpoint.
 *
 * The transport is pluggable so the core stays dependency-free: pass any
 * PSR-18 client, or a callable that performs the request.
 */
final class HttpPublisher implements Publisher
{
    /**
     * @var callable(OutboxMessage, array<string, string>): array{status: int, body?: string}
     */
    private $transport;

    /**
     * @param callable(OutboxMessage, array<string, string>): array{status: int, body?: string} $transport
     */
    public function __construct(callable $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Builds a publisher on top of any PSR-18 client.
     *
     * The client and the stream factory are duck-typed on purpose: the
     * package must not depend on a concrete HTTP library. Any object with
     * `createRequest()` and `sendRequest()` works.
     *
     * @param object $client A PSR-18 client.
     * @param callable(string): object $streamFactory Turns a string into a PSR-7 stream.
     * @param array<string, mixed> $config
     */
    public static function fromPsr18(
        object $client,
        string $url,
        callable $streamFactory,
        array $config = [],
    ): self {
        $method = (string) ($config['method'] ?? 'POST');

        return new self(
            static function (OutboxMessage $message, array $headers) use ($client, $url, $method, $streamFactory): array {
                $body = json_encode([
                    'event_type' => $message->eventType,
                    'aggregate_type' => $message->aggregateType,
                    'aggregate_id' => $message->aggregateId,
                    'deduplication_key' => $message->deduplicationKey,
                    'payload' => $message->payload,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $request = $client->createRequest($method, $url)
                    ->withHeader('Content-Type', 'application/json');

                foreach ($headers as $name => $value) {
                    $request = $request->withHeader($name, $value);
                }

                $response = $client->sendRequest($request->withBody($streamFactory($body)));

                return [
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getBody(),
                ];
            },
        );
    }

    public function publish(OutboxMessage $message): void
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'X-Outbox-Event-Type' => $message->eventType,
        ], $message->headers);

        if ($message->deduplicationKey !== null) {
            $headers['X-Outbox-Deduplication-Key'] = $message->deduplicationKey;
        }

        $result = ($this->transport)($message, $headers);
        $status = (int) $result['status'];

        if ($status < 200 || $status >= 300) {
            throw new PublishFailedException(
                sprintf('HTTP endpoint returned status %d for event "%s".', $status, $message->eventType),
                $message->eventType,
            );
        }
    }
}
