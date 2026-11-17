<?php

declare(strict_types=1);

namespace App\Tests\Functional\Control;

use App\Control\Application\ControlSettings;
use App\Control\Domain\AssuranceMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ControlKit;
use App\Tests\Support\ControlTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Codes d'activation (LOT 09.2, DM-131) : droit de l'administrateur communal relu sous verrou, isolation entre communes, bornes des seuils (une commune ne peut
 * que RACCOURCIR), plafonds, annulation, stockage de l'empreinte seule, audit sans code.
 */
final class ControlActivationServiceTest extends ControlTestCase
{
    public function testAnAdministratorIssuesACodeWithPilotDefaultsAndOnlyItsHashIsStored(): void
    {
        $issued = $this->issue();

        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{12}$/', $issued->code->value());
        self::assertSame(hash('sha256', $issued->code->value()), $this->scalar('SELECT code_hash FROM control_activation'));
        self::assertSame(21_600, $issued->activation->trustWarnSeconds(), 'pilote : avertissement 6 h');
        self::assertSame(43_200, $issued->activation->trustHardSeconds(), 'pilote : seuil dur 12 h');
        self::assertSame(AssuranceMode::Standard, $issued->activation->assuranceMode());
        self::assertSame(1_800, $issued->activation->expiresAt()->getTimestamp() - $this->clock->now()->getTimestamp(), 'code valable 30 minutes');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM control_activation WHERE code_hash = ?', [$issued->code->value()]), 'le code en clair n’est jamais conservé');
    }

