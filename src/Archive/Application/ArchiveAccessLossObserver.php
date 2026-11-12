<?php

declare(strict_types=1);

namespace App\Archive\Application;

use App\Archive\Domain\EventManagerDesignationRepository;
use App\Archive\Domain\RevocationCause;
use App\Archive\Domain\StatisticsCoordinationGrantRepository;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Shared\Application\AccessLossObserver;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;

/**
 * Révocation EXPLICITE des habilitations d'archive et de coordination statistique quand un rôle ou une appartenance est perdu (LOT 05.6.3, option B, règle 5) : cause
 * tracée, définitive, jamais rétablie par le retour du rôle ou du compte. S'exécute dans la transaction de la modification d'appartenance ; le recontrôle à chaque
 * accès (`ArchiveAccessPolicy`, lecteur de bilan) reste la défense en profondeur si un chemin avait contourné ces crochets.
 */
final readonly class ArchiveAccessLossObserver implements AccessLossObserver
{
    public function __construct(
        private EventManagerDesignationRepository $designations,
        private StatisticsCoordinationGrantRepository $grants,
        private AuditTrail $audit,
    ) {
    }

    public function roleLost(User $user, Municipality $municipality, MunicipalityRole $role, ?User $actor, \DateTimeImmutable $now): void
    {
        if (MunicipalityRole::EventManager === $role) {
            $this->revokeInMunicipality($user, $municipality, RevocationCause::RoleLost, $actor, $now);
        }
    }

    public function membershipDeactivated(User $user, Municipality $municipality, ?User $actor, \DateTimeImmutable $now): void
    {
        $this->revokeInMunicipality($user, $municipality, RevocationCause::MembershipDeactivated, $actor, $now);
    }

    public function userDeactivated(User $user, ?User $actor, \DateTimeImmutable $now): void
    {
        foreach ($this->designations->activeForUser($user->id()) as $designation) {
            $this->revokeDesignation($designation, RevocationCause::UserDeactivated, $actor, $now);
        }
        foreach ($this->grants->activeForUser($user->id()) as $grant) {
            $this->revokeGrant($grant, RevocationCause::UserDeactivated, $actor, $now);
        }
    }

    private function revokeInMunicipality(User $user, Municipality $municipality, RevocationCause $cause, ?User $actor, \DateTimeImmutable $now): void
    {
        foreach ($this->designations->activeForUserInMunicipality($user->id(), $municipality->id()) as $designation) {
            $this->revokeDesignation($designation, $cause, $actor, $now);
        }
        foreach ($this->grants->activeForUserInOrganizer($user->id(), $municipality->id()) as $grant) {
            $this->revokeGrant($grant, $cause, $actor, $now);
        }
    }

    private function revokeDesignation(\App\Archive\Domain\EventManagerDesignation $designation, RevocationCause $cause, ?User $actor, \DateTimeImmutable $now): void
    {
        $designation->revoke($cause, $actor, $now);
        $this->audit->record(new AuditEvent($now, AuditAction::ArchiveManagerRevoked, AuditSubjectType::ArchiveDesignation, $designation->id(), $designation->participation()->municipality()->id(), null === $actor ? AuditActor::system() : AuditActor::user($actor->id()), null, [
            'participation' => $designation->participation()->id()->toRfc4122(),
            'user' => $designation->user()->id()->toRfc4122(),
            'cause' => $cause->value,
        ]));
    }

    private function revokeGrant(\App\Archive\Domain\StatisticsCoordinationGrant $grant, RevocationCause $cause, ?User $actor, \DateTimeImmutable $now): void
    {
        $grant->revoke($cause, $actor, $now);
        $this->audit->record(new AuditEvent($now, AuditAction::StatisticsCoordinationRevoked, AuditSubjectType::StatisticsCoordination, $grant->id(), $grant->event()->organizerMunicipality()->id(), null === $actor ? AuditActor::system() : AuditActor::user($actor->id()), null, [
            'event' => $grant->event()->id()->toRfc4122(),
            'user' => $grant->user()->id()->toRfc4122(),
            'cause' => $cause->value,
        ]));
    }
}
