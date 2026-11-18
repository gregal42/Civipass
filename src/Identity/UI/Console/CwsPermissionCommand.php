<?php

declare(strict_types=1);

namespace App\Identity\UI\Console;

use App\Identity\Application\CwsPermissionManager;
use App\Identity\Domain\CwsPermission;
use App\Identity\Domain\CwsPermissionGrantRepository;
use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:cws:permission', description: 'Attribue, révoque ou liste les habilitations CWS (clôture exceptionnelle, impersonation) — LOT 08.6.')]
final class CwsPermissionCommand extends Command
{
    public function __construct(
        private readonly CwsPermissionManager $manager,
        private readonly CwsPermissionGrantRepository $grants,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'grant | revoke | list')
            ->addArgument('email', InputArgument::OPTIONAL, 'Adresse du super-administrateur bénéficiaire')
            ->addArgument('permission', InputArgument::OPTIONAL, implode(' | ', array_map(static fn (CwsPermission $p): string => $p->value, CwsPermission::cases())))
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'Adresse du super-administrateur qui attribue ou révoque (obligatoire ; jamais le bénéficiaire pour une attribution)')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Motif de l’attribution (obligatoire, 10 à 200 caractères, sans donnée personnelle)')
            ->setHelp(<<<'HELP'
                Aucune habilitation n'est attribuée par défaut. Un super-administrateur ne peut pas s'attribuer lui-même une habilitation.
                  app:cws:permission grant  marie@cws.example CWS_FORCE_CLOSE_EVENT --by=paul@cws.example --reason="Astreinte de crise 2026"
                  app:cws:permission revoke marie@cws.example CWS_FORCE_CLOSE_EVENT --by=paul@cws.example
                  app:cws:permission list
                Chaque opération est auditée (origine CONSOLE).
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        if ('list' === $action) {
            $rows = [];
            foreach ($this->grants->all() as $grant) {
                $rows[] = [$grant->user()->email(), $grant->permission()->value, $grant->isActive() ? 'active' : 'révoquée', $grant->grantedAt()->format('Y-m-d H:i'), $grant->grantedBy()->email(), $grant->reason()];
            }
            $io->table(['Compte', 'Permission', 'État', 'Attribuée le (UTC)', 'Par', 'Motif'], $rows);

            return Command::SUCCESS;
        }
        if (!\in_array($action, ['grant', 'revoke'], true)) {
            $io->error('Action inconnue : grant, revoke ou list.');

            return Command::INVALID;
        }
        $permission = CwsPermission::tryFrom((string) $input->getArgument('permission'));
        $email = (string) $input->getArgument('email');
        $by = (string) $input->getOption('by');
        if (null === $permission || '' === $email || '' === $by) {
            $io->error('Arguments requis : adresse du bénéficiaire, permission et --by.');

            return Command::INVALID;
        }
        try {
            $beneficiary = $this->user($email);
            $operator = $this->user($by);
            if ('grant' === $action) {
                $this->manager->grant($beneficiary, $permission, $operator, (string) $input->getOption('reason'));
                $io->success(sprintf('%s attribuée à %s (par %s).', $permission->value, $email, $by));
            } else {
                $this->manager->revoke($beneficiary, $permission, $operator);
                $io->success(sprintf('%s révoquée pour %s (effet immédiat).', $permission->value, $email));
            }
        } catch (DomainRuleViolation|OperationNotAllowed $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function user(string $email): User
    {
        return $this->users->findByEmail(EmailAddress::fromString($email)) ?? throw new OperationNotAllowed('Compte introuvable.');
    }
}
