<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests;

use Cosmira\NginxPushstream\Message;
use Cosmira\NginxPushstream\Publisher;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class MessageTest extends TestCase
{
    #[Test]
    #[DataProvider('messages')]
    public function publishesMessageToItsDestination(callable $makeMessage, array $expected): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response(['accepted' => true], 202)]);

        $message = $makeMessage();
        $response = $this->app->make(Publisher::class)->sendMessage('users:42.done', $message);

        self::assertSame(202, $response->status());
        self::assertSame(['accepted' => true], $response->json());
        $http->assertSentCount(1);
        [$request] = $http->recorded()[0];
        self::assertSame('POST', $request->method());
        self::assertSame('http://127.0.0.1/api/notify/publish/users%3A42.done', $request->url());
        self::assertSame(['application/json'], $request->header('Content-Type'));
        self::assertSame(['channel' => 'users:42.done', ...$expected], json_decode($request->body(), true));
        self::assertSame($expected, json_decode(json_encode($message, JSON_THROW_ON_ERROR), true));
    }

    public static function messages(): array
    {
        return [
            'default data'             => [fn (): Message => Message::data(), ['state' => 'data']],
            'false'                    => [fn (): Message => Message::data(false), ['state' => 'data', 'data' => false]],
            'zero'                     => [fn (): Message => Message::data(0), ['state' => 'data', 'data' => 0]],
            'empty string'             => [fn (): Message => Message::data(''), ['state' => 'data', 'data' => '']],
            'empty arrays'             => [fn (): Message => Message::data([], []), ['state' => 'data', 'data' => [], 'meta' => []]],
            'metadata without data'    => [fn (): Message => Message::data(meta: ['module' => 'reports']), ['state' => 'data', 'meta' => ['module' => 'reports']]],
            'custom data state'        => [fn (): Message => Message::data(['message' => 'Готово'], ['module' => 'reports'], 'success'), ['state' => 'success', 'data' => ['message' => 'Готово'], 'meta' => ['module' => 'reports']]],
            'empty data state'         => [fn (): Message => Message::data(state: ''), ['state' => '']],
            'nested nulls'             => [fn (): Message => Message::data(['value' => null], ['value' => null]), ['state' => 'data', 'data' => ['value' => null], 'meta' => ['value' => null]]],
            'default error'            => [fn (): Message => Message::error('report_failed'), ['state' => 'error', 'error' => 'report_failed']],
            'error with metadata'      => [fn (): Message => Message::error('report_failed', ['module' => 'reports']), ['state' => 'error', 'error' => 'report_failed', 'meta' => ['module' => 'reports']]],
            'empty error and metadata' => [fn (): Message => Message::error('', []), ['state' => 'error', 'error' => '', 'meta' => []]],
            'custom error state'       => [fn (): Message => Message::error('report_failed', state: 'warning'), ['state' => 'warning', 'error' => 'report_failed']],
            'empty error state'        => [fn (): Message => Message::error('0', state: ''), ['state' => '', 'error' => '0']],
        ];
    }

    #[Test]
    public function reusesMessageWithTheActualDestination(): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response('', 204)]);
        $publisher = $this->app->make(Publisher::class);
        $message = Message::data(['channel' => 'payload_value']);

        $publisher->sendMessage('broadcast', $message);
        $publisher->sendMessage('service_update', $message);

        $http->assertSentCount(2);
        self::assertSame(['channel' => 'broadcast', 'state' => 'data', 'data' => ['channel' => 'payload_value']], json_decode($http->recorded()[0][0]->body(), true));
        self::assertSame(['channel' => 'service_update', 'state' => 'data', 'data' => ['channel' => 'payload_value']], json_decode($http->recorded()[1][0]->body(), true));
        self::assertSame(['state' => 'data', 'data' => ['channel' => 'payload_value']], $message->jsonSerialize());
    }

    #[Test]
    public function rejectsInvalidChannelWithoutPublishing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->app->make(Publisher::class)->sendMessage('ALL', Message::data());
        } finally {
            $this->app->make(Factory::class)->assertNothingSent();
        }
    }

    #[Test]
    public function rejectsInvalidJsonWithoutPublishing(): void
    {
        $this->expectException(JsonException::class);

        try {
            $this->app->make(Publisher::class)->sendMessage('broadcast', Message::data("\xB1"));
        } finally {
            $this->app->make(Factory::class)->assertNothingSent();
        }
    }
}
