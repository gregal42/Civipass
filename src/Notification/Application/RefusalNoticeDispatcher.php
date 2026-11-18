<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\FailureKind;
use App\Notification\Domain\OutboxErrorCode;
use App\Notification\Domain\RetryPolicy;
use App\Request\Domain\RefusalNoticeRepository;
use App\Request\Domain\RefusalNoticeStatus;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Distributeur de l'information des demandeurs refusés (LOT 08.5, DM-136) : un passage = les lignes échues, traitées une à une. Même politique que les notifications
 * de laissez-passer (RetryPolicy : 1, 5, 15, 60, 240 min ; abandon après 6 essais ou 24 h à compter du premier essai ; mêmes classes d'erreurs SMTP).
 *
 * Réservation par bail (UPDATE conditionnel atomique) ; l'adresse est RELUE à l'envoi ; l'envoi se fait HORS de toute transaction ; l'état final n'est écrit que si le
 * bail est encore le nôtre. Livraison AU MOINS UNE FOIS (un arrêt brutal après l'acceptation SMTP peut renvoyer le même message, même Message-ID). SENT signifie
 * « remis au serveur de messagerie », jamais reçu ni lu. La clôture de l'événement ne neutralise PAS l'information (DM-136) : aucun lien ni donnée de dossier.
 *
 * Interrupteur `REFUSAL_NOTICE_ENABLED` (0 par défaut) : tant que le libellé juridique n'est pas validé, rien n'est envoyé, les lignes restent en attente.
 * Journaux : identifiants et codes seulement ; jamais l'adresse ni un message d'exception.
 */
final class RefusalNoticeDispatcher
{
    public const LEASE = 'PT10M';

    public function __construct(
        private readonly RefusalNoticeRepository $notices,
        private readonly EntityManagerInterface $em,
        private readonly RefusalNoticeComposer $composer,
        private readonly MailerInterface $mailer,
        private readonly SmtpFailureClassifier $classifier,
        private readonly TransactionRunner $transactions,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:REFUSAL_NOTICE_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @return array<string, int> compteurs par résultat (`sent`, `retry`, `abandoned`, `no_recipient`, `skipped`, `error`) ; vide si l'envoi est désactivé */
    public function run(int $limit = 100, int $maxSeconds = 50): array
    {
        if (!$this->enabled) {
            return [];
        }
        $outcomes = [];
        $deadline = microtime(true) + $maxSeconds;
        foreach ($this->notices->findDue($this->clock->now(), $limit) as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $label = $this->dispatchOne($id);
            } catch (\Throwable $e) {
                // La ligne (réservée) sera reprise à l'expiration du bail ; l'essai est déjà compté. Aucune donnée dans le journal.
                $this->logger->error('refusal_notice.dispatch.failure', ['notice' => $id->toRfc4122(), 'class' => $e::class]);
                $label = 'error';
                if (!$this->em->isOpen()) {
                    $outcomes[$label] = ($outcomes[$label] ?? 0) + 1;
                    break;
                }
            }
            $outcomes[$label] = ($outcomes[$label] ?? 0) + 1;
        }

        return $outcomes;
    }

    public function dispatchOne(Uuid $id): string
    {
        if (!$this->enabled) {
            return 'skipped';
        }
        $this->em->clear();
        $now = $this->clock->now();
        $lease = $now->add(new \DateInterval(self::LEASE));
        $claim = $this->notices->claim($id, $now, $lease);
        if (null === $claim) {
            return 'skipped';
        }
        $exhausted = RetryPolicy::exhausted($claim['attempts'] - 1, $claim['firstAttemptAt'], $now);
        if (null !== $exhausted) {
            return $this->settle($id, $lease, RefusalNoticeStatus::Abandoned, $exhausted->value, 'abandoned');
        }
        $facts = $this->notices->facts($id);
        if (null === $facts || '' === $facts->recipient) {
            // Adresse retirée depuis la décision : plus de destinataire, à informer par un autre moyen.
            return $this->settle($id, $lease, RefusalNoticeStatus::NoRecipient, null, 'no_recipient');
        }
        try {
            $email = $this->composer->compose($id, $facts);
        } catch (\Throwable $e) {
            return $this->settle($id, $lease, RefusalNoticeStatus::Abandoned, OutboxErrorCode::AddressInvalid->value, 'abandoned');
        }
        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            return $this->failed($id, $lease, $claim, $this->classifier->classify($e), $now, $e);
        }

        return $this->settle($id, $lease, RefusalNoticeStatus::Sent, null, 'sent');
    }

    /** @param array{attempts: int, firstAttemptAt: \DateTimeImmutable} $claim */
    private function failed(Uuid $id, \DateTimeImmutable $lease, array $claim, FailureKind $kind, \DateTimeImmutable $now, \Throwable $e): string
    {
        $this->logger->warning('refusal_notice.send.failure', ['notice' => $id->toRfc4122(), 'kind' => $kind->value, 'class' => $e::class]);
        if (FailureKind::Temporary !== $kind) {
            $code = FailureKind::InvalidAddress === $kind ? OutboxErrorCode::AddressInvalid : OutboxErrorCode::SmtpPermanent;

            return $this->settle($id, $lease, RefusalNoticeStatus::Abandoned, $code->value, 'abandoned');
        }
        $decision = RetryPolicy::decide($claim['attempts'], $claim['firstAttemptAt'], $now);
        if ($decision instanceof OutboxErrorCode) {
            return $this->settle($id, $lease, RefusalNoticeStatus::Abandoned, $decision->value, 'abandoned');
        }
        $this->notices->retryAt($id, $lease, $decision, OutboxErrorCode::SmtpTemporary->value);

        return 'retry';
    }

    private function settle(Uuid $id, \DateTimeImmutable $lease, RefusalNoticeStatus $status, ?string $code, string $label): string
    {
        $now = $this->clock->now();
        $this->transactions->run(function () use ($id, $lease, $status, $code, $now): void {
            if (!$this->notices->finish($id, $lease, $status, $now, $code)) {
                return; // bail repris par une autre exécution : elle écrira l'état
            }
            $notice = $this->notices->find($id);
            $action = match ($status) {
                RefusalNoticeStatus::Sent => AuditAction::RefusalNoticeSent,
                RefusalNoticeStatus::Abandoned => AuditAction::RefusalNoticeAbandoned,
                default => null,
            };
            if (null !== $notice && null !== $action) {
                $this->audit->record(new AuditEvent($now, $action, AuditSubjectType::RefusalNotice, $id, $notice->municipality()->id(), AuditActor::system(), null, ['status' => $status->value, 'attempts' => $notice->attempts(), 'code' => $code]));
            }
        });

        return $label;
    }
}
