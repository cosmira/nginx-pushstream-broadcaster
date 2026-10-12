<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests;

use Cosmira\NginxPushstream\NginxPushstreamServiceProvider;
use Cosmira\NginxPushstream\Publisher;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;

final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function registersSingletonDefaultsAndPublishableConfig(): void
    {
        $config = $this->app->make('config')->get('nginx-pushstream');
        self::assertSame('http://127.0.0.1/api/notify/publish/{channel}', $config['url']);
        self::assertSame(5, $config['timeout']);
        self::assertSame(2, $config['connect_timeout']);
        self::assertSame($this->app->make(Publisher::class), $this->app->make(Publisher::class));
        self::assertSame([
            dirname(__DIR__).'/src/../config/nginx-pushstream.php' => $this->app->configPath('nginx-pushstream.php'),
        ], ServiceProvider::pathsToPublish(NginxPushstreamServiceProvider::class, 'nginx-pushstream-config'));
    }

    #[Test]
    public function usesPackageSettingsForDirectPublisher(): void
    {
        $this->app->make('config')->set('nginx-pushstream', [
            'url' => 'https://nginx.test/pub?id={channel}', 'timeout' => 17, 'connect_timeout' => 4,
        ]);
        $http = $this->app->make(Factory::class);
        $options = [];
        $http->fake(function ($request, array $requestOptions) use (&$options, $http) {
            $options = $requestOptions;

            return $http->response('', 200);
        });
        $this->app->make(Publisher::class)->sendRaw('broadcast', '{}');
        [$request] = $http->recorded()[0];
        self::assertSame('https://nginx.test/pub?id=broadcast', $request->url());
        self::assertSame(17, $options['timeout']);
        self::assertSame(4, $options['connect_timeout']);
        self::assertFalse($options['allow_redirects']);
        self::assertTrue($options['verify']);
    }

    #[Test]
    public function connectionOverridesPackageDefaultsWithoutChangingOtherConnections(): void
    {
        $config = $this->app->make('config');
        $config->set('nginx-pushstream.timeout', 19);
        $config->set('broadcasting.connections.custom', [
            'driver'          => 'nginx-pushstream',
            'url'             => 'https://custom.test/pub/{channel}',
            'connect_timeout' => 6,
        ]);
        $config->set('broadcasting.connections.default', ['driver' => 'nginx-pushstream']);
        $http = $this->app->make(Factory::class);
        $options = [];
        $http->fake(function ($request, array $requestOptions) use (&$options, $http) {
            $options[] = $requestOptions;

            return $http->response('', 200);
        });
        $manager = $this->app->make(BroadcastManager::class);
        $manager->connection('custom')->broadcast(['news'], 'ready');
        $manager->connection('default')->broadcast(['news'], 'ready');
        $http->assertSentCount(2);
        self::assertSame('https://custom.test/pub/news', $http->recorded()[0][0]->url());
        self::assertSame('http://127.0.0.1/api/notify/publish/news', $http->recorded()[1][0]->url());
        self::assertSame(19, $options[0]['timeout']);
        self::assertSame(6, $options[0]['connect_timeout']);
        self::assertSame(19, $options[1]['timeout']);
        self::assertSame(2, $options[1]['connect_timeout']);
    }
}
