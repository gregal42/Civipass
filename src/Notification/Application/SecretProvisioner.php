<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationSecret;
use App\Notification\Domain\NotificationSecretRepository;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\OutboxErrorCode;
use App\Notification\Domain\OutboxStatus;
use App\Notification\Domain\ProvisionOutcome;
use App\Permit\Application\SystemAccessLinks;
use App\Shared\Application\TransactionRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Création du lien bénéficiaire automatique et enregistrement de son secret chiffré (LOT 05.3.2), HORS de la transaction d'approbation :
 * une approbation n'est jamais bloquée par la cryptographie. Une seule transaction, sous le verrou de la ligne d'outbox, écrit le lien
 * (émetteur système), le secret chiffré et l'état du message, ou rien : jamais de lien sans secret conservé, jamais deux liens pour un message
 * (verrou + `UNIQUE(outbox_id)` + `UNIQUE(access_token_id)`).
 *
 * Fail-closed (validé le 9 novembre 2026) : trousseau absent ou invalide → aucun lien, aucun clair, la ligne reste PENDING avec
 * `CRYPTO_UNAVAILABLE`, aucune tentative consommée ; au bout de 7 jours après la création de l'intention, échec définitif sans lien. À la
 * reprise, le laissez-passer doit être encore utilisable, sinon échec définitif (`NOT_RELEVANT`) : on n'annonce pas une mise à disposition devenue fausse.
 */
final readonly class SecretProvisioner
{
    public const CRYPTO_MAX_WAIT = 'P7D';

    public function __construct(
        private TransactionRunner $transactions,
        private NotificationOutboxRepository $outbox,
        private NotificationSecretRepository $secrets,
        private SystemAccessLinks $links,
        private SecretCipher $cipher,
        private KeyRingProvider $keys,
        private ClockInterface $clock,
    ) {
    }

    public function provision(Uuid $outboxId): ProvisionOutcome
    {
        $ring = $this->keys->keyRing();

        return $this->transactions->run(function () use ($outboxId, $ring): ProvisionOutcome {
            $row = $this->outbox->lock($outboxId);
            if (null === $row || NotificationType::PermitIssued !== $row->type() || OutboxStatus::Pending !== $row->status()) {
                return ProvisionOutcome::Skipped;
            }
            if (null !== $this->secrets->findByOutbox($row->id())) {
                return ProvisionOutcome::AlreadyProvisioned;
            }
            if ($row->attempts() > 0 || null !== $row->firstAttemptAt()) {
                return ProvisionOutcome::Skipped; // un réessai ne crée JAMAIS de lien : sans secret conservé, le distributeur abandonne
            }
            $now = $this->clock->now(); // pris sous verrou
            $cryptoDeadline = $row->occurredAt()->add(new \DateInterval(self::CRYPTO_MAX_WAIT));
            if (!$ring->isUsable() || ($row->hasError(OutboxErrorCode::CryptoUnavailable) && $now >= $cryptoDeadline)) {
                if ($now >= $cryptoDeadline) {
                    $row->abandon(OutboxErrorCode::CryptoUnavailableExpired, $now);

                    return ProvisionOutcome::CryptoExpired;
                }
                $row->flagCryptoUnavailable();

                return ProvisionOutcome::CryptoUnavailable;
            }

            $link = $this->links->issue($row->permitId(), $now);
            if (null === $link) {
                $row->abandon(OutboxErrorCode::NotRelevant, $now);

                return ProvisionOutcome::NotRelevant;
            }
            $encrypted = $this->cipher->encrypt($ring, $link->secret, $row->id(), $row->type(), $row->permitId());
            $this->secrets->add(new NotificationSecret($row->id(), Uuid::fromString($link->linkId), $encrypted, $now));
            $row->clearError();

            return ProvisionOutcome::Provisioned;
        });
    }

    /**
     * Messages qui attendent un lien, les plus anciens d'abord (appelé par le distributeur du 05.3.3).
     *
     * @return array<string, int> nombre de messages par résultat
     */
    public function provisionPending(int $limit = 100): array
    {
        $counts = [];
        foreach ($this->outbox->findAwaitingSecret($limit) as $id) {
            $outcome = $this->provision($id)->value;
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        return $counts;
    }
}
