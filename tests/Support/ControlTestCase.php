<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Control\Application\ControlActivationService;
use App\Control\Application\ControlSettings;
use App\Control\Application\DeviceApprovalService;
use App\Control\Application\DeviceSyncService;
use App\Control\Application\DeviceRegistrationService;
use App\Control\Application\Result\IssuedActivationCode;
use App\Control\Application\Result\RegisteredDevice;
use App\Control\Domain\AssuranceMode;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use Symfony\Component\Clock\MockClock;

/**
 * Monde de test du contrôle hors connexion (LOT 09) : l'événement actif et les deux communes participantes du parcours public, plus un administrateur et un
 * instructeur de la commune organisatrice, un administrateur de la commune partenaire et un administrateur d'une commune ÉTRANGÈRE. L'événement couvre la période
 * courante (les appareils sont bornés par sa fin). Horloge de test commune à tous les services construits ici.
 */
abstract class ControlTestCase extends PublicRequestTestCase
{
    protected MockClock $clock;
    protected Municipality $org;
    protected Municipality $partner;
    protected Municipality $foreign;
    protected User $adminO;
    protected User $instructorO;
    protected User $adminP;
    protected User $adminX;
    protected ControlActivationService $activations;
    protected DeviceRegistrationService $registration;
    protected DeviceApprovalService $approvals;
    protected DeviceSyncService $syncer;

    protected function setUp(): void
    {
        parent::setUp();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->org = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail();
        $this->partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail();
        $this->foreign = MunicipalityBuilder::aMunicipality()->withInseeCode('13055')->build();
        $this->adminO = UserBuilder::aUser()->build();
        $this->instructorO = UserBuilder::aUser()->build();
        $this->adminP = UserBuilder::aUser()->build();
        $this->adminX = UserBuilder::aUser()->build();
        $this->save(
            $this->foreign, $this->adminO, $this->instructorO, $this->adminP, $this->adminX,
            MembershipBuilder::aMembership($this->adminO, $this->org)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->instructorO, $this->org)->withRoles(MunicipalityRole::Instructor)->build(),
            MembershipBuilder::aMembership($this->adminP, $this->partner)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->adminX, $this->foreign)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
        );
        $this->useClock(new MockClock($now->format('Y-m-d H:i:s').' UTC'));
    }

    protected function useClock(MockClock $clock, ?ControlSettings $settings = null): void
    {
        $this->clock = $clock;
        $c = static::getContainer();
        $this->activations = ControlKit::activations($c, $clock, $settings);
        $this->registration = ControlKit::registration($c, $clock, $settings);
        $this->approvals = ControlKit::approvals($c, $clock, $settings);
        $this->syncer = ControlKit::sync($c, $clock);
    }

    /** Réinitialise l'EntityManager : après des mises à jour conditionnelles, les entités lues avant sont périmées. */
    protected function fresh(): void
    {
        $this->em()->clear();
        $this->org = $this->em()->find(Municipality::class, $this->org->id()) ?? self::fail();
        $this->partner = $this->em()->find(Municipality::class, $this->partner->id()) ?? self::fail();
    }

    protected function user(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail();
    }

    protected function issue(?User $actor = null, ?Municipality $municipality = null, AssuranceMode $mode = AssuranceMode::Standard, int $maxDevices = 3, ?int $warn = null, ?int $hard = null, ?int $minutes = null): IssuedActivationCode
    {
        return $this->activations->issue($municipality ?? $this->org, $this->event->id(), $this->user($actor ?? $this->adminO), $mode, $maxDevices, $warn, $hard, $minutes);
    }

    protected function register(string $code, string $label = 'Barrage Nord', string $platform = 'ANDROID', string $client = 'v4:198.51.100.10', ?string $previousSecret = null): RegisteredDevice
    {
        return $this->registration->register($code, $label, $platform, $previousSecret, $client);
    }

    /**
     * Enregistre PUIS approuve un appareil de la commune (administrateur de la commune), et le retourne relu.
     *
     * @return array{0: \App\Control\Domain\ControlDevice, 1: string} l'appareil et son secret en clair
     */
    protected function activeDevice(?Municipality $municipality = null, ?User $admin = null, string $label = 'Barrage Nord', AssuranceMode $mode = AssuranceMode::Standard, ?int $hard = null, ?int $warn = null): array
    {
        $municipality ??= $this->org;
        $admin ??= $this->adminO;
        $issued = $this->issue($admin, $municipality, $mode, 5, $warn, $hard);
        $registered = $this->register($issued->code->display(), $label);
        $this->approvals->approve($municipality, $registered->device->id(), (string) $registered->device->pairingCode(), $this->user($admin));
        $this->fresh();
        $device = static::getContainer()->get(\App\Control\Domain\ControlDeviceRepository::class)->find($registered->device->id()) ?? self::fail();

        return [$device, $registered->secret->reveal()];
    }

    /** @param array<int|string, mixed> $params */
    protected function scalar(string $sql, array $params = []): mixed
    {
        return $this->em()->getConnection()->fetchOne($sql, $params);
    }
}
