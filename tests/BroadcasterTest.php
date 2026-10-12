<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests;

use Cosmira\NginxPushstream\NginxPushstreamBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class BroadcasterTest extends TestCase
{
    private function driver(): NginxPushstreamBroadcaster
    {
        $this->app->make('config')->set('broadcasting.connections.pushstream', ['driver' => 'nginx-pushstream']);

        return $this->app->make(BroadcastManager::class)->connection('pushstream');
    }

    #[Test]
    public function broadcastsToEveryPublicChannelThroughLaravelManager(): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response('', 200)]);
        $driver = $this->driver();
        $driver->broadcast([new Channel('reports'), 'broadcast'], 'report.ready', ['id' => 42]);
        $http->assertSentCount(2);
        foreach (['reports', 'broadcast'] as $index => $channel) {
            [$request] = $http->recorded()[$index];
            self::assertSame('http://127.0.0.1/api/notify/publish/'.$channel, $request->url());
            self::assertSame(['event' => 'report.ready', 'data' => ['id' => 42]], json_decode($request->body(), true));
        }
    }

    #[Test]
    public function sendsDefaultEmptyPayload(): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response('', 200)]);
        $this->driver()->broadcast(['broadcast'], 'tick');
        [$request] = $http->recorded()[0];
        self::assertSame(['event' => 'tick', 'data' => []], json_decode($request->body(), true));
    }

    #[Test]
    public function emptyChannelsDoNotPublishOrEncodePayload(): void
    {
        $this->driver()->broadcast([], 'tick', ['invalid' => "\xB1"]);
        $this->app->make(Factory::class)->assertNothingSent();
    }

    #[Test]
    #[DataProvider('guardedChannels')]
    public function rejectsGuardedChannelsBeforeAnyPublication(Channel|string $channel): void
    {
        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('Private and presence channels are not supported by Push Stream.');

        try {
            $this->driver()->broadcast(['public', $channel], 'test');
        } finally {
            $this->app->make(Factory::class)->assertNothingSent();
        }
    }

    public static function guardedChannels(): array
    {
        return [[new PrivateChannel('user')], [new PresenceChannel('room')], ['private-user'], ['presence-room']];
    }

    #[Test]
    public function rejectsUnencodableEventData(): void
    {
        $this->expectException(JsonException::class);
        $this->driver()->broadcast(['reports'], 'test', ['invalid' => "\xB1"]);
    }

    #[Test]
    public function deniesSubscriberAuthentication(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Subscriber authorization must be enforced by the application and nginx.');
        $this->driver()->auth(Request::create('/broadcasting/auth', 'POST', ['channel_name' => 'private-user']));
    }

    #[Test]
    public function deniesPusherAuthenticationResponses(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Push Stream does not implement the Pusher authentication protocol.');
        $this->driver()->validAuthenticationResponse(Request::create('/broadcasting/auth'), true);
    }
}
