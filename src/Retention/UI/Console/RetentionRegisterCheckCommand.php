<?php

declare(strict_types=1);

namespace App\Retention\UI\Console;

use App\Identity\Domain\UserRepository;
use App\Retention\Application\RegisterReconciliation;
use App\Retention\Application\ReplayRefused;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:retention:register-check', description: 'Compare le registre SQL et le registre hors base (chaîne et ancrage compris) ; réparation limitée sur approbation.')]
final class RetentionRegisterCheckCommand extends Command
{
    public function __construct(private readonly RegisterReconciliation $reconciliation, private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('repair', null, InputOption::VALUE_NONE, 'Ajoute au fichier les lignes SQL manquantes (exige --operator et --approval-ref).')
            ->addOption('adopt', null, InputOption::VALUE_NONE, 'Réinscrit en base les lignes du fichier dont les données sont absentes (exige --operator et --approval-ref).')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Super-administrateur CWS qui répare (tracé).')
            ->addOption('approval-ref', null, InputOption::VALUE_REQUIRED, 'Référence d\'approbation écrite.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('repair') || $input->getOption('adopt')) {
            $id = (string) $input->getOption('operator');
            $operator = Uuid::isValid($id) ? $this->users->find(Uuid::fromString($id)) : null;
            try {
                if (null === $operator) {
                    throw new OperationNotAllowed('Ressource inaccessible.');
                }
                $r = $this->reconciliation->repair($operator, (string) $input->getOption('approval-ref'), (bool) $input->getOption('adopt'));
            } catch (OperationNotAllowed) {
                $output->writeln('<error>Opérateur inconnu ou non habilité (super-administrateur CWS requis).</error>');

                return Command::FAILURE;
            } catch (ReplayRefused $e) {
                $output->writeln('<error>'.$e->reasonCode.' : '.$e->getMessage().'</error>');

                return Command::FAILURE;
            }
        } else {
            $r = $this->reconciliation->check();
        }
        foreach ($r->missingInFile as $id) {
            $output->writeln('SQL sans fichier : '.$id);
        }
        foreach ($r->fileOnly as $id) {
            $output->writeln('fichier sans SQL : '.$id);
        }
        foreach ($r->chainBreaks as $break) {
            $output->writeln('<error>CHAÎNE ROMPUE : '.$break.'</error>');
        }
        foreach ($r->anchorProblems as $problem) {
            $output->writeln('<error>ANCRAGE : '.$problem.'</error>');
        }
        $output->writeln(sprintf('manquantes_fichier=%d orphelines_fichier=%d ruptures_chaine=%d problemes_ancrage=%d lignes_invalides=%d ajoutees=%d reinscrites=%d', \count($r->missingInFile), \count($r->fileOnly), \count($r->chainBreaks), \count($r->anchorProblems), $r->invalidLines, $r->repaired, $r->adopted));

        return [] === $r->chainBreaks && [] === $r->anchorProblems && 0 === $r->invalidLines && ([] === $r->missingInFile || $r->repaired >= \count($r->missingInFile)) && ([] === $r->fileOnly || $r->adopted >= \count($r->fileOnly)) ? Command::SUCCESS : Command::FAILURE;
    }
}
