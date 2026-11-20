<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:a2a-executor')]
final class MakeExecutorCommand extends GeneratorCommand
{
    #[\Override]
    protected $name = 'make:a2a-executor';

    #[\Override]
    protected $description = 'Create a new A2A agent executor';

    #[\Override]
    protected $type = 'A2A executor';

    protected function getStub(): string
    {
        return dirname(__DIR__, 2).'/stubs/executor.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\A2A';
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('force', null, InputOption::VALUE_NONE, 'Overwrite the executor if it exists'),
        ];
    }
}
