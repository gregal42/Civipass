<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Notification\Application\KeyRingProvider;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessService;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Domain\ManualNotificationOutcome;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\RecordingMailer;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Envoi manuel d'une nouvelle notification de mise à disposition (LOT 05.3.4) : nouvelle intention, nouveau lien à l'envoi, aucun lien révoqué,
 * double clic inoffensif, un seul message en attente, droits, participation ACTIVE, isolation des communes, audit sans secret.
 */
#[Group('requires-request-schema')]
final class ManualNotificationTest extends MunicipalDecisionTestCase
{
    private KeyRingProvider $keys;
    private RecordingMailer $mailer;
    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
        $this->mailer = new RecordingMailer($this->em->getConnection());
        $this->requestId = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
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
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->requestId, ApprovalDuration::fixed(DurationMode::H72));
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function access(): PermitAccessService
    {
        return NotificationKit::access(self::getContainer(), $this->clock);
    }

    private function request(?User $agent = null, ?Municipality $commune = null, ?string $nonce = null, ?string $requestId = null): ManualNotificationOutcome
    {
        return $this->access()->requestNotification($this->managed($agent ?? $this->instructor), ($commune ?? $this->organizer)->id()->toRfc4122(), $requestId ?? $this->requestId, $nonce ?? Uuid::v7()->toRfc4122());
    }

    /** @return array<string, int> */
    private function dispatch(): array
    {
        return NotificationKit::dispatcher(self::getContainer(), $this->keys, $this->clock, $this->mailer)->run()->outcomes;
    }

    private function manualRows(): int
    {
        return $this->rows('notification_outbox WHERE deduplication_key LIKE \'PERMIT_ISSUED_MANUAL:%\'');
    }

    public function testTheRequestCreatesANewIntentionAuditedWithoutSecretAndSendsNothingItself(): void
    {
        $this->dispatch(); // le premier message automatique est parti
        self::assertSame(1, $this->mailer->attempts);

        self::assertSame(ManualNotificationOutcome::Created, $this->request());

        self::assertSame(1, $this->manualRows());
        self::assertSame(1, $this->mailer->attempts, 'aucun envoi dans la requête');
        self::assertSame(0, $this->rows('notification_secret'), 'aucun secret avant l\'envoi');
        $audit = $this->em->getConnection()->fetchAssociative('SELECT * FROM audit_log WHERE action = \'PERMIT_NOTIFICATION_REQUESTED\'');
        self::assertIsArray($audit);
        self::assertSame(bin2hex($this->instructor->id()->toBinary()), bin2hex((string) $audit['actor_id']));
        self::assertStringNotContainsString('lea@exemple.fr', json_encode($audit, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));
    }

    public function testTheManualMessageCarriesANewLinkAndRevokesNothing(): void
    {
        $this->dispatch(); // première mise à disposition automatique
        $firstLink = (string) $this->em->getConnection()->fetchOne('SELECT secret_hash FROM permit_access_token');
        $this->access()->issue($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->requestId); // lien d'un agent
        $this->request();

        self::assertSame(['sent' => 1], $this->dispatch());

        self::assertCount(2, $this->mailer->sent);
        self::assertNotNull($this->mailer->sent[1]['secretHash']);
        self::assertNotSame($this->mailer->sent[0]['secretHash'], $this->mailer->sent[1]['secretHash'], 'nouveau lien, jamais un secret supprimé');
        self::assertSame(3, $this->rows('permit_access_token'));
        self::assertSame(3, $this->rows('permit_access_token WHERE revoked_at IS NULL'), 'aucun lien, automatique ou manuel, n\'est révoqué');
        self::assertContains($firstLink, $this->em->getConnection()->fetchFirstColumn('SELECT secret_hash FROM permit_access_token WHERE revoked_at IS NULL'));
        self::assertSame(0, $this->rows('notification_secret'));
    }

    public function testADoubleClickWithTheSameFormIdentifierRecordsOneIntentionAndOneAudit(): void
    {
        $this->dispatch();
        $nonce = Uuid::v7()->toRfc4122();

        self::assertSame(ManualNotificationOutcome::Created, $this->request(nonce: $nonce));
        self::assertSame(ManualNotificationOutcome::Duplicate, $this->request(nonce: $nonce));

        self::assertSame(1, $this->manualRows());
        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_NOTIFICATION_REQUESTED\''));
    }

    public function testASecondRequestWhileAMessageIsPendingIsRefusedWithoutAnyNewIntention(): void
    {
        // Le premier message automatique est encore en attente.
        self::assertSame(ManualNotificationOutcome::AlreadyPending, $this->request());
        self::assertSame(0, $this->manualRows());

        $this->dispatch();
        self::assertSame(ManualNotificationOutcome::Created, $this->request());
        self::assertSame(ManualNotificationOutcome::AlreadyPending, $this->request(), 'une autre fenêtre, un autre identifiant : toujours un seul message en attente');
        self::assertSame(1, $this->manualRows());
    }

    public function testANewRequestIsPossibleOnceThePreviousMessageWasSent(): void
    {
        $this->dispatch();
        $this->request();
        $this->dispatch();
        $this->clock->sleep(301); // écart minimal entre deux demandes acceptées

        self::assertSame(ManualNotificationOutcome::Created, $this->request());
        self::assertSame(2, $this->manualRows());
    }

    public function testNothingIsRecordedWithoutAnEmailAddress(): void
    {
        $this->dispatch();
        $this->sql('UPDATE request_permit_request SET email = NULL');

        try {
            $this->request();
            self::fail('Aucune adresse : la demande doit être refusée.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('adresse e-mail', $e->getMessage());
        }

        self::assertSame(0, $this->manualRows());
    }

    public function testNothingIsRecordedForAPermitThatIsNoLongerUsable(): void
    {
        $this->dispatch();
        $this->sql('UPDATE permit_authorization SET revoked_at = :now', ['now' => $this->clock->now()->format('Y-m-d H:i:s')]);

        $this->expectException(DomainRuleViolation::class);
        $this->request();
    }

    public function testASuspendedParticipationCannotRequestANotification(): void
    {
        $this->dispatch();
        $this->sql('UPDATE event_participation SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->organizerParticipation->id()->toBinary()]);

        try {
            $this->request();
            self::fail('Participation non active.');
        } catch (PermitAccessUnavailable) {
            self::assertSame(0, $this->manualRows());
        }
    }

    public function testAnInvalidFormIdentifierIsRejected(): void
    {
        $this->dispatch();

        $this->expectException(DomainRuleViolation::class);
        $this->request(nonce: 'pas-un-uuid');
    }

    public function testAnAgentWithoutTheRoleIsDeniedAndAnOtherCommuneSeesNothing(): void
    {
        $this->dispatch();
        $reader = UserBuilder::aUser()->build();
        $organizer = $this->em->find(Municipality::class, $this->organizer->id()) ?? self::fail('Commune absente.');
        $this->em->persist($reader);
        $this->em->persist(MembershipBuilder::aMembership($reader, $organizer)->withRoles(Role::EventManager)->build());
        $this->em->flush();

        try {
            $this->request(agent: $reader);
            self::fail('EVENT_MANAGER sans délivrance directe : refusé.');
        } catch (PermitAccessDenied) {
        }
        try {
            $this->request(agent: $this->partnerInstructor, commune: $this->partner); // commune sans autorisation sur ce laissez-passer
            self::fail('Commune non concernée.');
        } catch (OperationNotAllowed) {
        }
        try {
            $this->request(requestId: Uuid::v7()->toRfc4122()); // identifiant forgé
            self::fail('Demande inconnue.');
        } catch (OperationNotAllowed) {
        }

        self::assertSame(0, $this->manualRows());
        self::assertSame(0, $this->rows('audit_log WHERE action = \'PERMIT_NOTIFICATION_REQUESTED\''));
    }

    /** Marque tout message de mise à disposition comme envoyé (le distributeur n'est pas l'objet de ces tests de limites). */
    private function markSent(): void
    {
        $this->sql('UPDATE notification_outbox SET status = \'SENT\', processed_at = :t WHERE status IN (\'PENDING\', \'RESERVED\')', ['t' => $this->clock->now()->format('Y-m-d H:i:s')]);
    }

    public function testASecondRequestWithinFiveMinutesIsRefusedWithAnExplicitOutcomeThenAcceptedAfter(): void
    {
        $this->dispatch();
        self::assertSame(ManualNotificationOutcome::Created, $this->request());
        $this->markSent();
        $this->clock->sleep(299);

        self::assertSame(ManualNotificationOutcome::TooSoon, $this->request());
        self::assertSame(1, $this->manualRows());

        $this->clock->sleep(2);
        self::assertSame(ManualNotificationOutcome::Created, $this->request());
        self::assertSame(2, $this->manualRows());
    }

    public function testAtMostThreeAcceptedRequestsPerSlidingTwentyFourHoursThenTheWindowSlides(): void
    {
        $this->dispatch();
        for ($i = 1; $i <= 3; ++$i) {
            self::assertSame(ManualNotificationOutcome::Created, $this->request(), 'demande '.$i);
            $this->markSent();
            $this->clock->sleep(600);
        }

        self::assertSame(ManualNotificationOutcome::DailyLimitReached, $this->request());
        self::assertSame(3, $this->manualRows());
        self::assertSame(3, $this->rows('audit_log WHERE action = \'PERMIT_NOTIFICATION_REQUESTED\''), 'seuls les renvois acceptés sont audités');

        $this->clock->sleep(24 * 3600 - 1800); // la première demande sort de la fenêtre
        self::assertSame(ManualNotificationOutcome::Created, $this->request());
    }

    public function testADoubleClickIsNotCountedTwiceAgainstTheLimits(): void
    {
        $this->dispatch();
        $nonce = Uuid::v7()->toRfc4122();

        self::assertSame(ManualNotificationOutcome::Created, $this->request(nonce: $nonce));
        self::assertSame(ManualNotificationOutcome::Duplicate, $this->request(nonce: $nonce));
        $this->markSent();
        $this->clock->sleep(301);
        self::assertSame(ManualNotificationOutcome::Created, $this->request());
        $this->markSent();
        $this->clock->sleep(301);
        self::assertSame(ManualNotificationOutcome::Created, $this->request(), 'troisième demande ACCEPTÉE : le doublon n\'a pas compté');
    }

    public function testTheExceptionalManualLinkHandoverByAnAgentIsNeverLimited(): void
    {
        $this->dispatch();
        for ($i = 0; $i < 4; ++$i) {
            $this->access()->issue($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->requestId);
        }

        self::assertSame(4, $this->rows('permit_access_token WHERE issuer_type = \'AGENT\''));
    }

    public function testACorrectedAddressDoesNotTriggerAnAutomaticSendButAManualRequestThenSendsTheNewMessage(): void
    {
        // Premier message : aucune adresse au moment de l'approbation.
        $this->sql('UPDATE notification_outbox SET status = \'NO_RECIPIENT\', processed_at = :t', ['t' => $this->clock->now()->format('Y-m-d H:i:s')]);
        $this->sql('UPDATE request_permit_request SET email = NULL');
        try {
            $this->request();
            self::fail('Sans adresse, le renvoi est refusé.');
        } catch (DomainRuleViolation) {
        }

        $this->sql('UPDATE request_permit_request SET email = \'corrigee@exemple.fr\''); // l'identité est corrigée
        self::assertSame([], $this->dispatch(), 'une adresse corrigée ne provoque AUCUN envoi automatique non sollicité');
        self::assertSame(0, $this->mailer->attempts);

        self::assertSame(ManualNotificationOutcome::Created, $this->request());
        self::assertSame(['sent' => 1], $this->dispatch());

        self::assertCount(1, $this->mailer->sent);
        self::assertNotNull($this->mailer->sent[0]['secretHash'], 'le renvoi porte un nouveau lien');
        self::assertSame(1, $this->rows('permit_access_token'));
    }
}
