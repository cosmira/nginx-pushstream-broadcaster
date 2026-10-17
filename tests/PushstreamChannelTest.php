<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests;

use Cosmira\NginxPushstream\PushstreamChannel;
use Cosmira\NginxPushstream\Tests\Fixtures\ArrayNotification;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\AnonymousNotifiable;
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

final class PushstreamChannelTest extends TestCase
{
    #[Test]
    public function deliversThroughTheStandardLaravelNotifiableApi(): void
    {
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'shared-yii-salt');
        Http::fake(['*' => Http::response('', 200)]);
        $user = new class extends Authenticatable
        {
            use Notifiable;

            public function getAuthIdentifier(): mixed
            {
                return 17;
            }
        };

        $user->notify(new ArrayNotification(['state' => 'data', 'data' => false, 'meta' => ['module' => 'reports']]));

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
        $message = ['state' => 'failure', 'error' => 'failed', 'meta' => ['module' => 'reports']];

        Notifications::route('pushstream', 'captured_channel')
            ->notifyNow(new ArrayNotification($message));

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

            public function toArray(object $notifiable): array
            {
                return ['state' => 'data', 'data' => ['recipient' => $notifiable->getAuthIdentifier()]];
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

        self::assertNull($this->app->make(PushstreamChannel::class)->route($user));
        $user->notify(new ArrayNotification(['state' => 'data', 'data' => 'private']));

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
        self::assertSame('user_'.$id.'_'.md5($id.'first-salt'), $this->app->make(PushstreamChannel::class)->route($user));
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'second-salt');
        self::assertSame('user_'.$id.'_'.md5($id.'second-salt'), $this->app->make(PushstreamChannel::class)->route($user));
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
    public function recipientsWithoutAnAuthenticationContractHaveNoDefaultRoute(): void
    {
        $user = new class
        {
            use Notifiable;

            public function getAuthIdentifier(): mixed
            {
                return 17;
            }
        };
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'shared-yii-salt');
        Http::fake();
        self::assertNull($this->app->make(PushstreamChannel::class)->route($user));
        $user->notify(new ArrayNotification(['data' => 'private']));
        Http::assertNothingSent();
    }

    #[Test]
    public function supportsLaravelsDefaultAuthenticatableModelWithoutOverridingItsIdentity(): void
    {
        $user = new class extends Authenticatable
        {
            use Notifiable;
        };
        $user->forceFill(['id' => 42]);
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'shared-yii-salt');
        Http::fake(['*' => Http::response('', 200)]);

        $user->notify(new ArrayNotification(['state' => 'data', 'data' => ['id' => 42]]));

        Http::assertSentCount(1);
        self::assertSame('http://127.0.0.1/api/notify/publish/user_42_'.md5('42shared-yii-salt'), Http::recorded()[0][0]->url());
        self::assertSame(['id' => 42], json_decode(Http::recorded()[0][0]->body(), true)['data']);
    }

    #[Test]
    public function integratesWithNotificationFakeForModelsAndAnonymousRecipients(): void
    {
        Notifications::fake();
        $user = $this->recipient();
        $message = ['state' => 'data', 'data' => false];
        $user->notify(new ArrayNotification($message));
        Notifications::route('pushstream', 'captured_channel')->notifyNow(new ArrayNotification($message));

        Notifications::assertSentTo($user, ArrayNotification::class, fn ($notification, $channels): bool => $notification->payload === $message && $channels === [PushstreamChannel::class]);
        Notifications::assertSentOnDemand(ArrayNotification::class, fn ($notification, $channels, $notifiable): bool => $notification->payload === $message
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
        Notifications::route('pushstream', 'reports')->notifyNow(new ArrayNotification(['state' => 'data', 'data' => 42]));
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
        Notifications::route('pushstream', 'reports')->notifyNow(new ArrayNotification(['state' => 'data']));
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
    public function requiresAnArrayMethod(): void
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
        Http::fake();
        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [PushstreamChannel::class];
            }

            public function toArray(object $notifiable): array
            {
                throw new \LogicException('Invalid routes must fail before serialization.');
            }
        };
        $this->expectException($exception);

        try {
            Notifications::route('pushstream', $route)->notifyNow($notification);
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function invalidExplicitRoutes(): iterable
    {
        yield 'empty' => ['', InvalidArgumentException::class];
        yield 'reserved' => ['ALL', InvalidArgumentException::class];
        yield 'slash' => ['a/b', InvalidArgumentException::class];
        yield 'false' => [false, InvalidArgumentException::class];
        yield 'array' => [[], InvalidArgumentException::class];
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
            Notifications::route('pushstream', 'reports')->notifyNow(new ArrayNotification(['state' => 'data', 'data' => 42]));
            self::fail('A failed publisher response must reach the caller.');
        } catch (RequestException $exception) {
            self::assertSame(503, $exception->response->status());
        }
        self::assertSame([], $sent);
        Http::assertSentCount(1);
    }

    #[Test]
    public function dedicatedHooksOverridePersonalRoutingAndCanExplicitlyOptOut(): void
    {
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'shared-yii-salt');
        Http::fake(['*' => Http::response('', 200)]);
        $user = new class extends Authenticatable
        {
            use Notifiable;
            public ?string $destination = 'custom';
            public ?Notification $received = null;

            public function routeNotificationForPushstream(?Notification $notification): ?string
            {
                $this->received = $notification;

                return $this->destination;
            }
        };
        $user->forceFill(['id' => 17]);
        $notification = new ArrayNotification(['data' => 42]);
        $user->notify($notification);
        self::assertInstanceOf(ArrayNotification::class, $user->received);
        self::assertSame($notification->payload, $user->received->payload);
        self::assertSame('custom', json_decode(Http::recorded()[0][0]->body(), true)['channel']);
        $user->destination = null;
        self::assertNull($this->app->make(PushstreamChannel::class)->route($user));
        $user->notify($notification);
        Http::assertSentCount(1);
    }

    #[Test]
    public function missingAnonymousRoutesDoNotFallBackToPersonalRouting(): void
    {
        Http::fake();
        $this->app->make('config')->set('nginx-pushstream.channel_salt', 'shared-yii-salt');
        $anonymous = new AnonymousNotifiable;
        self::assertNull($this->app->make(PushstreamChannel::class)->route($anonymous));
        $anonymous->notifyNow(new ArrayNotification(['data' => 42]));
        Http::assertNothingSent();
    }

    #[Test]
    public function malformedArrayMethodsFailBeforePublishing(): void
    {
        Http::fake();
        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [PushstreamChannel::class];
            }

            public function toArray(object $notifiable): mixed
            {
                return 'not an array';
            }
        };
        $this->expectException(RuntimeException::class);

        try {
            Notifications::route('pushstream', 'reports')->notifyNow($notification);
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function applicationDataCannotOverrideTheResolvedDestination(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        Notifications::route('pushstream', 'trusted')->notifyNow(new ArrayNotification([
            'channel' => 'attacker', 'data' => ['channel' => 'nested', 'value' => null],
        ]));
        self::assertSame(['channel' => 'trusted', 'data' => ['channel' => 'nested', 'value' => null]], json_decode(Http::recorded()[0][0]->body(), true));
        self::assertSame('http://127.0.0.1/api/notify/publish/trusted', Http::recorded()[0][0]->url());
    }

    #[Test]
    #[DataProvider('arrayPayloads')]
    public function transportsApplicationArraysWithoutChangingTheirValues(array $payload): void
    {
        Http::fake(['*' => Http::response('', 202)]);
        Notifications::route('pushstream', 'reports')->notifyNow(new ArrayNotification($payload));
        self::assertSame(['channel' => 'reports'] + $payload, json_decode(Http::recorded()[0][0]->body(), true));
        Http::assertSentCount(1);
    }

    public static function arrayPayloads(): iterable
    {
        yield 'empty payload' => [[]];
        foreach ([false, 0, '', [], null, ['nested' => null]] as $data) {
            yield get_debug_type($data).json_encode($data) => [['state' => 'data', 'data' => $data, 'meta' => []]];
        }
    }

    private function recipient(mixed $id = 17): object
    {
        return new class($id) extends Authenticatable
        {
            use Notifiable;

            public function __construct(private mixed $identity) {}

            public function getKey(): mixed
            {
                return $this->identity;
            }

            public function getAuthIdentifier(): mixed
            {
                return $this->identity;
            }
        };
    }
}
