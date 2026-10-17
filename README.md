# Nginx Push Stream Broadcaster

`cosmira/nginx-pushstream-broadcaster` provides a Laravel notification channel, broadcast driver, and HTTP publisher
for the [nginx Push Stream module](https://github.com/wandenberg/nginx-push-stream-module).
PHP sends an HTTP POST to the publisher; nginx delivers the body to WebSocket subscribers.

Requires PHP 8.2+ and Laravel 12 or 13 (Laravel 13 requires PHP 8.3+).

## Installation

Install version 2.1 or later:

```sh
composer require cosmira/nginx-pushstream-broadcaster:^2.1
```

If your Composer registry does not list the package, add the GitHub repository to your application's `composer.json`:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/cosmira/nginx-pushstream-broadcaster"}
    ]
}
```

Then run `composer require cosmira/nginx-pushstream-broadcaster:^2.1`.
Laravel discovers the service provider automatically.

For direct publishing, setting `NGINX_PUSHSTREAM_URL` is sufficient. Publishing the package configuration is optional:

```sh
php artisan vendor:publish --tag=nginx-pushstream-config
```

Set the internal publisher endpoint in `.env`:

```dotenv
NGINX_PUSHSTREAM_URL=http://127.0.0.1/api/notify/publish/{channel}
BROADCAST_CONNECTION=nginx-pushstream
```

For Laravel events or Broadcast Notifications, also add a connection to `config/broadcasting.php`:

```php
'connections' => [
    'nginx-pushstream' => [
        'driver' => 'nginx-pushstream',
    ],
],
```

For personal notifications, set `NGINX_PUSHSTREAM_CHANNEL_SALT` to the shared channel secret.
The package notification channel owns personal routing.
The salt is not a login password or subscriber authentication mechanism.

Package configuration sets `timeout` to 5 seconds and `connect_timeout` to 2 seconds.
Each broadcast connection can override `url`, `timeout`, and `connect_timeout` independently.
The URL must contain exactly one `{channel}` placeholder; both path and query endpoints work:
`http://nginx/pub?id={channel}`.

## Laravel Notifications

The `toPushstream()` and `PushstreamMessage` API is available since 2.1.0.
The raw `toArray()` API introduced in 2.0.0 remains supported as described under compatibility.

Use Laravel's standard `Notifiable` on your authenticatable User model:

```php
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;
}
```

Define a normal Laravel Notification. Its `via()` selects `PushstreamChannel::class`;
its `toPushstream()` returns structured application data and the client module:

```php
use Cosmira\NginxPushstream\PushstreamChannel;
use Cosmira\NginxPushstream\PushstreamMessage;
use Illuminate\Notifications\Notification;

final class ReportReady extends Notification
{
    public function __construct(private readonly int $reportId) {}

    public function via(object $notifiable): array
    {
        return [PushstreamChannel::class];
    }

    public function toPushstream(object $notifiable): PushstreamMessage
    {
        return new PushstreamMessage(
            data: ['report_id' => $this->reportId],
            module: 'reports',
        );
    }
}

$user->notify(new ReportReady(42));
```

The channel derives the personal destination from `getAuthIdentifier()` and the package salt.
`PushstreamMessage` supplies the `state`, `data`, and `meta` envelope used by the application client.
The channel adds the authoritative `channel` field. False, zero, empty values, nulls, and numeric array
keys are preserved. No broadcast `event`, `id`, or `type` fields are added.
The published body for `new ReportReady(42)` is:

```json
{"channel":"user_7_<hash>","state":"data","data":{"report_id":42},"meta":{"module":"reports"}}
```

For a failure, provide the safe application error code without assembling protocol fields:

```php
return (new PushstreamMessage(
    data: ['report_id' => $this->reportId],
    module: 'reports',
    meta: ['job_id' => '42'],
))->error('report_generation_failed');
```

The message places the data and error code in `meta.data` for the existing client protocol.
It does not inspect exceptions or choose safe error codes for the application.
Laravel events, `Notification::fake()`, on-demand routing, and notification cancellation remain available.
See [application integration](docs/application-integration.md) for routing, queues, and testing.

## Compatibility with raw notifications

Existing notifications with `toArray(object $notifiable): array` remain supported.
Their array is sent unchanged except for the authoritative `channel` field. The channel does not
wrap that payload in `PushstreamMessage` or filter its values. This is the API available in v2.0.0.

Since 2.1.0, `toPushstream()` takes priority when both methods exist.
It must return `PushstreamMessage`. An invalid result raises an exception without trying `toArray()`.
A null route skips formatting and publication.

## Upgrade from 1.x

