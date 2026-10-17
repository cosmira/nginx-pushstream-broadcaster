<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use RuntimeException;

final readonly class PushstreamChannel
{
    /**
     * Use the publisher shared with the application's HTTP client.
     */
    public function __construct(
        /**
         * Transport preserving the application notification payload.
         */
        private Publisher $publisher,
    ) {}

    /**
     * Resolve an explicit route or the authenticated recipient's personal channel.
     */
    public function route(object $notifiable, ?Notification $notification = null): ?string
    {
        if ($notifiable instanceof AnonymousNotifiable
            || method_exists($notifiable, 'routeNotificationForPushstream')) {
            $channel = $notifiable->routeNotificationFor('pushstream', $notification);

            if ($channel === null) {
                return null;
            }

            if (! is_string($channel)) {
                throw new InvalidArgumentException('Push Stream route must be a string or null.');
            }

            $this->publisher->validateChannel($channel);

            return $channel;
        }

        if (! $notifiable instanceof Authenticatable) {
            return null;
        }

        return $this->personalChannel($notifiable);
    }

    /**
     * Deliver the notification's array without adding a broadcast envelope or queue.
     */
    public function send(object $notifiable, Notification $notification): ?Response
    {
        $channel = $this->route($notifiable, $notification);

        if ($channel === null) {
            return null;
        }

        if (! method_exists($notification, 'toArray')) {
            throw new RuntimeException('Notification is missing a toArray method.');
        }

        $payload = $notification->toArray($notifiable);

        if (! is_array($payload)) {
            throw new RuntimeException('Notification toArray must return an array.');
        }

        return $this->publisher->sendJson($channel, ['channel' => $channel] + $payload);
    }

    /**
     * Preserve the shared Yii address format without inventing missing credentials.
     */
    private function personalChannel(Authenticatable $notifiable): ?string
    {
        $id = $notifiable->getAuthIdentifier();

        if ((! is_int($id) && ! is_string($id)) || $id === '') {
            return null;
        }

        $salt = config('nginx-pushstream.channel_salt');

        if (! is_string($salt) || $salt === '') {
            return null;
        }

        $channel = 'user_'.$id.'_'.md5($id.$salt);

        try {
            $this->publisher->validateChannel($channel);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $channel;
    }
}
