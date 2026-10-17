<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests\Fixtures;

use Cosmira\NginxPushstream\PushstreamChannel;
use Cosmira\NginxPushstream\PushstreamMessage;
use Illuminate\Notifications\Notification;

final class MessageNotification extends Notification
{
    public function __construct(public readonly PushstreamMessage $message) {}

    public function via(object $notifiable): array
    {
        return [PushstreamChannel::class];
    }

    public function toPushstream(object $notifiable): PushstreamMessage
    {
        return $this->message;
    }
}
