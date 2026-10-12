<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests;

use Cosmira\NginxPushstream\Tests\Fixtures\NotificationRecipient;
use Cosmira\NginxPushstream\Tests\Fixtures\ReportReadyNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as Notifications;
use PHPUnit\Framework\Attributes\Test;

final class LaravelIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make('config')->set('broadcasting.default', 'pushstream');
        $this->app->make('config')->set('broadcasting.connections.pushstream', ['driver' => 'nginx-pushstream']);
        Http::fake(['*' => Http::response('', 200)]);
    }

    #[Test]
    public function dispatchesAnActualEventWithoutLeakingTheSocketIdentifier(): void
    {
        $event = new class implements ShouldBroadcastNow
        {
            use InteractsWithSockets;

            public function broadcastOn(): array
            {
                return [new Channel('reports'), new Channel('broadcast')];
            }

            public function broadcastAs(): string
            {
                return 'report.ready';
            }

            public function broadcastWith(): array
            {
                return ['id' => 42];
            }
        };
        $event->socket = 'socket-123';
        event($event);

        Http::assertSentCount(2);
        foreach (['reports', 'broadcast'] as $index => $channel) {
            [$request] = Http::recorded()[$index];
            self::assertSame('http://127.0.0.1/api/notify/publish/'.$channel, $request->url());
            self::assertSame(['event' => 'report.ready', 'data' => ['id' => 42]], json_decode($request->body(), true));
        }
    }

    #[Test]
    public function deliversBroadcastNotificationToExplicitPublicChannel(): void
    {
        $user = new NotificationRecipient;
        $notification = new ReportReadyNotification;
        $notification->id = '019a02b0-0000-7000-8000-000000000042';
        Notifications::sendNow($user, $notification);

        Http::assertSentCount(1);
        [$request] = Http::recorded()[0];
        self::assertSame('http://127.0.0.1/api/notify/publish/user_42_hash', $request->url());
        self::assertSame([
            'event' => BroadcastNotificationCreated::class,
            'data'  => ['report_id' => 42, 'id' => $notification->id, 'type' => 'report.ready'],
        ], json_decode($request->body(), true));
    }
}
