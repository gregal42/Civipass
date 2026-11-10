<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Notification\Application\KeyRingProvider;
use App\Permit\Application\AuthorizationPeriodService;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Domain\PeriodEnd;
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

/**
 * Notifications de prolongation et de raccourcissement (LOT 05.5.2, DM-119 §5) : prolongation ordinaire et regroupée (un groupe = un seul message,
 * couverture par révision), raccourcissement PRIORITAIRE, jamais regroupé ni retardé, envoyé même quand il retire le dernier droit ; ni motif ni commentaire
 * dans aucun message ; la commune et la nouvelle fin (heure de l'événement) y figurent. Horloge figée au 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class PeriodNotificationTest extends MunicipalDecisionTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private KeyRingProvider $keys;
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['permit_authorization_period'])) {
            self::markTestSkipped('Schéma des périodes non migré (LOT 05.5.2) : test exécutable après la migration validée.');
        }
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
        $this->mailer = new RecordingMailer($this->em->getConnection());
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function approved(): string
    {
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
            [$this->organizerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        $this->clock->sleep(700);
        self::assertSame(['sent' => 1], $this->dispatch(), 'mise à disposition');
        $this->mailer->sent = [];
        $this->mailer->tried = [];
        $this->mailer->attempts = 0;

        return $id;
    }

    private function periods(): AuthorizationPeriodService
    {
        $c = self::getContainer();

        return new AuthorizationPeriodService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRepository::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class));
    }

    private function extend(string $requestId, PeriodEnd $end): void
    {
        $this->periods()->extend($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, $end, 'OTHER', self::COMMENT);
    }

    private function shorten(string $requestId, PeriodEnd $end): void
    {
        $this->periods()->shorten($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, $end, 'OTHER', self::COMMENT, true);
    }

    /** @return array<string, int> */
    private function dispatch(): array
    {
        return NotificationKit::dispatcher(self::getContainer(), $this->keys, $this->clock, $this->mailer)->run()->outcomes;
    }

    /** @return list<array<string, mixed>> */
    private function outboxRows(string $type): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT status, last_error_code, available_at, occurred_at, covered_revisions, LOWER(HEX(revision_id)) AS revision FROM notification_outbox WHERE type = :t ORDER BY occurred_at, id', ['t' => $type]);
    }

    private function assertNoLeak(string $text): void
    {
        foreach ([self::COMMENT, 'Autre motif', 'OTHER', 'http'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text, 'ni motif, ni commentaire, ni lien : '.$forbidden);
        }
    }

    public function testAnExtensionIsAnnouncedWithTheCommuneAndTheNewEndInEventTime(): void
    {
        $requestId = $this->approved();

        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24)); // fin : 16 juin 09:00 UTC = 11:00 à Paris
        $this->clock->sleep(700);

        self::assertSame(['sent' => 1], $this->dispatch());
        $text = $this->mailer->sent[0]['text'];
        self::assertStringContainsString($this->organizer->name(), $text);
        self::assertStringContainsString('16/06/2026 à 11:00', $text);
        self::assertStringContainsString('Seul le contrôle du QR code fait foi', $text);
        $this->assertNoLeak($text);
    }

    public function testSeveralExtensionsOfTheGroupProduceOneMessageAndTheOthersAreCoveredNotLost(): void
    {
        $requestId = $this->approved();
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24));
        $this->clock->sleep(30);
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24));
        $this->clock->sleep(700);

        self::assertSame(['sent' => 1, 'suppressed' => 1], $this->dispatch());

        self::assertCount(1, $this->mailer->sent, 'un groupe de prolongations = un seul message, qui décrit l\'état courant (fin le 17 juin)');
        self::assertStringContainsString('17/06/2026 à 11:00', $this->mailer->sent[0]['text']);
        $rows = $this->outboxRows('PERMIT_EXTENDED');
        self::assertSame(['SENT', 'SUPPRESSED'], array_column($rows, 'status'));
        self::assertSame('ALREADY_INCLUDED', $rows[1]['last_error_code']);
        self::assertCount(2, json_decode((string) $rows[0]['covered_revisions'], true), 'le message a annoncé les deux révisions');
    }

    public function testAnExtensionAfterAnAlreadySentOneIsNotSuppressedJustBecauseTheAuthorizationWasNotified(): void
    {
        $requestId = $this->approved();
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24));
        $this->clock->sleep(700);
        self::assertSame(['sent' => 1], $this->dispatch());
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24));
        $this->clock->sleep(700);

        self::assertSame(['sent' => 1], $this->dispatch(), 'une nouvelle modification n\'est jamais supprimée parce que l\'autorisation avait déjà été notifiée');

        self::assertCount(2, $this->mailer->sent);
    }

    public function testAShorteningIsPriorityNeverGroupedAndSentEvenWhenItEndsEveryRight(): void
    {
        $requestId = $this->approved();
        $this->clock->sleep(7200);

        $this->shorten($requestId, PeriodEnd::custom('2026-06-12', '10:00')); // date passée : fin immédiate, plus aucun droit

        $row = $this->outboxRows('PERMIT_SHORTENED')[0];
        self::assertSame($row['occurred_at'], $row['available_at'], 'aucun délai');
        self::assertSame(['sent' => 1], $this->dispatch(), 'sans attendre, sans regroupement, malgré un laissez-passer devenu inutilisable');
        $text = $this->mailer->sent[0]['text'];
        self::assertStringContainsString($this->organizer->name(), $text);
        self::assertStringContainsString('prennent fin le 12/06/2026 à 13:11', $text, 'fin immédiate = maintenant (11:11:40 UTC = 13:11 à Paris), jamais la date demandée');
        $this->assertNoLeak($text);
        self::assertSame([$row['revision']], array_map(static fn (string $id): string => str_replace('-', '', $id), json_decode((string) $this->outboxRows('PERMIT_SHORTENED')[0]['covered_revisions'], true)));
    }

    public function testAShorteningIsNotSuppressedByAPriorExtensionMessage(): void
    {
        $requestId = $this->approved();
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24));
        $this->clock->sleep(700);
        $this->dispatch();
        $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'));

        self::assertSame(['sent' => 1], $this->dispatch());

        self::assertCount(2, $this->mailer->sent, 'deux messages distincts : la couverture ne se partage pas entre types');
    }

    public function testAShorteningFollowedByARevocationIsNotAnnouncedAsTheRevocationSpeaks(): void
    {
        $requestId = $this->approved();
        $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'));
        $c = self::getContainer();
        (new AuthorizationRevocationService($c->get(EventTransactions::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->revoke($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, 'DUPLICATE', null, true);

        $this->dispatch();

        self::assertCount(1, $this->mailer->sent, 'seule la révocation, exacte, est envoyée');
        self::assertStringContainsString('retiré son autorisation', $this->mailer->sent[0]['text']);
        $row = $this->outboxRows('PERMIT_SHORTENED')[0];
        self::assertSame(['SUPPRESSED', 'SUPERSEDED_BY_REVOCATION'], [$row['status'], $row['last_error_code']], 'trace conservée, non-distribution justifiée par un code explicite');
        $audit = $this->em->getConnection()->fetchAssociative("SELECT new_value FROM audit_log WHERE action = 'NOTIFICATION_SUPPRESSED'");
        self::assertIsArray($audit);
        $data = json_decode((string) $audit['new_value'], true);
        self::assertSame(['PERMIT_SHORTENED', 'SUPERSEDED_BY_REVOCATION'], [$data['notification'], $data['reason']]);
        self::assertSame($row['revision'], str_replace('-', '', (string) $data['revision']), 'l\'audit désigne la révision concernée');
        self::assertStringNotContainsString('lea@exemple.fr', (string) $audit['new_value']);
    }

    private function expireAndResume(string $requestId, int $days = 5): void
    {
        $this->clock->sleep($days * 86400);
        $this->periods()->extend($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED', self::COMMENT);
    }

    public function testAResumptionIsPresentedAsAReactivationWithTheNewStartAndTheNewDeadline(): void
    {
        $requestId = $this->approved();

        $this->expireAndResume($requestId, 4); // 16 juin 09:11:40 UTC = 11:11 à Paris ; échéance +24 h
        $this->clock->sleep(700);

        self::assertSame(['sent' => 1], $this->dispatch());
        $text = $this->mailer->sent[0]['text'];
        self::assertStringContainsString('réactivé', $text);
        self::assertStringContainsString('reprennent le 16/06/2026 à 11:11', $text, 'nouvelle date de début');
        self::assertStringContainsString('jusqu\'au 17/06/2026 à 11:11', $text, 'nouvelle échéance');
        self::assertStringContainsString('Aucun droit n\'est rétabli pour la période écoulée', $text);
        self::assertStringNotContainsString('prolongé la période', $text, 'une reprise n\'est pas une prolongation');
        self::assertSame('PERMIT_RESUMED', $this->em->getConnection()->fetchOne("SELECT type FROM notification_outbox WHERE type LIKE 'PERMIT_%' AND type <> 'PERMIT_ISSUED'"));
        $this->assertNoLeak($text);
    }

    public function testAPendingExtensionIsCoveredByALaterResumptionAndNeverAnnouncedFirst(): void
    {
        $requestId = $this->approved();
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24)); // fin 16 juin 09:00 UTC
        $this->expireAndResume($requestId, 5); // reprise le 17 juin 09:11 : les deux lignes sont en attente

        $this->dispatch();
        $this->dispatch();

        self::assertCount(1, $this->mailer->sent, 'un seul message : la réactivation décrit la période entière');
        self::assertStringContainsString('réactivé', $this->mailer->sent[0]['text']);
        $extension = $this->outboxRows('PERMIT_EXTENDED')[0];
        self::assertSame(['SUPPRESSED', 'ALREADY_INCLUDED'], [$extension['status'], $extension['last_error_code']]);
        self::assertSame('SENT', $this->outboxRows('PERMIT_RESUMED')[0]['status']);
    }

    public function testAPendingExtensionIsCoveredByALaterShorteningWhichIsSentFirst(): void
    {
        $requestId = $this->approved();
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24)); // fin 16 juin 09:00 UTC
        $this->clock->sleep(30);
        $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00')); // 14 juin 10:00 UTC : avant l'ancienne fin

        $this->dispatch();
        $this->clock->sleep(700);
        $this->dispatch();

        self::assertCount(1, $this->mailer->sent, 'jamais « prolongé jusqu\'au 14 juin » après « fin avancée » : l\'état final est annoncé une fois');
        self::assertStringContainsString('avancé la fin', $this->mailer->sent[0]['text']);
        self::assertSame(['SUPPRESSED', 'ALREADY_INCLUDED'], [$this->outboxRows('PERMIT_EXTENDED')[0]['status'], $this->outboxRows('PERMIT_EXTENDED')[0]['last_error_code']]);
    }

    public function testAShorteningNeverCoversAResumptionSoTheReactivationIsStillAnnounced(): void
    {
        $requestId = $this->approved();
        $this->expireAndResume($requestId, 4);
        $this->clock->sleep(30);
        $this->shorten($requestId, PeriodEnd::custom('2026-06-16', '18:00')); // 16:00 UTC, avant la fin de la reprise

        $this->dispatch();
        $this->clock->sleep(700);
        $this->dispatch();

        self::assertCount(2, $this->mailer->sent);
        $texts = implode("\n", array_column($this->mailer->sent, 'text'));
        self::assertStringContainsString('réactivé', $texts);
        self::assertStringContainsString('avancé la fin', $texts);
    }

    public function testTwoCommunesExtensionsAreNeverCoveredByEachOther(): void
    {
        $requestId = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
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
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        foreach ([[$this->instructor, $this->organizer], [$this->partnerInstructor, $this->partner]] as [$agent, $commune]) {
            $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $requestId, ApprovalDuration::fixed(DurationMode::H72));
        }
        $this->clock->sleep(700);
        $this->dispatch();
        $this->mailer->sent = [];
        $this->periods()->extend($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, PeriodEnd::fixedHours(DurationMode::H24), null, null);
        $this->periods()->extend($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $requestId, PeriodEnd::fixedHours(DurationMode::H48), null, null);
        $this->clock->sleep(700);

        $this->dispatch();

        self::assertCount(2, $this->mailer->sent, 'une annonce par commune : la couverture ne passe jamais d\'une autorisation à l\'autre');
        $texts = implode("\n", array_column($this->mailer->sent, 'text'));
        self::assertStringContainsString('16/06/2026 à 11:00', $texts);
        self::assertStringContainsString('17/06/2026 à 11:00', $texts);
    }

    public function testNoAddressMeansNoMessageButTheChangeIsRecordedAsUndeliverable(): void
    {
        $id = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            null,
            null,
            '+33612345678',
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER'],
            [$this->organizerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));

        $this->shorten($id, PeriodEnd::custom('2026-06-14', '12:00'));
        $this->dispatch();

        self::assertSame(0, $this->mailer->attempts);
        self::assertSame('NO_RECIPIENT', $this->outboxRows('PERMIT_SHORTENED')[0]['status']);
    }

    // ------------------------------------------------------------------ résilience : pannes, reprises, absence de blocage, exactitude

    /** @return array<string, mixed> */
    private function one(string $type): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT status, attempts, last_error_code, available_at, covered_revisions FROM notification_outbox WHERE type = :t', ['t' => $type]) ?: [];
    }

    public function testATemporaryFailureOfAPriorityNotificationIsRetriedThenSentWithTheSameMessageId(): void
    {
        $requestId = $this->approved();
        $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'));
        $this->mailer->script(RecordingMailer::smtpFailure(451), RecordingMailer::smtpFailure(451));

        self::assertSame(['retry' => 1], $this->dispatch());
        $row = $this->one('PERMIT_SHORTENED');
        self::assertSame(['PENDING', '1', 'SMTP_TEMPORARY'], [$row['status'], (string) $row['attempts'], $row['last_error_code']]);
        self::assertSame([], $this->dispatch(), 'rien avant l\'échéance de la nouvelle tentative (1 minute)');
        $this->clock->sleep(61);
        self::assertSame(['retry' => 1], $this->dispatch());
        $this->clock->sleep(301);
        self::assertSame(['sent' => 1], $this->dispatch(), 'la panne passée, le message part');

        $row = $this->one('PERMIT_SHORTENED');
        self::assertSame(['SENT', '3', null], [$row['status'], (string) $row['attempts'], $row['last_error_code']]);
        self::assertCount(1, array_unique(array_column($this->mailer->tried, 'id')), 'Message-ID stable : le client de messagerie peut dédoublonner');
        self::assertCount(1, $this->mailer->sent, 'un seul message reçu');
        self::assertNotNull($row['covered_revisions'], 'la révision annoncée n\'est conservée qu\'à l\'envoi');
    }

    public function testAPriorityNotificationThatCanNeverBeDeliveredKeepsItsTraceAndIsNeverRetried(): void
    {
        $requestId = $this->approved();
        $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'));
        $this->mailer->script(RecordingMailer::smtpFailure(550));

        self::assertSame(['abandoned' => 1], $this->dispatch());
        $this->clock->sleep(86400);
        self::assertSame([], $this->dispatch(), 'jamais réessayée');

        $row = $this->one('PERMIT_SHORTENED');
        self::assertSame(['ABANDONED', '1', 'SMTP_PERMANENT'], [$row['status'], (string) $row['attempts'], $row['last_error_code']], 'état définitif et motif conservés');
        self::assertSame(1, $this->mailer->attempts);
        $authorization = (string) $this->em->getConnection()->fetchOne('SELECT LOWER(HEX(id)) FROM permit_authorization');
        $permit = (string) $this->em->getConnection()->fetchOne('SELECT LOWER(HEX(permit_id)) FROM permit_authorization');
        $uuid = static fn (string $h): \Symfony\Component\Uid\Uuid => \Symfony\Component\Uid\Uuid::fromString(substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20));
        $lines = $this->service(\App\Permit\Application\NotificationStatusProvider::class)->overviewFor($uuid($permit), [$uuid($authorization)])->lines;
        $line = array_values(array_filter($lines, static fn ($l): bool => 'Raccourcissement d’une autorisation' === $l->title))[0] ?? self::fail('Ligne absente de l\'écran agent.');
        self::assertSame(['failed', 'Échec d’envoi'], [$line->state, $line->stateLabel]);
    }

    public function testAnExpiredLeaseAfterAnIncidentIsTakenOverAndTheMessageIsSentOnce(): void
    {
        $requestId = $this->approved();
        $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'));
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        // Un distributeur est mort après la réservation : message réservé, bail encore valide.
        $this->sql("UPDATE notification_outbox SET status = 'RESERVED', attempts = 1, first_attempt_at = :n, leased_until = :l WHERE type = 'PERMIT_SHORTENED'", ['n' => $now, 'l' => $this->clock->now()->modify('+5 minutes')->format('Y-m-d H:i:s')]);

        self::assertSame([], $this->dispatch(), 'bail valide : non repris');
        $this->clock->sleep(600);
        self::assertSame(['sent' => 1], $this->dispatch(), 'bail expiré : repris');

        self::assertSame(['SENT', '2'], [$this->one('PERMIT_SHORTENED')['status'], (string) $this->one('PERMIT_SHORTENED')['attempts']]);
        self::assertCount(1, $this->mailer->sent);
        self::assertSame([], $this->dispatch(), 'jamais renvoyé');
    }

    public function testAnExtensionIsNotHeldForeverByABlockedPriorityMessage(): void
    {
        $requestId = $this->approved();
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24));
        $this->clock->sleep(30);
        $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'));
        // Le raccourcissement reste « réservé » avec un bail qui ne sera jamais repris dans la fenêtre.
        $this->sql("UPDATE notification_outbox SET status = 'RESERVED', attempts = 1, first_attempt_at = :n, leased_until = :l WHERE type = 'PERMIT_SHORTENED'", ['n' => $this->clock->now()->format('Y-m-d H:i:s'), 'l' => $this->clock->now()->modify('+30 days')->format('Y-m-d H:i:s')]);
        $this->clock->sleep(700);

        self::assertSame(['held' => 1], $this->dispatch(), 'tant que le blocage est récent, la prolongation attend');

        $this->clock->sleep(25 * 3600);
        $outcome = $this->dispatch();
        self::assertSame(['sent' => 1], $outcome, 'au-delà de 24 h elle part : jamais de blocage indéfini');
        self::assertStringContainsString('modifié la période', $this->mailer->sent[0]['text'], 'la période a changé depuis la décision : message neutre et exact');
    }

    public function testAnExtensionIsReleasedWhenItsBlockerEndsWithoutBeingDelivered(): void
    {
        $requestId = $this->approved();
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24));
        $this->clock->sleep(30);
        $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'));
        $this->mailer->script(RecordingMailer::smtpFailure(550)); // le raccourcissement est refusé définitivement
        $this->clock->sleep(700);

        $first = $this->dispatch();
        $second = $this->dispatch();

        self::assertSame('ABANDONED', $this->one('PERMIT_SHORTENED')['status']);
        self::assertSame('SENT', $this->one('PERMIT_EXTENDED')['status'], 'le blocage est levé dès que le message prioritaire est définitivement abandonné');
        self::assertCount(1, $this->mailer->sent);
        self::assertStringContainsString('situation d\'aujourd\'hui', $this->mailer->sent[0]['text']);
        self::assertSame(2, array_sum($first) + array_sum($second) - ($first['held'] ?? 0) - ($second['held'] ?? 0));
    }

    public function testSuppressionReasonsSurviveTheOutboxPurgeThroughTheAudit(): void
    {
        $requestId = $this->approved();
        $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'));
        $c = self::getContainer();
        (new AuthorizationRevocationService($c->get(EventTransactions::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->revoke($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, 'DUPLICATE', null, true);
        $this->dispatch();
        self::assertSame('SUPERSEDED_BY_REVOCATION', $this->one('PERMIT_SHORTENED')['last_error_code']);
        $this->clock->sleep(100 * 86400);

        NotificationKit::outboxPurger($c, $this->clock)->purge();

        self::assertSame([], $this->one('PERMIT_SHORTENED'), 'la ligne d\'outbox est purgée après 90 jours (rétention existante)');
        $audit = $this->em->getConnection()->fetchAssociative("SELECT new_value FROM audit_log WHERE action = 'NOTIFICATION_SUPPRESSED'");
        self::assertIsArray($audit, 'la trace et le motif de non-distribution survivent dans l\'audit');
        self::assertSame('SUPERSEDED_BY_REVOCATION', json_decode((string) $audit['new_value'], true)['reason']);
    }

    public function testAResumptionMessageStaysExactWhenTheDeadlineChangedBetweenTheDecisionAndTheSending(): void
    {
        $requestId = $this->approved();
        $this->expireAndResume($requestId, 4); // reprise le 16 juin à 09:11:40 UTC, échéance décidée : 17 juin 09:11:40 UTC
        $this->clock->sleep(30);
        $this->shorten($requestId, PeriodEnd::custom('2026-06-16', '18:00')); // l'échéance recule à 16:00 UTC avant l'envoi

        $this->dispatch();
        $this->clock->sleep(700);
        $this->dispatch();

        $texts = array_column($this->mailer->sent, 'text');
        $resumed = array_values(array_filter($texts, static fn (string $t): bool => str_contains($t, 'réactivé')))[0] ?? self::fail('Message de reprise absent.');
        self::assertStringContainsString('évolué depuis cette réactivation', $resumed);
        self::assertStringContainsString('du 16/06/2026 à 11:11 au 16/06/2026 à 18:00', $resumed, 'la période d\'AUJOURD\'HUI, début de la reprise et échéance actuelle');
        self::assertStringNotContainsString('17/06/2026', $resumed, 'jamais l\'échéance décidée mais périmée');
        self::assertStringNotContainsString('sont valables jusqu\'au', $resumed);
        $this->assertNoLeak($resumed);
    }

    public function testAnExtensionMessageIsNeutralWhenAnotherChangeFollowedBeforeSending(): void
    {
        $requestId = $this->approved();
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24)); // décidé : 16 juin 09:00 UTC
        $this->clock->sleep(30);
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24)); // 17 juin 09:00 UTC avant l'envoi
        $this->clock->sleep(700);

        $this->dispatch();

        $text = $this->mailer->sent[0]['text'];
        self::assertStringContainsString('a modifié la période', $text);
        self::assertStringContainsString('au 17/06/2026 à 11:00', $text);
        self::assertStringNotContainsString('a prolongé la période', $text, 'on ne prétend pas décrire le résultat de la première révision');
    }
}
