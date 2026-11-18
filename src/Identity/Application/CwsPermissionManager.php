<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\CwsPermission;
use App\Identity\Domain\CwsPermissionGrant;
use App\Identity\Domain\CwsPermissionGrantRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use Psr\Clock\ClockInterface;

/**
 * Attribution et révocation des habilitations CWS (LOT 08.6, DM-139). Une seule porte d'écriture : transaction, verrou des comptes dans l'ordre de leurs
 * identifiants (pas d'interblocage), invariants du domaine, audit dans la même transaction. Appelé par les commandes console (aucun écran en V1). L'attributeur
 * est TOUJOURS désigné et distinct du bénéficiaire ; l'origine d'audit est la console, l'attributeur figure dans la nouvelle valeur.
 */
final readonly class CwsPermissionManager
{
    public function __construct(
        private TransactionRunner $transaction,
        private UserRepository $users,
        private CwsPermissionGrantRepository $grants,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DomainRuleViolation auto-attribution, comptes non habilitables, motif invalide, attribution déjà active
     */
    public function grant(User $beneficiary, CwsPermission $permission, User $grantedBy, string $reason): CwsPermissionGrant
    {
        return $this->transaction->run(function () use ($beneficiary, $permission, $grantedBy, $reason): CwsPermissionGrant {
            [$beneficiary, $grantedBy] = $this->lockBoth($beneficiary, $grantedBy);
            if (null !== $this->grants->lockActive($beneficiary->id(), $permission)) {
                throw new DomainRuleViolation('Cette habilitation est déjà active pour ce compte.');
            }
            $now = $this->clock->now();
            $grant = CwsPermissionGrant::grant($beneficiary, $permission, $grantedBy, $reason, $now);
            $this->grants->add($grant);
            $this->audit->record(new AuditEvent($now, AuditAction::CwsPermissionGranted, AuditSubjectType::CwsPermission, $grant->id(), null, AuditActor::console(), null, [
                'beneficiary' => $beneficiary->id()->toRfc4122(),
                'permission' => $permission->value,
                'grantedBy' => $grantedBy->id()->toRfc4122(),
                'reason' => $grant->reason(),
            ]));

            return $grant;
        });
    }

    /**
     * @throws DomainRuleViolation aucune attribution active, opérateur non habilité
     */
    public function revoke(User $beneficiary, CwsPermission $permission, User $revokedBy): void
    {
        $this->transaction->run(function () use ($beneficiary, $permission, $revokedBy): void {
            [$beneficiary, $revokedBy] = $this->lockBoth($beneficiary, $revokedBy, requireBeneficiaryActive: false);
            $grant = $this->grants->lockActive($beneficiary->id(), $permission) ?? throw new DomainRuleViolation('Aucune habilitation active à révoquer pour ce compte.');
            $now = $this->clock->now();
            $grant->revoke($revokedBy, $now);
            $this->audit->record(new AuditEvent($now, AuditAction::CwsPermissionRevoked, AuditSubjectType::CwsPermission, $grant->id(), null, AuditActor::console(), ['active' => true], [
                'beneficiary' => $beneficiary->id()->toRfc4122(),
                'permission' => $permission->value,
                'revokedBy' => $revokedBy->id()->toRfc4122(),
            ]));
        });
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function lockBoth(User $beneficiary, User $operator, bool $requireBeneficiaryActive = true): array
    {
        $ids = [$beneficiary->id()->toRfc4122() => $beneficiary, $operator->id()->toRfc4122() => $operator];
        ksort($ids);
        $locked = [];
        foreach ($ids as $key => $user) {
            $locked[$key] = $this->users->lock($user);
        }
        $b = $locked[$beneficiary->id()->toRfc4122()];
        $o = $locked[$operator->id()->toRfc4122()];
        if (!$o->isActive() || !$o->isSuperAdmin()) {
            throw new DomainRuleViolation('L’opérateur doit être un super-administrateur actif.');
        }
        if ($requireBeneficiaryActive && (!$b->isActive() || !$b->isSuperAdmin())) {
            throw new DomainRuleViolation('Le bénéficiaire doit être un super-administrateur actif.');
        }

        return [$b, $o];
    }
}
