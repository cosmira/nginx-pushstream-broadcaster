<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class NginxPushstreamBroadcaster extends Broadcaster
{
    /**
     * Create the driver using the HTTP publisher.
     */
    public function __construct(
        /**
         * HTTP transport for broadcast event delivery.
         */
        private readonly Publisher $publisher,
    ) {}

    /**
     * Deny Pusher authentication because nginx owns subscriber access.
     */
    public function auth(mixed $request): never
    {
        throw new AccessDeniedHttpException(
            'Subscriber authorization must be enforced by the application and nginx.',
        );
    }

    /**
     * Deny authentication responses unsupported by Push Stream.
     */
    public function validAuthenticationResponse(mixed $request, mixed $result): never
    {
        throw new AccessDeniedHttpException(
            'Push Stream does not implement the Pusher authentication protocol.',
        );
    }

    /**
     * Publish Laravel events to public channels.
     */
    public function broadcast(array $channels, mixed $event, array $payload = []): void
    {
        $names = $this->formatChannels($channels);
        unset($payload['socket']);

        foreach ($names as $channel) {
            if (str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-')) {
                throw new BroadcastException(
                    'Private and presence channels are not supported by Push Stream.',
                );
            }
        }

        foreach ($names as $channel) {
            $this->publisher->sendRaw($channel, json_encode([
                'event' => $event,
                'data'  => $payload,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
