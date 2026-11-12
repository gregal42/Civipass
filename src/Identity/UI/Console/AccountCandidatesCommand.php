<?php

declare(strict_types=1);

namespace App\Identity\UI\Console;

use App\Identity\Application\AccountCandidateReader;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:accounts:candidates', description: 'Rapport (lecture seule) des comptes candidats à suppression ou anonymisation ; réservé à un super-administrateur CWS.')]
final class AccountCandidatesCommand extends Command
{
    public function __construct(private readonly AccountCandidateReader $reader, private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Identifiant du super-administrateur CWS qui lance le rapport (tracé).')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table ou json.', 'table')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de lignes.', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getOption('operator');
        $operator = Uuid::isValid($id) ? $this->users->find(Uuid::fromString($id)) : null;
        try {
            if (null === $operator) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $report = $this->reader->report($operator, max(1, (int) $input->getOption('limit')));
        } catch (OperationNotAllowed) {
            $output->writeln('<error>Opérateur inconnu ou non habilité : le rapport est réservé aux super-administrateurs CWS (--operator=<identifiant>).</error>');

            return Command::FAILURE;
        }
        if ('json' === $input->getOption('format')) {
            $output->writeln(json_encode(array_map(static fn ($l): array => (array) $l, $report['lines']), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        } else {
            foreach ($report['lines'] as $l) {
                $refs = implode(',', array_map(static fn (string $k, int $n): string => $k.'='.$n, array_keys($l->references), $l->references));
                $output->writeln(sprintf('%s %s %s actif=%d derniere_activite=%s %s', $l->userId, $l->maskedEmail, $l->category, (int) $l->active, $l->lastActivityMonth, $refs));
            }
        }
        foreach ($report['unclassified'] as $reference) {
            $output->writeln('<comment>Référence non classée (traitée comme conservée) : '.$reference.'</comment>');
        }
        $output->writeln(sprintf('%d candidat(s)%s ; seuil %d mois. Indicateurs techniques : aucune autorisation juridique de suppression ou d\'anonymisation, aucun compte modifié.', \count($report['lines']), $report['truncated'] ? ' (liste tronquée)' : '', $this->reader->months()));

        return Command::SUCCESS;
    }
}
