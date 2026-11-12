<?php

declare(strict_types=1);

namespace App\Archive\Application;

use App\Archive\Domain\LegalHoldReason;
use App\Archive\Domain\RequestLegalHold;
use App\Archive\Domain\RequestLegalHoldRepository;
use App\Identity\Domain\User;
use App\Request\Domain\PermitRequestRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;

/**
 * Gel de conservation d'UN dossier communal (LOT 05.6.3, DM-120 D3). Pose, revue et levée : `MUNICIPALITY_ADMIN` de la commune du dossier SEULEMENT (isolation : un
 * dossier d'une autre commune reçoit le refus uniforme). Droit et dossier sont relus sous le verrou de la ligne de dossier ; un seul gel actif par dossier (contrainte
 * d'unicité en dernier recours). Motif obligatoire (`AUTRE` exige un commentaire) ; auteur, date, échéance de revue enregistrés ; chaque épisode est conservé.
 * Aucune prolongation automatique : seule `review()` reporte l'échéance. Audit : identifiants et codes, jamais le commentaire.
 */
final readonly class LegalHoldService
{
    public function __construct(
        private TransactionRunner $transaction,
        private ArchiveAccessPolicy $policy,
        private PermitRequestRepository $requests,
        private RequestLegalHoldRepository $holds,
        private ArchiveSettings $settings,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     * @throws DomainRuleViolation
     */
    public function place(User $actor, string $municipalityId, string $dossierId, string $reasonCode, ?string $comment): RequestLegalHold
    {
        $reason = LegalHoldReason::tryFrom($reasonCode) ?? throw new DomainRuleViolation('Choisissez un motif de gel.');
        $comment = self::comment($comment);
        $this->policy->administeredDossier($actor, $municipalityId, $dossierId);

        return $this->transaction->run(function () use ($actor, $municipalityId, $dossierId, $reason, $comment): RequestLegalHold {
            $access = $this->policy->administeredDossier($actor, $municipalityId, $dossierId);
            $part = $this->requests->lockPart($access->dossier->id(), $access->membership->municipality()->id()) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            if (null !== $this->holds->findActive($part->id(), lock: true)) {
                throw new DomainRuleViolation('Ce dossier est déjà gelé.');
            }
            $now = $this->clock->now();
            $hold = new RequestLegalHold($part, $reason, $comment, $actor, $now, $this->settings->nextHoldReview($now));
            $this->holds->add($hold);
            $this->record(AuditAction::LegalHoldPlaced, $hold, $actor, $now, ['reason' => $reason->value, 'reviewDueAt' => $hold->reviewDueAt()->format(\DATE_ATOM)]);

            return $hold;
        });
    }

    /**
     * Revue explicite (« confirmer le maintien ») : reporte l'échéance, jamais automatique.
     *
     * @throws OperationNotAllowed
     * @throws DomainRuleViolation
     */
    public function review(User $actor, string $municipalityId, string $dossierId): RequestLegalHold
    {
        $this->policy->administeredDossier($actor, $municipalityId, $dossierId);

        return $this->transaction->run(function () use ($actor, $municipalityId, $dossierId): RequestLegalHold {
            $access = $this->policy->administeredDossier($actor, $municipalityId, $dossierId);
            $this->requests->lockPart($access->dossier->id(), $access->membership->municipality()->id()) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $hold = $this->holds->findActive($access->dossier->id(), lock: true) ?? throw new DomainRuleViolation('Ce dossier n\'est pas gelé.');
            $now = $this->clock->now();
            $hold->review($actor, $now, $this->settings->nextHoldReview($now));
            $this->record(AuditAction::LegalHoldReviewed, $hold, $actor, $now, ['reviewCount' => $hold->reviewCount(), 'reviewDueAt' => $hold->reviewDueAt()->format(\DATE_ATOM)]);

            return $hold;
        });
    }

    /**
     * @throws OperationNotAllowed
     * @throws DomainRuleViolation
     */
    public function lift(User $actor, string $municipalityId, string $dossierId, ?string $comment): RequestLegalHold
    {
        $comment = self::comment($comment);
        $this->policy->administeredDossier($actor, $municipalityId, $dossierId);

        return $this->transaction->run(function () use ($actor, $municipalityId, $dossierId, $comment): RequestLegalHold {
            $access = $this->policy->administeredDossier($actor, $municipalityId, $dossierId);
            $this->requests->lockPart($access->dossier->id(), $access->membership->municipality()->id()) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $hold = $this->holds->findActive($access->dossier->id(), lock: true) ?? throw new DomainRuleViolation('Ce dossier n\'est pas gelé.');
            $now = $this->clock->now();
            $hold->lift($actor, $comment, $now);
            $this->record(AuditAction::LegalHoldLifted, $hold, $actor, $now, []);

            return $hold;
        });
    }

    /** @param array<string, mixed> $payload */
    private function record(AuditAction $action, RequestLegalHold $hold, User $actor, \DateTimeImmutable $now, array $payload): void
    {
        $this->audit->record(new AuditEvent($now, $action, AuditSubjectType::LegalHold, $hold->id(), $hold->dossier()->municipality()->id(), AuditActor::user($actor->id()), null, ['dossier' => $hold->dossier()->id()->toRfc4122()] + $payload));
    }

    private static function comment(?string $comment): ?string
    {
        $comment = null === $comment ? null : trim($comment);
        if (null !== $comment && mb_strlen($comment) > 200) {
            throw new DomainRuleViolation('Le commentaire est limité à 200 caractères.');
        }

        return '' === $comment ? null : $comment;
    }

    /**
     * Gels actifs en retard de revue (rapport sans donnée personnelle) : ils continuent de protéger leur dossier (arbitrage A2) mais sont signalés.
     *
     * @return list<RequestLegalHold>
     */
    public function overdue(int $limit = 200): array
    {
        return $this->holds->overdue($this->clock->now(), $limit);
    }
}
