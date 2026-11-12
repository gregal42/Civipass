<?php

declare(strict_types=1);

namespace App\Ops\UI\Console;

use App\Ops\Application\FailureNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ops:notify-failure', description: 'Envoie l\'alerte d\'échec d\'une unité à OPS_ALERT_EMAIL ; appelé par l\'unité systemd OnFailure.')]
final class OpsNotifyFailureCommand extends Command
{
    public function __construct(private readonly FailureNotifier $notifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('unit', InputArgument::REQUIRED, 'Nom de l\'unité en échec.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->notifier->notify((string) $input->getArgument('unit'))) {
            $output->writeln('<comment>Alerte non envoyée : OPS_ALERT_EMAIL non configuré (ou nom d\'unité invalide). L\'échec reste visible dans le journal.</comment>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
