<?php

declare(strict_types=1);

namespace App\Notification\UI\Console;

use App\Notification\Application\KeyRingProvider;
use App\Notification\Application\SecretCipher;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SecretDecryptionFailed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Vérification EXPLICITE de la configuration cryptographique, à exécuter avant l'activation opérationnelle des notifications (LOT 05.3.2, D3) :
 * trousseau lisible, clé active connue, chiffrement puis déchiffrement d'une donnée jetable, rejet d'une donnée liée à un autre message.
 * Affiche des identifiants de clés, JAMAIS une valeur de clé. Code de sortie non nul au moindre défaut.
 */
#[AsCommand(name: 'app:notifications:check-crypto', description: 'Vérifie le trousseau de chiffrement des secrets bénéficiaires (aucune valeur de clé affichée)')]
final class CheckCryptoCommand extends Command
{
    public function __construct(private readonly KeyRingProvider $keys, private readonly SecretCipher $cipher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ring = $this->keys->keyRing();
        if (!$ring->isUsable()) {
            $io->error(sprintf('Trousseau inutilisable : %s. Les notifications automatiques restent en attente (CRYPTO_UNAVAILABLE) ; la remise manuelle du lien reste possible.', $ring->problem()));

            return Command::FAILURE;
        }
        $outbox = Uuid::v7();
        $permit = Uuid::v7();
        $probe = 'sonde-'.bin2hex(random_bytes(8));
        try {
            $encrypted = $this->cipher->encrypt($ring, $probe, $outbox, NotificationType::PermitIssued, $permit);
            $ok = hash_equals($probe, $this->cipher->decrypt($ring, $encrypted, $outbox, NotificationType::PermitIssued, $permit));
            $bound = false;
            try {
                $this->cipher->decrypt($ring, $encrypted, Uuid::v7(), NotificationType::PermitIssued, $permit);
            } catch (SecretDecryptionFailed) {
                $bound = true;
            }
        } catch (\Throwable) {
            $io->error('Échec du chiffrement ou du déchiffrement de contrôle.');

            return Command::FAILURE;
        }
        if (!$ok || !$bound) {
            $io->error('Contrôle cryptographique en échec (aller-retour ou liaison au message).');

            return Command::FAILURE;
        }
        $io->success(sprintf('Trousseau valide. Clé active : %s. Clés disponibles : %s.', $ring->activeId(), implode(', ', $ring->ids())));

        return Command::SUCCESS;
    }
}
