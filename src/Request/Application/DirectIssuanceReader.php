<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityRole;
use App\Request\Application\Read\IssuedConfirmation;
use App\Request\Domain\DecisionPath;
use App\Request\Domain\PermitRequestRepository;
use App\Request\Domain\PermitRequestZone;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Accès à la confirmation d'une délivrance directe (DM-108) : règle EXPLICITE, distincte de la consultation des demandes (INSTRUCTOR).
 * Elle se relit à chaque affichage (rafraîchissement compris), en base, jamais d'après la seule présence de l'identifiant dans l'URL :
 *  1. adhésion effective de l'utilisateur à la commune de l'URL, avec un rôle habilité à délivrer (administrateur, gestionnaire ou
 *     instructeur) — sinon AgentRequestDenied (403) ;
 *  2. le dossier a une part dans CETTE commune, décidée par DÉLIVRANCE DIRECTE (une demande instruite ou en attente n'est jamais exposée) ;
 *  3. l'utilisateur est l'AGENT ÉMETTEUR de cette décision : un autre agent de la commune, même administrateur, ne la voit pas.
 * Tout autre cas reçoit le refus uniforme d'une ressource inexistante. Aucun droit de consultation générale n'en découle.
 */
final readonly class DirectIssuanceReader
{
    public function __construct(
        private MunicipalityContext $context,
        private PermitRequestRepository $requests,
    ) {
    }

    /**
     * @throws OperationNotAllowed commune ou ressource inaccessible (uniforme)
     * @throws AgentRequestDenied  rôle insuffisant
     */
    public function confirmation(User $user, string $municipalityId, string $requestId): IssuedConfirmation
    {
        $membership = $this->context->requireMembership($user, $municipalityId, write: false);
        if (!AgentRequestAccess::mayEnter($membership)) {
            throw new AgentRequestDenied('Rôle MUNICIPALITY_ADMIN, INSTRUCTOR ou EVENT_MANAGER requis.');
        }
        $municipality = $membership->municipality();
        $locator = Uuid::isValid($requestId) ? $this->requests->locatePart(Uuid::fromString($requestId), $municipality->id()) : null;
        $part = null === $locator ? null : $this->requests->findPart($locator->partId, $municipality->id());
        $decision = $part?->decision();
        if (null === $part || null === $decision || DecisionPath::DirectIssuance !== $decision->path() || !$decision->decidedBy()->id()->equals($user->id())) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $request = $part->request();
        $zones = array_map(static fn (PermitRequestZone $z): string => $z->zoneLabel(), $part->zones());
        sort($zones);

        return new IssuedConfirmation(
            $request->id()->toRfc4122(),
            $request->reference()->value,
            $request->event()->name(),
            $request->event()->timezone()->identifier(),
            $municipality->name(),
            trim($request->firstName().' '.$request->lastName()),
            $zones,
            $decision->outcome(),
            $decision->durationMode(),
            $decision->decidedAt(),
            $decision->validFrom(),
            $decision->validUntil(),
            trim($decision->decidedBy()->firstName().' '.$decision->decidedBy()->lastName()),
            $membership->hasRole(MunicipalityRole::Instructor),
            $membership->hasRole(MunicipalityRole::Instructor) || $membership->hasRole(MunicipalityRole::MunicipalityAdmin),
        );
    }
}
