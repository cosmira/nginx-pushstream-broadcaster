<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Publisher URL
    |--------------------------------------------------------------------------
    |
    | This internal endpoint receives HTTP POST requests from your application.
    | Include exactly one {channel} placeholder in the path or query string.
    | Restrict publisher access to trusted application hosts in nginx.
    |
    */

    'url' => env('NGINX_PUSHSTREAM_URL', 'http://127.0.0.1/api/notify/publish/{channel}'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeouts
    |--------------------------------------------------------------------------
    |
    | These limits are measured in seconds. The connection timeout limits
    | connection establishment; the total timeout includes the response.
    | Failed requests are not retried automatically to avoid duplicates.
    |
    */

    'timeout'         => 5,
    'connect_timeout' => 2,

    /*
    |--------------------------------------------------------------------------
    | Personal Channel Secret
    |--------------------------------------------------------------------------
    |
    | The notification trait uses this shared secret to form personal channels.
    | Keep it identical to the subscriber application's secret during migration.
    | Missing or empty secrets disable personal routing. Channel names do not
    | authenticate subscribers; enforce subscriber access in your app and nginx.
    |
    */

    'channel_salt' => env('NGINX_PUSHSTREAM_CHANNEL_SALT'),

];
