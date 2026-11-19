<?php

declare(strict_types=1);

namespace App\Identity\UI\Console;

use App\Identity\Application\TwoFactorService;
use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Réinitialise le second facteur d'un super-administrateur CWS (appareil perdu, codes de secours épuisés ou perdus) : supprime le secret et les codes, le compte
 * se réinscrit à sa prochaine connexion. Action tracée (audit `TWO_FACTOR_RESET`, motif obligatoire). Avec `--operator` (un AUTRE super-administrateur actif),
 * c'est lui l'acteur ; sans, l'accès au serveur fait foi (acteur « console ») : procédure de dernier recours du runbook d'accès (docs/runbooks/recuperation-acces.md).
 */
#[AsCommand(name: 'app:super-admin:reset-two-factor', description: 'Réinitialise le second facteur (TOTP) d\'un super-administrateur CWS ; tracé, motif obligatoire.')]
final class ResetTwoFactorCommand extends Command
{
    public function __construct(private readonly TwoFactorService $twoFactor, private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse du super-administrateur dont le second facteur est réinitialisé.')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Motif (obligatoire, conservé dans l\'audit).')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Identifiant d\'un AUTRE super-administrateur actif qui agit (sinon : accès au serveur, acteur « console »).')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Confirme sans interaction.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $target = $this->users->findByEmail(EmailAddress::fromString((string) $input->getArgument('email')));
            if (null === $target) {
                throw new OperationNotAllowed('Compte introuvable.');
            }
            $operator = null;
            if (null !== ($operatorId = $input->getOption('operator'))) {
                $operator = Uuid::isValid((string) $operatorId) ? $this->users->find(Uuid::fromString((string) $operatorId)) : null;
                if (null === $operator) {
                    throw new OperationNotAllowed('Opérateur introuvable.');
                }
            }
            if (!$input->getOption('yes')) {
                $output->writeln('Confirmez avec --yes : le second facteur de ce compte sera supprimé (le compte devra se réinscrire).');

                return Command::FAILURE;
            }
            $removed = $this->twoFactor->reset($target, $operator, (string) $input->getOption('reason'));
        } catch (OperationNotAllowed|DomainRuleViolation $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $output->writeln($removed ? 'Second facteur réinitialisé : le compte se réinscrira à sa prochaine connexion.' : 'Ce compte n\'avait pas de second facteur : rien à réinitialiser.');

        return Command::SUCCESS;
    }
}
