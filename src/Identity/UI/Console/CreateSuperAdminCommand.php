<?php

declare(strict_types=1);

namespace App\Identity\UI\Console;

use App\Identity\Application\UserManager;
use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\PlainPasswordPolicy;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\DomainRuleViolation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:super-admin:create',
    description: 'Initialise le premier superadmin CWS (uniquement si aucun superadmin actif n\'existe).',
)]
final class CreateSuperAdminCommand extends Command
{
    public const PASSWORD_ENV = 'CIVIPASS_SUPERADMIN_PASSWORD';

    public function __construct(
        private readonly UserManager $userManager,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse email du superadmin (compte créé ou compte actif existant promu)')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'Prénom (compte à créer)')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Nom (compte à créer)')
            ->setHelp(<<<'HELP'
                Réservée à l'initialisation (ou à la récupération d'un incident) : elle refuse de s'exécuter
                dès qu'un superadmin actif existe, et ne permet ni retrait ni désactivation.

                Le mot de passe n'est JAMAIS un argument ni une option (historique shell, liste des processus) :
                  - interactif : saisie masquée avec confirmation ;
                  - non interactif : variable d'environnement CIVIPASS_SUPERADMIN_PASSWORD.

                Un compte existant actif est promu sans modifier son mot de passe. L'opération est idempotente
                et auditée (origine CONSOLE).
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $email = EmailAddress::fromString((string) $input->getArgument('email'));
            $existing = $this->users->findByEmail($email);

            $password = null;
            $firstName = (string) $input->getOption('first-name');
            $lastName = (string) $input->getOption('last-name');
            if (null === $existing) {
                if ($input->isInteractive()) {
                    $firstName = '' !== $firstName ? $firstName : (string) $io->ask('Prénom');
                    $lastName = '' !== $lastName ? $lastName : (string) $io->ask('Nom');
                    $password = $this->askPassword($io);
                } else {
                    $password = getenv(self::PASSWORD_ENV) ?: null;
                    if (null === $password) {
                        $io->error(sprintf('Mode non interactif : définissez la variable d\'environnement %s (jamais en argument).', self::PASSWORD_ENV));

                        return Command::INVALID;
                    }
                    if ('' === $firstName || '' === $lastName) {
                        $io->error('Mode non interactif : --first-name et --last-name sont requis pour créer un compte.');

                        return Command::INVALID;
                    }
                }
            }

            $user = $this->userManager->initializeSuperAdmin($email->value, $password, $firstName, $lastName);
        } catch (DomainRuleViolation $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%s est superadmin (identifiant %s).', $user->email(), $user->id()->toRfc4122()));

        return Command::SUCCESS;
    }

    private function askPassword(SymfonyStyle $io): string
    {
        $password = (string) $io->askHidden(
            sprintf('Mot de passe (%d caractères minimum)', PlainPasswordPolicy::MIN_LENGTH),
            static function (?string $value): string {
                PlainPasswordPolicy::assertValid((string) $value);

                return (string) $value;
            },
        );
        $confirmation = (string) $io->askHidden('Confirmez le mot de passe');
        if (!hash_equals($password, $confirmation)) {
            throw new DomainRuleViolation('Les deux mots de passe ne correspondent pas.');
        }

        return $password;
    }
}
