<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use TiborSrc\A2A\Laravel\A2AManager;
use TiborSrc\A2A\Laravel\Server\CacheTaskStore;
use TiborSrc\A2A\Laravel\Server\EloquentTaskStore;
use TiborSrc\A2A\Laravel\Server\EventingTaskStore;
use TiborSrc\A2A\Laravel\Support\Values;

#[AsCommand(name: 'a2a:prune')]
final class PruneTasksCommand extends Command
{
    #[\Override]
    protected $signature = 'a2a:prune {--days= : Delete terminal tasks older than this many days}';

    #[\Override]
    protected $description = 'Prune completed A2A tasks';

    public function handle(A2AManager $a2a): int
    {
        $days = $this->option('days');
        $days = is_numeric($days) ? (int) $days : Values::integer(config('a2a.prune_days', 14), 14);

        $before = new \DateTimeImmutable("-{$days} days");
        $store = $a2a->store();

        if ($store instanceof EventingTaskStore) {
            $store = $store->inner();
        }

        $removed = match (true) {
            $store instanceof EloquentTaskStore => $store->prune($before),
            $store instanceof CacheTaskStore => $store->prune($before),
            default => 0,
        };

        $this->info("Pruned {$removed} A2A task(s).");

        return self::SUCCESS;
    }
}
