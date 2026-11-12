<?php

declare(strict_types=1);

namespace App\Archive\Application;

use App\Archive\Domain\EventManagerDesignation;
use App\Archive\Domain\EventManagerDesignationRepository;
use App\Archive\Domain\RevocationCause;
use App\Event\Domain\EventParticipationRepository;
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
 * Désignation et révocation des `EVENT_MANAGER` habilités à consulter les archives d'UN événement et d'UNE commune (LOT 05.6.3, option B). Seul le `MUNICIPALITY_ADMIN`
 * de la commune désigne ou révoque ; droit, appartenance et rôle de la cible sont relus SOUS le verrou de la commune (même ordre que `MembershipManager` : aucun
 * cycle, et un retrait de rôle concurrent est sérialisé). Une désignation révoquée est DÉFINITIVE : redonner le rôle ne la rétablit jamais, une nouvelle
 * désignation est un nouvel acte. Audit : identifiants et cause seulement.
 */
final readonly class ArchiveDesignationService
{
    public function __construct(
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private ArchiveAccessPolicy $policy,
        private EventRepository $events,
        private EventParticipationRepository $participations,
        private UserRepository $users,
        private MembershipRepository $memberships,
        private EventManagerDesignationRepository $designations,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OperationNotAllowed droit insuffisant ou ressource inaccessible (refus uniforme)
     * @throws DomainRuleViolation cible invalide ou déjà désignée
     */
    public function designate(User $actor, string $municipalityId, string $eventId, string $targetUserId): EventManagerDesignation
    {
        $this->policy->administrator($actor, $municipalityId, write: true);

        return $this->transaction->run(function () use ($actor, $municipalityId, $eventId, $targetUserId): EventManagerDesignation {
            $municipality = $this->municipalities->lock(Uuid::fromString($municipalityId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $this->policy->administrator($actor, $municipalityId, write: true); // relu sous verrou
            $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
            $participation = null === $event ? null : $this->participations->findByEventAndMunicipality($event->id(), $municipality->id());
            if (null === $participation || null === $participation->activatedAt()) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $target = Uuid::isValid($targetUserId) ? $this->users->find(Uuid::fromString($targetUserId)) : null;
            $membership = null === $target ? null : $this->memberships->findOneByUserAndMunicipality($target, $municipality);
            if (null === $target || null === $membership || !$target->isActive() || !$membership->isActive() || !$membership->hasRole(MunicipalityRole::EventManager)) {
                throw new DomainRuleViolation('La personne désignée doit être un gestionnaire d\'événement actif de la commune.');
            }
            if (null !== $this->designations->findActive($participation->id(), $target->id(), lock: true)) {
                throw new DomainRuleViolation('Cette personne est déjà désignée pour cet événement.');
            }
            $now = $this->clock->now();
            $designation = new EventManagerDesignation($participation, $target, $actor, $now);
            $this->designations->add($designation);
            $this->audit->record(new AuditEvent($now, AuditAction::ArchiveManagerDesignated, AuditSubjectType::ArchiveDesignation, $designation->id(), $municipality->id(), AuditActor::user($actor->id()), null, [
                'participation' => $participation->id()->toRfc4122(),
                'user' => $target->id()->toRfc4122(),
            ]));

            return $designation;
        });
    }

    /**
     * @throws OperationNotAllowed
     * @throws DomainRuleViolation désignation déjà révoquée
     */
    public function revoke(User $actor, string $municipalityId, string $designationId): void
    {
        $this->policy->administrator($actor, $municipalityId, write: true);
        $this->transaction->run(function () use ($actor, $municipalityId, $designationId): void {
            $municipality = $this->municipalities->lock(Uuid::fromString($municipalityId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $this->policy->administrator($actor, $municipalityId, write: true);
            $designation = Uuid::isValid($designationId) ? $this->designations->find(Uuid::fromString($designationId)) : null;
            // Désignation d'une AUTRE commune ou inexistante : même refus uniforme.
            if (null === $designation || !$designation->participation()->municipality()->id()->equals($municipality->id())) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $now = $this->clock->now();
            $designation->revoke(RevocationCause::AdminRequest, $actor, $now);
            $this->audit->record(new AuditEvent($now, AuditAction::ArchiveManagerRevoked, AuditSubjectType::ArchiveDesignation, $designation->id(), $municipality->id(), AuditActor::user($actor->id()), null, [
                'participation' => $designation->participation()->id()->toRfc4122(),
                'user' => $designation->user()->id()->toRfc4122(),
                'cause' => RevocationCause::AdminRequest->value,
            ]));
        });
    }

    /**
     * Désignations (actives et historique) d'un événement pour la commune de l'administrateur, et gestionnaires pouvant être désignés.
     *
     * @return array{designations: list<EventManagerDesignation>, candidates: list<User>}
     *
     * @throws OperationNotAllowed
     */
    public function forEvent(User $actor, string $municipalityId, string $eventId): array
    {
        $membership = $this->policy->administrator($actor, $municipalityId, write: false);
        $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
        $participation = null === $event ? null : $this->participations->findByEventAndMunicipality($event->id(), $membership->municipality()->id());
        if (null === $participation || null === $participation->activatedAt()) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $designations = $this->designations->forParticipation($participation->id());
        $already = [];
        foreach ($designations as $d) {
            if ($d->isActive()) {
                $already[$d->user()->id()->toRfc4122()] = true;
            }
        }
        $candidates = [];
        foreach ($this->memberships->findByMunicipality($membership->municipality()) as $m) {
            if ($m->isActive() && $m->user()->isActive() && $m->hasRole(MunicipalityRole::EventManager) && !isset($already[$m->user()->id()->toRfc4122()])) {
                $candidates[] = $m->user();
            }
        }

        return ['designations' => $designations, 'candidates' => $candidates];
    }
}
