<?php

declare(strict_types=1);

namespace App\Retention\UI\Console;

use App\Retention\Application\RetentionNoticeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:retention:notice', description: 'Envoie l\'avis préalable à la purge aux administrateurs communaux (simulation par défaut).')]
final class RetentionNoticeCommand extends Command
{
    public function __construct(private readonly RetentionNoticeService $notices)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Envoie réellement les avis (sinon simulation).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de dossiers par passage.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $execute = (bool) $input->getOption('execute');
        RetentionRunPrinter::print($output, $this->notices->run($execute, max(1, (int) $input->getOption('limit'))), $execute);

        return Command::SUCCESS;
    }
}
