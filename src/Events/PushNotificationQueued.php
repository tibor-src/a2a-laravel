<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Events;

use TiborSrc\A2A\Types\StreamResponse;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;

final readonly class PushNotificationQueued
{
    public function __construct(
        public TaskPushNotificationConfig $config,
        public StreamResponse $payload,
    ) {}
}
