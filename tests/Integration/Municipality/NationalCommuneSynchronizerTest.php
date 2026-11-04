<?php

declare(strict_types=1);

namespace App\Tests\Integration\Municipality;

use App\Municipality\Application\NationalCommuneSynchronizer;
use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\NationalCommuneRecord;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EventFixtures;

/**
 * Synchronisation du référentiel national (MySQL de test) : import complet idempotent, désactivation sans suppression,
 * réactivation, essai à blanc, garde-fou contre une source partielle, séparation d'avec les communes inscrites.
 */
final class NationalCommuneSynchronizerTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['municipality_national_commune'])) {
            self::markTestSkipped('Référentiel national non migré sur la base de test (make test-db).');
        }
    }

    private function synchronizer(int $minimum = 3): NationalCommuneSynchronizer
    {
        return new NationalCommuneSynchronizer(
            $this->em,
            $this->service(\App\Shared\Application\TransactionRunner::class),
            $this->service(\App\Municipality\Domain\NationalCommuneRepository::class),
            $this->service(\App\Shared\Application\AuditTrail::class),
            $this->service(\Psr\Clock\ClockInterface::class),
            $minimum,
        );
    }

    /**
     * @return list<NationalCommuneRecord>
     */
    private function source(): array
    {
        return [
            new NationalCommuneRecord('33503', 'Saumos', '33', '75', ['33680'], 1800),
            new NationalCommuneRecord('33529', 'Le Temple', '33', '75', ['33680'], 1500),
            new NationalCommuneRecord('2A001', 'Afa', '2A', '94', ['20167'], 3350),
        ];
    }

    private function rows(string $where = '1 = 1'): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_national_commune WHERE '.$where);
    }

    public function testAFirstImportCreatesEveryCommune(): void
    {
        $report = $this->synchronizer()->synchronize($this->source(), 'geo.api.gouv.fr');

        self::assertSame([3, 3, 0, 0, 0], [$report->received, $report->created, $report->updated, $report->unchanged, $report->deactivated]);
        self::assertSame(3, $this->rows('active = 1'));
        self::assertSame('geo.api.gouv.fr', $this->em->getConnection()->fetchOne("SELECT source FROM municipality_national_commune WHERE insee_code = '33503'"));
    }

    public function testRunningTheSameImportAgainChangesNothingAndNeverDuplicates(): void
    {
        $this->synchronizer()->synchronize($this->source(), 'geo.api.gouv.fr');
        $this->em->clear();

        $report = $this->synchronizer()->synchronize($this->source(), 'geo.api.gouv.fr');

        self::assertSame([0, 0, 3, 0], [$report->created, $report->updated, $report->unchanged, $report->deactivated]);
        self::assertSame(3, $this->rows(), 'aucun doublon après synchronisation');
    }

    public function testAChangedFieldIsUpdatedAndOnlyThatCommune(): void
    {
        $this->synchronizer()->synchronize($this->source(), 'geo.api.gouv.fr');
        $this->em->clear();
        $records = $this->source();
        $records[1] = new NationalCommuneRecord('33529', 'Le Temple-sur-Lande', '33', '75', ['33680'], 1500);

        $report = $this->synchronizer()->synchronize($records, 'geo.api.gouv.fr');

        self::assertSame([0, 1, 2], [$report->created, $report->updated, $report->unchanged]);
        self::assertSame('le temple sur lande', $this->em->getConnection()->fetchOne("SELECT search_name FROM municipality_national_commune WHERE insee_code = '33529'"));
    }

    public function testACommuneMissingFromTheSourceIsDeactivatedNotDeletedAndReactivatedWhenItReturns(): void
    {
        $this->synchronizer()->synchronize($this->source(), 'geo.api.gouv.fr');
        $this->em->clear();
        $withoutAfa = array_slice($this->source(), 0, 2);

        $report = $this->synchronizer(2)->synchronize($withoutAfa, 'geo.api.gouv.fr');

        self::assertSame(1, $report->deactivated);
        self::assertSame(1, $this->rows("active = 0 AND insee_code = '2A001' AND deactivated_at IS NOT NULL"));
        self::assertSame(3, $this->rows(), 'jamais supprimée');

        $this->em->clear();
        $back = $this->synchronizer()->synchronize($this->source(), 'geo.api.gouv.fr');
        self::assertSame(1, $back->updated, 'le retour est une mise à jour');
        self::assertSame(3, $this->rows('active = 1'));
        self::assertSame(1, $this->rows("insee_code = '2A001' AND deactivated_at IS NULL"));
    }

    public function testADryRunReportsWithoutWritingAnything(): void
    {
        $audits = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log');

        $report = $this->synchronizer()->synchronize($this->source(), 'geo.api.gouv.fr', dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertSame(3, $report->created);
        self::assertSame(0, $this->rows(), 'rien n’est écrit');
        self::assertSame($audits, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log'), 'aucun audit');
    }

    public function testAnObviouslyPartialSourceIsRefusedBeforeAnyWrite(): void
    {
        $this->synchronizer(30000)->synchronize($this->source(), 'geo.api.gouv.fr', allowPartial: true); // données de départ
        $this->em->clear();

        try {
            $this->synchronizer(30000)->synchronize($this->source(), 'geo.api.gouv.fr');
            self::fail('source partielle : refus attendu');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('minimum attendu', $e->getMessage());
        }
        self::assertSame(3, $this->rows('active = 1'), 'rien n’a été désactivé');
    }

    public function testAnExplicitPartialImportWritesWhatItReceivesAndNeverDeactivates(): void
    {
        $this->synchronizer()->synchronize($this->source(), 'geo.api.gouv.fr');
        $this->em->clear();

        $report = $this->synchronizer(30000)->synchronize([$this->source()[0]], 'fichier:test.json', allowPartial: true);

        self::assertTrue($report->partial);
        self::assertSame(0, $report->deactivated);
        self::assertSame(3, $this->rows('active = 1'));
    }

    public function testInvalidAndDuplicateRowsAreIgnoredAndCounted(): void
    {
        $records = [...$this->source(), new NationalCommuneRecord('XXXXX', 'Code invalide', '00'), new NationalCommuneRecord('33503', 'Doublon de Saumos', '33'), new NationalCommuneRecord('33999', '', '33')];

        $report = $this->synchronizer()->synchronize($records, 'geo.api.gouv.fr');

        self::assertSame(3, $report->created);
        self::assertSame(3, $report->ignored, 'code invalide, doublon et nom vide');
        self::assertSame(3, $this->rows());
        self::assertSame('Saumos', $this->em->getConnection()->fetchOne("SELECT name FROM municipality_national_commune WHERE insee_code = '33503'"), 'la première occurrence l’emporte');
    }

    public function testRegisteredMunicipalitiesAreNeverTouched(): void
    {
        $municipality = new Municipality('Commune inscrite', InseeCode::fromString('33503'), EventFixtures::now());
        $this->em->persist($municipality);
        $this->em->flush();
        $before = $this->em->getConnection()->fetchAssociative('SELECT * FROM municipality WHERE insee_code = ?', ['33503']);

        $this->synchronizer()->synchronize($this->source(), 'geo.api.gouv.fr');
        $this->synchronizer(2)->synchronize(array_slice($this->source(), 1), 'geo.api.gouv.fr'); // Saumos disparaît du référentiel

        self::assertSame($before, $this->em->getConnection()->fetchAssociative('SELECT * FROM municipality WHERE insee_code = ?', ['33503']), 'la commune inscrite (nom, statut, droits) est intacte');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality'), 'aucune commune inscrite créée');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_commercial_access'), 'aucun droit commercial');
    }

    public function testEachSynchronizationIsAuditedOnceByTheSystem(): void
    {
        $this->synchronizer()->synchronize($this->source(), 'geo.api.gouv.fr');

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'NATIONAL_COMMUNES_SYNCHRONIZED'"));
        $new = (string) $this->em->getConnection()->fetchOne("SELECT new_value FROM audit_log WHERE action = 'NATIONAL_COMMUNES_SYNCHRONIZED'");
        self::assertSame(3, json_decode($new, true)['created']);
    }
}
