<?php

declare(strict_types=1);

namespace App\Tests\Functional\Control;

use App\Control\Domain\ControlDevice;
use App\Control\Domain\ControlDeviceRepository;
use App\Tests\Support\ControlEnv;
use App\Tests\Support\ControlTestCase;
use App\Tests\Support\ParallelPhp;

/**
 * Concurrence RÉELLE sur MySQL (processus distincts) : un code à usage unique ne donne qu'UN appareil ; deux approbations simultanées du même appareil, une seule
 * réussit ; le compteur d'usage ne dépasse jamais le plafond.
 */
final class ControlConcurrencyTest extends ControlTestCase
{
    use ControlEnv;

    protected function tearDown(): void
    {
        $this->restoreControlEnv();
        parent::tearDown();
    }

    public function testASingleUseCodeRegistersExactlyOneDeviceUnderConcurrency(): void
    {
        $this->useClock(new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        $issued = $this->activations->issue($this->org, $this->event->id(), $this->user($this->adminO), \App\Control\Domain\AssuranceMode::Standard, 1, null, null);

        $results = ParallelPhp::run('control-race.php', array_fill(0, 4, ['register', $issued->code->value()]));

        $ok = array_filter($results, static fn (array $r): bool => str_contains($r['stdout'], '"ok":true'));
        self::assertCount(1, $ok, json_encode($results, \JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->rows('control_device'));
        self::assertSame(1, (int) $this->scalar('SELECT uses_count FROM control_activation'));
    }

    public function testTwoSimultaneousApprovalsOfTheSameDeviceYieldASingleWinner(): void
    {
        $issued = $this->activations->issue($this->org, $this->event->id(), $this->user($this->adminO), \App\Control\Domain\AssuranceMode::Standard, 3, null, null, null);
        $registered = $this->registration->register($issued->code->value(), 'Barrage', 'ANDROID', null, 'v4:198.51.100.5');
        $this->em()->clear();
        $device = static::getContainer()->get(ControlDeviceRepository::class)->find($registered->device->id()) ?? self::fail();
        self::assertInstanceOf(ControlDevice::class, $device);
        $args = ['approve', $device->id()->toRfc4122(), (string) $device->pairingCode(), $this->adminO->id()->toRfc4122(), $this->org->id()->toRfc4122()];

        $results = ParallelPhp::run('control-race.php', [$args, $args, $args]);

        $ok = array_filter($results, static fn (array $r): bool => str_contains($r['stdout'], '"ok":true'));
        self::assertCount(1, $ok, json_encode($results, \JSON_THROW_ON_ERROR));
        self::assertSame('ACTIVE', $this->scalar('SELECT status FROM control_device'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'CONTROL_DEVICE_APPROVED'"), 'un seul audit d’approbation');
    }
}
