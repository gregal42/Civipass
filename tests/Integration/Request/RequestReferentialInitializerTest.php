<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Request\Application\RequestReferentialInitializer;
use App\Request\Domain\RequestReferentialCatalog;
use App\Tests\Support\RequestSchemaTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('requires-request-schema')]
final class RequestReferentialInitializerTest extends RequestSchemaTestCase
{
    private function initializer(): RequestReferentialInitializer
    {
        return $this->service(RequestReferentialInitializer::class);
    }

    public function testCreatesTheValidatedMotifsAndCategories(): void
    {
        $created = $this->initializer()->initialize();

        self::assertSame(['home', 'workplace', 'professional', 'relative_assistance', 'priority', 'land_or_holding', 'other'], $created['motifs']);
        self::assertSame(['private_person', 'professional', 'priority_responder', 'public_agent', 'volunteer'], $created['categories']);

        $motifs = $this->em->getConnection()->fetchAllAssociative('SELECT code, label, position, active, needs_detail FROM request_motif ORDER BY position');
        self::assertSame([10, 20, 30, 40, 50, 60, 70], array_map(static fn (array $r): int => (int) $r['position'], $motifs));
        self::assertSame('Accès à une exploitation ou un terrain', $motifs[5]['label']);
        self::assertSame(['other'], array_column(array_filter($motifs, static fn (array $r): bool => 1 === (int) $r['needs_detail']), 'code'));
        self::assertSame([1], array_values(array_unique(array_map(static fn (array $r): int => (int) $r['active'], $motifs))));

        $categories = $this->em->getConnection()->fetchAllKeyValue('SELECT code, label FROM request_beneficiary_category ORDER BY position');
        self::assertSame(array_column(RequestReferentialCatalog::CATEGORIES, 'label', 'code'), $categories);
    }

    public function testReplayCreatesNothingAndKeepsIdentifiersAndAdministeredValues(): void
    {
        $this->initializer()->initialize();
        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE request_motif SET label = 'Libellé modifié', active = 0 WHERE code = 'home'");
        $before = $connection->fetchAllKeyValue('SELECT code, LOWER(HEX(id)) FROM request_motif ORDER BY position');
        $this->em->clear();

        self::assertSame(['motifs' => [], 'categories' => []], $this->initializer()->initialize());

        self::assertSame($before, $connection->fetchAllKeyValue('SELECT code, LOWER(HEX(id)) FROM request_motif ORDER BY position'));
        $home = $connection->fetchAssociative("SELECT label, active FROM request_motif WHERE code = 'home'");
        self::assertSame('Libellé modifié', $home['label'] ?? null);
        self::assertSame(0, (int) ($home['active'] ?? 1));
    }

    public function testAMissingEntryIsRecreatedWithoutTouchingTheOthers(): void
    {
        $this->initializer()->initialize();
        $connection = $this->em->getConnection();
        $connection->executeStatement("DELETE FROM request_beneficiary_category WHERE code = 'volunteer'");
        $this->em->clear();

        self::assertSame(['motifs' => [], 'categories' => ['volunteer']], $this->initializer()->initialize());
        self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM request_beneficiary_category'));
    }
}
