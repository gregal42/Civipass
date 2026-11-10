<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Event\Domain\EventStatus;
use App\Permit\Domain\VehicleChangeKind;
use App\Permit\Domain\VehicleChangePlanner;
use App\Permit\Domain\VehicleRowState;
use App\Request\Domain\LicensePlate;
use App\Request\Domain\TravelMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Règles pures des plaques (LOT 05.5.4) : plafond et doublons sur l'état final, dernière plaque, mode de déplacement, suspension, réactivation, remplacement.
 */
final class VehicleChangePlannerTest extends TestCase
{
    private function row(string $plate, int $position, bool $removed = false): VehicleRowState
    {
        return new VehicleRowState(Uuid::v7(), $plate, LicensePlate::fromInput($plate)->format, $position, $removed);
    }

    private function plate(string $raw): LicensePlate
    {
        return LicensePlate::fromInput($raw);
    }

    /**
     * @param list<VehicleRowState>                     $rows
     * @param list<LicensePlate>                        $adds
     * @param list<Uuid>                                $removes
     * @param list<Uuid>                                $reinstates
     * @param list<array{from: Uuid, to: LicensePlate}> $replacements
     */
    private function plan(array $rows, array $adds = [], array $removes = [], array $reinstates = [], array $replacements = [], TravelMode $mode = TravelMode::Vehicle, bool $revoked = false, EventStatus $status = EventStatus::Active): \App\Permit\Domain\VehiclePlan
    {
        return (new VehicleChangePlanner())->change($rows, $adds, $removes, $reinstates, $replacements, $mode, $revoked, $status, 10);
    }

    private function refused(callable $call, string $fragment): void
    {
        try {
            $call();
            self::fail('Refus attendu : '.$fragment);
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString($fragment, $e->getMessage());
        }
    }

    public function testAnAdditionTakesTheNextPositionAndKeepsTheCountOnTheFinalState(): void
    {
        $a = $this->row('AB123CD', 1);
        $plan = $this->plan([$a], adds: [$this->plate('ef-456-gh'), $this->plate('1234 AB 56')]);

        self::assertSame(3, $plan->activeAfter);
        self::assertSame([VehicleChangeKind::Added, VehicleChangeKind::Added], array_map(static fn ($c) => $c->kind, $plan->changes));
        self::assertSame([2, 3], array_map(static fn ($c) => $c->position, $plan->changes));
        self::assertSame('EF456GH', $plan->changes[0]->registration);
        self::assertFalse($plan->hasRemoval());
    }

    public function testRemovalIsPriorityAndTheLastActivePlateCannotBeRemoved(): void
    {
        $a = $this->row('AB123CD', 1);
        $b = $this->row('EF456GH', 2);

        self::assertTrue($this->plan([$a, $b], removes: [$a->vehicleId])->hasRemoval());
        $this->refused(fn () => $this->plan([$a], removes: [$a->vehicleId]), 'au moins une plaque');
        $this->refused(fn () => $this->plan([$a, $b], removes: [$a->vehicleId, $b->vehicleId]), 'au moins une plaque');
    }

    public function testReplacingTheLastPlateIsAllowedAtomicallyAndProducesTwoLinkedChanges(): void
    {
        $a = $this->row('AB123CD', 1);
        $plan = $this->plan([$a], replacements: [['from' => $a->vehicleId, 'to' => $this->plate('GH-482-KT')]]);

        self::assertSame(1, $plan->activeAfter);
        self::assertSame([VehicleChangeKind::ReplacedOut, VehicleChangeKind::ReplacedIn], array_map(static fn ($c) => $c->kind, $plan->changes));
        self::assertSame([0, 0], array_map(static fn ($c) => $c->pair, $plan->changes), 'les deux révisions se rattachent au même remplacement');
        self::assertTrue($plan->hasRemoval(), 'un remplacement est prioritaire');
        self::assertSame('GH482KT', $plan->changes[1]->registration);
    }

    public function testTheTenPlateCeilingIsCheckedOnTheFinalStateOfTheWholeOperation(): void
    {
        $rows = [];
        for ($i = 0; $i < 10; ++$i) {
            $rows[] = $this->row(sprintf('AB%03dCD', $i + 100), $i + 1);
        }

        $this->refused(fn () => $this->plan($rows, adds: [$this->plate('ZZ999ZZ')]), 'plus de 10 plaques');
        // un retrait + un ajout, ou un remplacement, reste à dix : valide
        self::assertSame(10, $this->plan($rows, adds: [$this->plate('ZZ999ZZ')], removes: [$rows[0]->vehicleId])->activeAfter);
        self::assertSame(10, $this->plan($rows, replacements: [['from' => $rows[0]->vehicleId, 'to' => $this->plate('ZZ999ZZ')]])->activeAfter);
        // onze plaques actives d'un coup sur un laissez-passer vide de plus de neuf
        $this->refused(fn () => $this->plan([$rows[0]], adds: array_map(fn (int $i) => $this->plate(sprintf('CD%03dEF', $i)), range(1, 10))), 'plus de 10 plaques');
    }

