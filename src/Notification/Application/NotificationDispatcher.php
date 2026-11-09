<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\DispatchReport;
use App\Notification\Domain\FailureKind;
use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationSecretRepository;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\OutboxErrorCode;
use App\Notification\Domain\ProvisionOutcome;
use App\Notification\Domain\RetryPolicy;
use App\Notification\Domain\SecretDecryptionFailed;
use App\Permit\Application\NotificationFactsReader;
use App\Permit\Application\SystemAccessLinks;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Distributeur des notifications du bénéficiaire (LOT 05.3.3, DT-049) : un passage = les messages échus, traités un par un.
 *
 * Pour chaque message : (1) contrôle de l'état courant (laissez-passer utilisable, destinataire) ; (2) lien automatique et secret chiffré
 * (SecretProvisioner) ; (3) RÉSERVATION sous verrou de la ligne, avec bail, essai compté ; (4) relecture de l'état courant, secret, lien actif ;
 * (5) composition puis ENVOI, HORS de toute transaction et SANS verrou ; (6) mise à jour de l'état sous verrou, seulement si le bail est encore le nôtre.
 * Un message devenu définitif déclenche aussitôt la suppression de son secret (et la révocation d'un lien jamais transmis).
 *
 * Garanties : livraison AU MOINS UNE FOIS, jamais « exactement une fois ». Un arrêt brutal après l'acceptation SMTP laisse le message réservé ; à
 * l'expiration du bail il est renvoyé (doublon possible, même Message-ID, même secret) mais JAMAIS avec un nouveau lien (UNIQUE(outbox_id)).
 *
 * Fenêtre de concurrence résiduelle (assumée, documentée) : entre la dernière lecture de l'état (étape 4) et l'acceptation du message par le
 * serveur SMTP, une révocation ou un lien révoqué peuvent survenir ; aucun verrou métier n'est tenu pendant l'appel SMTP. Le message annonce
 * alors un état vrai quelques instants plus tôt ; la page du lien et le contrôle public affichent toujours les droits courants.
 *
 * Journaux : uniquement des identifiants de message et des codes ; jamais un message d'exception (il peut citer une adresse), un secret ou une adresse.
 */
final class NotificationDispatcher
{
    public const LEASE = 'PT10M';

    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly EntityManagerInterface $em,
        private readonly NotificationOutboxRepository $outbox,
        private readonly NotificationSecretRepository $secrets,
        private readonly NotificationOrdering $ordering,
        private readonly AuditTrail $audit,
        private readonly SecretProvisioner $provisioner,
        private readonly SecretVault $vault,
        private readonly SecretPurger $purger,
        private readonly SystemAccessLinks $links,
        private readonly NotificationFactsReader $facts,
        private readonly MessageComposer $composer,
        private readonly MailerInterface $mailer,
        private readonly SmtpFailureClassifier $classifier,
        private readonly KeyRingProvider $keys,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(int $limit = 100, int $maxSeconds = 50): DispatchReport
    {
        $outcomes = [];
        $deadline = microtime(true) + $maxSeconds;
        $this->em->clear();
        foreach ($this->outbox->findDispatchable($this->clock->now(), $limit) as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $label = $this->dispatchOne($id);
            } catch (\Throwable $e) {
                // Le message (réservé) sera repris à l'expiration du bail ; l'essai est déjà compté. Aucune donnée dans le journal.
                $this->logger->error('notification.dispatch.failure', ['outbox' => $id->toRfc4122(), 'class' => $e::class]);
                $label = 'error';
                if (!$this->em->isOpen()) {
                    $outcomes[$label] = ($outcomes[$label] ?? 0) + 1;
                    break;
                }
            }
            $outcomes[$label] = ($outcomes[$label] ?? 0) + 1;
        }

        $purge = ['purged' => 0, 'revoked' => 0, 'expired' => 0];
        if ($this->em->isOpen()) {
            try {
                $purge = $this->purger->purge(); // purge des secrets intégrée à chaque passage (D13) : les distributeurs concurrents se partagent les verrous de ligne
            } catch (\Throwable $e) {
                $this->logger->error('notification.purge.failure', ['class' => $e::class]);
            }
        }

        return new DispatchReport($outcomes, $purge);
    }

    /** Traite UN message ; retourne le résultat (`sent`, `retry`, `abandoned`, …). */
    public function dispatchOne(Uuid $id): string
    {
        $this->em->clear();
        $row = $this->outbox->findFresh($id);
        if (null === $row || !$row->isDispatchable($this->clock->now())) {
            return 'skipped';
        }
        [$type, $permitId, $authorizationId] = [$row->type(), $row->permitId(), $row->authorizationId()];

        // (0) Ordre (LOT 05.3.4) : une extension attend la mise à disposition ; redondante ou sans premier message remis, elle est supprimée.
        if (ChangeCategory::Ordinary === $type->category()) {
            switch ($this->ordering->decide($id)) {
                case OrderingDecision::Held:
                    return 'held';
                case OrderingDecision::Suppressed:
                    return 'suppressed';
                case OrderingDecision::Skipped:
                    return 'skipped';
                case OrderingDecision::Proceed:
                    break;
            }
        }

        // (1) État courant, avant tout lien : un laissez-passer devenu inutilisable (ou, pour une extension, dont les droits ont été révoqués)
        // ne reçoit rien ; un message inexact n'est jamais envoyé.
        $facts = $this->facts->read($permitId, $this->clock->now(), $authorizationId);
        if (null === $facts || !$facts->usable || !$facts->authorizationActive) {
            return $this->settle($id, $this->inexact(...), $this->inexactLabel($type), lease: null);
        }
        if (!$facts->hasRecipient()) {
            return $this->settle($id, static fn (NotificationOutbox $r, \DateTimeImmutable $now) => $r->markNoRecipient($now), 'no_recipient', lease: null);
        }

        // (2) Lien automatique et secret chiffré (première approbation).
        if (NotificationType::PermitIssued === $type) {
            switch ($this->provisioner->provision($id)) {
                case ProvisionOutcome::CryptoUnavailable:
                    return 'waiting_crypto';
                case ProvisionOutcome::CryptoExpired:
                    $this->purger->purgeOne($id);

                    return 'abandoned';
                case ProvisionOutcome::NotRelevant:
                    return 'not_relevant';
                default:
                    break;
            }
        }

        // (3) Réservation sous verrou, essai compté.
        $claim = $this->reserve($id);
        if (!$claim instanceof \DateTimeImmutable) {
            return $claim;
        }

        // (4) État courant, relu juste avant l'envoi (jamais d'instance périmée).
        $this->em->clear();
        $now = $this->clock->now();
        $facts = $this->facts->read($permitId, $now, $authorizationId);
        if (null === $facts || !$facts->usable || !$facts->authorizationActive) {
            return $this->settle($id, $this->inexact(...), $this->inexactLabel($type), $claim);
        }
        if (!$facts->hasRecipient()) {
            return $this->settle($id, static fn (NotificationOutbox $r, \DateTimeImmutable $at) => $r->markNoRecipient($at), 'no_recipient', $claim);
        }
        $secret = null;
        if (NotificationType::PermitIssued === $type) {
            $stored = $this->secrets->findByOutbox($id);
            if (null === $stored) {
                return $this->settle($id, static fn (NotificationOutbox $r, \DateTimeImmutable $at) => $r->abandon(OutboxErrorCode::SecretUnavailable, $at), 'abandoned', $claim);
            }
            if (!$this->links->isLinkActive($stored->accessTokenId(), $now)) {
                return $this->settle($id, static fn (NotificationOutbox $r, \DateTimeImmutable $at) => $r->abandon(OutboxErrorCode::LinkRevoked, $at), 'abandoned', $claim);
            }
            try {
                $secret = $this->vault->reveal($id);
            } catch (SecretDecryptionFailed) {
                if (!$this->keys->keyRing()->isUsable()) {
                    return $this->settle($id, static fn (NotificationOutbox $r, \DateTimeImmutable $at) => $r->releaseWithoutAttempt(), 'waiting_crypto', $claim);
                }

                return $this->settle($id, static fn (NotificationOutbox $r, \DateTimeImmutable $at) => $r->abandon(OutboxErrorCode::SecretUnavailable, $at), 'abandoned', $claim);
            }
        }

        // (5) Composition puis envoi : AUCUNE transaction, AUCUN verrou.
        $failure = null;
        try {
            $email = $this->composer->compose($id, $type, $facts, $secret);
            $this->mailer->send($email);
        } catch (TransportExceptionInterface|\InvalidArgumentException $e) {
            $failure = $this->classifier->classify($e);
        } finally {
            $secret = null;
        }

        // (6) État, sous verrou, si le bail est encore le nôtre.
        if (null === $failure) {
            $covered = $facts->listedAuthorizations; // ce que le message a réellement annoncé (preuve de redondance des extensions)

            return $this->settle($id, static fn (NotificationOutbox $r, \DateTimeImmutable $at) => $r->completeSent($at, $covered), 'sent', $claim);
        }
        $this->logger->warning('notification.dispatch.send_failed', ['outbox' => $id->toRfc4122(), 'kind' => $failure->value]);

        return $this->settle($id, static function (NotificationOutbox $r, \DateTimeImmutable $at) use ($failure): void {
            if (FailureKind::Temporary !== $failure) {
                $r->abandon(FailureKind::InvalidAddress === $failure ? OutboxErrorCode::AddressInvalid : OutboxErrorCode::SmtpPermanent, $at);

                return;
            }
            $decision = RetryPolicy::decide($r->attempts(), $r->firstAttemptAt() ?? $at, $at);
            $decision instanceof \DateTimeImmutable ? $r->retryAt($decision, OutboxErrorCode::SmtpTemporary) : $r->abandon($decision, $at);
        }, 'retry', $claim);
    }

    /** Message devenu INEXACT avant l'envoi : une extension est supprimée (et auditée), une mise à disposition est abandonnée. */
    private function inexact(NotificationOutbox $row, \DateTimeImmutable $now): void
    {
        if (ChangeCategory::Ordinary === $row->category()) {
            $row->suppress(OutboxErrorCode::Inaccurate, $now);
            NotificationOrdering::auditSuppression($this->audit, $row, $now);

            return;
        }
        $row->abandon(OutboxErrorCode::NotRelevant, $now);
    }

    private function inexactLabel(NotificationType $type): string
    {
        return ChangeCategory::Ordinary === $type->category() ? 'suppressed' : 'not_relevant';
    }

    /**
     * Réservation exclusive : la ligne est verrouillée, son éligibilité relue, les plafonds contrôlés, puis le bail posé.
     *
     * @return \DateTimeImmutable|string le bail obtenu, ou le résultat si le message n'est plus à envoyer
     */
    private function reserve(Uuid $id): \DateTimeImmutable|string
    {
        $result = $this->transactions->run(function () use ($id): \DateTimeImmutable|string {
            $row = $this->outbox->lock($id);
            $now = $this->clock->now();
            if (null === $row || !$row->isDispatchable($now)) {
                return 'skipped'; // déjà réservé par un autre distributeur (bail valide) ou traité
            }
            if (ChangeCategory::Ordinary === $row->category() && $this->outbox->hasActiveFirstMessage($row->permitId())) {
                return 'held'; // une mise à disposition a été demandée entre-temps : l'extension attend
            }
            $cap = RetryPolicy::exhausted($row->attempts(), $row->firstAttemptAt(), $now);
            if (null !== $cap) {
                $row->abandon($cap, $now);

                return 'abandoned';
            }
            $lease = $now->add(new \DateInterval(self::LEASE));
            $lease = $lease->setTime((int) $lease->format('G'), (int) $lease->format('i'), (int) $lease->format('s')); // à la seconde : la base ne garde pas les microsecondes
            $row->reserve($now, $lease);

            return $lease;
        });
        if ('abandoned' === $result) {
            $this->purger->purgeOne($id);
        }

        return $result;
    }

    /**
     * Mise à jour finale sous verrou. `$lease` : bail à détenir encore (null avant réservation : le message doit être échu). Un message devenu
     * définitif déclenche la purge de son secret.
     *
     * @param callable(NotificationOutbox, \DateTimeImmutable): void $mutate
     */
    private function settle(Uuid $id, callable $mutate, string $label, ?\DateTimeImmutable $lease): string
    {
        $outcome = $this->transactions->run(function () use ($id, $mutate, $label, $lease): string {
            $row = $this->outbox->lock($id);
            $now = $this->clock->now();
            if (null === $row || (null === $lease ? !$row->isDispatchable($now) : !$row->holdsLease($lease))) {
                return 'lease_lost'; // bail repris par un autre distributeur, ou message déjà traité : ne rien écraser
            }
            $mutate($row, $now);

            return $row->status()->isFinal() ? ('retry' === $label ? 'abandoned' : $label) : $label;
        });
        if (!\in_array($outcome, ['lease_lost', 'retry', 'waiting_crypto'], true)) {
            $this->purger->purgeOne($id);
        }

        return $outcome;
    }
}
