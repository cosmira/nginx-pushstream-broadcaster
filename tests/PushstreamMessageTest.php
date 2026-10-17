<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests;

use Cosmira\NginxPushstream\PushstreamMessage;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PushstreamMessageTest extends TestCase
{
    #[Test]
    public function buildsASuccessWithoutRequiringProtocolFields(): void
    {
        $message = new PushstreamMessage(data: ['display_name' => 'Alex'], module: 'access');

        self::assertInstanceOf(Arrayable::class, $message);
        self::assertSame([
            'state' => 'data',
            'data'  => ['display_name' => 'Alex'],
            'meta'  => ['module' => 'access'],
        ], $message->toArray());
    }

    #[Test]
    public function preservesFalseyValuesAndNumericKeysWithoutMutatingItsInput(): void
    {
        $data = [8 => false, 3 => 0, 'null' => null, 'empty' => '', 'list' => [], 'nested' => ['zero' => '0']];
        $meta = ['module' => 'untrusted', 9 => false, 4 => null, 'empty' => '', 'zero' => 0, 'list' => []];
        $message = new PushstreamMessage($data, 'reports', $meta);
        $expected = ['state' => 'data', 'data' => $data, 'meta' => ['module' => 'reports', 9 => false, 4 => null, 'empty' => '', 'zero' => 0, 'list' => []]];

        self::assertSame($expected, $message->toArray());
        self::assertSame('untrusted', $meta['module']);
    }

    #[Test]
    public function ownsFailureFieldsAndPreservesAllOtherMetadata(): void
    {
        $message = new PushstreamMessage(
            ['server_id' => 'first:9090', 8 => null, 'id' => '42', 'value' => false, 'error_message' => 'untrusted'],
            'refresh_sensors',
            ['module' => 'untrusted', 'user_id' => 7, 6 => 0, 'host' => '', 'job_id' => '42', 'data' => ['private' => 'ignored']],
        );

        self::assertSame($message, $message->error('exception_on_processing_job'));
        $expected = [
            'state' => 'error',
            'error' => 'error',
            'meta'  => [
                'module' => 'refresh_sensors', 'user_id' => 7, 6 => 0, 'host' => '', 'job_id' => '42',
                'data'   => ['server_id' => 'first:9090', 8 => null, 'id' => '42', 'value' => false, 'error_message' => 'exception_on_processing_job'],
            ],
        ];
        self::assertSame($expected, $message->toArray());
    }

    #[Test]
    public function keepsAdditionalSuccessDataMetadata(): void
    {
        $message = new PushstreamMessage([], 'reports', ['data' => false]);

        self::assertSame(['state' => 'data', 'data' => [], 'meta' => ['module' => 'reports', 'data' => false]], $message->toArray());
    }

    #[Test]
    #[DataProvider('nonblankStrings')]
    public function preservesNonblankModuleAndErrorCodesVerbatim(string $value): void
    {
        $message = new PushstreamMessage([], $value);
        self::assertSame($value, $message->toArray()['meta']['module']);
        self::assertSame($value, $message->error($value)->toArray()['meta']['data']['error_message']);
    }

    public static function nonblankStrings(): iterable
    {
        yield 'zero' => ['0'];
        yield 'surrounding whitespace' => [' reports '];
        yield 'code' => ['failed_to_execute_test_command'];
    }

    #[Test]
    #[DataProvider('blankStrings')]
    public function rejectsBlankModules(string $module): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PushstreamMessage([], $module);
    }

    #[Test]
    #[DataProvider('blankStrings')]
    public function rejectsBlankErrorCodesWithoutChangingTheMessage(string $code): void
    {
        $message = (new PushstreamMessage([], 'reports'))->error('previous');

        try {
            $message->error($code);
            self::fail('Blank error codes must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame('previous', $message->toArray()['meta']['data']['error_message']);
        }
    }

    public static function blankStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => [' '];
        yield 'control whitespace' => ["\n\t\r\0\x0B"];
    }

    #[Test]
    public function allowsAnExplicitLaterErrorCode(): void
    {
        $message = (new PushstreamMessage([], 'reports'))->error('first');

        self::assertSame('second', $message->error('second')->toArray()['meta']['data']['error_message']);
    }
}
