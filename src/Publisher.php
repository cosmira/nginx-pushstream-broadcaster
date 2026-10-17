<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

final readonly class Publisher
{
    /**
     * Create the publisher with a Laravel HTTP factory and channel URL.
     */
    public function __construct(
        /**
         * Laravel HTTP client shared with the Http facade.
         */
        private Factory $http,
        /**
         * Publisher URL with a single channel placeholder.
         */
        private string $url,
        /**
         * HTTP options overriding the default timeouts.
         */
        private array $options = [],
    ) {
        if (substr_count($url, '{channel}') !== 1) {
            throw new InvalidArgumentException(
                'Publisher URL must contain exactly one {channel} placeholder.',
            );
        }
    }

    /**
     * Publish a JSON envelope containing the channel name and application data.
     */
    public function send(string $channel, mixed $data): Response
    {
        return $this->sendJson($channel, [
            'channel_name' => $channel,
            'data'         => $data,
        ]);
    }

    /**
     * Publish an application message addressed to the destination channel.
     */
    public function sendMessage(string $channel, Message $message): Response
    {
        return $this->sendJson($channel, [
            'channel' => $channel,
            ...$message->jsonSerialize(),
        ]);
    }

    /**
     * Publish application data as JSON without adding an envelope.
     */
    public function sendJson(string $channel, mixed $data): Response
    {
        return $this->sendRaw($channel, json_encode($data, JSON_THROW_ON_ERROR));
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
        $response = $this->http
            ->withOptions(array_replace([
                'timeout'         => 5,
                'connect_timeout' => 2,
            ], $this->options))
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
