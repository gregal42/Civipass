<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Application;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\Result\CreatedEvent;
use App\Event\Application\Result\InvitationResult;
use App\Event\Application\Support\EventAuditRecorder;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Tests\Support\EventFixtures as F;
use PHPUnit\Framework\TestCase;

/**
 * Contrats de la sous-étape 5.2 : commandes et résultats immuables sans entité, instantanés d'audit sans texte libre,
 * conflit technique distinct des refus métier.
 */
final class ServiceContractsTest extends TestCase
{
    public function testCommandsAndResultsAreImmutableAndCarryNoEntity(): void
    {
        foreach ([CreateEventCommand::class, InviteMunicipalityCommand::class, CreatedEvent::class, InvitationResult::class] as $class) {
            $reflection = new \ReflectionClass($class);
            self::assertTrue($reflection->isFinal(), $class);
            self::assertTrue($reflection->isReadOnly(), $class);
            foreach ($reflection->getProperties() as $property) {
                $type = (string) $property->getType();
                foreach (['Municipality', 'User', 'Doctrine', 'EventParticipation', 'EventZone'] as $forbidden) {
                    self::assertStringNotContainsString($forbidden, str_replace('ParticipationStatus', '', $type), $class.'::'.$property->getName());
                }
                self::assertStringNotContainsString('Domain\\Event;', $type);
            }
        }
    }

    public function testAnInvitationResultStatesItRecordsARequestNotADelivery(): void
    {
        $properties = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(InvitationResult::class))->getProperties());

        self::assertContains('lastInvitationSentAt', $properties);
        foreach ($properties as $property) {
            self::assertDoesNotMatchRegularExpression('/deliver|delivered|sent(Ok|Successfully)|emailSent|smsSent/i', $property, 'aucun accusé d\'envoi : pas de mécanisme de notification');
        }
    }

    public function testOperationConflictIsTechnicalAndNotABusinessRefusal(): void
    {
        $conflict = new OperationConflict(ConflictCause::LockWaitTimeout);

        self::assertNotContains(DomainRuleViolation::class, class_parents($conflict), 'un conflit technique n\'est pas un refus métier');
        self::assertInstanceOf(\RuntimeException::class, $conflict);
        self::assertSame(['LOCK_WAIT_TIMEOUT', 'DEADLOCK', 'UNIQUE_VIOLATION', 'STALE_STATE'], array_map(static fn (ConflictCause $c): string => $c->value, ConflictCause::cases()), 'les quatre seuls cas pris en charge');
    }

    public function testAuditStatesContainOnlyStructuredBusinessFieldsAndNoFreeText(): void
    {
        $event = F::draft();
        $event->describe('Description libre', F::now());
        $event->changeOperationalInstructions('Consignes libres', F::now());
        $participation = F::organizerParticipation($event);
        $zone = F::zone($participation);

        $states = [EventAuditRecorder::eventState($event), EventAuditRecorder::participationState($participation), EventAuditRecorder::zoneState($zone, 'AUTO_PROPOSAL')];
        $json = json_encode($states, \JSON_THROW_ON_ERROR);

        foreach (['Incendie du massif', 'Description libre', 'Consignes libres', 'Route départementale 107'] as $freeText) {
            self::assertStringNotContainsString($freeText, $json);
        }
        self::assertSame(['reference', 'type', 'timezone', 'startsAt', 'endsAt', 'status'], array_keys($states[0]));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $states[0]['startsAt'], 'UTC');
        self::assertSame(['event', 'municipality', 'status', 'organizer'], array_keys($states[1]));
        self::assertSame('AUTO_PROPOSAL', $states[2]['origin']);
    }
}
