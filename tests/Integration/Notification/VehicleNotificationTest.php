<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Notification\Application\KeyRingProvider;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitVehicleService;
use App\Permit\Application\Result\VehicleChangeRequest;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Notifications de modification des plaques (LOT 05.5.4, DM-119 §5) : ajout ou réactivation ordinaires et regroupés ; retrait, remplacement ou opération avec retrait
 * PRIORITAIRES ; une seule intention par opération ; JAMAIS de plaque ni de motif ni de commentaire ; couverture par LAISSEZ-PASSER (les plaques sont communes) ; un ajout
 * attend (de façon bornée) un retrait en attente ; un message reste exact tant qu'une autorisation non révoquée existe ; pannes temporaires. Horloge figée au 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class VehicleNotificationTest extends MunicipalDecisionTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private KeyRingProvider $keys;
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
        $this->mailer = new RecordingMailer($this->em->getConnection());
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
            ['QW-123-ER', 'TY-456-UI'],
            $zones,
            true,
        ), $this->key())->requestId;
        foreach ($both ? [[$this->instructor, $this->organizer], [$this->partnerInstructor, $this->partner]] : [[$this->instructor, $this->organizer]] as [$agent, $commune]) {
            $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        }
        $this->clock->sleep(700);
        self::assertSame($both ? ['sent' => 1, 'suppressed' => 1] : ['sent' => 1], $this->dispatch(), 'mise à disposition');
        $this->mailer->sent = [];
        $this->mailer->tried = [];
        $this->mailer->attempts = 0;

        return $id;
    }

    private function idOf(string $registration): string
    {
        return Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT id FROM permit_vehicle WHERE registration = :r', ['r' => $registration]))->toRfc4122();
    }

    /**
     * @param list<string>                          $adds
     * @param list<string>                          $removes      plaques normalisées
     * @param list<string>                          $reinstates   plaques normalisées
     * @param list<array{from: string, to: string}> $replacements `from` : plaque normalisée
     */
    private function change(string $requestId, array $adds = [], array $removes = [], array $reinstates = [], array $replacements = [], ?User $agent = null, ?Municipality $commune = null): void
    {
        $c = self::getContainer();
        $service = new PermitVehicleService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $c->get(PermitRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class));
        $service->change(
            $this->managed($agent ?? $this->instructor),
            ($commune ?? $this->organizer)->id()->toRfc4122(),
            $requestId,
            new VehicleChangeRequest($adds, array_map($this->idOf(...), $removes), array_map($this->idOf(...), $reinstates), array_map(fn (array $r): array => ['from' => $this->idOf($r['from']), 'to' => $r['to']], $replacements), 'OTHER', 'OTHER', 'OTHER', 'OTHER', self::COMMENT, true),
        );
    }

    /** @return array<string, int> */
    private function dispatch(): array
    {
        return NotificationKit::dispatcher(self::getContainer(), $this->keys, $this->clock, $this->mailer)->run()->outcomes;
    }

    /** @return list<array<string, mixed>> */
    private function outbox(string $type): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT status, attempts, last_error_code, available_at, occurred_at FROM notification_outbox WHERE type = :t ORDER BY occurred_at, id', ['t' => $type]);
    }

    private function assertNoLeak(): void
    {
        $text = implode("\n", array_map(static fn (array $m): string => $m['text']."\n".$m['html'], $this->mailer->sent));
        foreach (['QW-123-ER', 'QW123ER', 'TY-456-UI', 'TY456UI', 'EF-456-GH', 'EF456GH', 'IJ-789-KL', 'IJ789KL', self::COMMENT, 'Autre motif', 'OTHER', 'http'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text, 'ni plaque, ni motif, ni commentaire, ni lien : '.$forbidden);
        }
        self::assertStringNotContainsString('<strong>QW', $text);
    }

    public function testAnAdditionIsAnnouncedOrdinarilyAndNeverNamesAPlateOrAReason(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: ['EF-456-GH']);

        $row = $this->outbox('VEHICLES_CHANGED')[0];
        self::assertNotSame($row['occurred_at'], $row['available_at'], 'ordinaire : regroupée');
        self::assertSame([], $this->dispatch(), 'rien avant l\'échéance du regroupement');
        $this->clock->sleep(700);

        self::assertSame(['sent' => 1], $this->dispatch());
        self::assertStringContainsString('ajouté ou de nouveau autorisé', $this->mailer->sent[0]['text']);
        $this->assertNoLeak();
    }

    public function testARemovalIsPriorityAndNeverGroupedAndWarnsThatARemovedPlateIsNoLongerRecognized(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, removes: ['TY456UI']);

        $row = $this->outbox('VEHICLES_REMOVED')[0];
        self::assertSame($row['occurred_at'], $row['available_at'], 'prioritaire : aucun délai');
        self::assertSame(['sent' => 1], $this->dispatch());

        self::assertStringContainsString('retiré ou remplacé', $this->mailer->sent[0]['text']);
        self::assertStringContainsString('n\'est plus reconnu au contrôle', $this->mailer->sent[0]['text']);
        $this->assertNoLeak();
    }

    public function testAReplacementAndAGroupWithARemovalAreEachOneSinglePriorityMessage(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, replacements: [['from' => 'QW123ER', 'to' => 'EF-456-GH']]);
        self::assertSame(['sent' => 1], $this->dispatch());
        $this->change($requestId, adds: ['IJ-789-KL', 'MN-012-OP'], removes: ['TY456UI']);
        self::assertSame(['sent' => 1], $this->dispatch());

        self::assertCount(2, $this->mailer->sent);
        self::assertCount(2, $this->outbox('VEHICLES_REMOVED'), 'une seule intention par opération, quel que soit le nombre de plaques');
        self::assertSame([], $this->outbox('VEHICLES_CHANGED'));
        $this->assertNoLeak();
    }

    public function testAnAdditionWaitsForAPendingRemovalAndIsCoveredByItNeverAnnouncedFirst(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: ['EF-456-GH']);
        $this->change($requestId, removes: ['TY456UI']);
        $this->clock->sleep(700);

        $this->dispatch();
        $this->dispatch();

        self::assertCount(1, $this->mailer->sent, 'un seul message : le retrait décrit l\'état final');
        self::assertStringContainsString('retiré ou remplacé', $this->mailer->sent[0]['text']);
        $added = $this->outbox('VEHICLES_CHANGED')[0];
        self::assertSame(['SUPPRESSED', 'ALREADY_INCLUDED'], [$added['status'], $added['last_error_code']]);
        self::assertSame('SENT', $this->outbox('VEHICLES_REMOVED')[0]['status']);
    }

    public function testTwoPendingAdditionsOfTheSamePermitProduceOneMessageEvenFromTwoCommunes(): void
    {
        $requestId = $this->approved(both: true);
        $this->change($requestId, adds: ['EF-456-GH']);
        $this->clock->sleep(30);
        $this->change($requestId, adds: ['IJ-789-KL'], agent: $this->partnerInstructor, commune: $this->partner);
        $this->clock->sleep(700);

        self::assertSame(['sent' => 1, 'suppressed' => 1], $this->dispatch(), 'les plaques sont communes : une seule annonce pour le laissez-passer');

        self::assertCount(1, $this->mailer->sent);
        $this->assertNoLeak();
    }

    public function testARemovalIsStillAnnouncedWhenTheActingCommuneRevokedButAnotherAuthorizationRemains(): void
    {
        $requestId = $this->approved(both: true);
        $this->change($requestId, removes: ['TY456UI']);
        $c = self::getContainer();
        (new AuthorizationRevocationService($c->get(EventTransactions::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->revoke($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, 'DUPLICATE', null, true);

        $this->dispatch();
        $this->dispatch();

        $texts = implode("\n", array_column($this->mailer->sent, 'text'));
        self::assertStringContainsString('retiré ou remplacé', $texts, 'la partenaire applique toujours le retrait : il est annoncé');
        self::assertSame('SENT', $this->outbox('VEHICLES_REMOVED')[0]['status']);
    }

    public function testARemovalMadeInexactByTheRevocationOfTheOnlyAuthorizationIsKeptSuppressedWithAudit(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, removes: ['TY456UI']);
        $c = self::getContainer();
        (new AuthorizationRevocationService($c->get(EventTransactions::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->revoke($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, 'DUPLICATE', null, true);

        $this->dispatch();
        $this->dispatch();

        self::assertCount(1, $this->mailer->sent, 'seule la révocation est annoncée');
        self::assertStringContainsString('retiré son autorisation', $this->mailer->sent[0]['text']);
        $removed = $this->outbox('VEHICLES_REMOVED')[0];
        self::assertSame('SUPPRESSED', $removed['status']);
        self::assertContains($removed['last_error_code'], ['INACCURATE', 'SUPERSEDED_BY_REVOCATION'], 'trace conservée, justification explicite');
        $audits = $this->em->getConnection()->fetchFirstColumn("SELECT new_value FROM audit_log WHERE action = 'NOTIFICATION_SUPPRESSED'");
        self::assertNotEmpty($audits);
    }

    public function testATemporaryFailureOfThePriorityRemovalIsRetriedThenSentOnce(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, removes: ['TY456UI']);
        $this->mailer->script(RecordingMailer::smtpFailure(451));

        self::assertSame(['retry' => 1], $this->dispatch());
        self::assertSame('PENDING', $this->outbox('VEHICLES_REMOVED')[0]['status']);
        $this->clock->sleep(61);
        self::assertSame(['sent' => 1], array_intersect_key($this->dispatch(), ['sent' => 1]));

        self::assertSame('SENT', $this->outbox('VEHICLES_REMOVED')[0]['status']);
        self::assertCount(1, array_unique(array_column($this->mailer->tried, 'id')), 'Message-ID stable');
    }

    public function testAnAdditionIsNotHeldForeverByABlockedRemoval(): void
    {
        $requestId = $this->approved();
        $this->change($requestId, adds: ['EF-456-GH']);
        $this->clock->sleep(30);
        $this->change($requestId, removes: ['TY456UI']);
        $this->sql("UPDATE notification_outbox SET status = 'RESERVED', attempts = 1, first_attempt_at = :n, leased_until = :l WHERE type = 'VEHICLES_REMOVED'", ['n' => $this->clock->now()->format('Y-m-d H:i:s'), 'l' => $this->clock->now()->modify('+30 days')->format('Y-m-d H:i:s')]);
        $this->clock->sleep(700);

        self::assertSame(['held' => 1], $this->dispatch(), 'tant que le blocage est récent, l\'ajout attend');
        $this->clock->sleep(25 * 3600);

        self::assertSame(['sent' => 1], $this->dispatch(), 'au-delà de 24 h il part : jamais de blocage indéfini');
    }
}
