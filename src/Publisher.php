<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

final readonly class Publisher
{
    /**
     * Create the publisher with a configured HTTP request and channel URL.
     */
    public function __construct(
        /**
         * HTTP request carrying application transport settings.
         */
        private PendingRequest $http,
        /**
         * Publisher URL with a single channel placeholder.
         */
        private string $url,
    ) {
        if (substr_count($url, '{channel}') !== 1) {
            throw new InvalidArgumentException(
                'Publisher URL must contain exactly one {channel} placeholder.',
            );
        }
    }

    /**
     * Publish the JSON envelope used by existing Yii clients.
     */
    public function send(string $channel, mixed $data): Response
    {
        return $this->sendRaw($channel, json_encode([
            'channel_name' => $channel,
            'data'         => $data,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Publish the supplied body without changing its wire representation.
     */
    public function sendRaw(string $channel, string $body): Response
    {
        $valid = preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9_.:-]*\z/', $channel) === 1;

        if (! $valid || $channel === 'ALL') {
            throw new InvalidArgumentException('Invalid Push Stream channel.');
        }

        $url = str_replace('{channel}', rawurlencode($channel), $this->url);
        $response = (clone $this->http)
            ->withoutRedirecting()
            ->withBody($body, 'application/json')
            ->post($url);
        $response->throw();

        if (! $response->successful()) {
            throw new BroadcastException(
                'Push Stream publisher returned HTTP '.$response->status().'.',
            );
        }

        return $response;
    }
}
