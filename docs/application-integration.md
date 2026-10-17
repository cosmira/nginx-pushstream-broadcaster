# Application integration

Use Laravel Notifications for application messages. The package trait supplies normal Laravel notification
methods and personal routing. Laravel broadcast events remain available for frontends using `{event,data}`.
All paths use the same HTTP publisher.

## Configure personal notifications

Install the package as described in the [README](../README.md#installation), then set:

```dotenv
NGINX_PUSHSTREAM_URL=https://nginx.internal/api/notify/publish/{channel}
NGINX_PUSHSTREAM_CHANNEL_SALT=your-shared-channel-secret
```

Publish the configuration to adjust timeouts or review each setting:

```sh
php artisan vendor:publish --tag=nginx-pushstream-config
```

The package's `config/nginx-pushstream.php` owns the URL, timeouts, and personal channel salt.
Do not use the application's `APP_KEY` as a fallback. During migration, reuse the existing salt and
exact `user_{id}_{md5(id . salt)}` convention so browsers retain their subscriptions.
Channel names do not authenticate subscribers. Enforce access in your application and nginx.

Add one trait to your authenticatable User model:

```php
use Cosmira\NginxPushstream\Concerns\ReceivesPushstreamNotifications;

class User extends Authenticatable
{
    use ReceivesPushstreamNotifications;
}
```

The trait includes Laravel's full `Notifiable` API. It does not send database, mail, or broadcast notifications
unless a notification selects those channels. The database notification relationship requires Laravel's
notifications table only if your application uses that channel.
The model supplies `getAuthIdentifier()`. Missing identities, invalid channel characters, and missing or
invalid salts produce a null route. Laravel skips delivery for a null route.

Expose the same route in your authenticated login/session response:

```php
return ['CHANNEL_NAME' => $user->routeNotificationFor('pushstream')];
```

The browser subscribes to `CHANNEL_NAME`. Rotating the salt changes future personal routes.

## Send an application message

```php
use Cosmira\NginxPushstream\Message;
use Cosmira\NginxPushstream\Notifications\PushstreamNotification;

$user->notify(new PushstreamNotification(Message::data(
    ['progress' => 50],
    ['module' => 'reports'],
)));
$user->notify(new PushstreamNotification(Message::error('report_failed')));
```

The message envelope contains `channel`, `state`, and either `data` or `error`, with optional `meta`.
`Message::data()` defaults to `data` state. `Message::error()` defaults to `error` state.
Both accept a custom state such as `success`.
Null data and metadata are omitted. False, zero, empty strings, empty arrays, and nested nulls are preserved.
The channel does not append Laravel's broadcast notification `id` or `type` fields.

## Define a domain notification

Use a normal Laravel Notification when the application owns a named event or selects other channels:

```php
use Cosmira\NginxPushstream\Message;
use Cosmira\NginxPushstream\PushstreamChannel;
use Illuminate\Notifications\Notification;

final class ReportReady extends Notification
{
    public function __construct(private readonly int $reportId) {}

    public function via(object $notifiable): array
    {
        return [PushstreamChannel::class];
    }

    public function toPushstream(object $notifiable): Message
    {
        return Message::data(['report_id' => $this->reportId], ['module' => 'reports']);
    }
}

$user->notify(new ReportReady(42));
```

`via()` uses the channel class; routing uses the stable `pushstream` key.
For queued delivery, implement Laravel's `ShouldQueue` and use `Queueable` on your domain notification.
Laravel controls its connection, retry policy, events, and `NotificationSending` cancellation.

## Preserve a destination in a background job

Capture the route before dispatching work that must notify its original subscriber:

```php
$channel = $user->routeNotificationFor('pushstream');
```

An application requiring delivery can reject a null route before starting the operation.
Store the non-null channel with the job, then send synchronously from the existing worker:

```php
use Illuminate\Support\Facades\Notification;

Notification::route('pushstream', $this->channel)
    ->notifyNow(new PushstreamNotification(Message::data(['progress' => 100])));
```

The generic `PushstreamNotification` does not implement `ShouldQueue`, so this adds no second queued job.
The stored route survives salt rotation without reloading the user. On-demand delivery also supports
shared channels such as `broadcast` and service channels such as `service_config`.
Explicit invalid non-null routes raise an error rather than silently skipping delivery.

A `NotificationSent` event with an HTTP response means nginx accepted publication; it is not a browser
acknowledgement. Laravel also emits that event after a channel skips a null route, with a null response.
Network and HTTP failures propagate to the caller. Retried jobs may send duplicate notifications.

## Use the lower-level publisher

Existing Publisher methods remain available:

| Method | Body delivered to subscribers |
| --- | --- |
| `sendMessage($channel, Message::data(...))` | `{channel,state,data?,meta?}` |
| `sendMessage($channel, Message::error(...))` | `{channel,state,error,meta?}` |
| `sendJson($channel, $message)` | JSON encoding without added fields |
| `sendRaw($channel, $body)` | Exactly the supplied bytes |
| `send($channel, $data)` | `{channel_name,data}` |
| Laravel broadcast event or Broadcast Notification | `{event,data}` |

Use `Publisher` directly for already serialized messages or alternate wire formats.
The package does not change the existing transport APIs.

## Laravel event

For a frontend using `{event,data}`, define a regular event:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class ReportReady implements ShouldBroadcastNow
{
    public function __construct(
        private readonly string $channel,
        private readonly int $reportId,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'report.ready';
    }

    public function broadcastWith(): array
    {
        return ['report_id' => $this->reportId];
    }
}
```

Dispatch normally:

```php
event(new \App\Events\ReportReady($user->routeNotificationFor('pushstream'), 42));
```

The subscriber receives `{"event":"report.ready","data":{"report_id":42}}`.
Use `ShouldBroadcast` instead of `ShouldBroadcastNow` for queued broadcasting, with a configured queue worker.
The package removes Laravel's `socket` transport field; `toOthers()` does not exclude the sender.

## Laravel Broadcast Notification

Normal Notifications default to a private channel. Explicitly return a public `Channel` for this driver:

```php
<?php

namespace App\Notifications;

use Illuminate\Broadcasting\Channel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

final class ReportReady extends Notification
{
    public function __construct(
        private readonly string $channel,
        private readonly int $reportId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['broadcast'];
    }

    public function broadcastOn(): array
    {
        return [new Channel($this->channel)];
    }

    public function broadcastType(): string
    {
        return 'report.ready';
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(['report_id' => $this->reportId]);
    }
}
```

Send through the normal Notifiable API:

```php
$user->notify(new \App\Notifications\ReportReady($user->routeNotificationFor('pushstream'), 42));
```

Broadcast Notifications queue their broadcast event; configure a queue worker (or the `sync` queue for local evaluation).
The JSON event name is `Illuminate\Notifications\Events\BroadcastNotificationCreated`;
`data` includes `report_id`, Laravel's notification `id`, and `type: "report.ready"`.
The client can route notification messages by `data.type`.
Overriding only `receivesBroadcastNotificationsOn()` does not remove Laravel's private-channel behavior.

## Browser subscription

For a websocket subscriber location matching the README, and nginx's `push_stream_message_template "~text~"`,
a native client can consume the application JSON directly.
The module's [template examples](https://github.com/wandenberg/nginx-push-stream-module/blob/master/misc/nginx.conf)
use `~text~` for the published message body:

```nginx
location ~ /api/notify/listen/(.*) {
    push_stream_subscriber websocket;
    push_stream_channels_path $1;
    push_stream_message_template "~text~";
}
```

```javascript
const scheme = location.protocol === 'https:' ? 'wss' : 'ws';
const channel = session.CHANNEL_NAME;
const socket = new WebSocket(`${scheme}://${location.host}/api/notify/listen/${encodeURIComponent(channel)}`);

socket.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (message.meta?.module !== 'reports') return;
    if (message.state === 'error') {
        showReportError(message.error);
        return;
    }
    if (message.state === 'data') {
        updateReportProgress(message.data);
    }
};
```

`session`, `updateReportProgress`, and `showReportError` belong to the application in this example.
If nginx wraps `~text~` in its own template, adapt parsing to that template or use the PushStream JavaScript client.
Configure access to subscriber channels in the application/nginx. Laravel public `Channel` means the driver does not
run `Broadcast::channel()` authorization. Returning an opaque name at login does not enforce authorization by itself.
Do not expose the publisher endpoint to browsers.

## Testing application code

The publisher uses Laravel's HTTP client shared with the `Http` facade, so normal fakes work even if the
Publisher singleton or broadcast connection has already been resolved:

```php
use Cosmira\NginxPushstream\Publisher;
use Illuminate\Support\Facades\Http;

