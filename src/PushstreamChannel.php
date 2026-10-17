<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream;

use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use RuntimeException;

final readonly class PushstreamChannel
{
    /**
     * Use the publisher shared with the application's HTTP client.
     */
    public function __construct(
        /**
         * Transport preserving the application message envelope.
         */
        private Publisher $publisher,
    ) {}

    /**
     * Deliver a Laravel notification to its explicitly resolved Push Stream route.
     */
    public function send(object $notifiable, Notification $notification): ?Response
    {
        $channel = $notifiable->routeNotificationFor('pushstream', $notification);

        if ($channel === null) {
            return null;
        }

        if (! method_exists($notification, 'toPushstream')) {
            throw new RuntimeException('Notification is missing a toPushstream method.');
        }

        return $this->publisher->sendMessage($channel, $notification->toPushstream($notifiable));
    }
}