Version 2 removed the package-specific notification trait, generic `PushstreamNotification`, old `Message`,
and `Publisher::sendMessage()`. Use standard Laravel `Notifiable` and domain Notifications.
`PushstreamMessage`, introduced in 2.1.0, is a separate API for application data, module, and safe error codes.
It does not restore the old generic notification or trait.
Use `app(PushstreamChannel::class)->route($user)` to capture the same route used by delivery.
`Publisher::send()`, `sendJson()`, `sendRaw()`, and the broadcast driver retain their existing protocols.

## Laravel events

See [application integration recipes](docs/application-integration.md) for complete events, Notifications,
user channels, progress updates, and client examples.

Use Laravel's `ShouldBroadcast` or `ShouldBroadcastNow` events with public `Channel` instances.
The driver publishes this JSON body separately to every channel:

```json
{"event":"report.ready","data":{"id":42}}
```

For example, an event with `broadcastAs()` returning `report.ready`, `broadcastOn()` returning
`new Channel('reports')`, and `broadcastWith()` returning `['id' => 42]` publishes to the `reports` channel.
Laravel's usual queue configuration applies to `ShouldBroadcast`; this package does not create its own queue.

Empty channel lists send no request. Errors propagate to the caller or Laravel queue worker.
Multi-channel delivery is sequential and not atomic: an error on a later channel does not undo earlier delivery.
The publisher does not automatically retry POST requests, because retries can duplicate notifications.

## Direct publishing

Inject `Cosmira\NginxPushstream\Publisher` into your application service:

```php
use Cosmira\NginxPushstream\Publisher;

$publisher = app(Publisher::class);
$publisher->send('broadcast', ['message' => 'Configuration published']);
```

`send()` publishes a JSON envelope containing the channel name and application data:

```json
{"channel_name":"broadcast","data":{"message":"Configuration published"}}
```

For a different application message format, `sendJson()` encodes the value without adding fields:

```php
$publisher->sendJson($channel, [
    'channel' => $channel,
    'state' => 'data',
    'data' => ['progress' => 50],
    'meta' => ['module' => 'reports'],
]);
```

For responses already serialized by the application, `sendRaw()` preserves every byte:

```php
$publisher->sendRaw($channel, $serializedResponse);
```

All three publishing methods return an `Illuminate\Http\Client\Response`.
JSON encoding failures, network failures, HTTP errors,
and redirects are surfaced as exceptions. TLS certificate verification stays enabled.

Channel names accept ASCII letters, digits, underscores and hyphens, followed by those characters or dots and colons.
`ALL`, slashes, wildcards, whitespace, and URL control characters are rejected.
Channel names are URL encoded before substitution into the publisher endpoint.

The notification channel provides the established `user_{id}_{md5(id . salt)}` convention.
Applications can define Laravel's `routeNotificationForPushstream()` hook or use on-demand routing.
Subscribers must use the same channel names and understand the selected message format.
Formats include Laravel event envelopes, direct publishing envelopes, or application messages sent with
`sendJson()` or `sendRaw()`.

## Subscriber configuration

The following nginx locations match the default publisher URL:

```nginx
location ~ /api/notify/publish/(.*) {
    push_stream_publisher admin;
    push_stream_channels_path $1;
}

location ~ /api/notify/listen/(.*) {
    push_stream_subscriber websocket;
    push_stream_channels_path $1;
}
```

Restrict the publisher to trusted application hosts. Subscriber authentication and channel authorization belong
to your application/nginx configuration.

This package does not implement the Pusher wire protocol or a Laravel Echo connector.
Use the PushStream JavaScript client or a WebSocket client that understands the configured nginx message template.
Laravel `PrivateChannel` and `PresenceChannel` are rejected before broadcasting, and Pusher authentication routes deny access.
`toOthers()` socket exclusion and presence member tracking are not supported by nginx Push Stream.
The driver removes Laravel's transport-only `socket` field from event data; every subscriber receives the message.
Broadcast Notifications require an explicit public `Channel` in `broadcastOn()`; the default private channel is unsupported.

## Quality checks

CI covers PHP 8.2–8.5, Laravel 12/13, lowest/stable dependencies, and Windows.
Pint checks formatting without automatically committing changes. Rector and PHPStan check source and tests.
Soda uses standard rules and multiline PHPDoc rules.
Markdownlint, typos, and ShellCheck also run in CI.

```sh
composer install
composer pint:test
composer test:rector
composer test:types
composer test:coverage
composer test:mutation
```

Install Soda separately:

```sh
git clone https://github.com/cosmira/soda .tools/soda
composer install --working-dir=.tools/soda --no-dev
composer test:soda
composer quality
```

`test:coverage` fails unless every statement in `src/` is covered, including the service provider.
Infection requires 100% MSI and 100% covered MSI with default mutators and no source exclusions.
The local mutation command uses one worker. Coverage requires PCOV or Xdebug.

HTTP tests use Laravel's fake client and prohibit stray requests. They verify the package contract;
delivery, subscriber authorization, and browser compatibility must also be verified against your nginx deployment.

## License

MIT. See [LICENSE.md](LICENSE.md).
