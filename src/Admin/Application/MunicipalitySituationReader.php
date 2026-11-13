<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Municipality\Application\EventCreationPolicy;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityCommercialAccessRepository;
use App\Onboarding\Domain\MunicipalityAdminInvitationRepository;
use Psr\Clock\ClockInterface;

/**
 * Lecture seule : agrégats de supervision d'une commune (aucune donnée opérationnelle : ni événement, ni demande, ni laissez-passer).
 */
final readonly class MunicipalitySituationReader
{
    public function __construct(
        private EventCreationPolicy $policy,
        private MunicipalityCommercialAccessRepository $accesses,
        private MembershipRepository $memberships,
        private MunicipalityAdminInvitationRepository $invitations,
        private ClockInterface $clock,
    ) {
    }

    public function read(Municipality $municipality): MunicipalitySituation
    {
        $now = $this->clock->now();
        $current = null;
        $scheduled = null;
        foreach ($this->accesses->findByMunicipality($municipality->id()) as $access) {
            if ($access->isValidAt($now)) {
                $current ??= $access;
            } elseif (!$access->isRevoked() && $access->grantedAt() > $now && self::isOpen($access, $now)) {
                $scheduled ??= $access;
            }
        }

        return new MunicipalitySituation(
            $municipality->status(),
            $this->policy->canCreateEvent($municipality, $now),
            $current,
            $scheduled,
            $this->memberships->countEffectiveAdmins($municipality),
            $this->invitations->countPending($municipality->id(), $now),
        );
    }

    private static function isOpen(MunicipalityCommercialAccess $access, \DateTimeImmutable $now): bool
    {
        return null === $access->expiresAt() || $access->expiresAt() > $now;
    }
}
