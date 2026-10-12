<?php

declare(strict_types=1);

return [
    'url'             => env('NGINX_PUSHSTREAM_URL', 'http://127.0.0.1/api/notify/publish/{channel}'),
    'timeout'         => 5,
    'connect_timeout' => 2,
];
