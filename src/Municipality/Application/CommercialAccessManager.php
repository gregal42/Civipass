<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityCommercialAccessRepository;
use App\Municipality\Domain\MunicipalityRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;

/**
 * Attribution, renouvellement et révocation manuels des accès commerciaux (DM-076, DM-078, DM-079, DT-030) : superadmin actif
 * uniquement, commune verrouillée (deux opérations simultanées se sérialisent), audit dans la même transaction.
 *
 * Un accès ne conditionne QUE la création de NOUVEAUX événements (EventCreationPolicy, DM-073) : l'expiration ou la révocation
 * n'interrompt jamais un événement ouvert. Pas d'état « en pause » (A4) : révoquer, puis éventuellement attribuer à nouveau.
 * Le motif est conservé sur l'accès (jamais dans l'audit : DT-037).
 */
final readonly class CommercialAccessManager
{
    public function __construct(
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private MunicipalityCommercialAccessRepository $accesses,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Nouvel accès, à compter de maintenant. Refusé s'il existe déjà un accès en cours ou programmé (renouveler à la place).
     */
    public function grant(Municipality $municipality, ?\DateTimeImmutable $expiresAt, string $reason, User $actor): MunicipalityCommercialAccess
    {
        $this->assertSuperAdmin($actor);

        return $this->transaction->run(function () use ($municipality, $expiresAt, $reason, $actor): MunicipalityCommercialAccess {
            $municipality = $this->lock($municipality);
            $now = $this->clock->now();
            if ([] !== $this->open($municipality, $now)) {
                throw new DomainRuleViolation('Un accès commercial est déjà en cours ou programmé : renouvelez-le ou révoquez-le.');
            }
            $access = new MunicipalityCommercialAccess($municipality, $actor, $now, $expiresAt, $reason, $now);
            $this->accesses->add($access);
            $this->record(AuditAction::CommercialAccessGranted, $access, $actor, $now);

            return $access;
        });
    }

    /**
     * Accès SUCCESSEUR : il commence à l'échéance de l'accès en cours (sans trou ni chevauchement). Exige un accès en cours à
     * échéance finie ; sinon il n'y a rien à renouveler (illimité) ou il faut attribuer un nouvel accès (échu, révoqué).
     */
    public function renew(Municipality $municipality, ?\DateTimeImmutable $expiresAt, string $reason, User $actor): MunicipalityCommercialAccess
    {
        $this->assertSuperAdmin($actor);

        return $this->transaction->run(function () use ($municipality, $expiresAt, $reason, $actor): MunicipalityCommercialAccess {
            $municipality = $this->lock($municipality);
            $now = $this->clock->now();
            $open = $this->open($municipality, $now);
            if ([] === $open) {
                throw new DomainRuleViolation('Aucun accès commercial en cours : attribuez-en un nouveau.');
            }
            $start = $now;
            foreach ($open as $access) {
                $end = $access->expiresAt() ?? throw new DomainRuleViolation('L\'accès en cours est sans date d\'expiration : il n\'y a rien à renouveler.');
                $start = $end > $start ? $end : $start;
            }
            $successor = new MunicipalityCommercialAccess($municipality, $actor, $start, $expiresAt, $reason, $now);
            $this->accesses->add($successor);
            $this->record(AuditAction::CommercialAccessRenewed, $successor, $actor, $now);

            return $successor;
        });
    }

    public function revoke(MunicipalityCommercialAccess $access, User $actor): void
    {
        $this->assertSuperAdmin($actor);
        $this->transaction->run(function () use ($access, $actor): void {
            $this->lock($access->municipality());
            $fresh = $this->accesses->find($access->id()) ?? throw new OperationNotAllowed('Accès commercial introuvable.');
            $now = $this->clock->now();
            if (null !== $fresh->expiresAt() && $fresh->expiresAt() <= $now && !$fresh->isRevoked()) {
                throw new DomainRuleViolation('Cet accès commercial est déjà échu : rien à révoquer.');
            }
            $fresh->revoke($actor, $now);
            $this->record(AuditAction::CommercialAccessRevoked, $fresh, $actor, $now);
        });
    }

    /**
     * Accès non révoqués et non échus (en cours OU programmés).
     *
     * @return list<MunicipalityCommercialAccess>
     */
    private function open(Municipality $municipality, \DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->accesses->findByMunicipality($municipality->id()),
            static fn (MunicipalityCommercialAccess $a): bool => !$a->isRevoked() && (null === $a->expiresAt() || $a->expiresAt() > $now),
        ));
    }

    private function lock(Municipality $municipality): Municipality
    {
        return $this->municipalities->lock($municipality->id()) ?? throw new OperationNotAllowed('Commune introuvable.');
    }

    private function assertSuperAdmin(User $actor): void
    {
        if (!$actor->isActive() || !$actor->isSuperAdmin()) {
            throw new OperationNotAllowed('Réservé à un super-administrateur CWS actif.');
        }
    }

    private function record(AuditAction $action, MunicipalityCommercialAccess $access, User $actor, \DateTimeImmutable $now): void
    {
        $new = ['grantedAt' => $access->grantedAt()->format(\DATE_ATOM), 'expiresAt' => $access->expiresAt()?->format(\DATE_ATOM)];
        if (AuditAction::CommercialAccessRevoked === $action) {
            $new = ['revokedAt' => $access->revokedAt()?->format(\DATE_ATOM)];
        }
        $this->audit->record(new AuditEvent($now, $action, AuditSubjectType::MunicipalityCommercialAccess, $access->id(), $access->municipality()->id(), AuditActor::user($actor->id()), null, $new));
    }
}
