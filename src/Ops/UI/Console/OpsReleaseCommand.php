<?php

declare(strict_types=1);

namespace App\Ops\UI\Console;

use App\Identity\Domain\UserRepository;
use App\Ops\Application\ReleaseRefused;
use App\Ops\Application\ReleaseService;
use App\Retention\Application\ReplayRefused;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ops:release', description: 'Lève la maintenance seulement si le contrôle de restauration ne laisse aucune anomalie bloquante non reconnue.')]
final class OpsReleaseCommand extends Command
{
    use ResolvesOperator;

    public function __construct(private readonly ReleaseService $release, private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Super-administrateur CWS (tracé).')
            ->addOption('acknowledge-loss', null, InputOption::VALUE_REQUIRED, 'Référence écrite reconnaissant les pertes possibles postérieures à la sauvegarde.')
            ->addOption('external-anchors', null, InputOption::VALUE_REQUIRED, 'Copie externe des points d\'ancrage.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operator = $this->operator($input, $this->users);
        $external = $input->getOption('external-anchors');
        try {
            if (null === $operator) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $ack = $this->release->release($operator, \is_string($input->getOption('acknowledge-loss')) ? $input->getOption('acknowledge-loss') : null, \is_string($external) && '' !== $external ? $external : null);
        } catch (OperationNotAllowed) {
            $output->writeln('<error>Opérateur inconnu ou non habilité (super-administrateur CWS requis).</error>');

            return Command::FAILURE;
        } catch (ReleaseRefused $e) {
            $output->writeln('<error>REMISE EN SERVICE REFUSÉE : '.$e->getMessage().'</error>');
            foreach ($e->items as $item) {
                $output->writeln(sprintf('  %s %s %s %s', $item->code, $item->qualification, $item->recordId, $item->detail));
            }

            return Command::FAILURE;
        } catch (ReplayRefused $e) {
            $output->writeln('<error>'.$e->reasonCode.' : '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Maintenance levée (%d anomalie(s) de perte reconnue(s)). Aucun droit ni aucune autorisation n\'a été modifié.', $ack));

        return Command::SUCCESS;
    }
}
