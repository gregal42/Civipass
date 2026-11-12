<?php

declare(strict_types=1);

namespace App\Retention\UI\Console;

use App\Identity\Domain\UserRepository;
use App\Ops\UI\Console\ResolvesOperator;
use App\Retention\Application\PurgeDisabled;
use App\Retention\Application\ReplayRefused;
use App\Retention\Application\RetentionReplayService;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Rejeu des effacements après restauration (LOT 05.6.7) : simulation par défaut ; jamais planifié ; exécution seulement avec l'empreinte du rapport et la référence d'approbation. */
#[AsCommand(name: 'app:retention:replay', description: 'Rejoue les effacements inscrits au registre après une restauration (simulation par défaut ; --execute exige --report, --approval-ref, --operator).')]
final class RetentionReplayCommand extends Command
{
    use ResolvesOperator;

    public function __construct(private readonly RetentionReplayService $replay, private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Super-administrateur CWS qui exécute (tracé).')
            ->addOption('approval-ref', null, InputOption::VALUE_REQUIRED, 'Référence de l\'instruction écrite de l\'autorité compétente.')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Empreinte du rapport présenté par app:retention:restore-check.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Exécute réellement le rejeu.')
            ->addOption('external-anchors', null, InputOption::VALUE_REQUIRED, 'Copie externe des points d\'ancrage à confronter.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operator = $this->operator($input, $this->users);
        $external = $input->getOption('external-anchors');
        try {
            if (null === $operator) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $r = $this->replay->run($operator, (string) $input->getOption('approval-ref'), \is_string($input->getOption('report')) ? $input->getOption('report') : null, (bool) $input->getOption('execute'), \is_string($external) && '' !== $external ? $external : null);
        } catch (OperationNotAllowed) {
            $output->writeln('<error>Opérateur inconnu ou non habilité (super-administrateur CWS requis).</error>');

            return Command::FAILURE;
        } catch (ReplayRefused $e) {
            $output->writeln('<error>REFUSÉ ('.$e->reasonCode.') : '.$e->getMessage().' Rien n\'a été modifié.</error>');

            return Command::FAILURE;
        } catch (PurgeDisabled $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $output->writeln($r->executed ? 'MODE EXÉCUTION' : 'SIMULATION (aucune écriture)');
        $output->writeln(sprintf('prévus_dossiers=%d prévus_partagés=%d rejoués_dossiers=%d rejoués_partagés=%d déjà_faits=%d bloqués=%d réinscrits=%d échecs=%d registre_fichier_en_échec=%d%s', $r->plannedDossiers, $r->plannedShared, $r->dossiersReplayed, $r->sharedReplayed, $r->alreadyDone, $r->blocked, $r->adopted, $r->failed, $r->registerFileFailures, $r->interrupted ? ' INTERROMPU' : ''));
        foreach ($r->reasons as $reason => $n) {
            $output->writeln(sprintf('  bloqué (%s) : %d', $reason, $n));
        }

        return $r->failed > 0 || $r->interrupted ? Command::FAILURE : Command::SUCCESS;
    }
}
