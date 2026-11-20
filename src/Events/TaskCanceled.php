<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Events;

use TiborSrc\A2A\Types\Task;

final readonly class TaskCanceled
{
    public function __construct(
        public Task $task,
    ) {}
}
