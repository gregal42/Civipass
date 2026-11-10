<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Domain\EventStatus;
use App\Permit\Application\Read\PublicAuthorization;
use App\Permit\Application\Read\PublicPeriod;
use App\Permit\Application\Read\PublicVerification;
use App\Permit\Application\Read\PublicZone;
use App\Permit\Application\Result\PublicVerificationLookup;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitVehicle;
use App\Permit\Domain\PublicVerdict;
use App\Permit\Domain\RightState;
use App\Permit\Domain\VerificationToken;
use App\Permit\Domain\ZoneRight;

/**
 * Lecture publique d'un laissez-passer par son jeton de vérification (LOT 05.4.1, DM-115), SANS compte. Lecture seule : aucune écriture,
 * aucun journal, aucune exception qui cite le jeton. Les droits sont ceux de PermitRightsEvaluator (mode vérification), jamais un second moteur.
 *
 * Jeton mal formé (`Malformed`, sans accès à la base) ou bien formé mais inconnu (`Unknown`) : deux résultats, une même réponse publique ; le compte des échecs seul les distingue. Jeton retiré : `Replaced`, sans donnée (DM-113). Événement clos : `EventClosed`, sans donnée.
 */
final readonly class PublicVerificationReader
{
    public function __construct(
        private PermitRepository $permits,
        private PermitRightsEvaluator $evaluator,
    ) {
    }

    public function find(#[\SensitiveParameter] string $token, \DateTimeImmutable $now): PublicVerificationLookup
    {
        $normalized = VerificationToken::normalize($token);
        if (null === $normalized) {
            return PublicVerificationLookup::malformed();
        }
        $found = $this->permits->findVerificationToken($normalized);
        if (null === $found) {
            return PublicVerificationLookup::unknown();
        }
        if (!$found->isActive()) {
            return PublicVerificationLookup::replaced();
        }
        $permit = $found->permit();
        // Événement DÉFINITIVEMENT clos (décision du 10 novembre 2026) : statut neutre seulement. Aucune donnée du laissez-passer n'est lue ni transmise (ni nom, ni plaque, ni zone,
        // ni référence, ni nom d'événement) : la règle est ICI, côté serveur, avant toute lecture des droits ; aucune autre représentation publique n'existe.
        if (EventStatus::Closed === $permit->event()->status()) {
            return PublicVerificationLookup::eventClosed();
        }
        $request = $permit->request();
        $rights = $this->evaluator->evaluate($permit, $now, true);
        $current = array_values(array_filter($rights->zones(), static fn (ZoneRight $z): bool => RightState::Removed !== $z->state));

        return PublicVerificationLookup::found(new PublicVerification(
            $permit->reference()->value,
            $permit->event()->name(),
            $rights->timezone,
            $request->lastName(),
            $request->firstName(),
            array_map(static fn (PermitVehicle $v): string => PlateFormatter::format($v), $permit->activeVehicles()),
            PublicVerdict::derive(array_map(static fn (ZoneRight $z): RightState => $z->state, $current)),
            $rights->validZoneCount(),
            \count($current),
            array_map(static fn ($a): PublicAuthorization => new PublicAuthorization(
                $a->municipalityName,
                $a->validFrom,
                $a->validUntil,
                $a->revokedAt,
                array_map(static fn (ZoneRight $z): PublicZone => new PublicZone(
                    $z->label,
                    $z->state,
                    array_map(static fn ($i): PublicPeriod => new PublicPeriod($i->start, $i->end), $z->periods),
                    $z->removedAt,
                ), $a->zones),
            ), $rights->authorizations),
            $now,
        ));
    }
}
