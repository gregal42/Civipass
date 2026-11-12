<?php

declare(strict_types=1);

namespace App\Archive\UI\Console;

use App\Archive\Application\LegalHoldService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rapport des gels de conservation EN RETARD de revue (LOT 05.6.3, arbitrage A2) : identifiants et dates seulement, AUCUNE donnée personnelle. Ne modifie rien : un gel
 * en retard continue de protéger son dossier et n'est jamais levé ni renouvelé automatiquement ; il est signalé pour une revue explicite.
 */
#[AsCommand(name: 'app:archive:holds-report', description: 'Liste les gels de conservation dont la revue est en retard (lecture seule, sans donnée personnelle).')]
final class HoldsReportCommand extends Command
{
    public function __construct(private readonly LegalHoldService $holds)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $overdue = $this->holds->overdue();
        foreach ($overdue as $hold) {
            $output->writeln(sprintf('dossier=%s commune=%s revue_due=%s revues=%d', $hold->dossier()->id()->toRfc4122(), $hold->dossier()->municipality()->id()->toRfc4122(), $hold->reviewDueAt()->format(\DATE_ATOM), $hold->reviewCount()));
        }
        $output->writeln(sprintf('%d gel(s) en retard de revue', \count($overdue)));

        return Command::SUCCESS;
    }
}
