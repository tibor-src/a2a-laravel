<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Notifications;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use TiborSrc\A2A\Server\HttpPushNotificationSender;
use TiborSrc\A2A\Types\StreamResponse;

final readonly class A2AWebhookChannel
{
    public function __construct(
        private ClientInterface $http,
    ) {}

    public function send(object $notifiable, TaskPushNotification $notification): void
    {
        unset($notifiable);
        $factory = new Psr17Factory;
        $sender = new HttpPushNotificationSender($this->http, $factory, $factory);
        $sender->send(
            $notification->config,
            StreamResponse::fromArray($notification->toWebhook(new \stdClass)),
        );
    }
}
