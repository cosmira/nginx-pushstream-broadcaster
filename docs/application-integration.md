# Application integration

Use the injected `Publisher` when your frontend already has an application message format.
Use Laravel events or Broadcast Notifications when your frontend understands event envelopes.
All examples use the same nginx HTTP publisher; no additional transport service is required.

## Choosing the publishing method

| Method | Body delivered to subscribers | Typical use |
| --- | --- | --- |
| `sendMessage($channel, Message::data(...))` | `{channel,state,data?,meta?}` | Data, progress, or completion messages |
| `sendMessage($channel, Message::error(...))` | `{channel,state,error,meta?}` | Application error codes |
| `sendJson($channel, $message)` | JSON encoding of `$message`, no added fields | Application notifications and progress/status |
| `sendRaw($channel, $body)` | Exactly the supplied bytes | Already serialized responses |
| `send($channel, $data)` | `{channel_name,data}` | Subscribers expecting that envelope |
| Laravel event / Notification | `{event,data}` | Subscribers dispatching by event name |

Install the package and set `NGINX_PUSHSTREAM_URL` for direct publishing.
Configure the broadcast connection from the [README](../README.md#installation) only for events and Notifications.

## User, shared, and service channels

The application and browser must agree on the exact channel name. Shared channels can use `broadcast`;
service channels can use names such as `service_config` and `service_update`.
Pass those names directly to the publisher.

For an established `user_<id>_<hash>` naming convention, one method on the application User model is enough:

```php
public function notificationChannelName(): string
{
    $id = (string) $this->getAuthIdentifier();

    return 'user_'.$id.'_'.md5($id.config('notifications.channel_salt'));
}
```

Define `channel_salt` in the application's `config/notifications.php` from an environment variable.
If preserving existing subscriptions, reuse the existing value and exact algorithm.
Changing the salt changes channel names. This hash only reproduces an established naming convention;
it does not provide subscriber authorization.

Expose that same method's result in the authenticated login/session response:

```php
return ['CHANNEL_NAME' => $user->notificationChannelName()];
```

The browser subscribes to `CHANNEL_NAME`. Jobs targeting a user call the same method.
The package does not need access to the current request, authenticated user, or application secrets.

## Progress, success, and error messages

Inject the publisher directly into a job's `handle()` or an application method:

```php
use Cosmira\NginxPushstream\Message;
use Cosmira\NginxPushstream\Publisher;

public function handle(Publisher $publisher): void
{
    $channel = $this->user->notificationChannelName();

    $publisher->sendMessage($channel, Message::data(
        ['progress' => 50],
        ['module' => 'reports'],
    ));
}
```

`Message` is an optional envelope available since version 1.1.
`sendMessage()` supplies the actual destination as `channel`.
`Message::data()` defaults to `state: "data"`; `Message::error()` defaults to `state: "error"`.
The application chooses data, error codes, metadata, and custom state values.

Null `data` and `meta` are omitted. False, zero, empty strings, and empty arrays remain in the message.
Nested null values are unchanged. Error messages always contain `error`.
`json_encode($message)` serializes the envelope without its destination channel.

For a custom completion state:

```php
$publisher->sendMessage($channel, Message::data(['download' => $url], state: 'success'));
```

The existing `send()`, `sendJson()`, `sendRaw()`, and Laravel event formats are unchanged.
Use `sendJson()` when the frontend expects a different envelope.

For an error message:

```php
$publisher->sendMessage($channel, Message::error(
    'report_generation_failed',
    ['module' => 'reports'],
));
```

For shared and service messages, reuse the same message with the required destination:

```php
$message = Message::data(['version' => 2]);
$publisher->sendMessage('broadcast', $message);
$publisher->sendMessage('service_config', $message);
$publisher->sendMessage('service_update', $message);
```

If a response object already produces the required serialized body:

```php
$publisher->sendRaw($channel, $response->render());
```

Successful publishing means nginx accepted the HTTP request; it is not an acknowledgement from a browser.
Network and HTTP failures throw exceptions. Allow jobs to fail visibly and choose their retry policy in the application;
a retried job can send the same notification more than once.

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
event(new \App\Events\ReportReady($user->notificationChannelName(), 42));
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
$user->notify(new \App\Notifications\ReportReady($user->notificationChannelName(), 42));
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

For event/Notification integration tests, fake HTTP and dispatch through Laravel as shown above.
Do not fake Events or Notifications when verifying that the real broadcaster sends an HTTP request.
Package tests cover those Laravel paths with HTTP fakes; a real nginx-to-browser acceptance check remains an
application deployment check.
