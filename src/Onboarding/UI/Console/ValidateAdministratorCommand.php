<?php

declare(strict_types=1);

namespace App\Onboarding\UI\Console;

use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\UserRepository;
use App\Onboarding\Application\AdministratorValidationService;
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
 * SEC-016 : valide ou refuse la demande de premier administrateur d'une commune issue d'un lien d'invitation personnel. RÉSERVÉ aux super-administrateurs CWS
 * (`--operator` = leur adresse : le valideur est toujours identifié, jamais « console »). La vérification de légitimité se fait HORS Civipass (procédure :
 * docs/runbooks/validation-administrateur-communal.md) ; le motif consigne comment. Sans argument, liste les demandes en attente.
 *   app:onboarding:validate-administrator --operator=<email>                              (liste)
 *   app:onboarding:validate-administrator <id> approve|reject --operator=<email> --reason="…" --yes.
 */
#[AsCommand(name: 'app:onboarding:validate-administrator', description: 'Liste, approuve ou refuse les demandes de premier administrateur communal (SEC-016) ; super-administrateur CWS, motif obligatoire.')]
final class ValidateAdministratorCommand extends Command
{
    public function __construct(private readonly AdministratorValidationService $validations, private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'Identifiant de la demande.')
            ->addArgument('decision', InputArgument::OPTIONAL, 'approve ou reject.')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail du super-administrateur CWS qui décide (obligatoire).')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Motif : comment la légitimité a été vérifiée (obligatoire, conservé dans l\'audit).')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Confirme sans interaction.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getOption('operator');
        try {
            $operator = '' === $email ? null : $this->users->findByEmail(EmailAddress::fromString($email));
        } catch (DomainRuleViolation) {
            $operator = null;
        }
        if (null === $operator) {
            $output->writeln('<error>--operator : super-administrateur CWS introuvable.</error>');

            return Command::FAILURE;
        }
        try {
            $id = $input->getArgument('id');
            if (null === $id) {
                $pending = $this->validations->pending($operator);
                foreach ($pending as $v) {
                    $output->writeln(sprintf('%s  %s  commune « %s »  demandeur %s  le %s', $v->id()->toRfc4122(), $v->status()->value, $v->municipality()->name(), $v->user()->email(), $v->requestedAt()->format('Y-m-d H:i')));
                }
                $output->writeln(sprintf('%d demande(s) en attente.', \count($pending)));

                return Command::SUCCESS;
            }
            $decision = (string) $input->getArgument('decision');
            if (!Uuid::isValid((string) $id) || !\in_array($decision, ['approve', 'reject'], true)) {
                $output->writeln('<error>Usage : <id> approve|reject --operator=… --reason=… --yes</error>');

                return Command::INVALID;
            }
            if (!$input->getOption('yes')) {
                $output->writeln('<error>Confirmation requise : ajoutez --yes une fois la légitimité vérifiée hors Civipass.</error>');

                return Command::INVALID;
            }
            'approve' === $decision
                ? $this->validations->approve($operator, Uuid::fromString((string) $id), (string) $input->getOption('reason'))
                : $this->validations->reject($operator, Uuid::fromString((string) $id), (string) $input->getOption('reason'));
            $output->writeln('approve' === $decision ? 'Demande approuvée : le demandeur est administrateur de la commune.' : 'Demande refusée : aucun droit attribué.');

            return Command::SUCCESS;
        } catch (OperationNotAllowed|DomainRuleViolation $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
    }
}
