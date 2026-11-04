<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Event\Domain\ParticipationStatus;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\MunicipalityStatus;
use App\Onboarding\Domain\InvitationLinkRepository;
use App\Onboarding\Domain\InvitationToken;
use App\Onboarding\Domain\LinkResolution;
use App\Onboarding\Domain\LinkStatus;
use Psr\Clock\ClockInterface;

/**
 * Analyse un jeton de lien d'invitation, en lecture seule : inconnu, expiré, utilisé, remplacé, plus actif ou utilisable. Aucune
 * écriture, aucun droit accordé. Un jeton inconnu ou mal formé ne déclenche aucune requête et ne révèle rien.
 */
final readonly class InvitationLinkResolver
{
    public function __construct(
        private InvitationLinkRepository $links,
        private MembershipRepository $memberships,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(string $plainToken): LinkResolution
    {
        if (!InvitationToken::looksValid($plainToken)) {
            return new LinkResolution(LinkStatus::Unknown);
        }
        $link = $this->links->findByTokenHash(InvitationToken::fromPlain($plainToken)->hash());
        if (null === $link) {
            return new LinkResolution(LinkStatus::Unknown);
        }
        $now = $this->clock->now();
        if (null !== $link->consumedAt()) {
            return new LinkResolution(LinkStatus::Used, $link);
        }
        if (null !== $link->revokedAt()) {
            return new LinkResolution(LinkStatus::Replaced, $link);
        }
        if ($link->isExpiredAt($now)) {
            return new LinkResolution(LinkStatus::Expired, $link);
        }
        $participation = $link->participation();
        $event = $participation->event();
        $municipality = $participation->municipality();
        if (ParticipationStatus::Invited !== $participation->status() || $event->status()->isClosed() || MunicipalityStatus::Active !== $municipality->status()) {
            return new LinkResolution(LinkStatus::Inactive, $link);
        }

        return new LinkResolution(
            LinkStatus::Usable,
            $link,
            $event->organizerMunicipality()->name(),
            $event->name(),
            $municipality->name(),
            $participation->invitationMessage(),
            $municipality->id()->toRfc4122(),
            $event->id()->toRfc4122(),
            $this->memberships->countEffectiveAdmins($municipality) > 0,
            $link->expiresAt(),
        );
    }
}
