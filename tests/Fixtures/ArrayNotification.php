<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests\Fixtures;

use Cosmira\NginxPushstream\PushstreamChannel;
use Illuminate\Notifications\Notification;

final class ArrayNotification extends Notification
{
    public function __construct(public readonly array $payload) {}

    public function via(object $notifiable): array
    {
        return [PushstreamChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
