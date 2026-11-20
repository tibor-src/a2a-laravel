<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use TiborSrc\A2A\Laravel\A2AManager;

#[AsCommand(name: 'a2a:cards:fetch')]
final class FetchCardsCommand extends Command
{
    #[\Override]
    protected $signature = 'a2a:cards:fetch {name? : The configured agent name}';

    #[\Override]
    protected $description = 'Fetch and cache remote A2A Agent Cards';

    public function handle(A2AManager $a2a): int
    {
        $registry = $a2a->registry();
        $name = $this->argument('name');
        $names = is_string($name) && $name !== '' ? [$name] : $registry->names();

        if ($names === []) {
            $this->warn('No A2A agents are configured under a2a.agents.');

            return self::SUCCESS;
        }

        foreach ($names as $agent) {
            $card = $registry->refresh($agent);
            $this->line("{$agent}: {$card->name} ({$card->version})");
        }

        return self::SUCCESS;
    }
}
