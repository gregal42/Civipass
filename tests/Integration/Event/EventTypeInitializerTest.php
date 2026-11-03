<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\EventTypeInitializer;
use App\Event\Domain\EventType;
use App\Event\Domain\EventTypeCatalog;
use App\Event\Domain\EventTypeRepository;
use App\Tests\Support\EventSchemaTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('requires-event-schema')]
final class EventTypeInitializerTest extends EventSchemaTestCase
{
    private function initializer(): EventTypeInitializer
    {
        return $this->service(EventTypeInitializer::class);
    }

    /**
     * @return array<string, string> code => identifiant
     */
    private function ids(): array
    {
        $this->em->clear();

        return $this->em->getConnection()->fetchAllKeyValue('SELECT code, LOWER(HEX(id)) FROM event_type ORDER BY sort_order');
    }

    public function testCreatesTheNineValidatedTypes(): void
    {
        $created = $this->initializer()->initialize();

        self::assertSame(['fire', 'flood', 'storm', 'accident', 'security', 'sport', 'cultural', 'works', 'other'], $created);
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT code, label, sort_order, active FROM event_type ORDER BY sort_order');
        self::assertCount(9, $rows);
        self::assertSame('Tempête / événement climatique', $rows[2]['label']);
        self::assertSame('Travaux / intervention technique', $rows[7]['label']);
        self::assertSame([10, 20, 30, 40, 50, 60, 70, 80, 90], array_map(static fn (array $r): int => (int) $r['sort_order'], $rows));
        self::assertSame([1], array_values(array_unique(array_map(static fn (array $r): int => (int) $r['active'], $rows))));
    }

    public function testReplayCreatesNoDuplicateAndKeepsIdentifiers(): void
    {
        $this->initializer()->initialize();
        $before = $this->ids();

        self::assertCount(0, $this->initializer()->initialize());
        self::assertCount(0, $this->initializer()->initialize());

        self::assertSame($before, $this->ids(), 'identifiants conservés lors d\'une réexécution');
        self::assertSame(9, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM event_type'));
    }

    public function testAdministrativeChangesAreNeverOverwritten(): void
    {
        $this->initializer()->initialize();
        $repository = $this->service(EventTypeRepository::class);
        $fire = $repository->findByCode('fire');
        self::assertNotNull($fire);
        $fire->relabel('Feu de forêt');
        $fire->reorder(5);
        $fire->deactivate();
        $this->em->flush();
        $this->em->clear();

        self::assertCount(0, $this->initializer()->initialize());

        $this->em->clear();
        $reloaded = $this->em->getRepository(EventType::class)->findOneBy(['code' => 'fire']);
        self::assertNotNull($reloaded);
        self::assertSame('Feu de forêt', $reloaded->label());
        self::assertSame(5, $reloaded->sortOrder());
        self::assertFalse($reloaded->isActive());
    }

    public function testOnlyMissingTypesAreCreated(): void
    {
        $this->save(new EventType('flood', 'Crue'), new EventType('custom', 'Type ajouté plus tard'));

        $created = $this->initializer()->initialize();

        self::assertSame(['fire', 'storm', 'accident', 'security', 'sport', 'cultural', 'works', 'other'], $created);
        self::assertSame('Crue', $this->service(EventTypeRepository::class)->findByCode('flood')?->label());
        self::assertSame(10, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM event_type'));
    }

    public function testCatalogMatchesTheValidatedList(): void
    {
        self::assertSame(
            ['fire' => 'Incendie', 'flood' => 'Inondation', 'storm' => 'Tempête / événement climatique', 'accident' => 'Accident / incident majeur', 'security' => 'Sécurité publique', 'sport' => 'Manifestation sportive', 'cultural' => 'Manifestation culturelle', 'works' => 'Travaux / intervention technique', 'other' => 'Autre événement'],
            array_column(EventTypeCatalog::DEFAULTS, 'label', 'code'),
        );
    }
}
