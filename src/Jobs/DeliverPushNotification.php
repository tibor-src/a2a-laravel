<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use TiborSrc\A2A\Laravel\Events\PushNotificationFailed;
use TiborSrc\A2A\Laravel\Events\PushNotificationSent;
use TiborSrc\A2A\Server\HttpPushNotificationSender;
use TiborSrc\A2A\Types\StreamResponse;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;

final class DeliverPushNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5, 15];

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $config,
        public array $payload,
    ) {}

    public function handle(ClientInterface $http, Dispatcher $events): void
    {
        $factory = new Psr17Factory;
        $sender = new HttpPushNotificationSender($http, $factory, $factory);
        $config = TaskPushNotificationConfig::fromArray($this->config);
        $payload = StreamResponse::fromArray($this->payload);

        try {
            $sender->send($config, $payload);
            $events->dispatch(new PushNotificationSent($config, $payload));
        } catch (\Throwable $throwable) {
            $events->dispatch(new PushNotificationFailed($config, $payload, $throwable->getMessage()));

            throw $throwable;
        }
    }
}
