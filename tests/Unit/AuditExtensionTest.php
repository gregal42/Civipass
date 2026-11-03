<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Application\AuditEvent;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditOrigin;
use App\Shared\Domain\AuditSubjectType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contrats d'audit préparés pour le module Event (DT-022). Aucun accès à la base.
 */
final class AuditExtensionTest extends TestCase
{
    public function testActorKeepsTheBusinessUserAndTheRealOperatorApart(): void
    {
        $operator = Uuid::v7();
        $user = Uuid::v7();

        $impersonation = AuditActor::support($operator, $user);
        $forced = AuditActor::support($operator);
        $plain = AuditActor::user($user);

        self::assertSame(AuditOrigin::Support, $impersonation->origin);
        self::assertSame($user, $impersonation->id, 'actor_id = utilisateur métier dont les droits sont exercés');
        self::assertSame($operator, $impersonation->operatorId, 'operator_id = opérateur CWS réel');
        self::assertNull($forced->id, 'clôture forcée : aucun compte métier');
        self::assertSame($operator, $forced->operatorId);
        self::assertNull($plain->operatorId, 'sans assistance, opérateur = acteur');
        self::assertNull(AuditActor::console()->operatorId);
        self::assertNull(AuditActor::system()->operatorId);
    }

    public function testEventsCarryAnOptionalBatchId(): void
    {
        $batch = Uuid::v7();
        $event = new AuditEvent(new \DateTimeImmutable(), AuditAction::ZoneBulkOperation, AuditSubjectType::EventZone, Uuid::v7(), Uuid::v7(), AuditActor::user(Uuid::v7()), null, ['status' => 'SUSPENDED'], $batch);
        $without = new AuditEvent(new \DateTimeImmutable(), AuditAction::ZoneCreated, AuditSubjectType::EventZone, Uuid::v7(), null, AuditActor::console(), null, []);

        self::assertSame($batch, $event->batchId);
        self::assertNull($without->batchId);
    }

    public function testNewActionsAndSubjectsFitTheExistingVarcharColumnsWithoutMigration(): void
    {
        foreach (AuditAction::cases() as $action) {
            self::assertLessThanOrEqual(60, \strlen($action->value), $action->value);
        }
        foreach (AuditSubjectType::cases() as $subject) {
            self::assertLessThanOrEqual(40, \strlen($subject->value), $subject->value);
        }
        foreach (AuditOrigin::cases() as $origin) {
            self::assertLessThanOrEqual(16, \strlen($origin->value), $origin->value);
        }
        self::assertCount(\count(AuditAction::cases()), array_unique(array_map(static fn (AuditAction $a): string => $a->value, AuditAction::cases())));
    }

    public function testEventModuleSubjectsAndActionsExist(): void
    {
        foreach (['EVENT', 'EVENT_PARTICIPATION', 'EVENT_ZONE', 'EVENT_ZONE_VALIDITY', 'EVENT_TYPE', 'MUNICIPALITY_COMMERCIAL_ACCESS'] as $subject) {
            self::assertContains($subject, array_map(static fn (AuditSubjectType $c): string => $c->value, AuditSubjectType::cases()));
        }
        foreach (['EVENT_CLOSED', 'PARTICIPATION_ACCEPTED', 'ZONE_DELETED', 'ZONE_VALIDITY_SLOT_DELETED', 'ZONE_BULK_OPERATION', 'COMMERCIAL_ACCESS_GRANTED', 'COMMERCIAL_ACCESS_REVOKED'] as $action) {
            self::assertContains($action, array_map(static fn (AuditAction $c): string => $c->value, AuditAction::cases()));
        }
    }
}
