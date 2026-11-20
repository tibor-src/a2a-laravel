<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'a2a:tck')]
final class TckCommand extends Command
{
    #[\Override]
    protected $signature = 'a2a:tck {--queued : Run the TCK against queued execution} {--dry-run : Print the command without running the TCK}';

    #[\Override]
    protected $description = 'Run the official A2A TCK against the workbench app';

    public function handle(): int
    {
        $script = dirname(__DIR__, 2).'/scripts/tck.php';
        $queued = $this->option('queued') === true ? '1' : '0';
        $php = getenv('A2A_TCK_PHP') ?: PHP_BINARY;
        $command = 'A2A_QUEUE='.escapeshellarg($queued).' '.escapeshellarg($php).' '.escapeshellarg($script);

        if ($this->option('dry-run') === true) {
            $this->line($command);

            return self::SUCCESS;
        }

        passthru($command, $status);

        return $status;
    }
}
