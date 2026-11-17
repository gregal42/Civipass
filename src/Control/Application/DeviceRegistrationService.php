<?php

declare(strict_types=1);

namespace App\Control\Application;

use App\Control\Application\Result\RegisteredDevice;
use App\Control\Domain\ActivationCode;
use App\Control\Domain\ControlActivationRepository;
use App\Control\Domain\ControlDevice;
use App\Control\Domain\ControlDeviceRepository;
use App\Control\Domain\DeactivationReason;
use App\Control\Domain\DevicePlatform;
use App\Control\Domain\DeviceSecret;
use App\Control\Domain\DeviceStatus;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use Psr\Clock\ClockInterface;

/**
 * Enregistrement d'un appareil de contrôle avec un code d'activation (LOT 09, DM-131) : PUBLIC (aucun compte), protégé par le code (60 bits, 30 minutes, usage
 * atomique et borné) et par deux limiteurs par adresse (tous les essais ; les échecs). Tout refus est UNIFORME. L'appareil est créé `PENDING` : il n'a AUCUN accès
 * aux données tant qu'un administrateur ne l'a pas approuvé. L'usage du code est consommé dans la MÊME transaction que la création : un échec (libellé invalide, plafond
 * d'appareils en attente) restitue l'usage. Le secret n'est remis qu'ici, une fois ; seule son empreinte est stockée. Un appareil qui change de profil présente son
 * ancien secret : l'ancien enregistrement est désactivé (fin d'utilisation).
 */
final readonly class DeviceRegistrationService
{
    public function __construct(
        private TransactionRunner $transaction,
        private ControlActivationRepository $activations,
        private ControlDeviceRepository $devices,
        private ControlThrottle $throttle,
        private ControlSettings $settings,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RegistrationRefused|RegistrationThrottled|DomainRuleViolation|ControlStorageUnavailable
     */
    public function register(#[\SensitiveParameter] string $rawCode, string $label, string $platform, #[\SensitiveParameter] ?string $previousSecret, string $clientKey): RegisteredDevice
    {
        if (null !== ($wait = $this->throttle->registrationAccess($clientKey))) {
            throw new RegistrationThrottled($wait);
        }
        $code = ActivationCode::tryFromInput($rawCode);
        if (null === $code) {
            throw $this->failure($clientKey);
        }
        $secret = DeviceSecret::generate();
        $pairing = ControlDevice::newPairingCode();
        $previousHash = null === $previousSecret ? null : DeviceSecret::tryFromValue($previousSecret)?->hash();

        try {
            $device = $this->transaction->run(function () use ($code, $label, $platform, $secret, $pairing, $previousHash): ControlDevice {
                $now = $this->clock->now();
                $activationId = $this->activations->consumeUse($code->hash(), $now) ?? throw RegistrationRefused::uniform();
                $activation = $this->activations->find($activationId) ?? throw RegistrationRefused::uniform();
                $scope = $activation->municipality()->id();
                if ($this->devices->countPending($scope, $activation->event()->id(), $now) >= ControlSettings::MAX_PENDING_DEVICES) {
                    throw RegistrationRefused::uniform(); // plafond d'appareils en attente : l'usage consommé est restitué par l'annulation de la transaction
                }
                $device = new ControlDevice($activation, $label, DevicePlatform::fromDeclared($platform), $secret->hash(), $pairing, $now, $now->add(new \DateInterval(sprintf('PT%dM', $this->settings->pendingMinutes))));
                $this->devices->add($device);
                $this->audit->record(new AuditEvent($now, AuditAction::ControlDeviceRegistered, AuditSubjectType::ControlDevice, $device->id(), $scope, AuditActor::public(), null, [
                    'activationId' => $activationId->toRfc4122(),
                    'platform' => $device->platform()->value,
                    'state' => DeviceStatus::Pending->value,
                ]));
                $this->supersede($previousHash, $now);

                return $device;
            });
        } catch (RegistrationRefused) {
            throw $this->failure($clientKey);
        }

        return new RegisteredDevice($device, $secret);
    }

    /** Désactive l'ancien enregistrement de CET appareil (il en change : « fin d'utilisation »), sans jamais révéler s'il existait. */
    private function supersede(?string $previousHash, \DateTimeImmutable $now): void
    {
        if (null === $previousHash) {
            return;
        }
        $old = $this->devices->findBySecretHash($previousHash);
        if (null !== $old && $this->devices->deactivateIfActive($old->id(), null, DeactivationReason::EndOfUse, $now)) {
            $this->audit->record(new AuditEvent($now, AuditAction::ControlDeviceDeactivated, AuditSubjectType::ControlDevice, $old->id(), $old->activation()->municipality()->id(), AuditActor::public(), null, ['reason' => DeactivationReason::EndOfUse->value]));
        }
    }

    private function failure(string $clientKey): \Throwable
    {
        $wait = $this->throttle->registrationFailure($clientKey);

        return null === $wait ? RegistrationRefused::uniform() : new RegistrationThrottled($wait);
    }
}
