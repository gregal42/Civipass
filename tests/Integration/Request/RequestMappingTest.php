<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Request\Domain\BeneficiaryCategory;
use App\Request\Domain\PermitRequest;
use App\Request\Domain\PermitRequestAttachment;
use App\Request\Domain\PermitRequestMunicipality;
use App\Request\Domain\PermitRequestVehicle;
use App\Request\Domain\PermitRequestZone;
use App\Request\Domain\RequestMotif;
use App\Request\Domain\RequestReferenceCounter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cohérence des mappings du module Request, SANS accès à la base : valide uniquement les métadonnées.
 */
final class RequestMappingTest extends KernelTestCase
{
    private const CLASSES = [
        RequestMotif::class => 'request_motif',
        BeneficiaryCategory::class => 'request_beneficiary_category',
        PermitRequest::class => 'request_permit_request',
        PermitRequestMunicipality::class => 'request_permit_request_municipality',
        PermitRequestZone::class => 'request_permit_request_zone',
        PermitRequestVehicle::class => 'request_permit_request_vehicle',
        PermitRequestAttachment::class => 'request_permit_request_attachment',
        RequestReferenceCounter::class => 'request_reference_counter',
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
        // request 5 (catégorie, motif, agent, commune de saisie, événement), part 3, zone 2, véhicule 1, pièce 1.
        self::assertSame(12, $checked);
    }

    public function testNoOrphanRemovalNorCascadeRemove(): void
    {
        foreach (array_keys(self::CLASSES) as $class) {
            foreach ($this->em->getClassMetadata($class)->associationMappings as $name => $mapping) {
                self::assertFalse($mapping->isCascadeRemove(), $class.'::'.$name);
                self::assertFalse($mapping->orphanRemoval, $class.'::'.$name);
            }
        }
    }

    public function testIdentifiersAreUuidV7ExceptTheYearCounter(): void
    {
        foreach (array_keys(self::CLASSES) as $class) {
            $metadata = $this->em->getClassMetadata($class);
            self::assertSame(RequestReferenceCounter::class === $class ? 'smallint' : 'uuid', $metadata->getTypeOfField($metadata->getSingleIdentifierFieldName()), $class);
            self::assertSame(ClassMetadata::GENERATOR_TYPE_NONE, $metadata->generatorType, $class);
        }
    }

    public function testUniqueConstraintsAndIndexes(): void
    {
        $unique = fn (string $class): array => array_map(static fn (array $c): array => $c['columns'], $this->em->getClassMetadata($class)->table['uniqueConstraints'] ?? []);
        $indexes = fn (string $class): array => array_map(static fn (array $c): array => $c['columns'], $this->em->getClassMetadata($class)->table['indexes'] ?? []);

        self::assertSame(['code'], $unique(RequestMotif::class)['uniq_request_motif_code']);
        self::assertSame(['code'], $unique(BeneficiaryCategory::class)['uniq_request_beneficiary_category_code']);
        self::assertSame(['public_reference'], $unique(PermitRequest::class)['uniq_request_permit_request_reference']);
        self::assertSame(['request_id', 'participation_id'], $unique(PermitRequestMunicipality::class)['uniq_request_municipality_request_participation']);
        self::assertSame(['request_municipality_id', 'zone_id'], $unique(PermitRequestZone::class)['uniq_request_zone_part_zone']);
        self::assertSame(['request_id', 'registration'], $unique(PermitRequestVehicle::class)['uniq_request_vehicle_request_registration']);
        self::assertSame(['storage_key'], $unique(PermitRequestAttachment::class)['uniq_request_attachment_storage_key']);

        self::assertSame(['event_id', 'submitted_at'], $indexes(PermitRequest::class)['idx_request_permit_request_event_submitted']);
        self::assertSame(['municipality_id', 'status'], $indexes(PermitRequestMunicipality::class)['idx_request_municipality_municipality_status']);
    }
}
