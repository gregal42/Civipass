<?php

declare(strict_types=1);

namespace App\Ops\UI\Console;

use App\Identity\Domain\UserRepository;
use App\Ops\Application\MaintenanceMode;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:ops:maintenance', description: 'Active le mode maintenance : toute tâche planifiée est refusée jusqu\'à app:ops:release (à faire AVANT une restauration).')]
final class OpsMaintenanceCommand extends Command
{
    use ResolvesOperator;

    public function __construct(
        private readonly MaintenanceMode $maintenance,
        private readonly UserRepository $users,
        private readonly TransactionRunner $transaction,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Motif (restauration, incident…).')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Opérateur (tracé si la base est disponible).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reason = trim((string) $input->getOption('reason'));
        if ('' === $reason) {
            $output->writeln('<error>Un motif est obligatoire (--reason).</error>');

            return Command::FAILURE;
        }
        $this->maintenance->enable($reason);
        $operator = $this->operator($input, $this->users);
        try {
            $this->transaction->run(function () use ($operator, $reason): void {
                $this->audit->record(new AuditEvent($this->clock->now(), AuditAction::OpsMaintenanceEnabled, AuditSubjectType::Retention, Uuid::v7(), null, null === $operator ? AuditActor::console() : AuditActor::user($operator->id()), null, ['reason' => mb_substr($reason, 0, 100)]));
            });
        } catch (\Throwable) {
            $output->writeln('<comment>Trace d\'audit non écrite (base indisponible ?) : la maintenance est néanmoins active.</comment>');
        }
        $output->writeln('Maintenance ACTIVE : les tâches planifiées sont refusées.');

        return Command::SUCCESS;
    }
}
