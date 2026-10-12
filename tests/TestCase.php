<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Tests;

use Cosmira\NginxPushstream\NginxPushstreamServiceProvider;
use Illuminate\Http\Client\Factory;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [NginxPushstreamServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(Factory::class)->preventStrayRequests();
    }
}
