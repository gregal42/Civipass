<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Application\EventOperationalGuard;
use App\Event\Application\Support\EventLocker;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Permit\Application\Result\RevisionScope;
use App\Permit\Domain\PermitRepository;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Garde COMMUN des révisions des droits d'un laissez-passer (LOT 05.5.1, DM-119 §3) : une seule implémentation, utilisée par toutes les opérations
 * (révocation maintenant ; périodes, zones, plaques ensuite). Aucune confiance dans les identifiants de l'URL.
 *
 *  - `locate` (sans verrou) : adhésion effective à la commune, rôle INSTRUCTOR ou MUNICIPALITY_ADMIN (EVENT_MANAGER : aucune révision), demande
 *    existante dotée d'un laissez-passer, et surtout l'autorisation de LA PROPRE commune de l'agent. Une commune sans autorisation sur ce
 *    laissez-passer, une demande inconnue ou sans laissez-passer : le même refus uniforme.
 *  - `lock` (dans la transaction) : participation de la commune ACTIVE relue sous verrou (événement partagé → participation), adhésion et rôles
 *    relus, verrou du laissez-passer, autorisation relue SOUS VERROU (une révocation concurrente est vue). Ordre de verrous : événement →
 *    participation → laissez-passer → autorisation.
 *
 * Le garde ne décide pas si l'autorisation est révoquée : chaque opération le décide (la révocation est idempotente, les autres refusent).
 */
final readonly class PermitRevisionGuard
{
    public function __construct(
        private EventLocker $locker,
        private MunicipalityContext $context,
        private PermitRepository $permits,
        private EventOperationalGuard $closure,
    ) {
    }

    /**
     * @param bool $write     vrai : commune ACTIVE exigée (écriture) ; faux : consultation
     * @param bool $adminOnly vrai : MUNICIPALITY_ADMIN seul (remplacement du QR, LOT 05.5.5) ; INSTRUCTOR et EVENT_MANAGER refusés
     *
     * @throws OperationNotAllowed commune, demande ou laissez-passer inaccessible (refus uniforme)
     * @throws PermitAccessDenied  rôle INSTRUCTOR ou MUNICIPALITY_ADMIN absent
     */
    public function locate(User $agent, string $municipalityId, string $requestId, bool $write, bool $adminOnly = false): RevisionScope
    {
        $membership = $this->context->requireMembership($agent, $municipalityId, $write);
        self::assertRole($membership, $adminOnly);
        $permit = Uuid::isValid($requestId) ? $this->permits->findByRequest(Uuid::fromString($requestId)) : null;
        $own = $membership->municipality()->id();
        foreach (null === $permit ? [] : $permit->authorizations() as $authorization) {
            if ($authorization->municipality()->id()->equals($own)) {
                // Habilitation et portion communale vérifiées : un événement clos ne sert plus aucun droit (LOT 05.6.2).
                $this->closure->assertOpen($permit->event()->id());

                return new RevisionScope($membership, $permit, $authorization);
            }
        }

        throw new OperationNotAllowed('Ressource inaccessible.');
    }

    /**
     * À appeler DANS la transaction, avant toute écriture.
     *
     * @param list<Uuid> $zoneIds zones de l'événement concernées par l'opération (verrouillées, par identifiant croissant, avant le laissez-passer)
     *
     * @throws PermitAccessUnavailable participation de la commune non ACTIVE
     * @throws PermitAccessDenied      rôle retiré depuis le contrôle initial
     * @throws OperationNotAllowed     adhésion retirée depuis le contrôle initial
     */
    public function lock(RevisionScope $scope, User $agent, string $municipalityId, array $zoneIds = [], bool $adminOnly = false): MunicipalityMembership
    {
        $participation = $this->locker->participation($scope->authorization->participation()->id(), $this->locker->sharedEvent($scope->permit->event()->id()));
        if (ParticipationStatus::Active !== $participation->status()) {
            throw new PermitAccessUnavailable($participation->status()->value);
        }
        $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
        self::assertRole($membership, $adminOnly);
        // Zones (LOT 05.5.3) : verrouillées par identifiant CROISSANT, APRÈS la participation et AVANT le laissez-passer (ordre global : événement → participation →
        // zones → … → laissez-passer, celui des services de décision et de zones). Une zone étrangère, inexistante ou d'une autre participation : refus uniforme.
        $ids = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $zoneIds);
        sort($ids);
        foreach (array_unique($ids) as $id) {
            $this->locker->zone(Uuid::fromString($id), $participation);
        }
        $this->permits->lock($scope->permit->id());
        $this->permits->refreshAuthorization($scope->authorization);

        return $membership;
    }

    private static function assertRole(MunicipalityMembership $membership, bool $adminOnly = false): void
    {
        if ($adminOnly) {
            if (!$membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
                throw new PermitAccessDenied('Rôle MUNICIPALITY_ADMIN requis pour remplacer le QR code.');
            }

            return;
        }
        if (!$membership->hasRole(MunicipalityRole::Instructor) && !$membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
            throw new PermitAccessDenied('Rôle INSTRUCTOR ou MUNICIPALITY_ADMIN requis pour réviser les droits.');
        }
    }
}
