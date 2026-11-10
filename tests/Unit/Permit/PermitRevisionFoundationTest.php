<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\Domain\AuthorizationRights;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\PermitRights;
use App\Permit\Domain\RevisionType;
use App\Permit\Domain\RevocationReason;
use App\Permit\Domain\RightState;
use App\Permit\Domain\ZoneRight;
use App\Request\Domain\DurationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Socle des révisions (LOT 05.5.1, DM-119) : catalogue de motifs, libellés, immutabilité de l'historique, clé de notification d'une révocation,
 * « droits vivants » et « entièrement révoqué » (comportement du lien bénéficiaire et du PDF).
 */
final class PermitRevisionFoundationTest extends TestCase
{
    private function at(string $utc = '2026-06-12 09:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    // ------------------------------------------------------------------ motifs

    public function testTheCatalogueHoldsTheValidatedCodesWithFrenchLabels(): void
    {
        self::assertSame(
            ['FALSE_STATEMENT', 'ELIGIBILITY_LOST', 'PUBLIC_SAFETY', 'ISSUED_IN_ERROR', 'DUPLICATE', 'MISUSE', 'BENEFICIARY_REQUEST', 'OTHER'],
            array_map(static fn (RevocationReason $r): string => $r->value, RevocationReason::cases()),
        );
        foreach (RevocationReason::cases() as $reason) {
            self::assertNotSame('', $reason->label());
            self::assertDoesNotMatchRegularExpression('/^[A-Z_]+$/', $reason->label(), 'jamais un code brut à l\'écran');
            self::assertLessThanOrEqual(30, \strlen($reason->value), 'tient dans la colonne reason_code');
        }
    }

    public function testOnlyOtherRequiresACommentAndTheLimitIsTwoHundredCharacters(): void
    {
        foreach (RevocationReason::cases() as $reason) {
            self::assertSame(RevocationReason::Other === $reason, $reason->needsComment());
        }
        self::assertSame(200, PermitRevision::COMMENT_MAX_LENGTH);
    }

    public function testTheNeutralLabelNeverCarriesAReasonOrARight(): void
    {
        foreach (RevisionType::cases() as $type) {
            self::assertNotSame('', $type->neutralLabel());
            foreach (RevocationReason::cases() as $reason) {
                self::assertStringNotContainsString($reason->label(), $type->neutralLabel());
            }
        }
    }

    // ------------------------------------------------------------------ immutabilité

    public function testARevisionOffersNoWayToChangeItself(): void
    {
        $forbidden = '/^(set|change|update|edit|modify|remove|delete|revoke|restore|reopen|cancel|anonymi[sz]e|clear|reset)/i';
        foreach ((new \ReflectionClass(PermitRevision::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertDoesNotMatchRegularExpression($forbidden, $method->getName(), 'mutateur public interdit : '.$method->getName());
        }
        // Les constructeurs sont fermés : une révision ne naît que par une fabrique de domaine.
        self::assertTrue((new \ReflectionClass(PermitRevision::class))->getConstructor()?->isPrivate());
    }

    public function testTheRepositoryCanOnlyAddAndRead(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(PermitRevisionRepository::class))->getMethods());
        sort($methods);

        self::assertSame(['add', 'addVehicleLine', 'countTokenReplacementsByActorSince', 'forPermit', 'lockedByBatch', 'lockedTokenReplacementTimes', 'vehicleLines'], $methods, 'ajout et lecture seulement (LOT 05.5.4 : lignes de plaques ; LOT 05.5.5 : lectures verrouillantes et comptages) : aucune suppression ni modification de l\'historique');
    }

    public function testAnAuthorizationCannotBeUnrevoked(): void
    {
        $names = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(\App\Permit\Domain\PermitAuthorization::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        self::assertContains('revoke', $names);
        foreach ($names as $name) {
            self::assertDoesNotMatchRegularExpression('/^(unrevoke|reinstate(?!Zone)|restore|reactivate|cancelRevocation)/i', $name);
        }
    }

    // ------------------------------------------------------------------ notification

    public function testTheRevocationKeyIsPerRevisionSoTwoRevocationsNeverCollide(): void
    {
        $permit = Uuid::v7();
        $authorization = Uuid::v7();
        $a = new NotificationIntent(NotificationKind::AuthorizationRevoked, $permit, $authorization, $this->at(), true, Uuid::v7());
        $b = new NotificationIntent(NotificationKind::AuthorizationRevoked, $permit, $authorization, $this->at(), true, Uuid::v7());

        self::assertNotSame($a->deduplicationKey(), $b->deduplicationKey());
        self::assertStringStartsWith('AUTHORIZATION_REVOKED:', $a->deduplicationKey());
        self::assertSame($a->deduplicationKey(), (new NotificationIntent(NotificationKind::AuthorizationRevoked, $permit, $authorization, $this->at(), true, $a->revisionId))->deduplicationKey(), 'rejeu = même clé');
    }

    public function testARevocationWithoutRevisionIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new NotificationIntent(NotificationKind::AuthorizationRevoked, Uuid::v7(), Uuid::v7(), $this->at(), true))->deduplicationKey();
    }

