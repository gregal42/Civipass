<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Control\Application\ControlActivationService;
use App\Control\Application\ControlScope;
use App\Control\Application\ControlSettings;
use App\Control\Application\ControlThrottle;
use App\Control\Application\Dataset\DatasetBuilder;
use App\Control\Application\DeviceApprovalService;
use App\Control\Application\DeviceSyncService;
use App\Control\Application\DeviceRegistrationService;
use App\Control\Domain\ControlActivationRepository;
use App\Control\Domain\ControlDeviceRepository;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\MunicipalityRepository;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/** Services du contrôle hors connexion câblés avec une HORLOGE DE TEST (expiration des codes, délais d'approbation, durée de vie des appareils). */
final class ControlKit
{
    public static function settings(int $hardMax = 43_200, int $warn = 21_600, int $codeMinutes = 30, int $pendingMinutes = 60, int $deviceHours = 72): ControlSettings
    {
        return new ControlSettings(true, $hardMax, $warn, $codeMinutes, $pendingMinutes, $deviceHours);
    }

    public static function activations(ContainerInterface $c, ClockInterface $clock, ?ControlSettings $settings = null): ControlActivationService
    {
        return new ControlActivationService(
            $c->get(TransactionRunner::class),
            $c->get(MunicipalityRepository::class),
            $c->get(ControlActivationRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(MembershipAccessPolicy::class),
            $c->get(ControlScope::class),
            $settings ?? self::settings(),
            $c->get(ControlThrottle::class),
            $c->get(AuditTrail::class),
            $clock,
        );
    }

    public static function registration(ContainerInterface $c, ClockInterface $clock, ?ControlSettings $settings = null): DeviceRegistrationService
    {
        return new DeviceRegistrationService(
            $c->get(TransactionRunner::class),
            $c->get(ControlActivationRepository::class),
            $c->get(ControlDeviceRepository::class),
            $c->get(ControlThrottle::class),
            $settings ?? self::settings(),
            $c->get(AuditTrail::class),
            $clock,
        );
    }

    public static function approvals(ContainerInterface $c, ClockInterface $clock, ?ControlSettings $settings = null): DeviceApprovalService
    {
        return new DeviceApprovalService(
            $c->get(TransactionRunner::class),
            $c->get(MunicipalityRepository::class),
            $c->get(ControlDeviceRepository::class),
            $c->get(MembershipRepository::class),
            $c->get(MembershipAccessPolicy::class),
            $c->get(ControlScope::class),
            $settings ?? self::settings(),
            $c->get(ControlThrottle::class),
            $c->get(AuditTrail::class),
            $clock,
        );
    }

    public static function sync(ContainerInterface $c, ClockInterface $clock): DeviceSyncService
    {
        return new DeviceSyncService($c->get(DatasetBuilder::class), $c->get(ControlDeviceRepository::class), $c->get(ControlThrottle::class), $c->get(TransactionRunner::class), $c->get(AuditTrail::class), $clock);
    }
}
