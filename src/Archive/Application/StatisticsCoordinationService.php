<?php

declare(strict_types=1);

namespace App\Archive\Application;

use App\Archive\Domain\RevocationCause;
use App\Archive\Domain\StatisticsCoordinationGrant;
use App\Archive\Domain\StatisticsCoordinationGrantRepository;
use App\Event\Domain\EventRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\MunicipalityRole;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Habilitation de COORDINATION STATISTIQUE (L5-36, arbitrage A1) : accordée et révoquée par le `MUNICIPALITY_ADMIN` de la commune ORGANISATRICE à un `EVENT_MANAGER` de
 * cette commune, pour la ventilation par commune du bilan de clôture. DISTINCTE de la désignation d'archive : l'une n'ouvre jamais l'autre et aucune ne donne accès à
 * un dossier. Mêmes garanties que la désignation (droit relu sous verrou de la commune, révocation définitive, audit sans valeur personnelle).
 */
final readonly class StatisticsCoordinationService
{
    public function __construct(
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private ArchiveAccessPolicy $policy,
        private EventRepository $events,
        private UserRepository $users,
        private MembershipRepository $memberships,
        private StatisticsCoordinationGrantRepository $grants,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     * @throws DomainRuleViolation
     */
    public function grant(User $actor, string $municipalityId, string $eventId, string $targetUserId): StatisticsCoordinationGrant
    {
        $this->policy->administrator($actor, $municipalityId, write: true);

        return $this->transaction->run(function () use ($actor, $municipalityId, $eventId, $targetUserId): StatisticsCoordinationGrant {
            $municipality = $this->municipalities->lock(Uuid::fromString($municipalityId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $this->policy->administrator($actor, $municipalityId, write: true);
            $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
            // Seule la commune ORGANISATRICE coordonne : toute autre reçoit le refus uniforme.
            if (null === $event || !$event->isOrganizedBy($municipality)) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $target = Uuid::isValid($targetUserId) ? $this->users->find(Uuid::fromString($targetUserId)) : null;
            $membership = null === $target ? null : $this->memberships->findOneByUserAndMunicipality($target, $municipality);
            if (null === $target || null === $membership || !$target->isActive() || !$membership->isActive() || !$membership->hasRole(MunicipalityRole::EventManager)) {
                throw new DomainRuleViolation('La personne habilitée doit être un gestionnaire d\'événement actif de la commune organisatrice.');
            }
            if (null !== $this->grants->findActive($event->id(), $target->id(), lock: true)) {
                throw new DomainRuleViolation('Cette personne a déjà l\'habilitation de coordination.');
            }
            $now = $this->clock->now();
            $grant = new StatisticsCoordinationGrant($event, $target, $actor, $now);
            $this->grants->add($grant);
            $this->audit->record(new AuditEvent($now, AuditAction::StatisticsCoordinationGranted, AuditSubjectType::StatisticsCoordination, $grant->id(), $municipality->id(), AuditActor::user($actor->id()), null, [
                'event' => $event->id()->toRfc4122(),
                'user' => $target->id()->toRfc4122(),
            ]));

            return $grant;
        });
    }

    /**
     * @throws OperationNotAllowed
     * @throws DomainRuleViolation
     */
    public function revoke(User $actor, string $municipalityId, string $grantId): void
    {
        $this->policy->administrator($actor, $municipalityId, write: true);
        $this->transaction->run(function () use ($actor, $municipalityId, $grantId): void {
            $municipality = $this->municipalities->lock(Uuid::fromString($municipalityId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $this->policy->administrator($actor, $municipalityId, write: true);
            $grant = Uuid::isValid($grantId) ? $this->grants->find(Uuid::fromString($grantId)) : null;
            if (null === $grant || !$grant->event()->isOrganizedBy($municipality)) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $now = $this->clock->now();
            $grant->revoke(RevocationCause::AdminRequest, $actor, $now);
            $this->audit->record(new AuditEvent($now, AuditAction::StatisticsCoordinationRevoked, AuditSubjectType::StatisticsCoordination, $grant->id(), $municipality->id(), AuditActor::user($actor->id()), null, [
                'event' => $grant->event()->id()->toRfc4122(),
                'user' => $grant->user()->id()->toRfc4122(),
                'cause' => RevocationCause::AdminRequest->value,
            ]));
        });
    }

    /**
     * @return array{grants: list<StatisticsCoordinationGrant>, candidates: list<User>}
     *
     * @throws OperationNotAllowed
     */
    public function forEvent(User $actor, string $municipalityId, string $eventId): array
    {
        $membership = $this->policy->administrator($actor, $municipalityId, write: false);
        $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
        if (null === $event || !$event->isOrganizedBy($membership->municipality())) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $grants = $this->grants->forEvent($event->id());
        $already = [];
        foreach ($grants as $g) {
            if ($g->isActive()) {
                $already[$g->user()->id()->toRfc4122()] = true;
            }
        }
        $candidates = [];
        foreach ($this->memberships->findByMunicipality($membership->municipality()) as $m) {
            if ($m->isActive() && $m->user()->isActive() && $m->hasRole(MunicipalityRole::EventManager) && !isset($already[$m->user()->id()->toRfc4122()])) {
                $candidates[] = $m->user();
            }
        }

        return ['grants' => $grants, 'candidates' => $candidates];
    }
}