$publisher = app(Publisher::class);
Http::preventStrayRequests();
Http::fake(['*' => Http::response('', 200)]);

$publisher->sendJson('service_config', ['state' => 'data']);

Http::assertSentCount(1);
Http::assertSent(fn ($request) =>
    $request->url() === 'http://127.0.0.1/api/notify/publish/service_config'
    && json_decode($request->body(), true) === ['state' => 'data']
);
```

For application dispatch tests, use Laravel's notification fake:

```php
use Cosmira\NginxPushstream\Notifications\PushstreamNotification;
use Illuminate\Support\Facades\Notification;

Notification::fake();
$user->notify(new PushstreamNotification(Message::data(['progress' => 50])));
Notification::assertSentTo($user, PushstreamNotification::class);
```

This verifies dispatch intent. It does not execute channel routing or publish HTTP.
For transport integration tests, use `Http::fake()` and call the real `$user->notify(...)` or
`Notification::route(...)->notifyNow(...)` API. Assert the exact request URL and body.

For event/Notification integration tests, fake HTTP and dispatch through Laravel as shown above.
Do not fake Events or Notifications when verifying that the real broadcaster sends an HTTP request.
Package tests cover those Laravel paths with HTTP fakes; a real nginx-to-browser acceptance check remains an
application deployment check.
