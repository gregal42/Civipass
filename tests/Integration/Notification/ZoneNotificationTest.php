<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Notification\Application\KeyRingProvider;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\AuthorizationZoneService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\Result\ZoneChangeRequest;
use App\Permit\Domain\PermitRevisionRepository;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\ProposableZones;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Notifications de modification des zones (LOT 05.5.3, DM-119 §5) : ajout ou réintégration ordinaires et regroupés, retrait (ou opération mixte) PRIORITAIRE ; une
 * opération mixte décrit tous ses changements ; jamais de motif ni de commentaire ; couverture par autorisation (jamais d'une commune à l'autre) ; un ajout attend
 * (de façon bornée) un retrait en attente ; un retrait obsolète après révocation est conservé `SUPERSEDED_BY_REVOCATION` avec audit ; message exact quand la liste a
 * changé entre la décision et l'envoi ; pannes temporaires et définitives. Horloge figée au 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class ZoneNotificationTest extends MunicipalDecisionTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private KeyRingProvider $keys;
    private RecordingMailer $mailer;
    private EventZone $zoneB;
    private EventZone $zoneC;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
        $this->mailer = new RecordingMailer($this->em->getConnection());
        $participation = $this->em->find(\App\Event\Domain\EventParticipation::class, $this->organizerParticipation->id()) ?? self::fail();
        $this->zoneB = F::readyZone($participation);
        $this->zoneC = F::readyZone($participation);
        $this->save($this->zoneB->createdBy(), $this->zoneB, $this->zoneC->createdBy(), $this->zoneC);
        foreach ([[$this->organizerZone, 'Zone A'], [$this->zoneB, 'Zone B'], [$this->zoneC, 'Zone C'], [$this->partnerZone, 'Zone partenaire']] as [$zone, $label]) {
            $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => $label, 'id' => $zone->id()->toBinary()]);
        }
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function approved(bool $both = false): string
    {
        $zones = [$this->organizerZone->id()->toRfc4122()];
        if ($both) {
            $zones[] = $this->partnerZone->id()->toRfc4122();
        }
        $id = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            null,
            'lea@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER'],
            $zones,
            true,
        ), $this->key())->requestId;
        foreach ($both ? [[$this->instructor, $this->organizer], [$this->partnerInstructor, $this->partner]] : [[$this->instructor, $this->organizer]] as [$agent, $commune]) {
            $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        }
        $this->clock->sleep(700);
        self::assertSame($both ? ['sent' => 1, 'suppressed' => 1] : ['sent' => 1], $this->dispatch(), 'mise à disposition (le message de la 2e commune est couvert par le premier)');
        $this->mailer->sent = [];
        $this->mailer->tried = [];
        $this->mailer->attempts = 0;

        return $id;
    }

    private function zones(): AuthorizationZoneService
    {
        $c = self::getContainer();

        return new AuthorizationZoneService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $c->get(EventZoneRepository::class), $c->get(ProposableZones::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class));
    }

    /**
     * @param list<EventZone> $adds
     * @param list<EventZone> $removes
     * @param list<EventZone> $reinstates
     */
    private function change(string $requestId, array $adds = [], array $removes = [], array $reinstates = [], ?User $agent = null, ?Municipality $commune = null): void
    {
        $ids = static fn (array $zones): array => array_map(static fn (EventZone $z): string => $z->id()->toRfc4122(), $zones);
        $this->zones()->change($this->managed($agent ?? $this->instructor), ($commune ?? $this->organizer)->id()->toRfc4122(), $requestId, new ZoneChangeRequest($ids($adds), $ids($removes), $ids($reinstates), 'OTHER', 'OTHER', 'OTHER', self::COMMENT));
    }

    /** @return array<string, int> */
    private function dispatch(): array
    {
        return NotificationKit::dispatcher(self::getContainer(), $this->keys, $this->clock, $this->mailer)->run()->outcomes;
    }

    /** @return list<array<string, mixed>> */
    private function outbox(string $type): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT status, attempts, last_error_code, available_at, occurred_at, covered_revisions FROM notification_outbox WHERE type = :t ORDER BY occurred_at, id', ['t' => $type]);
    }

    private function assertNoLeak(string $text): void
    {
        foreach ([self::COMMENT, 'Autre motif', 'OTHER', 'NEED_ENDED', 'http'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text, 'ni motif, ni commentaire, ni lien : '.$forbidden);
        }
    }

    public function testAnAdditionIsAnnouncedOrdinarilyWithTheZoneNamesAndNoReason(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: [$this->zoneB]);

        $row = $this->outbox('ZONES_CHANGED')[0];
        self::assertNotSame($row['occurred_at'], $row['available_at'], 'ordinaire : regroupée');
        self::assertSame([], $this->dispatch(), 'rien avant l\'échéance du regroupement');
        $this->clock->sleep(700);

        self::assertSame(['sent' => 1], $this->dispatch());
        $text = $this->mailer->sent[0]['text'];
        self::assertStringContainsString($this->organizer->name(), $text);
        self::assertStringContainsString('Zones ajoutées : Zone B', $text);
        self::assertStringContainsString('Zone A, Zone B', $text, 'la liste d\'aujourd\'hui');
        $this->assertNoLeak($text);
    }

    public function testARemovalIsPriorityAndNeverGroupedAndSaysWhichZonesLostTheirRights(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->clock->sleep(700);
        $this->dispatch();
        $this->mailer->sent = [];
        $this->change($requestId, removes: [$this->organizerZone]);

        $row = $this->outbox('ZONES_REMOVED')[0];
        self::assertSame($row['occurred_at'], $row['available_at'], 'prioritaire : aucun délai');
        self::assertSame(['sent' => 1], $this->dispatch());

        $text = $this->mailer->sent[0]['text'];
        self::assertStringContainsString('Zones retirées (vous n\'avez plus de droit dans ces zones) : Zone A', $text);
        self::assertStringContainsString('Zone B', $text);
        $this->assertNoLeak($text);
    }

    public function testAMixedOperationDescribesEveryChange(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->change($requestId, removes: [$this->zoneB]);
        $this->clock->sleep(700);
        $this->dispatch();
        $this->mailer->sent = [];

        $this->change($requestId, adds: [$this->zoneC], removes: [$this->organizerZone], reinstates: [$this->zoneB]);
        $this->dispatch();

        $text = $this->mailer->sent[0]['text'];
        foreach (['Zones retirées (vous n\'avez plus de droit dans ces zones) : Zone A', 'Zones ajoutées : Zone C', 'Zones réintégrées : Zone B'] as $expected) {
            self::assertStringContainsString($expected, $text, $expected);
        }
        $this->assertNoLeak($text);
    }

    public function testAnAdditionWaitsForAPendingRemovalAndIsCoveredByItNeverAnnouncedFirst(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->change($requestId, removes: [$this->organizerZone]);
        $this->clock->sleep(700);

        $this->dispatch();
        $this->dispatch();

        self::assertCount(1, $this->mailer->sent, 'un seul message : le retrait décrit l\'état final');
        self::assertStringContainsString('Zones retirées', $this->mailer->sent[0]['text']);
        $added = $this->outbox('ZONES_CHANGED')[0];
        self::assertSame(['SUPPRESSED', 'ALREADY_INCLUDED'], [$added['status'], $added['last_error_code']]);
        self::assertSame('SENT', $this->outbox('ZONES_REMOVED')[0]['status']);
    }

    public function testTwoPendingAdditionsProduceOneMessageWhichSaysTheListEvolved(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->clock->sleep(30);
        $this->change($requestId, adds: [$this->zoneC]);
        $this->clock->sleep(700);

        self::assertSame(['sent' => 1, 'suppressed' => 1], $this->dispatch());

        self::assertCount(1, $this->mailer->sent);
        $text = $this->mailer->sent[0]['text'];
        self::assertStringContainsString('a encore évolué', $text);
        self::assertStringContainsString('Zone A, Zone B, Zone C', $text, 'la situation d\'aujourd\'hui');
    }

    public function testTwoCommunesChangesAreNeverCoveredByEachOther(): void
    {
        $requestId = $this->approved(both: true);
        $partnerParticipation = $this->em->find(\App\Event\Domain\EventParticipation::class, $this->partnerParticipation->id()) ?? self::fail();
        $partnerExtra = F::readyZone($partnerParticipation);
        $this->save($partnerExtra->createdBy(), $partnerExtra);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => 'Zone partenaire 2', 'id' => $partnerExtra->id()->toBinary()]);
        $this->change($requestId, adds: [$this->zoneB]);
        $this->change($requestId, adds: [$partnerExtra], agent: $this->partnerInstructor, commune: $this->partner);
        $this->clock->sleep(700);

        $this->dispatch();

        self::assertCount(2, $this->mailer->sent, 'une annonce par commune');
        $texts = implode("\n", array_column($this->mailer->sent, 'text'));
        self::assertStringContainsString('Zones ajoutées : Zone B', $texts);
        self::assertStringContainsString('Zones ajoutées : Zone partenaire 2', $texts);
    }

    public function testARemovalMadeObsoleteByARevocationIsKeptSuppressedWithAnExplicitReasonAndAudit(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->change($requestId, removes: [$this->organizerZone]);
        $c = self::getContainer();
        (new AuthorizationRevocationService($c->get(EventTransactions::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->revoke($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, 'DUPLICATE', null, true);
        $this->clock->sleep(700);

        $this->dispatch();
        $this->dispatch();

        self::assertCount(1, $this->mailer->sent, 'seule la révocation est annoncée');
        self::assertStringContainsString('retiré son autorisation', $this->mailer->sent[0]['text']);
        $removed = $this->outbox('ZONES_REMOVED')[0];
        self::assertSame(['SUPPRESSED', 'SUPERSEDED_BY_REVOCATION'], [$removed['status'], $removed['last_error_code']], 'trace conservée, justification explicite');
        $audits = $this->em->getConnection()->fetchFirstColumn("SELECT new_value FROM audit_log WHERE action = 'NOTIFICATION_SUPPRESSED'");
        $reasons = array_map(static fn (string $j): string => json_decode($j, true)['reason'], $audits);
        self::assertContains('SUPERSEDED_BY_REVOCATION', $reasons);
    }

    public function testTheMessageIsExactWhenTheListChangedBetweenTheDecisionAndTheSending(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->clock->sleep(700);
        $this->dispatch();
        $this->mailer->sent = [];
        $this->change($requestId, removes: [$this->zoneB]);
        $this->clock->sleep(30);
        $this->change($requestId, adds: [$this->zoneC]); // la liste évolue encore avant l'envoi du retrait

        $this->dispatch();

        $text = $this->mailer->sent[0]['text'];
        self::assertStringContainsString('Zones retirées (vous n\'avez plus de droit dans ces zones) : Zone B', $text);
        self::assertStringContainsString('a encore évolué', $text);
        self::assertStringContainsString('Zone A, Zone C', $text, 'jamais une liste périmée');
    }

    public function testATemporaryFailureOfThePriorityRemovalIsRetriedThenSentOnce(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->change($requestId, removes: [$this->organizerZone]);
        $this->mailer->script(RecordingMailer::smtpFailure(451));

        self::assertSame(['retry' => 1], $this->dispatch());
        self::assertSame('PENDING', $this->outbox('ZONES_REMOVED')[0]['status']);
        $this->clock->sleep(61);
        self::assertSame(['sent' => 1], array_intersect_key($this->dispatch(), ['sent' => 1]));

        self::assertSame('SENT', $this->outbox('ZONES_REMOVED')[0]['status']);
        self::assertCount(1, array_unique(array_column($this->mailer->tried, 'id')), 'Message-ID stable');
    }

    public function testAnAdditionIsNotHeldForeverByABlockedRemoval(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->clock->sleep(30);
        $this->change($requestId, removes: [$this->organizerZone]);
        $this->sql("UPDATE notification_outbox SET status = 'RESERVED', attempts = 1, first_attempt_at = :n, leased_until = :l WHERE type = 'ZONES_REMOVED'", ['n' => $this->clock->now()->format('Y-m-d H:i:s'), 'l' => $this->clock->now()->modify('+30 days')->format('Y-m-d H:i:s')]);
        $this->clock->sleep(700);

        self::assertSame(['held' => 1], $this->dispatch(), 'tant que le blocage est récent, l\'ajout attend');
        $this->clock->sleep(25 * 3600);

        self::assertSame(['sent' => 1], $this->dispatch(), 'au-delà de 24 h il part : jamais de blocage indéfini');
    }

    public function testALabelCorrectionNeverNotifiesAnyone(): void
    {
        $requestId = $this->approved();
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => 'Zone A corrigée', 'id' => $this->organizerZone->id()->toBinary()]);

        $this->zones()->correctLabels($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, [$this->organizerZone->id()->toRfc4122()], 'TYPO', true);
        $this->clock->sleep(86400);

        self::assertSame([], $this->dispatch());
        self::assertSame(0, $this->mailer->attempts);
    }
}
