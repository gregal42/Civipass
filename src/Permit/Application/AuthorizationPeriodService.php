<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\Event;
use App\Identity\Domain\User;
use App\Permit\Application\Result\PeriodChange;
use App\Permit\Application\Result\RevisionScope;
use App\Permit\Domain\AuthorizationPeriodPlanner;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Permit\Domain\PeriodEnd;
use App\Permit\Domain\PeriodKind;
use App\Permit\Domain\PeriodPlan;
use App\Permit\Domain\PeriodReason;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\ShorteningReason;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;

/**
 * Prolongation, reprise après expiration et raccourcissement de l'autorisation d'UNE commune (LOT 05.5.2, DM-119 §4.2). Une commune ne touche que SA
 * période courante ; la décision initiale, le QR, les autres communes sont inchangés ; une autorisation révoquée refuse tout. Les règles de période sont
 * celles du planificateur (`AuthorizationPeriodPlanner`), les mêmes pour l'aperçu et pour l'écriture.
 *
 * Tout est relu SOUS VERROU (événement partagé → participation → laissez-passer → autorisation) puis écrit dans UNE transaction : période, éventuelle
 * période archivée (reprise), révision immuable, audit (code du motif, jamais le commentaire), intention de notification (raccourcissement :
 * PRIORITAIRE ; prolongation et reprise : ordinaire, regroupée). Un événement non ACTIF refuse prolongation et reprise ; le raccourcissement, qui retire
 * des droits, reste possible pendant une suspension (pas sur un événement clos).
 *
 * Historique volumineux : AUCUN plafond, ni fonctionnel ni arbitraire. Protections : détection d'une opération « inhabituelle » au-delà du seuil d'alerte
 * (`ALERT_THRESHOLD`, audit marqué, rien n'est bloqué), lecture d'une seule requête par autorisation et calcul linéaire. Seule limite : le type de stockage
 * (`POSITION_STORAGE_LIMIT`, SMALLINT non signé) : une erreur claire plutôt qu'une erreur SQL, pas une règle métier.
 */
