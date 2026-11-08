<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Request\Application\RefusalReasonInitializer;
use App\Request\Domain\RequestReferentialCatalog;
use App\Request\Infrastructure\DoctrineRefusalReasonRepository;
use App\Shared\Application\TransactionRunner;
use App\Tests\Support\RequestSchemaTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('requires-request-schema')]
final class RefusalReasonInitializerTest extends RequestSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['request_refusal_reason'])) {
            self::markTestSkipped('Schéma de décision non migré : test exécutable après `make test-db` (LOT 04, incrément 04.1).');
        }
    }

    private function initializer(): RefusalReasonInitializer
    {
        return new RefusalReasonInitializer(new DoctrineRefusalReasonRepository($this->em), $this->service(TransactionRunner::class));
    }

    public function testInitializationCreatesTheSevenValidatedReasonsInOrder(): void
    {
        $created = $this->initializer()->initialize();

        self::assertSame(['ineligible_beneficiary', 'reason_not_justified', 'documents_insufficient', 'zone_not_concerned', 'duplicate', 'inconsistent_information', 'other'], $created);
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT code, label, position, active, needs_detail FROM request_refusal_reason ORDER BY position');
        self::assertSame([10, 20, 30, 40, 50, 60, 70], array_map(static fn (array $r): int => (int) $r['position'], $rows));
        self::assertSame(array_column(RequestReferentialCatalog::REFUSAL_REASONS, 'label', 'code'), array_column($rows, 'label', 'code'));
        self::assertSame(['other'], array_column(array_filter($rows, static fn (array $r): bool => 1 === (int) $r['needs_detail']), 'code'));
        self::assertSame([1], array_values(array_unique(array_map(static fn (array $r): int => (int) $r['active'], $rows))));
    }

    public function testInitializationIsIdempotentAndNeverOverwritesAdministeredEntries(): void
    {
        $this->initializer()->initialize();
        $this->em->getConnection()->executeStatement("UPDATE request_refusal_reason SET label = 'Doublon', active = 0, position = 5 WHERE code = 'duplicate'");
        $this->em->clear();

        self::assertSame([], $this->initializer()->initialize());
        $row = $this->em->getConnection()->fetchAssociative("SELECT label, active, position FROM request_refusal_reason WHERE code = 'duplicate'");
        self::assertSame(['Doublon', 0, 5], [$row['label'] ?? null, (int) ($row['active'] ?? -1), (int) ($row['position'] ?? -1)]);
        self::assertSame(7, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM request_refusal_reason'));
    }

    public function testOnlyActiveReasonsAreProposed(): void
    {
        $this->initializer()->initialize();
        $this->em->getConnection()->executeStatement("UPDATE request_refusal_reason SET active = 0 WHERE code = 'duplicate'");
        $this->em->clear();

        $codes = array_map(static fn ($r): string => $r->code(), (new DoctrineRefusalReasonRepository($this->em))->active());
        self::assertNotContains('duplicate', $codes);
        self::assertCount(6, $codes);
    }
}
