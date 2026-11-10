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
use App\Permit\Application\Read\NotificationFacts;
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
        if (ChangeCategory::Ordinary === $type->category() || NotificationType::QrReplaced === $type) {
            // (le remplacement du QR est prioritaire : l'ordonnancement ne lui applique que la fusion des remplacements en attente)
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
        $facts = $this->facts->read($permitId, $this->clock->now(), $authorizationId, $row->revisionId());
        if (!self::accurate($type, $facts)) {
            return $this->settle($id, fn (NotificationOutbox $r, \DateTimeImmutable $n) => $this->inexact($r, $n, true === $facts?->authorizationRevoked), $this->inexactLabel($type), lease: null);
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

        // (4) État courant, relu juste avant l'envoi (jamais d'instance périmée). Les révisions en attente sont lues AVANT l'état : une révision commise entre
        // les deux est décrite par le message mais non déclarée couverte (au pire un message de plus, jamais une perte).
        $this->em->clear();
        $now = $this->clock->now();
        // Un message décrit l'état courant d'UNE autorisation (celle de la ligne) : il ne couvre que les révisions en attente de CETTE autorisation, jamais celles
        // d'une autre commune. Une REPRISE ou un RACCOURCISSEMENT décrit la période courante en entier : il couvre aussi les prolongations en attente (devenues
        // sans objet) ; une prolongation ne couvre que les siennes. Un raccourcissement ne couvre jamais une reprise (la réactivation resterait inconnue).
        $siblings = match ($type) {
            NotificationType::PermitExtended => $this->outbox->pendingRevisionIds($permitId, NotificationType::PermitExtended, $authorizationId),
            NotificationType::PermitResumed => [...$this->outbox->pendingRevisionIds($permitId, NotificationType::PermitResumed, $authorizationId), ...$this->outbox->pendingRevisionIds($permitId, NotificationType::PermitExtended, $authorizationId)],
            NotificationType::PermitShortened => $this->outbox->pendingRevisionIds($permitId, NotificationType::PermitExtended, $authorizationId),
            NotificationType::ZonesChanged => $this->outbox->pendingRevisionIds($permitId, NotificationType::ZonesChanged, $authorizationId),
            // Un retrait décrit l'état final des zones (et nomme aussi les ajouts de son opération) : il couvre les siens et les ajouts en attente de la MÊME autorisation.
            NotificationType::ZonesRemoved => [...$this->outbox->pendingRevisionIds($permitId, NotificationType::ZonesRemoved, $authorizationId), ...$this->outbox->pendingRevisionIds($permitId, NotificationType::ZonesChanged, $authorizationId)],
            // Plaques (niveau LAISSEZ-PASSER, aucune autorisation) : couverture par laissez-passer, toutes communes confondues ; un retrait ou un remplacement décrit l'état final et couvre les ajouts en attente.
            NotificationType::VehiclesChanged => $this->outbox->pendingRevisionIds($permitId, NotificationType::VehiclesChanged),
            NotificationType::VehiclesRemoved => [...$this->outbox->pendingRevisionIds($permitId, NotificationType::VehiclesRemoved), ...$this->outbox->pendingRevisionIds($permitId, NotificationType::VehiclesChanged)],
            // QR code (niveau laissez-passer) : seules les notifications EN ATTENTE du même laissez-passer fusionnent ; un message déjà envoyé ne couvre jamais un nouveau remplacement.
            NotificationType::QrReplaced => $this->outbox->pendingRevisionIds($permitId, NotificationType::QrReplaced),
            default => [],
        };
        $facts = $this->facts->read($permitId, $now, $authorizationId, $row->revisionId());
        if (!self::accurate($type, $facts)) {
            return $this->settle($id, fn (NotificationOutbox $r, \DateTimeImmutable $n) => $this->inexact($r, $n, true === $facts?->authorizationRevoked), $this->inexactLabel($type), $claim);
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
            // Ce que le message a réellement annoncé. Mise à disposition et extension : les autorisations listées (preuve de redondance d'une
            // NOUVELLE COMMUNE). Révocation et raccourcissement : leur seule révision et AUCUNE autorisation (une extension en attente doit toujours
            // partir). Prolongation : sa révision et celles des prolongations encore en attente (le message décrit l'état courant : un groupe = un message) ;
            // reprise : les siennes et les prolongations en attente ; jamais les révisions d'un autre type (DM-119 C5).
            $isRevision = \in_array($type, [NotificationType::AuthorizationRevoked, NotificationType::PermitShortened, NotificationType::PermitExtended, NotificationType::PermitResumed, NotificationType::ZonesChanged, NotificationType::ZonesRemoved, NotificationType::VehiclesChanged, NotificationType::VehiclesRemoved, NotificationType::QrReplaced], true);
            $covered = $isRevision ? [] : $facts->listedAuthorizations;

            return $this->settle($id, static function (NotificationOutbox $r, \DateTimeImmutable $at) use ($covered, $isRevision, $siblings): void {
                $revisions = $isRevision && null !== $r->revisionId() ? array_values(array_unique([$r->revisionId()->toRfc4122(), ...$siblings])) : [];
                $r->completeSent($at, $covered, $revisions);
            }, 'sent', $claim);
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

    /**
     * Le message est-il encore exact ? Une extension exige un laissez-passer utilisable ET une autorisation qui donne encore des droits ; une
     * RÉVOCATION exige l'inverse : que l'autorisation visée soit bien révoquée. Elle ne dépend ni de l'utilisabilité du laissez-passer (il peut ne
     * plus avoir aucun droit, c'est précisément ce qu'on annonce) ni de la présence de la commune parmi les communes actives.
     */
    private static function accurate(NotificationType $type, ?NotificationFacts $facts): bool
    {
        if (null === $facts) {
            return false;
        }
        if (NotificationType::AuthorizationRevoked === $type) {
            return $facts->authorizationRevoked;
        }
        if (NotificationType::VehiclesChanged === $type || NotificationType::VehiclesRemoved === $type || NotificationType::QrReplaced === $type) {
            // Les plaques sont communes au laissez-passer : le message reste exact tant qu'AU MOINS UNE autorisation non révoquée figure sur le laissez-passer (la révocation de la
            // commune qui a agi n'annule pas un retrait de plaque que les autres communes appliquent toujours).
            return [] !== $facts->listedAuthorizations;
        }
        if (NotificationType::PermitShortened === $type || NotificationType::ZonesRemoved === $type) {
            // Un raccourcissement ou un retrait de zone peut mettre fin à tout droit (fin immédiate) : le laissez-passer n'est alors plus « utilisable », ce qu'on annonce justement.
            // Seule une révocation survenue depuis le rend inexact (la révocation est annoncée à part).
            return null !== $facts->authorizationMunicipality && !$facts->authorizationRevoked;
        }

        return $facts->usable && $facts->authorizationActive;
    }

    /**
     * Message devenu INEXACT avant l'envoi : une extension est supprimée (et auditée), une mise à disposition est abandonnée. Un RACCOURCISSEMENT rendu
     * obsolète par une révocation est SUPPRIMÉ avec un code explicite (`SUPERSEDED_BY_REVOCATION`) et une entrée d'audit : sa trace est conservée, la
     * non-distribution est justifiée (la révocation, plus grave, est annoncée à part).
     */
    private function inexact(NotificationOutbox $row, \DateTimeImmutable $now, bool $revoked = false): void
    {
        if (NotificationType::PermitShortened === $row->type() || NotificationType::ZonesRemoved === $row->type() || NotificationType::VehiclesRemoved === $row->type() || NotificationType::QrReplaced === $row->type()) {
            $row->suppress($revoked ? OutboxErrorCode::SupersededByRevocation : OutboxErrorCode::Inaccurate, $now);
            NotificationOrdering::auditSuppression($this->audit, $row, $now);

            return;
        }
        if (ChangeCategory::Ordinary === $row->category()) {
            $row->suppress(OutboxErrorCode::Inaccurate, $now);
            NotificationOrdering::auditSuppression($this->audit, $row, $now);

            return;
        }
        $row->abandon(OutboxErrorCode::NotRelevant, $now);
    }

    private function inexactLabel(NotificationType $type): string
    {
        return ChangeCategory::Ordinary === $type->category() || NotificationType::PermitShortened === $type || NotificationType::ZonesRemoved === $type || NotificationType::VehiclesRemoved === $type || NotificationType::QrReplaced === $type ? 'suppressed' : 'not_relevant';
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