    // ------------------------------------------------------------------ droits vivants

    /**
     * @param list<list<RightState>> $states       états des zones, une liste par autorisation
     * @param list<bool>             $revokedFlags
     */
    private function rights(array $states, array $revokedFlags): PermitRights
    {
        $authorizations = [];
        foreach ($states as $i => $zoneStates) {
            $zones = array_map(static fn (RightState $s): ZoneRight => new ZoneRight(Uuid::v7(), 'Zone', $s), $zoneStates);
            $authorizations[] = new AuthorizationRights(Uuid::v7(), Uuid::v7(), 'Commune '.$i, DurationMode::UntilClosure, $this->at(), null, $revokedFlags[$i] ? $this->at() : null, $zones);
        }

        return new PermitRights(Uuid::v7(), 'LP-2026-0001', 'Europe/Paris', $this->at(), $authorizations);
    }

    /**
     * @return iterable<string, array{list<list<RightState>>, list<bool>, bool, bool}>
     */
    public static function liveness(): iterable
    {
        yield 'une zone valide' => [[[RightState::Valid]], [false], true, false];
        yield 'à venir' => [[[RightState::NotYetValid]], [false], true, false];
        yield 'hors période (reviendra)' => [[[RightState::OutsidePeriod]], [false], true, false];
        yield 'suspendue (peut reprendre)' => [[[RightState::ZoneSuspended]], [false], true, false];
        yield 'expirée seulement' => [[[RightState::Expired]], [false], false, false];
        yield 'événement clos' => [[[RightState::EventClosed]], [false], false, false];
        yield 'toutes révoquées' => [[[RightState::Revoked], [RightState::Revoked]], [true, true], false, true];
        yield 'une révoquée, une valide' => [[[RightState::Revoked], [RightState::Valid]], [true, false], true, false];
        yield 'une révoquée, une expirée' => [[[RightState::Revoked], [RightState::Expired]], [true, false], false, false];
        yield 'zone retirée seule' => [[[RightState::Removed]], [false], false, false];
        yield 'aucune autorisation' => [[], [], false, false];
    }

    /**
     * @param list<list<RightState>> $states
     * @param list<bool>             $revoked
     */
    #[DataProvider('liveness')]
    public function testLiveRightsAndEntireRevocationDriveThePageAndThePdf(array $states, array $revoked, bool $live, bool $entirelyRevoked): void
    {
        $rights = $this->rights($states, $revoked);

        self::assertSame($live, $rights->hasLiveRights());
        self::assertSame($entirelyRevoked, $rights->isEntirelyRevoked());
    }

    public function testFinalStatesAreExactlyTheOnesThatNeverComeBack(): void
    {
        $final = array_values(array_filter(RightState::cases(), static fn (RightState $s): bool => $s->isFinal()));
        sort($final, \SORT_REGULAR);
        $expected = [RightState::Expired, RightState::EventClosed, RightState::Revoked, RightState::Removed, RightState::ZoneNotAuthorized];
        sort($expected, \SORT_REGULAR);

        self::assertSame($expected, $final);
    }
}