final readonly class AuthorizationPeriodService
{
    /** Seuil au-delà duquel l'opération est marquée « inhabituelle » dans l'audit (jamais bloquante). */
    public const ALERT_THRESHOLD = 20;

    /** Limite PHYSIQUE du stockage du rang d'une période close (SMALLINT non signé) : ce n'est ni un plafond métier ni un seuil de sécurité. */
    public const POSITION_STORAGE_LIMIT = 65535;

    public function __construct(
        private EventTransactions $transactions,
        private EventLocker $locker,
        private PermitRevisionGuard $guard,
        private PermitRepository $permits,
        private PermitRevisionRepository $revisions,
        private ClockInterface $clock,
        private AuditTrail $audit,
        private NotificationIntentRecorder $notifications,
        private AuthorizationPeriodPlanner $planner = new AuthorizationPeriodPlanner(),
    ) {
    }

    /**
     * Aperçu SANS écriture, sans verrou : la période qui résulterait de l'opération « maintenant ».
     *
     * @throws OperationNotAllowed commune, demande ou laissez-passer inaccessible
     * @throws PermitAccessDenied  rôle absent
     * @throws DomainRuleViolation refus motivé
     */
    public function preview(User $agent, string $municipalityId, string $requestId, PeriodKind $kind, PeriodEnd $end): PeriodPlan
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: false);

        return $this->plan($scope, $kind, $end, $this->clock->now(), $scope->permit->event());
    }

    /**
     * Prolongation (période non terminée) ou reprise (période terminée) : l'état décide, relu sous verrou.
     *
     * @throws OperationNotAllowed     commune, demande ou laissez-passer inaccessible
     * @throws PermitAccessDenied      rôle absent
     * @throws PermitAccessUnavailable participation non ACTIVE (relue sous verrou)
     * @throws DomainRuleViolation     refus motivé, motif manquant (obligatoire pour une reprise), commentaire invalide
     * @throws OperationConflict       conflit technique : rien n'est écrit, réessayable
     */
    public function extend(User $agent, string $municipalityId, string $requestId, PeriodEnd $end, ?string $reasonCode, ?string $comment): PeriodChange
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: true);
        $reason = null === $reasonCode || '' === $reasonCode ? null : (PeriodReason::tryFrom($reasonCode) ?? throw new DomainRuleViolation('Motif inconnu.'));
        $comment = self::comment($reason?->needsComment() ?? false, $comment);

        return $this->apply($agent, $municipalityId, $scope, PeriodKind::Extended, $end, $reason?->value, $comment, false);
    }

    /**
     * Raccourcissement : motif obligatoire, confirmation explicite ; une date passée donne une fin immédiate (jamais antidatée).
     *
     * @throws DomainRuleViolation motif absent ou inconnu, commentaire invalide, confirmation absente, refus motivé
     */
    public function shorten(User $agent, string $municipalityId, string $requestId, PeriodEnd $end, string $reasonCode, ?string $comment, bool $confirmed): PeriodChange
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: true);
        $reason = ShorteningReason::tryFrom($reasonCode) ?? throw new DomainRuleViolation('Choisissez un motif de raccourcissement.');
        $comment = self::comment($reason->needsComment(), $comment);
        if (!$confirmed) {
            throw new DomainRuleViolation('Confirmez le raccourcissement : des droits seront retirés.');
        }

        return $this->apply($agent, $municipalityId, $scope, PeriodKind::Shortened, $end, $reason->value, $comment, true);
    }

    private function apply(User $agent, string $municipalityId, RevisionScope $scope, PeriodKind $kind, PeriodEnd $end, ?string $reasonCode, ?string $comment, bool $reasonRequired): PeriodChange
    {
        return $this->transactions->run(function () use ($agent, $municipalityId, $scope, $kind, $end, $reasonCode, $comment, $reasonRequired): PeriodChange {
            $membership = $this->guard->lock($scope, $agent, $municipalityId);
            $authorization = $scope->authorization;
            $now = self::second($this->clock->now()); // pris sous verrou (DT-038)
            $plan = $this->plan($scope, $kind, $end, $now, $this->locker->sharedEvent($scope->permit->event()->id())); // événement relu sous verrou partagé
            if (PeriodKind::Resumed === $plan->kind && null === $reasonCode) {
                throw new DomainRuleViolation('Un motif est obligatoire pour reprendre les droits après expiration.');
            }
            if ($reasonRequired && null === $reasonCode) {
                throw new DomainRuleViolation('Un motif est obligatoire.');
            }
            $closed = $this->permits->closedPeriodCount($authorization->id());
            if (PeriodKind::Resumed === $plan->kind && $closed >= self::POSITION_STORAGE_LIMIT) {
                throw new DomainRuleViolation('Limite de stockage de l\'historique des périodes atteinte pour cette autorisation : contactez l\'administration de la plateforme.');
            }
            $revision = PermitRevision::period($plan->kind->revisionType(), $authorization, $agent, $reasonCode, $comment, $now, $plan->beforeState(), $plan->afterState());
            if (PeriodKind::Resumed === $plan->kind) {
                $authorization->resumeAt($plan->from, $plan->mode, $plan->until, $revision, $closed + 1);
            } else {
                $authorization->changeCurrentEnd($plan->mode, $plan->until);
            }
            $this->revisions->add($revision);
            $unusual = $closed >= self::ALERT_THRESHOLD;
            $this->audit->record(new AuditEvent(
                $now,
                match ($plan->kind) {
                    PeriodKind::Extended => AuditAction::PermitPeriodExtended,
                    PeriodKind::Resumed => AuditAction::PermitPeriodResumed,
                    PeriodKind::Shortened => AuditAction::PermitPeriodShortened,
                },
                AuditSubjectType::Permit,
                $scope->permit->id(),
                $membership->municipality()->id(),
                AuditActor::user($agent->id()),
                null,
                [
                    'reference' => $scope->permit->reference()->value,
                    'authorization' => $authorization->id()->toRfc4122(),
                    'revision' => $revision->id()->toRfc4122(),
                    'municipality' => $membership->municipality()->id()->toRfc4122(),
                    'reason' => $reasonCode,
                    'validFrom' => $plan->from->format(\DATE_ATOM),
                    'validUntil' => $plan->until?->format(\DATE_ATOM),
                    'immediate' => $plan->immediate,
                    'closedPeriods' => $closed + (PeriodKind::Resumed === $plan->kind ? 1 : 0),
                    'unusual' => $unusual,
                ],
            ));
            $this->notifications->record(new NotificationIntent(
                match ($plan->kind) {
                    PeriodKind::Extended => NotificationKind::PermitExtended,
                    PeriodKind::Resumed => NotificationKind::PermitResumed,
                    PeriodKind::Shortened => NotificationKind::PermitShortened,
                },
                $scope->permit->id(),
                $authorization->id(),
                $now,
                '' !== trim((string) $scope->permit->request()->email()),
                $revision->id(),
            ));

            return new PeriodChange($plan, $unusual);
        });
    }

    private function plan(RevisionScope $scope, PeriodKind $kind, PeriodEnd $end, \DateTimeImmutable $now, Event $event): PeriodPlan
    {
        $authorization = $scope->authorization;
        $args = [$authorization->window(), $authorization->isRevoked(), $event->status(), $event->endsAt(), $event->timezone(), $now, $end];

        return PeriodKind::Shortened === $kind ? $this->planner->shorten(...$args) : $this->planner->extend(...$args);
    }

    private static function comment(bool $required, ?string $comment): ?string
    {
        $comment = null === $comment ? '' : trim($comment);
        if (mb_strlen($comment) > PermitRevision::COMMENT_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le commentaire ne peut pas dépasser %d caractères.', PermitRevision::COMMENT_MAX_LENGTH));
        }
        if ('' === $comment) {
            if ($required) {
                throw new DomainRuleViolation('Un commentaire est obligatoire pour « Autre motif ».');
            }

            return null;
        }

        return $comment;
    }

    private static function second(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $utc = $instant->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTimestamp($utc->getTimestamp());
    }
}
