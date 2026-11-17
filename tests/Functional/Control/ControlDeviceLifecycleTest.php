<?php

declare(strict_types=1);

namespace App\Tests\Functional\Control;

use App\Control\Application\RegistrationRefused;
use App\Control\Application\RegistrationThrottled;
use App\Control\Domain\ControlDevice;
use App\Control\Domain\ControlDeviceRepository;
use App\Control\Domain\DeactivationReason;
use App\Control\Domain\DeviceStatus;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ControlTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Enregistrement, approbation manuelle, désactivation et renouvellement des appareils (LOT 09.2, DM-131) : appareil en attente sans accès, code expiré/réutilisé/annulé,
 * refus uniformes, limiteurs, approbation atomique avec code d'appariement, isolation entre communes, audit et base sans secret en clair.
 */
final class ControlDeviceLifecycleTest extends ControlTestCase
{
    private function pending(string $label = 'Barrage Nord'): ControlDevice
    {
        $issued = $this->issue(maxDevices: 5);
        $registered = $this->register($issued->code->display(), $label);
        $this->fresh();

        return $this->devices()->find($registered->device->id()) ?? self::fail();
    }

    private function devices(): ControlDeviceRepository
    {
        return static::getContainer()->get(ControlDeviceRepository::class);
    }

    private function active(string $label = 'Barrage Nord'): ControlDevice
    {
        $device = $this->pending($label);
        $this->approvals->approve($this->org, $device->id(), (string) $device->pairingCode(), $this->user($this->adminO));
        $this->fresh();

        return $this->devices()->find($device->id()) ?? self::fail();
    }

    public function testARegisteredDeviceIsPendingAndHasNoAccess(): void
    {
        $issued = $this->issue();
        $registered = $this->register($issued->code->display());
        $this->fresh();
        $device = $this->devices()->find($registered->device->id()) ?? self::fail();

        self::assertSame(DeviceStatus::Pending, $device->status());
        self::assertFalse($device->isOperational($this->clock->now()), 'aucun accès aux données avant approbation');
        self::assertTrue($device->isAwaitingApproval($this->clock->now()));
        self::assertMatchesRegularExpression('/^[0-9]{6}$/', (string) $device->pairingCode());
        self::assertSame(43, \strlen($registered->secret->reveal()));
        self::assertNull($device->approvedAt());
        self::assertSame(3_600, $device->expiresAt()->getTimestamp() - $this->clock->now()->getTimestamp(), 'échéance d’approbation : 60 minutes');
    }

