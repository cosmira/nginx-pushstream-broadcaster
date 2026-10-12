<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

final class NginxPushstreamServiceProvider extends ServiceProvider
{
    /**
     * Package configuration key and Laravel driver identifier.
     */
    private const NAME = 'nginx-pushstream';

    /**
     * Register the standalone publisher and merge package defaults.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nginx-pushstream.php', self::NAME);
        $this->app->singleton(Publisher::class, function (Application $app): Publisher {
            $config = $app->make('config')->get(self::NAME);

            return $this->publisher($app, $config);
        });
    }

    /**
     * Register the broadcast driver and publish configuration.
     */
    public function boot(BroadcastManager $broadcasts): void
    {
        $name = self::NAME;
        $create = $this->publisher(...);
        $broadcasts->extend($name, function (
            Application $app,
            array $config,
        ) use ($create, $name): NginxPushstreamBroadcaster {
            $defaults = $app->make('config')->get($name);

            $publisher = $create($app, array_replace($defaults, $config));

            return new NginxPushstreamBroadcaster($publisher);
        });

        $this->publishes([
            __DIR__.'/../config/nginx-pushstream.php' => $this->app->configPath(
                'nginx-pushstream.php',
            ),
        ], 'nginx-pushstream-config');
    }

    /**
     * Create a publisher from package or connection configuration.
     */
    private function publisher(Application $app, array $config): Publisher
    {
        $http = $app->make(Factory::class)
            ->timeout($config['timeout'])
            ->connectTimeout($config['connect_timeout']);

        return new Publisher($http, $config['url']);
    }
}
