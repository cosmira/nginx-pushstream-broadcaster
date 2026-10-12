<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests;

use Cosmira\NginxPushstream\Publisher;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class PublisherTest extends TestCase
{
    #[Test]
    public function sendsJsonEnvelopeAndReturnsResponse(): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response(['channel' => 'broadcast'], 201)]);
        $response = $this->app->make(Publisher::class)->send('broadcast', ['message' => 'Готово']);

        self::assertSame(201, $response->status());
        self::assertSame(['channel' => 'broadcast'], $response->json());
        $http->assertSentCount(1);
        [$request] = $http->recorded()[0];
        self::assertSame('POST', $request->method());
        self::assertSame('http://127.0.0.1/api/notify/publish/broadcast', $request->url());
        self::assertSame(['application/json'], $request->header('Content-Type'));
        self::assertSame(['channel_name' => 'broadcast', 'data' => ['message' => 'Готово']], json_decode($request->body(), true));
    }

    #[Test]
    public function sendsRawBodyUnchangedToPathAndQueryEndpoints(): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response('', 204)]);
        $body = "{\"channel\":\"user_42_hash\", \"module\":\"reports\"}\n";
        $publisher = new Publisher($http, 'https://nginx.test/pub?id={channel}', ['timeout' => 7]);
        $response = $publisher->sendRaw('users:42.done', $body);
        self::assertSame(204, $response->status());
        $publisher->sendRaw('next', '{}');
        $http->assertSentCount(2);
        [$first] = $http->recorded()[0];
        [$second] = $http->recorded()[1];
        self::assertSame('https://nginx.test/pub?id=users%3A42.done', $first->url());
        self::assertSame($body, $first->body());
        self::assertSame('{}', $second->body());
        self::assertSame('https://nginx.test/pub?id=next', $second->url());
    }

    #[Test]
    public function usesFreshRequestsWithDefaultsAndExplicitOptions(): void
    {
        $http = $this->app->make(Factory::class);
        $options = [];
        $http->fake(function ($request, array $requestOptions) use (&$options, $http) {
            $options[] = $requestOptions;

            return $http->response('', 200);
        });
        (new Publisher($http, 'https://nginx.test/pub/{channel}'))->sendRaw('broadcast', '{}');
        (new Publisher($http, 'https://nginx.test/pub/{channel}', [
            'timeout'         => 7, 'connect_timeout' => 3, 'headers' => ['X-Publisher' => 'reports'],
            'allow_redirects' => true,
        ]))->sendRaw('reports', '{}');

        self::assertSame(5, $options[0]['timeout']);
        self::assertSame(2, $options[0]['connect_timeout']);
        self::assertSame(7, $options[1]['timeout']);
        self::assertSame(3, $options[1]['connect_timeout']);
        self::assertFalse($options[1]['allow_redirects']);
        self::assertSame(['reports'], $http->recorded()[1][0]->header('X-Publisher'));
    }

    #[Test]
    public function sendsApplicationJsonWithoutAddingAnEnvelope(): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response(['accepted' => true], 202)]);
        $channel = 'user_42_hash';
        $message = ['channel' => $channel, 'state' => 'data', 'data' => ['progress' => 50], 'meta' => ['module' => 'reports']];
        $response = $this->app->make(Publisher::class)->sendJson($channel, $message);

        self::assertSame(202, $response->status());
        self::assertSame(['accepted' => true], $response->json());
        self::assertSame($message, json_decode($http->recorded()[0][0]->body(), true));
    }

    #[Test]
    #[DataProvider('invalidChannels')]
    public function rejectsInvalidChannelsBeforePublishing(string $channel): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->app->make(Publisher::class)->sendRaw($channel, '{}');
        } finally {
            $this->app->make(Factory::class)->assertNothingSent();
        }
    }

    public static function invalidChannels(): array
    {
        return array_map(fn (string $channel): array => [$channel], ['', 'ALL', '*', '../users', '.', 'users/foo', 'users?x=1', 'users#x', 'users x', 'users%2Ffoo', "users\n"]);
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function requiresExactlyOnePlaceholder(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Publisher($this->app->make(Factory::class), $url);
    }

    public static function invalidUrls(): array
    {
        return [['http://nginx.test/pub'], ['http://nginx.test/{channel}/{channel}']];
    }

    #[Test]
    #[DataProvider('httpErrors')]
    public function propagatesHttpErrorsWithoutRetry(int $status): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response('failure', $status)]);
        $this->expectException(RequestException::class);

        try {
            $this->app->make(Publisher::class)->send('broadcast', 'failure');
        } finally {
            $http->assertSentCount(1);
        }
    }

    public static function httpErrors(): array
    {
        return [[400], [401], [404], [500], [503]];
    }

    #[Test]
    #[DataProvider('nonSuccessStatuses')]
    public function rejectsNonSuccessResponses(int $status): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response('', $status)]);
        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('Push Stream publisher returned HTTP '.$status.'.');
        $this->app->make(Publisher::class)->sendRaw('broadcast', '{}');
    }

    public static function nonSuccessStatuses(): array
    {
        return [[199], [300], [301], [302], [307]];
    }

    #[Test]
    public function propagatesConnectionFailure(): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(fn () => throw new ConnectionException('offline'));
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('offline');
        $this->app->make(Publisher::class)->sendRaw('broadcast', '{}');
    }

    #[Test]
    public function rejectsInvalidJsonWithoutPublishing(): void
    {
        $this->expectException(JsonException::class);

        try {
            $this->app->make(Publisher::class)->send('broadcast', "\xB1");
        } finally {
            $this->app->make(Factory::class)->assertNothingSent();
        }
    }
}
