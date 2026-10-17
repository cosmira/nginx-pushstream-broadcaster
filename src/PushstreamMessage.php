<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/**
 * @implements Arrayable<string, mixed>
 */
final class PushstreamMessage implements Arrayable
{
    /**
     * Explicit application error code, without transport or exception details.
     */
    private ?string $error = null;

    /**
     * Create a structured message for an explicit client module.
     *
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $meta
     */
    public function __construct(
        /**
         * Structured application data preserved without filtering.
         */
        private readonly array $data,
        /**
         * Client module that consumes this notification.
         */
        private readonly string $module,
        /**
         * Additional metadata, excluding authoritative generated fields.
         */
        private readonly array $meta = [],
    ) {
        if (trim($module) === '') {
            throw new InvalidArgumentException('Push Stream message module must not be blank.');
        }
    }

    /**
     * Mark the message as a failure with a caller-approved application code.
     */
    public function error(string $code): self
    {
        if (trim($code) === '') {
            throw new InvalidArgumentException('Push Stream message error code must not be blank.');
        }

        $this->error = $code;

        return $this;
    }

    /**
     * Serialize the product's success or error envelope.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $meta = ['module' => $this->module] + $this->meta;

        if ($this->error === null) {
            return ['state' => 'data', 'data' => $this->data, 'meta' => $meta];
        }

        $data = $this->data;
        $data['error_message'] = $this->error;
        $meta['data'] = $data;

        return ['state' => 'error', 'error' => 'error', 'meta' => $meta];
    }
}
