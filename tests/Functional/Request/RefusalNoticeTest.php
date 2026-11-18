<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Notification\Application\SmtpFailureClassifier;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RefusalNoticeComposer;
use App\Request\Application\RefusalNoticeDispatcher;
use App\Request\Application\RefusalNoticeService;
use App\Request\Application\RefusalReasonInitializer;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\ManualChannel;
use App\Request\Domain\RefusalNoticeRepository;
use App\Request\Domain\TravelMode;
use App\Request\Infrastructure\DoctrineRefusalReasonRepository;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ControlEnv;
use App\Tests\Support\ControlTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/** LOT 08.5 : information des demandeurs refusés : création, envoi fiable, contenu minimal, liste « à communiquer », isolation. Aucun envoi réel : transport nul / faux serveur. */
final class RefusalNoticeTest extends ControlTestCase
{
    use ControlEnv;

    private const COMMENT = 'Commentaire interne confidentiel du refus';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        (new RefusalReasonInitializer(new DoctrineRefusalReasonRepository($this->em()), static::getContainer()->get(TransactionRunner::class)))->initialize();
    }

    protected function tearDown(): void
    {
        $this->restoreControlEnv();
        parent::tearDown();
    }

    /** @param list<string> $zones */
    private function submit(?string $email = 'camille.secret@exemple.fr', array $zones = ['org']): string
    {
        $ids = array_map(fn (string $z): string => ('org' === $z ? $this->organizerZone : $this->partnerZone)->id()->toRfc4122(), $zones);
        $id = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'MARTIN', 'Camille', self::ADDRESS, $email, self::PHONE, 'private_person', 'home', 'Motif confidentiel du dossier', TravelMode::Vehicle, ['GH-482-KT'], $ids, true),
            sprintf('rn-%016d', random_int(1, 9_999_999)),
        )->requestId;
        $this->em()->clear();

        return $id;
    }

    private function refuse(string $requestId, string $code = 'ineligible_beneficiary', bool $partner = false): void
    {
        $agent = $partner ? $this->instructorP : $this->instructorO;
        $commune = $partner ? $this->partner : $this->org;
        static::getContainer()->get(MunicipalInstructionService::class)->refuse($this->user($agent), $commune->id()->toRfc4122(), $requestId, $code, 'other' === $code ? self::COMMENT : self::COMMENT);
        $this->em()->clear();
    }

    private function dispatcher(?MailerInterface $mailer = null, ?MockClock $clock = null, bool $enabled = true): RefusalNoticeDispatcher
    {
        $c = static::getContainer();

        return new RefusalNoticeDispatcher(
            $c->get(RefusalNoticeRepository::class),
            $this->em(),
            new RefusalNoticeComposer('no-reply@civipass.invalid'),
            $mailer ?? $c->get(MailerInterface::class),
            new SmtpFailureClassifier(),
            $c->get(TransactionRunner::class),
            $c->get(AuditTrail::class),
            $clock ?? new NativeClock(),
            new NullLogger(),
            $enabled,
        );
    }

    private function noticeStatus(): string
    {
        return (string) $this->scalar('SELECT status FROM request_refusal_notice LIMIT 1');
    }

    // ---- Création ------------------------------------------------------------------------------------------------------------------

    public function testARefusalCreatesOneNoticeAndAnApprovalNone(): void
    {
        $refused = $this->submit();
        $approved = $this->submit();
        $this->refuse($refused);
        static::getContainer()->get(MunicipalInstructionService::class)->approve($this->user($this->instructorO), $this->org->id()->toRfc4122(), $approved, ApprovalDuration::untilClosure());
        $this->em()->clear();

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM request_refusal_notice'));
        self::assertSame('PENDING', $this->noticeStatus());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(DISTINCT decision_id) FROM request_refusal_notice'));
    }

    public function testWithoutEmailTheNoticeIsDefinitiveAndVisibleToAgents(): void
    {
        $this->refuse($this->submit(email: null));
        self::assertSame('NO_RECIPIENT', $this->noticeStatus());
        self::assertNotNull($this->scalar('SELECT processed_at FROM request_refusal_notice'));
    }

    public function testNoNoticeForHistoricalRefusals(): void
    {
        // Refus enregistré AVANT la mise en service : la migration ne crée aucune ligne rétroactive.
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM request_refusal_notice'));
    }

    // ---- Envoi ---------------------------------------------------------------------------------------------------------------------

    public function testNothingIsSentWhileTheSwitchIsOff(): void
    {
        $this->refuse($this->submit());
        self::assertSame([], $this->dispatcher(enabled: false)->run());
        self::assertCount(0, self::getMailerMessages());
        self::assertSame('PENDING', $this->noticeStatus());
    }

    public function testSentMessageIsMinimalAndNeverLeaksForbiddenContent(): void
    {
        $this->refuse($this->submit(), 'ineligible_beneficiary');
        self::assertSame(['sent' => 1], $this->dispatcher()->run());
        self::assertSame('SENT', $this->noticeStatus());
        $mails = self::getMailerMessages();
        self::assertCount(1, $mails);
        $body = (string) self::emailAt(0)->getTextBody();
        self::assertSame('camille.secret@exemple.fr', self::emailAt(0)->getTo()[0]->getAddress());
        self::assertStringContainsString('Bénéficiaire non éligible', $body);
        self::assertStringContainsString($this->scalar('SELECT public_reference FROM request_permit_request'), $body);
        foreach ([self::COMMENT, 'MARTIN', 'Camille', 'GH-482-KT', self::ADDRESS, self::PHONE, 'Motif confidentiel', 'http://', 'https://', 'Zone organisatrice'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body.(string) self::emailAt(0)->getSubject(), $forbidden);
        }
        self::assertStringContainsString('ni lien ni laissez-passer', $body);
    }

    public function testDuplicateAndOtherReasonsNeverCommunicateTheirLabel(): void
    {
        foreach (['duplicate' => 'Demande en doublon', 'other' => 'Autre'] as $code => $label) {
            $this->refuse($this->submit(), $code);
        }
        $this->dispatcher()->run();
        self::assertCount(2, self::getMailerMessages());
        foreach ([0, 1] as $i) {
            $body = (string) self::emailAt($i)->getTextBody();
            self::assertStringNotContainsString('Motif', $body);
            self::assertStringNotContainsString('doublon', mb_strtolower($body));
            self::assertStringNotContainsString(self::COMMENT, $body);
            self::assertStringContainsString('n’a pas pu être acceptée', $body);
        }
    }

    public function testAddressIsReReadAtSendTime(): void
    {
        $id = $this->submit();
        $this->refuse($id);
        $this->sql("UPDATE request_permit_request SET email = 'corrigee@exemple.fr'");
        $this->dispatcher()->run();
        self::assertSame('corrigee@exemple.fr', self::emailAt(0)->getTo()[0]->getAddress());

        $this->sql("UPDATE request_refusal_notice SET status = 'PENDING', leased_until = NULL, processed_at = NULL");
        $this->sql('UPDATE request_permit_request SET email = NULL');
        self::assertSame(['no_recipient' => 1], $this->dispatcher()->run());
        self::assertSame('NO_RECIPIENT', $this->noticeStatus());
    }

    public function testAClosedEventDoesNotNeutralizeTheNotice(): void
    {
        $this->refuse($this->submit());
        $this->sql("UPDATE event_event SET status = 'CLOSED'");
        self::assertSame(['sent' => 1], $this->dispatcher()->run());
        self::assertSame('SENT', $this->noticeStatus());
    }

    public function testPartialRefusalSendsOneMessagePerRefusingCommuneWithoutRevealingTheOther(): void
    {
        $id = $this->submit(zones: ['org', 'partner']);
        $this->refuse($id);
        $this->refuse($id, 'documents_insufficient', partner: true);
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM request_refusal_notice'));
        $this->dispatcher()->run();
        $mails = self::getMailerMessages();
        self::assertCount(2, $mails);
        $orgName = $this->org->name();
        $partnerName = $this->partner->name();
        foreach ([0, 1] as $i) {
            $text = (string) self::emailAt($i)->getSubject().(string) self::emailAt($i)->getTextBody();
            $mentionsOrg = str_contains($text, $orgName);
            $mentionsPartner = str_contains($text, $partnerName);
            self::assertNotSame($mentionsOrg, $mentionsPartner, 'chaque message ne nomme que SA commune');
        }
    }

    // ---- Fiabilité -----------------------------------------------------------------------------------------------------------------

    private function failingMailer(string $message): object
    {
        return new class ($message) implements MailerInterface {
            public int $calls = 0;

            public function __construct(private readonly string $message)
            {
            }

            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                ++$this->calls;
                throw new TransportException($this->message);
            }
        };
    }

    public function testTemporaryFailuresAreRetriedAccordingToThePolicyThenAbandoned(): void
    {
        $this->refuse($this->submit());
        $mailer = $this->failingMailer('Expected response code "250" but got code "451", with message "try later".');
        $clock = new MockClock('2026-11-18 10:00:00 UTC');
        $this->sql("UPDATE request_refusal_notice SET available_at = '2026-11-18 09:00:00'");
        $delays = [1, 5, 15, 60, 240];
        foreach ($delays as $i => $minutes) {
            self::assertSame(['retry' => 1], $this->dispatcher($mailer, $clock)->run(), 'essai '.($i + 1));
            self::assertSame('PENDING', $this->noticeStatus());
            $next = (string) $this->scalar('SELECT available_at FROM request_refusal_notice');
            self::assertSame($clock->now()->modify("+$minutes minutes")->format('Y-m-d H:i:s'), $next);
            self::assertSame([], $this->dispatcher($mailer, $clock)->run(), 'rien avant l\'échéance');
            $clock->modify("+$minutes minutes");
        }
        self::assertSame(['abandoned' => 1], $this->dispatcher($mailer, $clock)->run(), 'sixième essai : abandon');
        self::assertSame('ABANDONED', $this->noticeStatus());
        self::assertSame('SMTP_EXHAUSTED', (string) $this->scalar('SELECT last_error_code FROM request_refusal_notice'));
        self::assertSame(6, $mailer->calls);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'REFUSAL_NOTICE_ABANDONED'"));
    }

    public function testPermanentFailureIsAbandonedImmediatelyAndNeverTheDecision(): void
    {
        $this->refuse($this->submit());
        $mailer = $this->failingMailer('Expected response code "250" but got code "550", with message "no such user".');
        self::assertSame(['abandoned' => 1], $this->dispatcher($mailer)->run());
        self::assertSame('ABANDONED', $this->noticeStatus());
        self::assertSame('SMTP_PERMANENT', (string) $this->scalar('SELECT last_error_code FROM request_refusal_notice'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM request_permit_request_decision WHERE outcome = 'REFUSED'"), 'la décision métier reste acquise');
    }

    public function testTwoDispatchersNeverProcessTheSameNotice(): void
    {
        $this->refuse($this->submit());
        $repository = static::getContainer()->get(RefusalNoticeRepository::class);
        $id = Uuid::fromBinary((string) $this->scalar('SELECT id FROM request_refusal_notice'));
        $now = (new NativeClock())->now()->modify('+1 second');
        $lease = $now->modify('+10 minutes');
        self::assertNotNull($repository->claim($id, $now, $lease));
        self::assertNull($repository->claim($id, $now, $lease), 'déjà réservée');
        self::assertNull($repository->claim($id, $now->modify('+5 minutes'), $lease->modify('+5 minutes')), 'bail encore valide');
        self::assertNotNull($repository->claim($id, $now->modify('+11 minutes'), $lease->modify('+21 minutes')), 'bail expiré : reprise');
        self::assertFalse($repository->finish($id, $lease, \App\Request\Domain\RefusalNoticeStatus::Sent, $now, null), 'un ancien bail ne peut plus écrire l\'état');
        self::assertSame('PENDING', $this->noticeStatus());
    }

    public function testAnExecutionThatDiesAfterTheClaimIsResumed(): void
    {
        $this->refuse($this->submit());
        $repository = static::getContainer()->get(RefusalNoticeRepository::class);
        $id = Uuid::fromBinary((string) $this->scalar('SELECT id FROM request_refusal_notice'));
        $now = (new NativeClock())->now()->modify('+1 second');
        $repository->claim($id, $now, $now->modify('+10 minutes'));
        self::assertSame([], $this->dispatcher(clock: new MockClock($now->modify('+1 minute')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC'))->run(), 'bail en cours');
        $later = new MockClock($now->modify('+11 minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC');
        self::assertSame(['sent' => 1], $this->dispatcher(clock: $later)->run());
    }

    public function testAMailerFailureNeverRaisesToTheDecision(): void
    {
        $id = $this->submit();
        $this->refuse($id); // aucun envoi pendant la décision
        self::assertCount(0, self::getMailerMessages());
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM request_permit_request_decision WHERE outcome = 'REFUSED'"));
    }

    // ---- Agents --------------------------------------------------------------------------------------------------------------------

    public function testToCommunicateListAndManualMarkingAreScopedToTheCommune(): void
    {
        $noEmail = $this->submit(email: null, zones: ['org', 'partner']);
        $this->refuse($noEmail);
        $this->refuse($noEmail, partner: true);
        $service = static::getContainer()->get(RefusalNoticeService::class);

        $rows = $service->list($this->user($this->instructorO), $this->org->id()->toRfc4122());
        self::assertCount(1, $rows);
        self::assertSame(1, $service->count($this->user($this->adminO), $this->org->id()->toRfc4122()));
        self::assertCount(1, $service->list($this->user($this->adminP), $this->partner->id()->toRfc4122()));

        // Identifiant d'une autre commune : refus uniforme.
        $partnerRows = $service->list($this->user($this->instructorP), $this->partner->id()->toRfc4122());
        try {
            $service->markInformed($this->user($this->instructorO), $this->org->id()->toRfc4122(), $partnerRows[0]->noticeId, ManualChannel::Phone);
            self::fail('une ligne d\'une autre commune ne doit pas être modifiable');
        } catch (OperationNotAllowed) {
            self::addToAssertionCount(1);
        }
        try {
            $service->list($this->user($this->adminX), $this->org->id()->toRfc4122());
            self::fail('commune étrangère');
        } catch (OperationNotAllowed) {
            self::addToAssertionCount(1);
        }

        $service->markInformed($this->user($this->instructorO), $this->org->id()->toRfc4122(), $rows[0]->noticeId, ManualChannel::Counter);
        $this->em()->clear();
        self::assertSame(0, $service->count($this->user($this->instructorO), $this->org->id()->toRfc4122()));
        self::assertSame(1, $service->count($this->user($this->instructorP), $this->partner->id()->toRfc4122()), 'l\'autre commune garde sa propre ligne');
        $row = $this->em()->getConnection()->fetchAssociative("SELECT * FROM request_refusal_notice WHERE status = 'MANUAL'");
        self::assertSame('COUNTER', $row['manual_channel']);
        self::assertSame($this->instructorO->id()->toBinary(), $row['manual_by']);
        self::assertNotNull($row['manual_at']);

        $audit = (string) $this->scalar("SELECT new_value FROM audit_log WHERE action = 'REFUSAL_NOTICE_MARKED_INFORMED'");
        self::assertStringContainsString('COUNTER', $audit);
        self::assertStringNotContainsString(self::PHONE, $audit);

        try {
            $service->markInformed($this->user($this->instructorO), $this->org->id()->toRfc4122(), $rows[0]->noticeId, ManualChannel::Phone);
            self::fail('déjà informé');
        } catch (DomainRuleViolation) {
            self::addToAssertionCount(1);
        }
    }

    public function testPendingNoticesAreNotInTheListAndEventManagerHasNoAccess(): void
    {
        $this->refuse($this->submit());
        $service = static::getContainer()->get(RefusalNoticeService::class);
        self::assertSame([], $service->list($this->user($this->instructorO), $this->org->id()->toRfc4122()), 'en attente d\'envoi : rien à communiquer manuellement');
        self::assertFalse($service->mayAccess($this->user($this->adminX), $this->org->id()->toRfc4122()));
    }

    public function testHttpListMarkingAndHomeCounter(): void
    {
        $this->refuse($this->submit(email: null));
        $mid = $this->org->id()->toRfc4122();
        $this->client->loginUser($this->user($this->instructorO));

        $this->client->request('GET', '/communes/'.$mid);
        self::assertSelectorTextContains('#home-refusals', '1 refus à communiquer');

        $this->client->request('GET', '/communes/'.$mid.'/refus-a-communiquer');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucune adresse e-mail');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('@exemple.fr', $html);
        $form = $this->client->getCrawler()->filter('form[action*="/informe"]')->form();
        $this->client->request('POST', $form->getUri(), ['_token' => 'forge', 'channel' => 'PHONE']);
        self::assertResponseRedirects('/communes/'.$mid.'/refus-a-communiquer');
        self::assertSame('NO_RECIPIENT', $this->noticeStatus(), 'jeton CSRF invalide : rien ne change');

        $this->client->request('GET', '/communes/'.$mid.'/refus-a-communiquer');
        $form = $this->client->getCrawler()->filter('form[action*="/informe"]')->form(['channel' => 'PHONE']);
        $this->client->submit($form);
        self::assertResponseRedirects('/communes/'.$mid.'/refus-a-communiquer');
        self::assertSame('MANUAL', $this->noticeStatus());

        $this->client->loginUser($this->user($this->adminX));
        $this->client->request('GET', '/communes/'.$mid.'/refus-a-communiquer');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRequestFicheShowsTheNoticeState(): void
    {
        $id = $this->submit(email: null);
        $this->refuse($id);
        $this->client->loginUser($this->user($this->instructorO));
        $this->client->request('GET', '/communes/'.$this->org->id()->toRfc4122().'/demandes/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Information du demandeur');
        self::assertSelectorTextContains('main', 'Aucune adresse e-mail : à informer par un autre moyen');
    }

    public function testDepositConfirmationMentionsTheDecisionNotice(): void
    {
        // Le texte de confirmation annonce l'information de la décision (avec ou sans e-mail).
        $template = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/request/public/confirmation.html.twig');
        self::assertStringContainsString('informé(e) de la décision de chaque commune', $template);
        self::assertStringContainsString('contactez la mairie en indiquant votre référence', $template);
    }
}
