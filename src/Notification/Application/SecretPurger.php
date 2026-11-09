<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationSecretRepository;
use App\Notification\Domain\OutboxErrorCode;
use App\Notification\Domain\OutboxStatus;
use App\Permit\Application\SystemAccessLinks;
use App\Shared\Application\TransactionRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Purge des secrets chiffrés (LOT 05.3.2, D13), automatisable et testable. Pour chaque secret dont le message est en état définitif, ou qui a
 * plus de 48 heures : suppression PHYSIQUE du chiffré. Si le message n'a jamais été transmis (tout état autre que SENT), le lien automatique
 * correspondant est révoqué par le système, et un message encore actif passe en échec définitif (`SECRET_EXPIRED`) : le secret supprimé ne
 * permet plus l'envoi. Un message SENT garde son lien actif (le bénéficiaire le possède). Une transaction par message, sous le verrou de sa ligne.
 */
final readonly class SecretPurger
{
    public function __construct(
        private TransactionRunner $transactions,
        private NotificationOutboxRepository $outbox,
        private NotificationSecretRepository $secrets,
        private SystemAccessLinks $links,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{purged: int, revoked: int, expired: int}
     */
    public function purge(int $limit = 500): array
    {
        $report = ['purged' => 0, 'revoked' => 0, 'expired' => 0];
        foreach ($this->secrets->findPurgeable($this->clock->now(), $limit) as $outboxId) {
            foreach ($this->purgeOne($outboxId) as $key => $n) {
                $report[$key] += $n;
            }
        }

        return $report;
    }

    /**
     * Traite UN message (appelé par le distributeur dès qu'un message devient définitif) : même règles que `purge()`, même verrou.
     *
     * @return array{purged: int, revoked: int, expired: int}
     */
    public function purgeOne(Uuid $outboxId): array
    {
        return $this->transactions->run(function () use ($outboxId): array {
            $row = $this->outbox->lock($outboxId);
            $secret = $this->secrets->findByOutbox($outboxId);
            if (null === $row || null === $secret) {
                return ['purged' => 0, 'revoked' => 0, 'expired' => 0];
            }
            $now = $this->clock->now();
            $expired = false;
            if (!$row->status()->isFinal()) {
                if ($now < $secret->expiresAt()) {
                    return ['purged' => 0, 'revoked' => 0, 'expired' => 0]; // état relu sous verrou : plus à purger
                }
                $row->abandon(OutboxErrorCode::SecretExpired, $now);
                $expired = true;
            }
            $revoked = OutboxStatus::Sent !== $row->status() && $this->links->revoke($secret->accessTokenId(), $now);
            $this->secrets->remove($secret);

            return ['purged' => 1, 'revoked' => $revoked ? 1 : 0, 'expired' => $expired ? 1 : 0];
        });
    }
}