    public function testDuplicatesAreRefusedOnTheSameOperationAndAgainstTheExistingPlatesIncludingWithOtherSeparators(): void
    {
        $a = $this->row('AB123CD', 1);

        $this->refused(fn () => $this->plan([$a], adds: [$this->plate('ab 123 cd')]), 'figure déjà');
        $this->refused(fn () => $this->plan([$a], adds: [$this->plate('EF456GH'), $this->plate('ef-456-gh')]), 'qu\'une fois');
        $this->refused(fn () => $this->plan([$a], replacements: [['from' => $a->vehicleId, 'to' => $this->plate('AB-123-CD')]]), 'identique');
    }

    public function testARemovedPlateIsReinstatedNeverAddedAndReinstatementRespectsTheCeiling(): void
    {
        $a = $this->row('AB123CD', 1);
        $gone = $this->row('EF456GH', 2, removed: true);

        $this->refused(fn () => $this->plan([$a, $gone], adds: [$this->plate('EF-456-GH')]), 'réactivez-la');
        $plan = $this->plan([$a, $gone], reinstates: [$gone->vehicleId]);
        self::assertSame(VehicleChangeKind::Reinstated, $plan->changes[0]->kind);
        self::assertSame(2, $plan->changes[0]->position, 'la ligne existante est réutilisée');
        self::assertSame(2, $plan->activeAfter);
        $this->refused(fn () => $this->plan([$a, $gone], reinstates: [$a->vehicleId]), 'rien à réactiver');
        $this->refused(fn () => $this->plan([$a, $gone], removes: [$gone->vehicleId]), 'déjà retirée');
    }

    public function testAFootBikePermitRefusesEveryOperationWithTheUniformRefusal(): void
    {
        $this->expectException(OperationNotAllowed::class);
        $this->plan([], adds: [$this->plate('AB123CD')], mode: TravelMode::FootBike);
    }

    public function testARevokedAuthorizationAndAClosedEventRefuseEverything(): void
    {
        $a = $this->row('AB123CD', 1);
        $b = $this->row('EF456GH', 2);

        $this->refused(fn () => $this->plan([$a, $b], removes: [$b->vehicleId], revoked: true), 'révoquée');
        $this->refused(fn () => $this->plan([$a, $b], removes: [$b->vehicleId], status: EventStatus::Closed), 'clos');
    }

    public function testASuspendedEventAllowsOnlyRemovalAndRefusesTheWholeGroupWhenItContainsAForbiddenAction(): void
    {
        $a = $this->row('AB123CD', 1);
        $b = $this->row('EF456GH', 2);
        $gone = $this->row('IJ789KL', 3, removed: true);

        self::assertSame(1, $this->plan([$a, $b], removes: [$b->vehicleId], status: EventStatus::Suspended)->activeAfter);
        $this->refused(fn () => $this->plan([$a, $b], removes: [$b->vehicleId], status: EventStatus::Suspended, adds: [$this->plate('MN012OP')]), 'suspendu');
        $this->refused(fn () => $this->plan([$a, $gone], reinstates: [$gone->vehicleId], status: EventStatus::Suspended), 'suspendu');
        $this->refused(fn () => $this->plan([$a], replacements: [['from' => $a->vehicleId, 'to' => $this->plate('MN012OP')]], status: EventStatus::Suspended), 'suspendu');
        $this->refused(fn () => $this->plan([$a], removes: [$a->vehicleId], status: EventStatus::Suspended), 'au moins une plaque');
    }

    public function testAnEmptyOperationAndTheSamePlateTwiceInTheOperationAreRefused(): void
    {
        $a = $this->row('AB123CD', 1);
        $b = $this->row('EF456GH', 2);

        $this->refused(fn () => $this->plan([$a]), 'au moins une plaque');
        $this->refused(fn () => $this->plan([$a, $b], removes: [$b->vehicleId], replacements: [['from' => $b->vehicleId, 'to' => $this->plate('MN012OP')]]), 'qu\'une fois');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function plates(): iterable
    {
        yield 'SIV' => ['AB-123-CD', true];
        yield 'SIV espaces et casse' => [' ab 123 cd ', true];
        yield 'FNI' => ['1234 AB 56', true];
        yield 'FNI Corse 2A' => ['123 ABC 2A', true];
        yield 'étrangère' => ['B-AB 1234', false];
        yield 'SIV invalide' => ['AB-12-CD', false];
        yield 'vide' => ['   ', false];
        yield 'injection' => ['AB123CD; DROP', false];
    }

    #[DataProvider('plates')]
    public function testTheNormalizationAcceptsOnlyFrenchSivAndFniPlates(string $raw, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(DomainRuleViolation::class);
        }
        $plate = LicensePlate::fromInput($raw);
        self::assertMatchesRegularExpression('/^[A-Z0-9]+$/', $plate->value);
    }
}