    public function testOnlyHashesAreStoredAndNoSecretReachesTheAuditOrTheLogs(): void
    {
        $issued = $this->issue();
        $registered = $this->register($issued->code->display());

        self::assertSame(hash('sha256', $registered->secret->reveal()), $this->scalar('SELECT secret_hash FROM control_device'));
        $dump = (string) json_encode([
            $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM control_device'),
            $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM control_activation'),
            $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM audit_log'),
        ], \JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertStringNotContainsString($registered->secret->reveal(), $dump);
        self::assertStringNotContainsString($issued->code->value(), $dump);
        self::assertStringNotContainsString($registered->device->pairingCode() ?? 'x', (string) json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM audit_log'), \JSON_INVALID_UTF8_SUBSTITUTE), 'le code d’appariement n’est pas audité');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'CONTROL_DEVICE_REGISTERED'"));
    }

    public function testTheCodeIsAcceptedWhateverItsFormatting(): void
    {
        $issued = $this->issue(maxDevices: 3);
        $this->register(strtolower($issued->code->display()));
        $this->register(str_replace('-', ' ', $issued->code->display()));
        self::assertSame(2, $this->rows('control_device'));
    }

    /** @return iterable<string, array{string}> */
    public static function badCodes(): iterable
    {
        yield 'vide' => [''];
        yield 'trop court' => ['ABCD'];
        yield 'inconnu' => ['ZZZZ-ZZZZ-ZZZZ'];
        yield 'caractères hors alphabet' => ['!!!!-????-****'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badCodes')]
    public function testUnknownOrMalformedCodesAreRefusedUniformly(string $code): void
    {
        $this->issue();
        try {
            $this->register($code);
            self::fail('Refus attendu.');
        } catch (RegistrationRefused $e) {
            self::assertSame('Code d’activation invalide, expiré ou déjà utilisé.', $e->getMessage());
        }
        self::assertSame(0, $this->rows('control_device'));
    }

    public function testExpiredExhaustedAndRevokedCodesGiveTheSameUniformRefusal(): void
    {
        $messages = [];
        // expiré
        $expired = $this->issue();
        $this->clock->modify('+31 minutes');
        try {
            $this->register($expired->code->display());
        } catch (RegistrationRefused $e) {
            $messages[] = $e->getMessage();
        }
        // épuisé (un seul appareil)
        $single = $this->issue(maxDevices: 1);
        $this->register($single->code->display());
        try {
            $this->register($single->code->display());
        } catch (RegistrationRefused $e) {
            $messages[] = $e->getMessage();
        }
        // annulé
        $revoked = $this->issue();
        $this->activations->revoke($this->org, $revoked->activation->id(), $this->user($this->adminO));
        try {
            $this->register($revoked->code->display());
        } catch (RegistrationRefused $e) {
            $messages[] = $e->getMessage();
        }

        self::assertCount(3, $messages);
        self::assertSame(1, \count(array_unique($messages)), 'aucun motif distinct n’est révélé');
        self::assertSame(1, $this->rows('control_device'));
        self::assertSame(1, (int) $this->scalar('SELECT uses_count FROM control_activation WHERE max_devices = 1'));
    }

    public function testRepeatedFailuresAreThrottledPerAddress(): void
    {
        $this->issue();
        $throttled = false;
        for ($i = 0; $i < 12; ++$i) {
            try {
                $this->register('ZZZZ-ZZZZ-ZZZZ', client: 'v4:203.0.113.9');
            } catch (RegistrationThrottled $e) {
                $throttled = true;
                self::assertGreaterThan(0, $e->retryAfterSeconds);
                break;
            } catch (RegistrationRefused) {
            }
        }
        self::assertTrue($throttled, 'au-delà de 10 échecs par quart d’heure : 429');
        // une autre adresse n'est pas touchée
        $this->expectException(RegistrationRefused::class);
        $this->register('ZZZZ-ZZZZ-ZZZZ', client: 'v4:203.0.113.77');
    }

    public function testAnInvalidLabelRestoresTheUseOfTheCode(): void
    {
        $issued = $this->issue(maxDevices: 1);
        try {
            $this->register($issued->code->display(), '   ');
            self::fail('Libellé vide refusé.');
        } catch (DomainRuleViolation) {
        }
        self::assertSame(0, (int) $this->scalar('SELECT uses_count FROM control_activation'), 'l’usage n’est pas consommé par un enregistrement refusé');
        self::assertSame(0, $this->rows('control_device'));
        $this->register($issued->code->display());
        self::assertSame(1, $this->rows('control_device'));
    }

    public function testPendingDevicesAreCappedPerCommuneAndTheUseIsRestored(): void
    {
        $codes = [$this->issue(maxDevices: 10), $this->issue(maxDevices: 10), $this->issue(maxDevices: 10)];
        for ($i = 0; $i < 20; ++$i) {
            $this->register($codes[intdiv($i, 10)]->code->display(), 'Appareil '.$i, client: 'v4:198.51.100.'.($i + 1));
        }
        try {
            $this->register($codes[2]->code->display(), 'Appareil de trop', client: 'v4:198.51.100.200');
            self::fail('Plafond d’appareils en attente : refus attendu.');
        } catch (RegistrationRefused) {
        }
        self::assertSame(20, $this->rows('control_device'));
        self::assertSame(0, (int) $this->scalar('SELECT uses_count FROM control_activation WHERE id = ?', [$codes[2]->activation->id()->toBinary()]), 'l’usage refusé est restitué (rollback de la transaction)');
    }

    public function testRegisteringWithAnOldSecretEndsTheOldRegistration(): void
    {
        $issued = $this->issue();
        $first = $this->register($issued->code->display(), 'Ancien');
        $second = $this->register($issued->code->display(), 'Nouveau', previousSecret: $first->secret->reveal());
        $this->fresh();

        self::assertSame('DEACTIVATED', $this->scalar('SELECT status FROM control_device WHERE id = ?', [$first->device->id()->toBinary()]));
        self::assertSame('END_OF_USE', $this->scalar('SELECT deactivation_reason FROM control_device WHERE id = ?', [$first->device->id()->toBinary()]));
        self::assertSame('PENDING', $this->scalar('SELECT status FROM control_device WHERE id = ?', [$second->device->id()->toBinary()]));
    }

    public function testApprovalWithThePairingCodeActivatesTheDevice(): void
    {
        $device = $this->pending();
        $this->approvals->approve($this->org, $device->id(), (string) $device->pairingCode(), $this->user($this->adminO));
        $this->fresh();
        $approved = $this->devices()->find($device->id()) ?? self::fail();

        self::assertSame(DeviceStatus::Active, $approved->status());
        self::assertNull($approved->pairingCode(), 'le code d’appariement est effacé');
        self::assertTrue($approved->isOperational($this->clock->now()));
        self::assertSame($this->adminO->id()->toRfc4122(), $approved->approvedBy()?->id()->toRfc4122());
        self::assertSame(72 * 3600, $approved->expiresAt()->getTimestamp() - $this->clock->now()->getTimestamp(), 'durée de vie : 72 h au pilote (bornée par la fin de l’événement)');
    }

    public function testTheLifetimeNeverExceedsThePlannedEndOfTheEvent(): void
    {
        $this->sql('UPDATE event_event SET ends_at = :e', ['e' => $this->clock->now()->modify('+5 hours')->format('Y-m-d H:i:s')]);
        $device = $this->pending();
        $this->approvals->approve($this->org, $device->id(), (string) $device->pairingCode(), $this->user($this->adminO));
        $this->fresh();

        self::assertSame(5 * 3600, ($this->devices()->find($device->id()) ?? self::fail())->expiresAt()->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    public function testAWrongPairingCodeApprovesNothing(): void
    {
        $device = $this->pending();
        $wrong = '000000' === $device->pairingCode() ? '111111' : '000000';
        try {
            $this->approvals->approve($this->org, $device->id(), $wrong, $this->user($this->adminO));
            self::fail('Code incorrect : refus attendu.');
        } catch (DomainRuleViolation) {
        }
        $this->fresh();
        self::assertSame(DeviceStatus::Pending, ($this->devices()->find($device->id()) ?? self::fail())->status());
    }

    public function testPairingAttemptsAreLimited(): void
    {
        $device = $this->pending();
        $wrong = '000000' === $device->pairingCode() ? '111111' : '000000';
        $messages = [];
        for ($i = 0; $i < 12; ++$i) {
            try {
                $this->approvals->approve($this->org, $device->id(), $wrong, $this->user($this->adminO));
            } catch (DomainRuleViolation $e) {
                $messages[] = $e->getMessage();
            }
        }
        self::assertContains('Trop de tentatives. Réessayez dans quelques minutes.', $messages);
        // même le bon code est refusé tant que la limite court
        $this->expectException(DomainRuleViolation::class);
        $this->approvals->approve($this->org, $device->id(), (string) $device->pairingCode(), $this->user($this->adminO));
    }

    public function testApprovalAfterTheDeadlineIsRefused(): void
    {
        $device = $this->pending();
        $this->clock->modify('+61 minutes');
        $this->expectException(DomainRuleViolation::class);
        $this->approvals->approve($this->org, $device->id(), (string) $device->pairingCode(), $this->user($this->adminO));
    }

    public function testASecondApprovalOfTheSameDeviceIsRefused(): void
    {
        $device = $this->pending();
        $pairing = (string) $device->pairingCode();
        $this->approvals->approve($this->org, $device->id(), $pairing, $this->user($this->adminO));

        $this->expectException(DomainRuleViolation::class);
        $this->approvals->approve($this->org, $device->id(), $pairing, $this->user($this->adminO));
    }

    public function testOnlyAnAdministratorOfTheDevicesCommuneMayApprove(): void
    {
        $device = $this->pending();
        $pairing = (string) $device->pairingCode();
        foreach ([[$this->org, $this->instructorO], [$this->partner, $this->adminP], [$this->foreign, $this->adminX], [$this->org, $this->adminP]] as [$commune, $actor]) {
            try {
                $this->approvals->approve($commune, $device->id(), $pairing, $this->user($actor));
                self::fail('Refus attendu.');
            } catch (OperationNotAllowed) {
                self::addToAssertionCount(1);
            }
        }
        $this->fresh();
        self::assertSame(DeviceStatus::Pending, ($this->devices()->find($device->id()) ?? self::fail())->status());
        $this->expectException(OperationNotAllowed::class);
        $this->approvals->approve($this->org, Uuid::v7(), $pairing, $this->user($this->adminO)); // identifiant forgé
    }

    public function testApprovalIsRefusedForASuspendedCommune(): void
    {
        $device = $this->pending();
        $this->sql("UPDATE municipality SET status = 'SUSPENDED' WHERE id = :id", ['id' => $this->org->id()->toBinary()]);
        $this->fresh();

        $this->expectException(OperationNotAllowed::class);
        $this->approvals->approve($this->org, $device->id(), (string) $device->pairingCode(), $this->user($this->adminO));
    }

    public function testDeactivationWithAReasonCutsTheAccess(): void
    {
        $device = $this->active();
        $this->approvals->deactivateDevice($this->org, $device->id(), DeactivationReason::Lost, $this->user($this->adminO));
        $this->fresh();
        $off = $this->devices()->find($device->id()) ?? self::fail();

        self::assertSame(DeviceStatus::Deactivated, $off->status());
        self::assertSame(DeactivationReason::Lost, $off->deactivationReason());
        self::assertFalse($off->isOperational($this->clock->now()));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'CONTROL_DEVICE_DEACTIVATED'"));
        $this->expectException(DomainRuleViolation::class); // déjà désactivé
        $this->approvals->deactivateDevice($this->org, $device->id(), DeactivationReason::Other, $this->user($this->adminO));
    }

    public function testAnActiveDeviceCannotBeRejectedButAPendingOneCan(): void
    {
        $active = $this->active('Actif');
        try {
            $this->approvals->deactivateDevice($this->org, $active->id(), DeactivationReason::Rejected, $this->user($this->adminO));
            self::fail('Seul un appareil en attente peut être refusé.');
        } catch (DomainRuleViolation) {
        }
        $pending = $this->pending('En attente');
        $this->approvals->deactivateDevice($this->org, $pending->id(), DeactivationReason::Rejected, $this->user($this->adminO));
        $this->fresh();
        self::assertNull(($this->devices()->find($pending->id()) ?? self::fail())->pairingCode(), 'le code d’appariement est effacé au refus');
    }

    public function testAnotherCommuneCannotDeactivateOrRenew(): void
    {
        $device = $this->active();
        foreach ([fn () => $this->approvals->deactivateDevice($this->partner, $device->id(), DeactivationReason::Lost, $this->user($this->adminP)), fn () => $this->approvals->renew($this->partner, $device->id(), $this->user($this->adminP))] as $action) {
            try {
                $action();
                self::fail('Refus attendu.');
            } catch (OperationNotAllowed) {
                self::addToAssertionCount(1);
            }
        }
        $this->fresh();
        self::assertSame(DeviceStatus::Active, ($this->devices()->find($device->id()) ?? self::fail())->status());
    }

    public function testRenewalExtendsTheAuthorizationOfAnActiveDeviceOnly(): void
    {
        $device = $this->active();
        $this->clock->modify('+60 hours');
        $this->approvals->renew($this->org, $device->id(), $this->user($this->adminO));
        $this->fresh();
        $renewed = $this->devices()->find($device->id()) ?? self::fail();

        self::assertSame(72 * 3600, $renewed->expiresAt()->getTimestamp() - $this->clock->now()->getTimestamp(), 'nouvelle échéance depuis le renouvellement');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'CONTROL_DEVICE_RENEWED'"));

        $pending = $this->pending('En attente');
        $this->expectException(DomainRuleViolation::class);
        $this->approvals->renew($this->org, $pending->id(), $this->user($this->adminO));
    }

    public function testADeactivatedDeviceCannotBeRenewed(): void
    {
        $device = $this->active();
        $this->approvals->deactivateDevice($this->org, $device->id(), DeactivationReason::Compromised, $this->user($this->adminO));
        $this->expectException(DomainRuleViolation::class);
        $this->approvals->renew($this->org, $device->id(), $this->user($this->adminO));
    }

    public function testAnExpiredDeviceIsNoLongerOperational(): void
    {
        $device = $this->active();
        self::assertTrue($device->isOperational($this->clock->now()));
        $this->clock->modify('+73 hours');
        self::assertFalse($device->isOperational($this->clock->now()), 'l’expiration se calcule, aucun statut périmé en base');
    }

    public function testEachCommuneSeesOnlyItsOwnDevices(): void
    {
        $this->active('Barrage O');
        $issued = $this->issue($this->adminP, $this->partner);
        $this->register($issued->code->display(), 'Barrage P');
        $this->fresh();

        $org = $this->devices()->listFor($this->org->id(), $this->event->id());
        $partner = $this->devices()->listFor($this->partner->id(), $this->event->id());
        self::assertSame(['Barrage O'], array_map(static fn (ControlDevice $d): string => $d->label(), $org));
        self::assertSame(['Barrage P'], array_map(static fn (ControlDevice $d): string => $d->label(), $partner));
    }
}
