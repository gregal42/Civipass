<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditLog;
use App\Shared\Domain\AuditOrigin;
use App\Shared\Domain\AuditSubjectType;
use App\Tests\Support\EventSchemaTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * operator_id et batch_id sont réellement enregistrés (DT-022) ; actor_id reste l'identité métier.
 */
final class AuditOperatorBatchTest extends EventSchemaTestCase
{
    private static function event(AuditActor $actor, ?Uuid $batch = null, AuditAction $action = AuditAction::ZoneBulkOperation): AuditEvent
    {
        return new AuditEvent(new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC')), $action, AuditSubjectType::EventZone, Uuid::v7(), Uuid::v7(), $actor, null, ['status' => 'SUSPENDED'], $batch);
    }

    private function record(AuditEvent ...$events): void
    {
        $trail = $this->service(AuditTrail::class);
        foreach ($events as $event) {
            $trail->record($event);
        }
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(Uuid $subject): array
    {
        return $this->em->getConnection()->fetchAssociative(
            'SELECT LOWER(HEX(actor_id)) actor, LOWER(HEX(operator_id)) operator, LOWER(HEX(batch_id)) batch, origin FROM audit_log WHERE subject_id = ?',
            [$subject->toBinary()],
        ) ?: self::fail('Ligne d\'audit absente.');
    }

    public function testActorOperatorAndBatchArePersistedAndDistinct(): void
    {
        $actor = Uuid::v7();
        $operator = Uuid::v7();
        $batch = Uuid::v7();
        $event = self::event(AuditActor::support($operator, $actor), $batch);

        $this->record($event);

        $row = $this->raw($event->subjectId);
        self::assertSame(str_replace('-', '', $actor->toRfc4122()), $row['actor'], 'actor_id = utilisateur métier');
        self::assertSame(str_replace('-', '', $operator->toRfc4122()), $row['operator'], 'operator_id = opérateur CWS réel');
        self::assertSame(str_replace('-', '', $batch->toRfc4122()), $row['batch']);
        self::assertSame('SUPPORT', $row['origin']);
    }

    public function testRealOperatorWithoutBusinessAccountForcedClosureShape(): void
    {
        $operator = Uuid::v7();
        $event = self::event(AuditActor::support($operator), null, AuditAction::EventClosed);

        $this->record($event);

        $row = $this->raw($event->subjectId);
        self::assertNull($row['actor'], 'aucun compte métier');
        self::assertSame(str_replace('-', '', $operator->toRfc4122()), $row['operator']);
        self::assertNull($row['batch']);
    }

    public function testOrdinaryEventsKeepOperatorAndBatchNull(): void
    {
        $user = Uuid::v7();
        $event = self::event(AuditActor::user($user));

        $this->record($event);

        $row = $this->raw($event->subjectId);
        self::assertSame(str_replace('-', '', $user->toRfc4122()), $row['actor']);
        self::assertNull($row['operator']);
        self::assertNull($row['batch']);
        self::assertSame(AuditOrigin::User->value, $row['origin']);
    }

    public function testEntityExposesBothFieldsAfterReload(): void
    {
        $batch = Uuid::v7();
        $operator = Uuid::v7();
        $first = self::event(AuditActor::support($operator, Uuid::v7()), $batch);
        $second = self::event(AuditActor::support($operator, Uuid::v7()), $batch);

        $this->record($first, $second);

        $logs = $this->em->getRepository(AuditLog::class)->findBy(['batchId' => $batch]);
        self::assertCount(2, $logs, 'les écritures d\'une même opération partagent le batch_id');
        foreach ($logs as $log) {
            self::assertTrue($operator->equals($log->operatorId() ?? self::fail('operator_id attendu')));
            self::assertTrue($batch->equals($log->batchId() ?? self::fail('batch_id attendu')));
        }
        self::assertCount(2, $this->em->getRepository(AuditLog::class)->findBy(['operatorId' => $operator]));
    }

    public function testNewIndexesExistOnTheRealTable(): void
    {
        $indexes = $this->em->getConnection()->fetchFirstColumn("SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'audit_log'");

        self::assertContains('idx_audit_operator', $indexes);
        self::assertContains('idx_audit_batch', $indexes);
    }
}
