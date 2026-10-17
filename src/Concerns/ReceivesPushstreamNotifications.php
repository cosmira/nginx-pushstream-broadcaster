<?php

declare(strict_types=1);

namespace Cosmira\NginxPushstream\Concerns;

use Illuminate\Notifications\Notifiable;

trait ReceivesPushstreamNotifications
{
    use Notifiable;

    /**
     * Resolve the identity shared with WebSocket subscribers.
     *
     * @return mixed
     */
    abstract public function getAuthIdentifier();

    /**
     * Return the personal channel only when its shared secret and identity are valid.
     */
    public function routeNotificationForPushstream(): ?string
    {
        $id = $this->getAuthIdentifier();
        if ((! is_int($id) && ! is_string($id)) || $id === '') {
            return null;
        }

        $salt = config('nginx-pushstream.channel_salt');

        if (! is_string($salt) || $salt === '') {
            return null;
        }

        $channel = 'user_'.$id.'_'.md5($id.$salt);

        return preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9_.:-]*\z/', $channel) === 1
            ? $channel : null;
    }
}
