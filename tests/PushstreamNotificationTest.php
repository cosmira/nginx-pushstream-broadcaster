<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests;

use Cosmira\NginxPushstream\Concerns\ReceivesPushstreamNotifications;
use Cosmira\NginxPushstream\Message;
use Cosmira\NginxPushstream\Notifications\PushstreamNotification;
use Cosmira\NginxPushstream\PushstreamChannel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as Notifications;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TypeError;

final class PushstreamNotificationTest extends TestCase
{
    #[Test]
    public function deliversThroughTheStandardLaravelNotifiableApi(): void
    {
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'shared-yii-salt');
        Http::fake(['*' => Http::response('', 200)]);
        $user = new class
        {
            use ReceivesPushstreamNotifications;

            public function getAuthIdentifier(): mixed
            {
                return 17;
            }
        };

        $user->notify(new PushstreamNotification(Message::data(false, ['module' => 'reports'])));

        Http::assertSentCount(1);
        [$request] = Http::recorded()[0];
        self::assertSame('http://127.0.0.1/api/notify/publish/user_17_'.md5('17shared-yii-salt'), $request->url());
        self::assertSame([
            'channel' => 'user_17_'.md5('17shared-yii-salt'),
            'state'   => 'data',
            'data'    => false,
            'meta'    => ['module' => 'reports'],
        ], json_decode($request->body(), true));
    }

    #[Test]
    public function sendsAnAnonymousNotificationNowWithoutAnotherQueuedJob(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();
        $message = Message::error('failed', ['module' => 'reports'], 'failure');

        Notifications::route('pushstream', 'captured_channel')
            ->notifyNow(new PushstreamNotification($message));

        Http::assertSentCount(1);
        self::assertSame([
            'channel' => 'captured_channel', 'state' => 'failure',
            'error'   => 'failed', 'meta' => ['module' => 'reports'],
        ], json_decode(Http::recorded()[0][0]->body(), true));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function supportsApplicationNotificationsAndTheActualRecipient(): void
    {
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'shared-yii-salt');
        Http::fake(['*' => Http::response('', 200)]);
        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [PushstreamChannel::class];
            }

            public function toPushstream(object $notifiable): Message
            {
                return Message::data(['recipient' => $notifiable->getAuthIdentifier()]);
            }
        };

        $this->recipient(42)->notify($notification);

        Http::assertSentCount(1);
        self::assertSame([
            'channel' => 'user_42_'.md5('42shared-yii-salt'),
            'state'   => 'data', 'data' => ['recipient' => 42],
        ], json_decode(Http::recorded()[0][0]->body(), true));
    }

    #[Test]
    #[DataProvider('invalidRecipients')]
    public function invalidPersonalRoutingSkipsDelivery(mixed $id, mixed $salt): void
    {
        $this->app->make('config')->set('nginx-pushstream.channel_salt', $salt);
        Http::fake();
        $user = $this->recipient($id);

        self::assertNull($user->routeNotificationFor('pushstream'));
        $user->notify(new PushstreamNotification(Message::data('private')));

        Http::assertNothingSent();
    }

    public static function invalidRecipients(): iterable
    {
        foreach ([null, '', false, 17, []] as $salt) {
            yield 'salt '.get_debug_type($salt).json_encode($salt) => [17, $salt];
        }
        foreach ([null, '', false, 0.5, [], new \stdClass, '17/18', '17 18', "17\n", '*', 'a?b', 'a&b'] as $id) {
            yield 'identity '.get_debug_type($id).json_encode($id) => [$id, 'shared-yii-salt'];
        }
    }

    #[Test]
    #[DataProvider('validRecipients')]
    public function usesTheAuthenticationIdentifierAndCurrentSalt(int|string $id): void
    {
        $user = $this->recipient($id);
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'first-salt');
        self::assertSame('user_'.$id.'_'.md5($id.'first-salt'), $user->routeNotificationFor('pushstream'));
        self::assertSame('user_'.$id.'_'.md5($id.'first-salt'), $user->routeNotificationForPushstream());
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'second-salt');
        self::assertSame('user_'.$id.'_'.md5($id.'second-salt'), $user->routeNotificationFor('pushstream'));
    }

    public static function validRecipients(): iterable
    {
        yield 'integer' => [17];
        yield 'string' => ['17'];
        yield 'zero' => [0];
        yield 'zero string' => ['0'];
        yield 'uuid' => ['019a02b0-0000-7000-8000-000000000042'];
        yield 'punctuation' => ['a.b:c_d-e'];
    }

    #[Test]
    public function composesWithAnExistingNotifiableTrait(): void
    {
        $user = new class
        {
            use Notifiable;
            use ReceivesPushstreamNotifications;

            public function getAuthIdentifier(): mixed
            {
                return 17;
            }
        };
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'shared-yii-salt');
        self::assertSame('user_17_'.md5('17shared-yii-salt'), $user->routeNotificationFor('pushstream'));
    }

    #[Test]
    public function supportsLaravelsDefaultAuthenticatableModelWithoutOverridingItsIdentity(): void
    {
        $user = new class extends Authenticatable
        {
            use Notifiable;
            use ReceivesPushstreamNotifications;
        };
        $user->forceFill(['id' => 42]);
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'shared-yii-salt');
        Http::fake(['*' => Http::response('', 200)]);

        $user->notify(new PushstreamNotification(Message::data(['id' => 42])));

        Http::assertSentCount(1);
        self::assertSame('http://127.0.0.1/api/notify/publish/user_42_'.md5('42shared-yii-salt'), Http::recorded()[0][0]->url());
        self::assertSame(['id' => 42], json_decode(Http::recorded()[0][0]->body(), true)['data']);
    }

    #[Test]
    public function integratesWithNotificationFakeForModelsAndAnonymousRecipients(): void
    {
        Notifications::fake();
        $user = $this->recipient();
        $message = Message::data(false);
        $user->notify(new PushstreamNotification($message));
        Notifications::route('pushstream', 'captured_channel')->notifyNow(new PushstreamNotification($message));

        Notifications::assertSentTo($user, PushstreamNotification::class, fn ($notification, $channels): bool => $notification->message === $message && $channels === [PushstreamChannel::class]);
        Notifications::assertSentOnDemand(PushstreamNotification::class, fn ($notification, $channels, $notifiable): bool => $notification->message === $message
                && $channels === [PushstreamChannel::class]
                && $notifiable->routes === ['pushstream' => 'captured_channel']);
        Notifications::assertCount(2);
    }

    #[Test]
    public function honorsCancellationByNotificationSendingListeners(): void
    {
        Http::fake();
        $sending = [];
        Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$sending): bool {
            $sending[] = $event->channel;

            return false;
        });
        Notifications::route('pushstream', 'reports')->notifyNow(new PushstreamNotification(Message::data(42)));
        self::assertSame([PushstreamChannel::class], $sending);
        Http::assertNothingSent();
    }

    #[Test]
    public function exposesTheRealTransportResponseToNotificationSentListeners(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $sent = [];
        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
            $sent[] = $event;
        });
        Notifications::route('pushstream', 'reports')->notifyNow(new PushstreamNotification(Message::data()));
        self::assertCount(1, $sent);
        self::assertSame(PushstreamChannel::class, $sent[0]->channel);
        self::assertSame(201, $sent[0]->response->status());
        self::assertSame(['channel' => 'reports', 'state' => 'data'], json_decode(Http::recorded()[0][0]->body(), true));
    }

    #[Test]
    public function skipsNullRoutesBeforeFormattingAMessage(): void
    {
        Http::fake();
        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [PushstreamChannel::class];
            }
        };
        Notifications::route('pushstream', null)->notifyNow($notification);
        Http::assertNothingSent();
    }

    #[Test]
    public function requiresAnExplicitMessageMethod(): void
    {
        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [PushstreamChannel::class];
            }
        };
        $this->expectException(RuntimeException::class);
        Notifications::route('pushstream', 'reports')->notifyNow($notification);
    }

    #[Test]
    #[DataProvider('invalidExplicitRoutes')]
    public function rejectsInvalidExplicitRoutesInsteadOfSkippingThem(mixed $route, string $exception): void
    {
        $this->expectException($exception);
        Notifications::route('pushstream', $route)->notifyNow(new PushstreamNotification(Message::data(42)));
    }

    public static function invalidExplicitRoutes(): iterable
    {
        yield 'empty' => ['', InvalidArgumentException::class];
        yield 'reserved' => ['ALL', InvalidArgumentException::class];
        yield 'slash' => ['a/b', InvalidArgumentException::class];
        yield 'false' => [false, TypeError::class];
        yield 'array' => [[], TypeError::class];
    }

    #[Test]
    public function propagatesPublicationFailureWithoutReportingDelivery(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $sent = [];
        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
            $sent[] = $event;
        });

        try {
            Notifications::route('pushstream', 'reports')->notifyNow(new PushstreamNotification(Message::data(42)));
            self::fail('A failed publisher response must reach the caller.');
        } catch (RequestException $exception) {
            self::assertSame(503, $exception->response->status());
        }
        self::assertSame([], $sent);
        Http::assertSentCount(1);
    }

    private function recipient(mixed $id = 17): object
    {
        return new class($id)
        {
            use ReceivesPushstreamNotifications;

            public function __construct(private mixed $id) {}

            public function getKey(): mixed
            {
                return $this->id;
            }

            public function getAuthIdentifier(): mixed
            {
                return $this->id;
            }
        };
    }
}
