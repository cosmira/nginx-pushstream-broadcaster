<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Notifications;

use Cosmira\NginxPushstream\Message;
use Cosmira\NginxPushstream\PushstreamChannel;
use Illuminate\Notifications\Notification;

final class PushstreamNotification extends Notification
{
    /**
     * Wrap an application message for Laravel notification delivery.
     */
    public function __construct(
        /**
         * Immutable envelope supplied by the application.
         */
        public readonly Message $message,
    ) {}

    /**
     * Resolve the custom channel directly through Laravel's container.
     *
     * @return list<class-string<PushstreamChannel>>
     */
    public function via(object $notifiable): array
    {
        return [PushstreamChannel::class];
    }

    /**
     * Keep the supplied envelope unchanged during notification delivery.
     */
    public function toPushstream(object $notifiable): Message
    {
        return $this->message;
    }
}
