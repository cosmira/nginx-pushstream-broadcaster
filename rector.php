<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\PHPUnit\PHPUnit120\Rector\Class_\AllowMockObjectsForDataProviderRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: false,
        codingStyle: false,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withComposerBased(
        phpunit: true,
        laravel: true
    )
    ->withoutParallel()
    ->withSkip([
        // Keep the suite compatible with PHPUnit 11; this adds a PHPUnit 12-only attribute.
        AllowMockObjectsForDataProviderRector::class,
        ClosureToArrowFunctionRector::class,
    ])
    ->withMemoryLimit('3G')
    ->withPhpSets(php82: true);
