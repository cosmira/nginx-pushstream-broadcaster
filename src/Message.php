<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream;

use JsonSerializable;

final readonly class Message implements JsonSerializable
{
    /**
     * Store the application envelope without its destination channel.
     */
    private function __construct(
        /**
         * Fields serialized into the published message.
         */
        private array $payload,
    ) {}

    /**
     * Create a data message, omitting only null data and metadata.
     */
    public static function data(
        mixed $data = null,
        ?array $meta = null,
        string $state = 'data',
    ): self {
        return new self(array_filter([
            'state' => $state,
            'data'  => $data,
            'meta'  => $meta,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Create an error message with optional metadata.
     */
    public static function error(string $error, ?array $meta = null, string $state = 'error'): self
    {
        return new self(array_filter([
            'state' => $state,
            'error' => $error,
            'meta'  => $meta,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Return the application envelope for JSON serialization.
     */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }
}
