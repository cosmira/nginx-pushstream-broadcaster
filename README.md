# Nginx Push Stream Broadcaster

`cosmira/nginx-pushstream-broadcaster` publishes Laravel events and existing Yii notification payloads through the
[nginx Push Stream module](https://github.com/wandenberg/nginx-push-stream-module).
PHP sends an HTTP POST to the publisher; nginx delivers the body to WebSocket subscribers.

Requires PHP 8.2+ and Laravel 12 or 13 (Laravel 13 requires PHP 8.3+).

## Installation

Once the package has been published to your Composer registry:

```sh
composer require cosmira/nginx-pushstream-broadcaster
```

Laravel discovers the service provider automatically. For local evaluation before publication, add a Composer
`path` repository pointing to this standalone package and require `cosmira/nginx-pushstream-broadcaster:@dev`.

```sh
php artisan vendor:publish --tag=nginx-pushstream-config
```

Set the internal publisher endpoint in `.env`:

```dotenv
NGINX_PUSHSTREAM_URL=http://127.0.0.1/api/notify/publish/{channel}
BROADCAST_CONNECTION=nginx-pushstream
```

Add a connection to `config/broadcasting.php`:

```php
'connections' => [
    'nginx-pushstream' => [
        'driver' => 'nginx-pushstream',
    ],
],
```

Package configuration sets `timeout` to 5 seconds and `connect_timeout` to 2 seconds.
Each broadcast connection can override `url`, `timeout`, and `connect_timeout` independently.
The URL must contain exactly one `{channel}` placeholder; both path and query endpoints work:
`http://nginx/pub?id={channel}`.

## Laravel events

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

## Existing Yii clients

Inject `Cosmira\NginxPushstream\Publisher` into your application service:

```php
use Cosmira\NginxPushstream\Publisher;

$publisher = app(Publisher::class);
$publisher->send('broadcast', ['message' => 'Configuration published']);
```

`send()` preserves `CometComponent::send()`'s JSON envelope:

```json
{"channel_name":"broadcast","data":{"message":"Configuration published"}}
```

For responses already serialized by the application, `sendRaw()` preserves every byte:

```php
$publisher->sendRaw($channel, $serializedResponse);
```

Both methods return an `Illuminate\Http\Client\Response`. JSON encoding failures, network failures, HTTP errors,
and redirects are surfaced as exceptions. TLS certificate verification stays enabled.

Channel names accept ASCII letters, digits, underscores and hyphens, followed by those characters or dots and colons.
`ALL`, slashes, wildcards, whitespace, and URL control characters are rejected.
Channel names are URL encoded before substitution into the publisher endpoint.

Yii user channels currently use `user_{id}_{md5(id + salt)}` and service channels use `service_{name}`.
The application owns these names and the salt; the package receives the exact channel string.
Keeping the original salt and payload is necessary for subscribers already connected to Yii channels.
Laravel event envelopes and Yii envelopes are separate contracts; migrating a consumer requires choosing the correct one.

## Subscriber configuration

An existing TM nginx deployment uses these locations:

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
to your application/nginx configuration. A hashed channel name alone does not establish an authorization policy.

This package does not implement the Pusher wire protocol or a Laravel Echo connector.
Use the existing PushStream JavaScript client or a WebSocket client that understands the configured nginx message template.
Laravel `PrivateChannel` and `PresenceChannel` are rejected before broadcasting, and Pusher authentication routes deny access.
`toOthers()` socket exclusion and presence member tracking are not supported by nginx Push Stream.

## Quality checks

The tooling and CI layout follow `cosmira/sandbox`. Package-specific database workflows are omitted.
CI covers PHP 8.2–8.5, Laravel 12/13, lowest/stable dependencies, and Windows.
Pint checks formatting without automatically committing changes. Rector and PHPStan check source and tests.
Soda uses the standard rules and the same multiline PHPDoc rules as sandbox.
Markdownlint, typos, and ShellCheck also run in CI.

```sh
composer install
composer pint:test
composer test:rector
composer test:types
composer test:coverage
composer test:mutation
```

Install Soda separately, matching sandbox's CI:

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
they do not establish delivery on a real TM nginx deployment. Subscriber authorization and browser compatibility
must be verified in the target environment before replacing Yii notification paths.

## License

MIT. See [LICENSE.md](LICENSE.md).
