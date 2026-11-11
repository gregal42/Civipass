<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain;

use App\Event\Domain\ClosureStatistics;
use App\Event\Domain\MunicipalityCounters;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Schéma JSON strict des compteurs internes de clôture (LOT 05.6.2, P4) : aucune donnée hors compteurs et identifiants de communes. */
final class ClosureStatisticsTest extends TestCase
{
    private const A = '0192a5f0-0000-7000-8000-000000000001';
    private const B = '0192a5f0-0000-7000-8000-000000000002';

    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return ['v' => 1, 'internal' => ['requests' => 3, 'municipalities' => [
            ['id' => self::A, 'approved' => 2, 'refused' => 1, 'pending' => 0, 'authorizations' => 2],
            ['id' => self::B, 'approved' => 1, 'refused' => 0, 'pending' => 0, 'authorizations' => 1],
        ]]];
    }

    public function testItRoundTripsThroughItsStrictJsonForm(): void
    {
        $statistics = ClosureStatistics::fromArray(self::valid());

        self::assertSame(self::valid(), $statistics->toArray());
        self::assertSame(4, $statistics->dossiers());
        self::assertSame(3, $statistics->requests);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function invalidDocuments(): iterable
    {
        yield 'clé inconnue à la racine' => [static fn (array $d): array => $d + ['extra' => 1]];
        yield 'clé inconnue dans le bloc interne' => [static function (array $d): array {
            $d['internal']['nom'] = 'Dupont';

            return $d;
        }];
        yield 'clé inconnue dans une ligne (plaque)' => [static function (array $d): array {
            $d['internal']['municipalities'][0]['plate'] = 'AB-123-CD';

            return $d;
        }];
        yield 'identifiant de demande à la place d\'une commune' => [static function (array $d): array {
            $d['internal']['municipalities'][0]['id'] = 'DEM-2026-0001';

            return $d;
        }];
        yield 'version inconnue' => [static function (array $d): array {
            $d['v'] = 2;

            return $d;
        }];
        yield 'bloc interne manquant' => [static fn (array $d): array => ['v' => 1]];
        yield 'compteur chaîne' => [static function (array $d): array {
            $d['internal']['municipalities'][0]['approved'] = '2';

            return $d;
        }];
        yield 'compteur flottant' => [static function (array $d): array {
            $d['internal']['municipalities'][0]['approved'] = 2.0;

            return $d;
        }];
        yield 'compteur booléen' => [static function (array $d): array {
            $d['internal']['municipalities'][0]['pending'] = true;

            return $d;
        }];
        yield 'compteur négatif' => [static function (array $d): array {
            $d['internal']['municipalities'][0]['refused'] = -1;

            return $d;
        }];
        yield 'plus d\'autorisations que d\'approbations' => [static function (array $d): array {
            $d['internal']['municipalities'][1]['authorizations'] = 2;

            return $d;
        }];
        yield 'commune en double' => [static function (array $d): array {
            $d['internal']['municipalities'][1]['id'] = self::A;

            return $d;
        }];
        yield 'demandes inférieures au plus gros effectif communal' => [static function (array $d): array {
            $d['internal']['requests'] = 2;

            return $d;
        }];
        yield 'demandes supérieures à la somme des dossiers' => [static function (array $d): array {
            $d['internal']['requests'] = 5;

            return $d;
        }];
        yield 'liste de communes non indexée' => [static function (array $d): array {
            $d['internal']['municipalities'] = ['a' => $d['internal']['municipalities'][0]];

            return $d;
        }];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('invalidDocuments')]
    public function testAnyDocumentOutsideTheSchemaIsRefused(callable $mutate): void
    {
        $this->expectException(DomainRuleViolation::class);

        ClosureStatistics::fromArray($mutate(self::valid()));
    }

    public function testTooManyMunicipalitiesAreRefused(): void
    {
        $rows = [];
        for ($i = 0; $i <= ClosureStatistics::MAX_MUNICIPALITIES; ++$i) {
            $rows[] = new MunicipalityCounters(Uuid::v7()->toRfc4122(), 0, 0, 0, 0);
        }
        $this->expectException(DomainRuleViolation::class);

        new ClosureStatistics(0, $rows);
    }

    public function testAnEventWithoutAnyRequestIsValid(): void
    {
        $statistics = new ClosureStatistics(0, [new MunicipalityCounters(self::A, 0, 0, 0, 0)]);

        self::assertSame(0, $statistics->dossiers());
        self::assertEquals($statistics, ClosureStatistics::fromArray($statistics->toArray()));
    }

    public function testTheStoredFormNeverContainsAnythingButCountersAndMunicipalityIdentifiers(): void
    {
        $json = (string) json_encode(ClosureStatistics::fromArray(self::valid())->toArray());

        self::assertSame(1, preg_match('/^[\[\]{}",:0-9a-z\-]+$/', $json), 'uniquement des clés de schéma, des entiers et des UUID');
        foreach (['plate', 'name', 'email', 'reference', 'comment', 'permit', 'request_id'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
    }
}
