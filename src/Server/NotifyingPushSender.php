<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Server;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use TiborSrc\A2A\Laravel\Events\PushNotificationQueued;
use TiborSrc\A2A\Laravel\Jobs\DeliverPushNotification;
use TiborSrc\A2A\Server\PushNotificationSender;
use TiborSrc\A2A\Types\StreamResponse;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;

final readonly class NotifyingPushSender implements PushNotificationSender
{
    public function __construct(
        private Dispatcher $events,
        private Bus $bus,
        private string $queue = 'a2a',
    ) {}

    public function send(TaskPushNotificationConfig $config, StreamResponse $payload): void
    {
        $job = new DeliverPushNotification($config->toArray(), $payload->toArray());
        $job->onQueue($this->queue);

        $this->bus->dispatch($job);
        $this->events->dispatch(new PushNotificationQueued($config, $payload));
    }
}
