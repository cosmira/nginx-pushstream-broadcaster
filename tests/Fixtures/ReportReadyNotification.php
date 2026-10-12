<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests\Fixtures;

use Illuminate\Broadcasting\Channel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

final class ReportReadyNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['broadcast'];
    }

    public function broadcastOn(): array
    {
        return [new Channel('user_42_hash')];
    }

    public function broadcastType(): string
    {
        return 'report.ready';
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(['report_id' => 42]);
    }
}
