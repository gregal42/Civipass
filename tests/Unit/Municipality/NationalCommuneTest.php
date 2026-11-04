<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Municipality\Domain\NationalCommune;
use App\Municipality\Domain\NationalCommuneRecord;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NationalCommuneTest extends TestCase
{
    private function now(string $date = '2026-11-04 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    private function record(string $code = '33529', string $name = 'Le Temple', string $department = '33'): NationalCommuneRecord
    {
        return new NationalCommuneRecord($code, $name, $department, '75', ['33680'], 1000);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'accents' => ['Clémenciat', 'clemenciat'];
        yield 'apostrophe et tiret' => ['L’Abergement-Clémenciat', 'l abergement clemenciat'];
        yield 'apostrophe droite' => ['L\'Île-d\'Yeu', 'l ile d yeu'];
        yield 'ligature' => ['Œuvres', 'oeuvres'];
        yield 'cédille' => ['Ça-et-Là', 'ca et la'];
        yield 'espaces multiples' => ['  Saint   Étienne  ', 'saint etienne'];
        yield 'saints' => ['Saint-Pierre-et-Miquelon', 'saint pierre et miquelon'];
        yield 'chiffres' => ['Paris 15e Arrondissement', 'paris 15e arrondissement'];
    }

    #[DataProvider('names')]
    public function testTheSearchNameIsLowercaseAsciiWithSingleSpaces(string $name, string $expected): void
    {
        self::assertSame($expected, NationalCommune::normalize($name));
    }

    public function testACommuneKeepsItsOfficialNameAndAnIndexedSearchName(): void
    {
        $commune = new NationalCommune($this->record('01001', 'L’Abergement-Clémenciat', '01'), 'geo.api.gouv.fr', $this->now());

        self::assertSame('L’Abergement-Clémenciat', $commune->name());
        self::assertSame('l abergement clemenciat', $commune->searchName());
        self::assertSame('01001', $commune->inseeCode()->value);
        self::assertTrue($commune->isActive());
    }

    public function testCorsicanAndOverseasCodesAreAccepted(): void
    {
        self::assertSame('2A001', (new NationalCommune($this->record('2A001', 'Afa', '2A'), 'src', $this->now()))->inseeCode()->value);
        self::assertSame('97101', (new NationalCommune($this->record('97101', 'Les Abymes', '971'), 'src', $this->now()))->inseeCode()->value);
    }

    public function testAnInvalidCodeOrNameIsRefused(): void
    {
        foreach ([['3352', 'X'], ['ABCDE', 'X'], ['33529', ''], ['33529', str_repeat('a', 151)]] as [$code, $name]) {
            try {
                new NationalCommune($this->record($code, $name), 'src', $this->now());
                self::fail('refus attendu : '.$code.' / '.$name);
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRefreshReportsAChangeOnlyWhenSomethingChanged(): void
    {
        $commune = new NationalCommune($this->record(), 'geo.api.gouv.fr', $this->now());

        self::assertFalse($commune->refresh($this->record(), 'geo.api.gouv.fr', $this->now('2026-12-01 10:00:00')), 'identique : aucune écriture');
        self::assertTrue($commune->refresh($this->record(name: 'Le Temple-Nouveau'), 'geo.api.gouv.fr', $this->now('2026-12-01 10:00:00')));
        self::assertSame('le temple nouveau', $commune->searchName(), 'le nom de recherche suit le nom');
        self::assertEquals($this->now('2026-12-01 10:00:00'), $commune->updatedAt());
    }

    public function testADisappearedCommuneIsDeactivatedNotDeletedAndReactivatedWhenItReturns(): void
    {
        $commune = new NationalCommune($this->record(), 'geo.api.gouv.fr', $this->now());

        $commune->deactivate($this->now('2027-01-02 00:00:00'));
        self::assertFalse($commune->isActive());
        self::assertEquals($this->now('2027-01-02 00:00:00'), $commune->deactivatedAt());

        self::assertTrue($commune->refresh($this->record(), 'geo.api.gouv.fr', $this->now('2027-06-01 00:00:00')), 'le retour est un changement');
        self::assertTrue($commune->isActive());
        self::assertNull($commune->deactivatedAt());
    }
}
