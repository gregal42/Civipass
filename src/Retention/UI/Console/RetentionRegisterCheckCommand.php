<?php

declare(strict_types=1);

namespace App\Retention\UI\Console;

use App\Retention\Application\RegisterReconciliation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:retention:register-check', description: 'Compare le registre SQL et le registre hors base ; --repair ajoute au fichier les lignes manquantes.')]
final class RetentionRegisterCheckCommand extends Command
{
    public function __construct(private readonly RegisterReconciliation $reconciliation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('repair', null, InputOption::VALUE_NONE, 'Répare les lignes SQL absentes du fichier (les lignes de fichier orphelines ne sont jamais modifiées).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $r = $this->reconciliation->check((bool) $input->getOption('repair'));
        foreach ($r->missingInFile as $id) {
            $output->writeln('SQL sans fichier : '.$id);
        }
        foreach ($r->fileOnly as $id) {
            $output->writeln('fichier sans SQL : '.$id);
        }
        $output->writeln(sprintf('manquantes_fichier=%d orphelines_fichier=%d lignes_invalides=%d réparées=%d', \count($r->missingInFile), \count($r->fileOnly), $r->invalidLines, $r->repaired));

        return $r->consistent() || ($r->repaired === \count($r->missingInFile) && [] === $r->fileOnly && 0 === $r->invalidLines) ? Command::SUCCESS : Command::FAILURE;
    }
}
