<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Suivi des notifications et envoi manuel sur l'écran agent (LOT 05.3.4) dans le navigateur simulé : libellés compréhensibles, aucun secret ni
 * code interne, extensions des autres communes invisibles, CSRF, double envoi, rôles et participation ACTIVE. Événement recalé autour de la date du jour.
 */
#[Group('requires-request-schema')]
final class NotificationScreenTest extends PublicRequestTestCase
{
    private User $instructor;
    private User $manager;
    private User $partnerInstructor;
    private User $outsider;
    private string $organizerId;
    private string $partnerId;
    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $other = \App\Tests\Support\MunicipalityBuilder::aMunicipality()->withInseeCode('33063')->build();
        $this->organizerId = $organizer->id()->toRfc4122();
        $this->partnerId = $partner->id()->toRfc4122();
        [$this->instructor, $this->manager, $this->partnerInstructor, $this->outsider] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 4));
        $this->save(
            $other,
            $this->instructor,
            $this->manager,
            $this->partnerInstructor,
            $this->outsider,
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->manager, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->partnerInstructor, $partner)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->outsider, $other)->withRoles(Role::Instructor)->build(),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->requestId = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', 'camille.secret@exemple.fr', '0611223344', 'private_person', 'home', null, TravelMode::Vehicle, ['GH-482-KT'], [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], true),
            sprintf('notif-screen-%016d', random_int(1, 999999)),
        )->requestId;
        static::getContainer()->get(MunicipalInstructionService::class)->approve($this->managed($this->instructor), $this->organizerId, $this->requestId, ApprovalDuration::untilClosure());
    }

    private function managed(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function as(User $user): void
    {
        $this->client->loginUser($this->managed($user));
    }

    private function url(string $suffix = '', ?string $commune = null): string
    {
        return '/communes/'.($commune ?? $this->organizerId).'/demandes/'.$this->requestId.'/laissez-passer'.$suffix;
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function setFirstStatus(string $status, ?string $code = null): void
    {
        $this->sql('UPDATE notification_outbox SET status = :s, last_error_code = :c, processed_at = UTC_TIMESTAMP() WHERE type = \'PERMIT_ISSUED\'', ['s' => $status, 'c' => $code]);
    }

    private function approvePartner(): void
    {
        static::getContainer()->get(MunicipalInstructionService::class)->approve($this->managed($this->partnerInstructor), $this->partnerId, $this->requestId, ApprovalDuration::fixed(DurationMode::H24));
    }

    public function testThePendingFirstMessageIsShownInPlainFrenchWithoutAnyTechnicalDetail(): void
    {
        $this->as($this->instructor);
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        $html = $this->html();
        self::assertStringContainsString('Notification par e-mail', $html);
        self::assertStringContainsString('Mise à disposition du laissez-passer', $html);
        self::assertStringContainsString('En attente d’envoi', $html);
        foreach (['PERMIT_ISSUED', 'PENDING', 'SMTP_', 'CRYPTO', 'deduplication', 'camille.secret@exemple.fr', 'attempts', 'outbox'] as $internal) {
            self::assertStringNotContainsString($internal, $html, 'aucune information technique ou personnelle superflue : '.$internal);
        }
        preg_match_all('/[A-Za-z0-9_-]{43}/', preg_replace('#https?://\S+#', '', strip_tags(preg_replace('#<script\b.*?</script>#s', '', $html) ?? '')) ?? '', $found); // hors importmap : ses empreintes d'intégrité (sha384-…) ne sont pas des secrets
        self::assertSame([], $found[0], 'aucun secret ni empreinte');
        self::assertSelectorNotExists('#manual-delivery');
    }

    /**
     * @return iterable<string, array{string, ?string, string, bool}>
     */
    public static function states(): iterable
    {
        yield 'envoyé' => ['SENT', null, 'Envoyé au serveur de messagerie', false];
        yield 'aucun e-mail' => ['NO_RECIPIENT', null, 'Aucun e-mail disponible', true];
        yield 'échec' => ['ABANDONED', 'SMTP_PERMANENT', 'Échec d’envoi', true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('states')]
    public function testEachStateHasAClearLabelAndTheManualDeliveryIndicatorWhenNothingReachedTheBeneficiary(string $status, ?string $code, string $label, bool $manual): void
    {
        $this->setFirstStatus($status, $code);
        $this->as($this->instructor);

        $this->client->request('GET', $this->url());

        self::assertStringContainsString($label, $this->html());
        $manual ? self::assertSelectorExists('#manual-delivery') : self::assertSelectorNotExists('#manual-delivery');
        self::assertStringNotContainsString('SMTP_PERMANENT', $this->html());
    }

    public function testASuppressedExtensionIsExplainedToItsOwnCommune(): void
    {
        $this->approvePartner();
        $this->sql('UPDATE notification_outbox SET status = \'SUPPRESSED\', last_error_code = \'ALREADY_INCLUDED\', processed_at = UTC_TIMESTAMP() WHERE type = \'AUTHORIZATION_ADDED\'');
        $this->as($this->partnerInstructor);

        $this->client->request('GET', $this->url('', $this->partnerId));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Extension des droits', $this->html());
        self::assertStringContainsString('Non envoyé', $this->html());
        self::assertStringContainsString('figuraient déjà dans le message de mise à disposition', $this->html());
        self::assertStringNotContainsString('ALREADY_INCLUDED', $this->html());
    }

    public function testAnotherCommunesExtensionIsNeitherShownNorCounted(): void
    {
        $this->approvePartner();
        $this->as($this->instructor);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Extension des droits', $this->html(), 'l\'extension de la commune partenaire n\'est pas visible de l\'organisatrice');
        self::assertSelectorNotExists('#notification-lines li:nth-child(2)');
    }

    public function testAnAgentRequestsANewMessageWithCsrfAndADoubleSubmissionRecordsOnlyOne(): void
    {
        $this->setFirstStatus('SENT');
        $this->as($this->instructor);
        $crawler = $this->client->request('GET', $this->url());
        $form = $crawler->filter('#notify-form');
        self::assertCount(1, $form);
        $fields = ['_token' => $form->filter('input[name=_token]')->attr('value'), 'request_id' => $form->filter('input[name=request_id]')->attr('value')];

        $this->client->request('POST', (string) $form->attr('action'), $fields);
        self::assertResponseRedirects($this->url());
        $this->client->followRedirect();
        self::assertStringContainsString('nouveau message de mise à disposition est programmé', $this->html());

        $this->client->request('POST', (string) $form->attr('action'), $fields); // double clic : même identifiant de formulaire
        $this->client->followRedirect();
        self::assertStringContainsString('déjà été enregistrée', $this->html());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE deduplication_key LIKE \'PERMIT_ISSUED_MANUAL:%\''));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = \'PERMIT_NOTIFICATION_REQUESTED\''));
        self::assertStringContainsString('Nouvel envoi de la mise à disposition', $this->html());
    }

    public function testARequestWithoutAValidCsrfTokenCreatesNothing(): void
    {
        $this->setFirstStatus('SENT');
        $this->as($this->instructor);

        $this->client->request('POST', $this->url('/notification'), ['_token' => 'forgé', 'request_id' => Uuid::v7()->toRfc4122()]);
        self::assertResponseRedirects($this->url());
        $this->client->followRedirect();
        self::assertStringContainsString('formulaire a expiré', $this->html());
        $this->client->request('POST', $this->url('/notification'), ['request_id' => Uuid::v7()->toRfc4122()]);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE deduplication_key LIKE \'PERMIT_ISSUED_MANUAL:%\''));
    }

    public function testNoFormIsOfferedWithoutAnAddressAndTheRequestIsRefused(): void
    {
        $this->setFirstStatus('NO_RECIPIENT');
        $this->sql('UPDATE request_permit_request SET email = NULL');
        $this->as($this->instructor);
        $crawler = $this->client->request('GET', $this->url());

        self::assertSelectorNotExists('#notify-form');
        self::assertSelectorExists('#notify-no-address');
        $token = $crawler->filter('form[action$="/liens"] input[name=_token]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', $this->url('/notification'), ['_token' => $token, 'request_id' => Uuid::v7()->toRfc4122()]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE deduplication_key LIKE \'PERMIT_ISSUED_MANUAL:%\''));
    }

    public function testASuspendedParticipationKeepsTheStatusReadableButOffersNoAction(): void
    {
        $this->setFirstStatus('SENT');
        $this->sql('UPDATE event_participation SET status = \'SUSPENDED\' WHERE municipality_id = :m', ['m' => Uuid::fromString($this->organizerId)->toBinary()]);
        $this->as($this->instructor);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Envoyé au serveur de messagerie', $this->html());
        self::assertSelectorNotExists('#notify-form');
        $this->client->request('POST', $this->url('/notification'), ['_token' => 'x', 'request_id' => Uuid::v7()->toRfc4122()]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE deduplication_key LIKE \'PERMIT_ISSUED_MANUAL:%\''));
    }

    public function testRolesAndCommunesAreEnforcedServerSide(): void
    {
        $this->client->request('POST', $this->url('/notification'));
        self::assertResponseRedirects('/login');

        $this->as($this->manager);
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url('/notification'), ['request_id' => Uuid::v7()->toRfc4122()]);
        self::assertResponseStatusCodeSame(403);

        foreach ([[$this->outsider, $this->organizerId], [$this->partnerInstructor, $this->partnerId], [$this->instructor, Uuid::v7()->toRfc4122()]] as [$agent, $commune]) {
            $this->as($agent);
            $this->client->request('POST', $this->url('/notification', $commune), ['request_id' => Uuid::v7()->toRfc4122()]);
            self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404], 'refus pour '.$commune);
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE deduplication_key LIKE \'PERMIT_ISSUED_MANUAL:%\''));
    }

    public function testThePageNeverLeaksASecretAfterAManualRequest(): void
    {
        $this->setFirstStatus('SENT');
        $this->as($this->instructor);
        $crawler = $this->client->request('GET', $this->url());
        $form = $crawler->filter('#notify-form');
        $this->client->request('POST', (string) $form->attr('action'), ['_token' => $form->filter('input[name=_token]')->attr('value'), 'request_id' => $form->filter('input[name=request_id]')->attr('value')]);
        $this->client->followRedirect();

        $html = $this->html();
        self::assertStringNotContainsString('/laissez-passer/', preg_replace('#<form[^>]*action="[^"]*"#', '', $html) ?? '', 'aucun lien bénéficiaire dans la page de suivi');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_secret'), 'aucun secret avant l\'envoi');
    }

    /**
     * RECETTE navigateur (hors `make quality`) : écrit les VRAIES pages agent, dans leurs différents états, sous `var/recette/` pour qu'un vrai navigateur
     * les rende (`CIVIPASS_RECETTE_RENDER=1`). Les ressources (CSS) sont lues sur le domaine configuré de l'application via `<base href>`.
     */
    public function testRenderThePagesForTheBrowserRecette(): void
    {
        if ('1' !== getenv('CIVIPASS_RECETTE_RENDER')) {
            self::markTestSkipped('Recette de rendu : définir CIVIPASS_RECETTE_RENDER=1.');
        }
        $local = (string) @file_get_contents(\dirname(__DIR__, 3).'/.env.local');
        $base = 1 === preg_match('/^DEFAULT_URI=(\S+)$/m', $local, $m) ? rtrim($m[1], '/') : 'http://localhost';
        $directory = \dirname(__DIR__, 3).'/var/recette';
        @mkdir($directory, 0o775, true);
        $write = function (string $name) use ($directory, $base): void {
            file_put_contents($directory.'/'.$name.'.html', str_replace('<head>', '<head><base href="'.$base.'/">', $this->html()));
        };

        $this->as($this->instructor);
        $this->client->request('GET', $this->url());
        $write('agent-1-en-attente');

        $this->setFirstStatus('SENT');
        $this->approvePartner();
        $this->client->request('GET', $this->url());
        $write('agent-2-envoye-organisatrice');
        $this->as($this->partnerInstructor);
        $this->client->request('GET', $this->url('', $this->partnerId));
        $write('agent-3-envoye-partenaire-extension-en-attente');

        $this->sql('UPDATE notification_outbox SET status = \'SUPPRESSED\', last_error_code = \'ALREADY_INCLUDED\', processed_at = UTC_TIMESTAMP() WHERE type = \'AUTHORIZATION_ADDED\'');
        $this->client->request('GET', $this->url('', $this->partnerId));
        $write('agent-4-extension-supprimee');

        $this->setFirstStatus('ABANDONED', 'SMTP_PERMANENT');
        $this->as($this->instructor);
        $this->client->request('GET', $this->url());
        $write('agent-5-echec');

        $this->setFirstStatus('NO_RECIPIENT');
        $this->sql('UPDATE request_permit_request SET email = NULL');
        $this->client->request('GET', $this->url());
        $write('agent-6-sans-adresse');

        self::assertFileExists($directory.'/agent-6-sans-adresse.html');
    }
}
