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
        $script = getenv('A2A_TCK_SCRIPT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'tck.php';
        $queued = $this->option('queued') === true ? '1' : '0';
        $php = getenv('A2A_TCK_PHP') ?: PHP_BINARY;

        if ($this->option('dry-run') === true) {
            $this->line($php.' '.$script);

            return self::SUCCESS;
        }

        $env = getenv();
        $env['A2A_QUEUE'] = $queued;

        $process = proc_open(
            [$php, $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            $env,
        );

        if (! is_resource($process)) {
            return self::FAILURE; // @codeCoverageIgnore
        }

        fclose($pipes[0]);
        $this->output->write((string) stream_get_contents($pipes[1]));
        $this->output->write((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
