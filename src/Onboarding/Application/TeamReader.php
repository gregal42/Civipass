<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Municipality\Application\RoleLabels;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Onboarding\Domain\MunicipalityAdminInvitationRepository;
use Psr\Clock\ClockInterface;

/**
 * Lecture de l'équipe d'UNE commune (LOT 07.2) : collaborateurs actifs et invitations. Ne lit que la commune passée en argument ;
 * l'appelant a déjà vérifié que l'utilisateur en est administrateur. Aucune donnée d'une autre commune.
 */
final readonly class TeamReader
{
    public const STATE_LABELS = ['PENDING' => 'En attente', 'EXPIRED' => 'Expirée', 'USED' => 'Acceptée', 'REVOKED' => 'Révoquée ou remplacée'];
    public const DELIVERY_LABELS = ['PENDING' => 'Non envoyé', 'SENT' => 'E-mail envoyé', 'FAILED' => 'Échec d’envoi'];

    public function __construct(
        private MembershipRepository $memberships,
        private MunicipalityAdminInvitationRepository $invitations,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{members: list<array<string, mixed>>, invitations: list<array<string, mixed>>, administrators: int}
     */
    public function read(Municipality $municipality): array
    {
        $now = $this->clock->now();
        $administrators = $this->memberships->countEffectiveAdmins($municipality);
        $members = [];
        foreach ($this->memberships->findByMunicipality($municipality) as $m) {
            if (!$m->isActive()) {
                continue;
            }
            $members[] = [
                'id' => $m->id()->toRfc4122(),
                'userId' => $m->user()->id()->toRfc4122(),
                'name' => trim($m->user()->firstName().' '.$m->user()->lastName()),
                'email' => $m->user()->email(),
                'roles' => RoleLabels::join($m->roles()),
                'roleValues' => array_map(static fn (MunicipalityRole $r): string => $r->value, $m->roles()),
                'lastLogin' => $m->user()->lastLoginAt(),
                'accountActive' => $m->user()->isActive(),
                'isAdministrator' => $m->hasRole(MunicipalityRole::MunicipalityAdmin),
                'lastAdministrator' => $this->isLastAdministrator($m, $administrators),
            ];
        }
        usort($members, static fn (array $a, array $b): int => [$b['isAdministrator'], mb_strtolower($a['name'])] <=> [$a['isAdministrator'], mb_strtolower($b['name'])]);

        $rows = [];
        foreach ($this->invitations->findByMunicipality($municipality) as $i) {
            $state = $i->stateAt($now);
            $rows[] = [
                'id' => $i->id()->toRfc4122(),
                'email' => $i->recipientEmail(),
                'roles' => RoleLabels::join($i->roles()),
                'state' => $state,
                'stateLabel' => self::STATE_LABELS[$state],
                'delivery' => self::DELIVERY_LABELS[$i->deliveryStatus()->value],
                'createdAt' => $i->createdAt(),
                'expiresAt' => $i->expiresAt(),
            ];
        }

        return ['members' => $members, 'invitations' => $rows, 'administrators' => $administrators];
    }

    private function isLastAdministrator(MunicipalityMembership $m, int $administrators): bool
    {
        return $m->hasRole(MunicipalityRole::MunicipalityAdmin) && $m->user()->isActive() && $administrators <= 1;
    }
}
