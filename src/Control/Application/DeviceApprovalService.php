<?php

declare(strict_types=1);

namespace App\Control\Application;

use App\Control\Domain\ControlDevice;
use App\Control\Domain\ControlDeviceRepository;
use App\Control\Domain\DeactivationReason;
use App\Control\Domain\DeviceStatus;
use App\Event\Domain\Event;
use App\Identity\Domain\User;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
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
 * Approbation, désactivation et renouvellement des appareils de contrôle (LOT 09, DM-131) par un ADMINISTRATEUR de la commune de l'appareil. L'approbation exige de
 * saisir le CODE D'APPARIEMENT affiché sur le téléphone (on n'approuve pas l'appareil d'un tiers qui porte le même libellé) et est ATOMIQUE : deux approbations
 * simultanées, une seule réussit. Aucune donnée n'est servie à un appareil non approuvé. Chaque cible est relue et DOIT appartenir à la commune de l'URL (refus
 * uniforme sinon). Le droit est relu sous le verrou de la commune. Fin de vie d'un appareil : `approbation + CONTROL_DEVICE_LIFETIME_HOURS`, bornée par la fin
 * planifiée de l'événement.
 */
final readonly class DeviceApprovalService
{
    public function __construct(
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private ControlDeviceRepository $devices,
        private MembershipRepository $memberships,
        private MembershipAccessPolicy $policy,
        private ControlScope $scope,
        private ControlSettings $settings,
        private ControlThrottle $throttle,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OperationNotAllowed|DomainRuleViolation|ControlStorageUnavailable
     */
    public function approve(Municipality $municipality, Uuid $deviceId, #[\SensitiveParameter] string $pairingCode, User $actor): void
    {
        $this->assertAdministrator($actor, $municipality);
        $device = $this->owned($municipality, $deviceId);
        if (null !== $this->throttle->pairingAttempt($actor->id()->toRfc4122(), $deviceId->toRfc4122())) {
            throw new DomainRuleViolation('Trop de tentatives. Réessayez dans quelques minutes.');
        }
        $code = preg_replace('/\s+/', '', $pairingCode) ?? '';
        $municipalityId = $municipality->id();
        $event = $device->activation()->event();
        $this->transaction->run(function () use ($municipalityId, $deviceId, $code, $actor, $event): void {
            $locked = $this->municipalities->lock($municipalityId) ?? throw new OperationNotAllowed('Commune introuvable.');
            $this->assertAdministratorLocked($actor, $locked);
            $this->scope->assertOperational($locked, $event);
            $now = $this->clock->now();
            if (!$this->devices->approve($deviceId, $code, $actor->id(), $now, $this->lifetimeEnd($event, $now))) {
                throw new DomainRuleViolation('Code d’appariement incorrect, appareil déjà traité ou délai d’approbation dépassé.');
            }
            $this->audit->record(new AuditEvent($now, AuditAction::ControlDeviceApproved, AuditSubjectType::ControlDevice, $deviceId, $municipalityId, AuditActor::user($actor->id()), ['state' => DeviceStatus::Pending->value], [
                'state' => DeviceStatus::Active->value,
                'expiresAt' => $this->lifetimeEnd($event, $now)->format(\DATE_ATOM),
            ]));
        });
    }

    /**
     * @throws OperationNotAllowed|DomainRuleViolation
     */
    public function deactivateDevice(Municipality $municipality, Uuid $deviceId, DeactivationReason $reason, User $actor): void
    {
        $this->assertAdministrator($actor, $municipality);
        $device = $this->owned($municipality, $deviceId);
        if (DeactivationReason::Rejected === $reason && DeviceStatus::Pending !== $device->status()) {
            throw new DomainRuleViolation('Seul un appareil en attente peut être refusé.');
        }
        $municipalityId = $municipality->id();
        $this->transaction->run(function () use ($municipalityId, $deviceId, $reason, $actor): void {
            $locked = $this->municipalities->lock($municipalityId) ?? throw new OperationNotAllowed('Commune introuvable.');
            // Désactiver coupe l'accès : permis à un administrateur même si la commune est suspendue ? Non : DM-034 (lecture seule), comme les autres écritures.
            $this->assertAdministratorLocked($actor, $locked);
            $now = $this->clock->now();
            if (!$this->devices->deactivateIfActive($deviceId, $actor->id(), $reason, $now)) {
                throw new DomainRuleViolation('Cet appareil est déjà désactivé.');
            }
            $this->audit->record(new AuditEvent($now, AuditAction::ControlDeviceDeactivated, AuditSubjectType::ControlDevice, $deviceId, $municipalityId, AuditActor::user($actor->id()), null, ['reason' => $reason->value]));
        });
    }

    /**
     * @throws OperationNotAllowed|DomainRuleViolation
     */
    public function renew(Municipality $municipality, Uuid $deviceId, User $actor): void
    {
        $this->assertAdministrator($actor, $municipality);
        $device = $this->owned($municipality, $deviceId);
        $municipalityId = $municipality->id();
        $event = $device->activation()->event();
        $this->transaction->run(function () use ($municipalityId, $deviceId, $actor, $event): void {
            $locked = $this->municipalities->lock($municipalityId) ?? throw new OperationNotAllowed('Commune introuvable.');
            $this->assertAdministratorLocked($actor, $locked);
            $this->scope->assertOperational($locked, $event);
            $now = $this->clock->now();
            $until = $this->lifetimeEnd($event, $now);
            if ($until <= $now || !$this->devices->renew($deviceId, $until)) {
                throw new DomainRuleViolation('Cet appareil ne peut pas être renouvelé (non approuvé, désactivé, ou événement terminé).');
            }
            $this->audit->record(new AuditEvent($now, AuditAction::ControlDeviceRenewed, AuditSubjectType::ControlDevice, $deviceId, $municipalityId, AuditActor::user($actor->id()), null, ['expiresAt' => $until->format(\DATE_ATOM)]));
        });
    }

    private function lifetimeEnd(Event $event, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $end = $now->add(new \DateInterval(sprintf('PT%dH', $this->settings->deviceLifetimeHours)));
        $planned = $event->endsAt();

        return null !== $planned && $planned < $end ? $planned : $end;
    }

    private function owned(Municipality $municipality, Uuid $deviceId): ControlDevice
    {
        $device = $this->devices->find($deviceId);
        if (null === $device || !$device->activation()->municipality()->id()->equals($municipality->id())) {
            throw new OperationNotAllowed('Ressource inaccessible.'); // identifiant forgé ou d'une autre commune
        }

        return $device;
    }

    private function assertAdministrator(User $actor, Municipality $municipality): void
    {
        if (!$this->policy->hasRole($actor, $municipality, MunicipalityRole::MunicipalityAdmin)) {
            throw new OperationNotAllowed('Droit insuffisant pour gérer les appareils de contrôle de cette commune.');
        }
    }

    private function assertAdministratorLocked(User $actor, Municipality $locked): void
    {
        if (!$this->memberships->isEffectiveAdmin($actor, $locked)) {
            throw new OperationNotAllowed('Droit insuffisant pour gérer les appareils de contrôle de cette commune.');
        }
    }
}
