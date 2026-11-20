<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use TiborSrc\A2A\Laravel\A2AManager;

#[AsCommand(name: 'a2a:cards')]
final class ListCardsCommand extends Command
{
    #[\Override]
    protected $signature = 'a2a:cards';

    #[\Override]
    protected $description = 'List configured remote A2A Agent Cards';

    public function handle(A2AManager $a2a): int
    {
        $registry = $a2a->registry();
        $rows = [];

        foreach ($registry->names() as $name) {
            $card = $registry->card($name);
            $rows[] = [$name, $card->name, $card->version, $registry->url($name)];
        }

        if ($rows === []) {
            $this->warn('No A2A agents are configured under a2a.agents.');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Card', 'Version', 'URL'], $rows);

        return self::SUCCESS;
    }
}
