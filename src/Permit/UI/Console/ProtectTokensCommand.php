<?php

declare(strict_types=1);

namespace App\Permit\UI\Console;

use App\Permit\Domain\VerificationToken;
use App\Permit\Infrastructure\Security\PermitTokenSealer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SEC-020 : protège les jetons de contrôle EXISTANTS entre les deux migrations (1/2 ajoute les colonnes, 2/2 supprime le clair).
 *   (par défaut)      scelle chaque jeton qui n'a pas encore de copie scellée, puis VÉRIFIE (réouverture identique, empreinte concordante) ;
 *   --rewrap          ré-écrit les copies scellées avec la clé ACTIVE (rotation : à jouer après avoir ajouté la nouvelle clé au trousseau) ;
 *   --restore-clear   (retour arrière) recopie les jetons en clair depuis les copies scellées, tant que la colonne `token` existe.
 * Aucun jeton n'est jamais affiché ni journalisé : seulement des compteurs. Idempotente ; n'invalide aucun QR.
 */
#[AsCommand(name: 'app:permit:protect-tokens', description: 'Scelle les jetons de contrôle existants (SEC-020), ré-écrit avec la clé active (--rewrap) ou restaure le clair (--restore-clear).')]
final class ProtectTokensCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly PermitTokenSealer $sealer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rewrap', null, InputOption::VALUE_NONE, 'Ré-écrit les copies scellées avec la clé active du trousseau.')
            ->addOption('restore-clear', null, InputOption::VALUE_NONE, 'Retour arrière : recopie le clair depuis les copies scellées (colonne token encore présente).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte sans rien écrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dry = (bool) $input->getOption('dry-run');
        $hasClear = $this->hasClearColumn();
        if ($input->getOption('restore-clear')) {
            return $this->restoreClear($output, $hasClear, $dry);
        }
        if ($input->getOption('rewrap')) {
            return $this->rewrap($output, $dry);
        }

        if (!$hasClear) {
            $missing = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM permit_verification_token WHERE token_encrypted IS NULL OR token_encrypted = ''");
            $output->writeln(0 === $missing ? 'Aucun jeton en clair : rien à protéger (étape 2 déjà jouée).' : sprintf('<error>%d jeton(s) sans copie scellée et sans clair : irrécupérables sans la sauvegarde.</error>', $missing));

            return 0 === $missing ? Command::SUCCESS : Command::FAILURE;
        }
        $rows = $this->connection->fetchAllAssociative("SELECT id, token, token_sha256 FROM permit_verification_token WHERE (token_encrypted IS NULL OR token_encrypted = '') AND token IS NOT NULL");
        $done = 0;
        foreach ($rows as $row) {
            $id = Uuid::fromBinary((string) $row['id']);
            $plain = (string) $row['token'];
            if (VerificationToken::fingerprint($plain) !== $row['token_sha256']) {
                $output->writeln('<error>Empreinte incohérente pour une ligne : arrêt (aucune écriture supplémentaire).</error>');

                return Command::FAILURE;
            }
            $sealed = $this->sealer->seal($plain, $id);
            if ($this->sealer->open($sealed, $id) !== $plain) {
                $output->writeln('<error>Réouverture différente : arrêt.</error>');

                return Command::FAILURE;
            }
            if (!$dry) {
                $this->connection->executeStatement('UPDATE permit_verification_token SET token_encrypted = :s WHERE id = :id', ['s' => $sealed, 'id' => $id->toBinary()]);
            }
            ++$done;
        }
        $output->writeln(sprintf('%s%d jeton(s) scellé(s) et vérifié(s) (clé « %s »). Étape suivante : migration SEC-020 2/2 (suppression du clair).', $dry ? '[dry-run] ' : '', $done, $this->sealer->activeKeyId()));

        return Command::SUCCESS;
    }

    private function rewrap(OutputInterface $output, bool $dry): int
    {
        $active = $this->sealer->activeKeyId();
        $done = 0;
        foreach ($this->connection->fetchAllAssociative("SELECT id, token_encrypted FROM permit_verification_token WHERE token_encrypted IS NOT NULL AND token_encrypted <> ''") as $row) {
            if ($this->sealer->keyIdOf((string) $row['token_encrypted']) === $active) {
                continue;
            }
            $id = Uuid::fromBinary((string) $row['id']);
            $plain = $this->sealer->open((string) $row['token_encrypted'], $id);
            $sealed = $this->sealer->seal($plain, $id);
            if ($this->sealer->open($sealed, $id) !== $plain) {
                $output->writeln('<error>Réouverture différente : arrêt.</error>');

                return Command::FAILURE;
            }
            if (!$dry) {
                $this->connection->executeStatement('UPDATE permit_verification_token SET token_encrypted = :s WHERE id = :id', ['s' => $sealed, 'id' => $id->toBinary()]);
            }
            ++$done;
        }
        $output->writeln(sprintf('%s%d copie(s) ré-écrite(s) avec la clé active « %s ».', $dry ? '[dry-run] ' : '', $done, $active));

        return Command::SUCCESS;
    }

    private function restoreClear(OutputInterface $output, bool $hasClear, bool $dry): int
    {
        if (!$hasClear) {
            $output->writeln('<error>La colonne token n\'existe pas : jouer d\'abord le retour arrière de la migration SEC-020 2/2 (doctrine:migrations:execute --down).</error>');

            return Command::FAILURE;
        }
        $done = 0;
        foreach ($this->connection->fetchAllAssociative("SELECT id, token_encrypted, token_sha256 FROM permit_verification_token WHERE token IS NULL AND token_encrypted IS NOT NULL AND token_encrypted <> ''") as $row) {
            $id = Uuid::fromBinary((string) $row['id']);
            $plain = $this->sealer->open((string) $row['token_encrypted'], $id);
            if (VerificationToken::fingerprint($plain) !== $row['token_sha256']) {
                $output->writeln('<error>Empreinte incohérente : arrêt.</error>');

                return Command::FAILURE;
            }
            if (!$dry) {
                $this->connection->executeStatement('UPDATE permit_verification_token SET token = :t WHERE id = :id', ['t' => $plain, 'id' => $id->toBinary()]);
            }
            ++$done;
        }
        $output->writeln(sprintf('%s%d jeton(s) restauré(s) en clair (retour arrière).', $dry ? '[dry-run] ' : '', $done));

        return Command::SUCCESS;
    }

    private function hasClearColumn(): bool
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'permit_verification_token' AND column_name = 'token'") > 0;
    }
}
