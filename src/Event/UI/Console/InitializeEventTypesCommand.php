<?php

declare(strict_types=1);

namespace App\Event\UI\Console;

use App\Event\Application\EventTypeInitializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:event-types:initialize',
    description: 'Crée les types d\'événement initiaux absents (idempotent, n\'écrase rien).',
)]
final class InitializeEventTypesCommand extends Command
{
    public function __construct(private readonly EventTypeInitializer $initializer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = $this->initializer->initialize();

        if ([] === $created) {
            $io->success('Tous les types d\'événement initiaux existent déjà : rien à faire.');

            return Command::SUCCESS;
        }
        $io->success(sprintf('%d type(s) créé(s) : %s.', \count($created), implode(', ', $created)));

        return Command::SUCCESS;
    }
}
