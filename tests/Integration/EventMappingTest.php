<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventReferenceCounter;
use App\Event\Domain\EventType;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Shared\Domain\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cohérence des mappings du module Event, SANS accès à la base : valide uniquement les métadonnées.
 */
final class EventMappingTest extends KernelTestCase
{
    private const CLASSES = [
        EventType::class => 'event_type',
        Event::class => 'event_event',
        EventParticipation::class => 'event_participation',
        EventZone::class => 'event_zone',
        EventZoneValidity::class => 'event_zone_validity',
        EventReferenceCounter::class => 'event_reference_counter',
        MunicipalityCommercialAccess::class => 'municipality_commercial_access',
    ];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
    }

    public function testMappingIsValid(): void
    {
        self::assertSame([], (new SchemaValidator($this->em))->validateMapping());
    }

    public function testTableNames(): void
    {
        foreach (self::CLASSES as $class => $table) {
            self::assertSame($table, $this->em->getClassMetadata($class)->getTableName(), $class);
        }
    }

    public function testEveryForeignKeyIsRestrict(): void
    {
        $checked = 0;
        foreach (array_keys(self::CLASSES) as $class) {
            foreach ($this->em->getClassMetadata($class)->associationMappings as $name => $mapping) {
                foreach ($mapping->joinColumns ?? [] as $join) {
                    self::assertSame('RESTRICT', strtoupper((string) $join->onDelete), $class.'::'.$name);
                    ++$checked;
                }
            }
        }
        self::assertSame(12, $checked, 'event_event 3, event_participation 3, event_zone 2, event_zone_validity 1, municipality_commercial_access 3');
    }

    public function testNoInverseSideNorCascade(): void
    {
        foreach (array_keys(self::CLASSES) as $class) {
            foreach ($this->em->getClassMetadata($class)->associationMappings as $name => $mapping) {
                self::assertTrue($mapping->isToOne(), $class.'::'.$name);
                self::assertFalse($mapping->isCascadePersist() || $mapping->isCascadeRemove(), $class.'::'.$name);
            }
        }
    }

    public function testIdentifiersAreUuidV7StoredAsBinaryExceptTheSingleRowCounter(): void
    {
        foreach (array_keys(self::CLASSES) as $class) {
            $metadata = $this->em->getClassMetadata($class);
            $type = $metadata->getTypeOfField($metadata->getSingleIdentifierFieldName());
            self::assertSame(EventReferenceCounter::class === $class ? 'smallint' : 'uuid', $type, $class);
            self::assertSame(ClassMetadata::GENERATOR_TYPE_NONE, $metadata->generatorType, $class);
        }
    }

    public function testUniqueConstraintsAndIndexes(): void
    {
        $unique = static fn (ClassMetadata $m): array => array_map(static fn (array $c): array => $c['columns'], $m->table['uniqueConstraints'] ?? []);
        $indexes = static fn (ClassMetadata $m): array => array_map(static fn (array $c): array => $c['columns'], $m->table['indexes'] ?? []);

        self::assertSame(['event_id', 'municipality_id'], $unique($this->em->getClassMetadata(EventParticipation::class))['uniq_event_participation_event_municipality']);
        self::assertSame(['public_reference'], $unique($this->em->getClassMetadata(Event::class))['uniq_event_public_reference']);
        self::assertSame(['code'], $unique($this->em->getClassMetadata(EventType::class))['uniq_event_type_code']);

        self::assertSame(['organizer_municipality_id', 'status'], $indexes($this->em->getClassMetadata(Event::class))['idx_event_organizer_status']);
        self::assertSame(['status', 'starts_at'], $indexes($this->em->getClassMetadata(Event::class))['idx_event_status_starts_at']);
        self::assertSame(['municipality_id', 'status'], $indexes($this->em->getClassMetadata(EventParticipation::class))['idx_event_participation_municipality_status']);
        self::assertSame(['participation_id', 'status', 'deleted_at'], $indexes($this->em->getClassMetadata(EventZone::class))['idx_event_zone_participation_status']);
        self::assertSame(['zone_id', 'deleted_at'], $indexes($this->em->getClassMetadata(EventZoneValidity::class))['idx_event_zone_validity_zone']);
        self::assertSame(['municipality_id', 'granted_at'], $indexes($this->em->getClassMetadata(MunicipalityCommercialAccess::class))['idx_commercial_access_municipality']);
    }

    public function testEnumsAndDatesAreStoredPortably(): void
    {
        $event = $this->em->getClassMetadata(Event::class);
        self::assertSame('string', $event->getTypeOfField('status'));
        self::assertSame('datetime_immutable', $event->getTypeOfField('startsAt'), 'UTC via le type surchargé');
        self::assertSame('datetime_immutable', $event->getTypeOfField('endsAt'));

        $validity = $this->em->getClassMetadata(EventZoneValidity::class);
        self::assertSame('date_immutable', $validity->getTypeOfField('startDate'), 'horaires locaux : types DATE/TIME, jamais UTC');
        self::assertSame('time_immutable', $validity->getTypeOfField('startTime'));
        self::assertSame('json', $validity->getTypeOfField('dstChoices'));
        self::assertSame('datetime_immutable', $validity->getTypeOfField('deletedAt'));
        self::assertTrue($validity->isNullable('deletedAt'));
        self::assertTrue($this->em->getClassMetadata(EventZone::class)->isNullable('deletedAt'));
        self::assertTrue($this->em->getClassMetadata(EventZone::class)->isNullable('confirmedAt'));
    }

    public function testAuditLogMapsOperatorAndBatchWithTheirIndexes(): void
    {
        $columns = array_values(array_map(static fn ($mapping): string => $mapping->columnName, $this->em->getClassMetadata(AuditLog::class)->fieldMappings));

        foreach (['actor_id', 'operator_id', 'batch_id'] as $column) {
            self::assertContains($column, $columns);
        }
        $indexes = array_map(static fn (array $c): array => $c['columns'], $this->em->getClassMetadata(AuditLog::class)->table['indexes']);
        self::assertSame(['operator_id'], $indexes['idx_audit_operator']);
        self::assertSame(['batch_id'], $indexes['idx_audit_batch']);
    }
}
