<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use TiborSrc\A2A\Types\StreamResponse;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;

final class TaskPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TaskPushNotificationConfig $config,
        public StreamResponse $payload,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        unset($notifiable);

        return [A2AWebhookChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebhook(object $notifiable): array
    {
        unset($notifiable);

        return $this->payload->toArray();
    }
}
