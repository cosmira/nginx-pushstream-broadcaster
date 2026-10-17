<?php

declare(strict_types=1);

use Cosmira\NginxPushstream\Notifications\PushstreamNotification;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Rules\Documentation\MultilineConstantPhpDoc;
use Cosmira\Soda\Rules\Documentation\MultilineMethodPhpDoc;
use Cosmira\Soda\Rules\Documentation\MultilinePropertyPhpDoc;
use Cosmira\Soda\Rules\Usage\NoUnusedParameters;

return Soda::configure()
    ->withPaths([__DIR__.'/src'])
    ->with([
        ...array_filter(
            RuleCatalog::standard(),
            fn (Check $rule): bool => ! $rule instanceof NoUnusedParameters,
        ),
        new NoUnusedParameters([
            PushstreamNotification::class.'::via',
            PushstreamNotification::class.'::toPushstream',
        ]),
        new MultilineMethodPhpDoc(['public', 'protected', 'private']),
        new MultilinePropertyPhpDoc(['public', 'protected', 'private']),
        new MultilineConstantPhpDoc(['public', 'protected', 'private']),
    ]);