    public function testTheAuditNeverContainsTheCode(): void
    {
        $issued = $this->issue();

        $rows = $this->em()->getConnection()->fetchAllAssociative("SELECT * FROM audit_log WHERE action = 'CONTROL_ACTIVATION_CREATED'");
        self::assertCount(1, $rows);
        $dump = (string) json_encode($rows, \JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertStringNotContainsString($issued->code->value(), $dump);
        self::assertStringNotContainsString($issued->code->hash(), $dump);
        self::assertStringContainsString('STANDARD', $dump);
    }

    public function testOnlyAnAdministratorOfTheCommuneMayIssue(): void
    {
        foreach ([[$this->instructorO, $this->org], [$this->adminP, $this->org], [$this->adminX, $this->org]] as [$actor, $commune]) {
            try {
                $this->issue($actor, $commune);
                self::fail('Un refus était attendu.');
            } catch (OperationNotAllowed) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->rows('control_activation'));
    }

    public function testAForgedEventOfAnotherCommuneIsRefusedUniformly(): void
    {
        $this->expectException(OperationNotAllowed::class);
        $this->activations->issue($this->foreign, $this->event->id(), $this->user($this->adminX), AssuranceMode::Standard, 3, null, null);
    }

    public function testAnUnknownEventIsRefusedUniformly(): void
    {
        $this->expectException(OperationNotAllowed::class);
        $this->activations->issue($this->org, Uuid::v7(), $this->user($this->adminO), AssuranceMode::Standard, 3, null, null);
    }

    public function testTheTwoCommunesGetIndependentCodes(): void
    {
        $a = $this->issue($this->adminO, $this->org);
        $b = $this->issue($this->adminP, $this->partner);

        self::assertNotSame($a->code->hash(), $b->code->hash());
        self::assertSame(2, $this->rows('control_activation'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM control_activation WHERE municipality_id = ?', [$this->partner->id()->toBinary()]));
    }

    /** @return iterable<string, array{?int, ?int, ?int, int}> */
    public static function invalidParameters(): iterable
    {
        yield 'seuil dur au-delà du plafond de configuration (12 h)' => [null, 43_201, null, 3];
        yield 'seuil dur au-delà du plafond technique (24 h)' => [null, 90_000, null, 3];
        yield 'seuil dur sous 2 h' => [3_600, 3_600, null, 3];
        yield 'avertissement au-delà du seuil dur' => [30_000, 28_800, null, 3];
        yield 'avertissement sous 30 minutes' => [600, 28_800, null, 3];
        yield 'code valable moins de 10 minutes' => [null, null, 5, 3];
        yield 'code valable plus de 60 minutes' => [null, null, 61, 3];
        yield 'zéro appareil' => [null, null, null, 0];
        yield 'plus de dix appareils' => [null, null, null, 11];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidParameters')]
    public function testOutOfBoundsParametersAreRefused(?int $warn, ?int $hard, ?int $minutes, int $devices): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->issue(maxDevices: $devices, warn: $warn, hard: $hard, minutes: $minutes);
    }

    public function testACommuneMayShortenItsThresholdsButNeverRaiseThem(): void
    {
        $short = $this->issue(warn: 3_600, hard: 7_200);
        self::assertSame(7_200, $short->activation->trustHardSeconds());

        $this->expectException(DomainRuleViolation::class);
        $this->issue(hard: 43_201);
    }

    public function testRaisingTheCeilingIsADeploymentDecisionAndNeverExceedsTwentyFourHours(): void
    {
        $this->useClock($this->clock, ControlKit::settings(hardMax: 86_400, warn: 43_200));
        self::assertSame(86_400, $this->issue(hard: 86_400)->activation->trustHardSeconds(), 'plafond relevé par décision : 24 h permis');

        $capped = new ControlSettings(true, 999_999, 43_200, 30, 60, 72);
        self::assertSame(86_400, $capped->hardMaxSeconds, 'le plafond technique de 24 h ne peut jamais être dépassé, quelle que soit la configuration');
        self::assertSame(['warn' => 21_600, 'hard' => 43_200], ControlKit::settings()->clampThresholds(null, 99_999));
    }

    public function testAtMostThreeUsableCodesAtOnce(): void
    {
        $this->issue();
        $this->issue();
        $this->issue();

        $this->expectException(DomainRuleViolation::class);
        $this->issue();
    }

    public function testAnExpiredCodeNoLongerCountsAsUsable(): void
    {
        $this->issue();
        $this->issue();
        $this->issue();
        $this->clock->modify('+31 minutes');

        $this->issue();
        self::assertSame(4, $this->rows('control_activation'));
    }

    public function testRevokingACode(): void
    {
        $issued = $this->issue();
        $this->activations->revoke($this->org, $issued->activation->id(), $this->user($this->adminO));
        $this->fresh();

        self::assertNotNull($this->scalar('SELECT revoked_at FROM control_activation'));
        $this->expectException(DomainRuleViolation::class);
        $this->activations->revoke($this->org, $issued->activation->id(), $this->user($this->adminO));
    }

    public function testAnotherCommuneCannotRevokeOrSeeACode(): void
    {
        $issued = $this->issue();

        $this->expectException(OperationNotAllowed::class);
        $this->activations->revoke($this->partner, $issued->activation->id(), $this->user($this->adminP));
    }

    public function testNothingIsPreparedForASuspendedCommuneOrAClosedEvent(): void
    {
        $this->sql("UPDATE municipality SET status = 'SUSPENDED' WHERE id = :id", ['id' => $this->org->id()->toBinary()]);
        try {
            $this->issue();
            self::fail('Commune suspendue : refus attendu.');
        } catch (DomainRuleViolation) {
        }
        $this->sql("UPDATE municipality SET status = 'ACTIVE' WHERE id = :id", ['id' => $this->org->id()->toBinary()]);
        $this->sql("UPDATE event_event SET status = 'CLOSED'");
        $this->expectException(DomainRuleViolation::class);
        $this->issue($this->adminO, $this->em()->find(\App\Municipality\Domain\Municipality::class, $this->org->id()));
    }

    public function testACodeCreationRateLimitProtectsTheCommune(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $issued = $this->issue();
            $this->activations->revoke($this->org, $issued->activation->id(), $this->user($this->adminO));
        }
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Trop de codes');
        $this->issue();
    }
}
