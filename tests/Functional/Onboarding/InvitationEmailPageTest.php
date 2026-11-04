<?php

declare(strict_types=1);

namespace App\Tests\Functional\Onboarding;

use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\NationalCommune;
use App\Municipality\Domain\NationalCommuneRecord;
use App\Onboarding\Application\InvitationNotifier;
use App\Onboarding\Infrastructure\MailInvitationNotifier;
use App\Tests\Support\FlakyInvitationNotifier;
use App\Onboarding\Domain\InvitationLink;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\MunicipalPagesTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Invitation d'une commune par e-mail (MySQL de test, transport de capture) : commune du référentiel non encore inscrite,
 * adresse de contact libre, e-mail HTML + texte avec lien sécurisé, jamais de jeton ni d'adresse complète dans l'audit, échec
 * d'envoi sans perte ni doublon, renvoi qui remplace le lien, aucun droit ni abonnement créé.
 */
final class InvitationEmailPageTest extends MunicipalPagesTestCase
{
    private function referential(string $code, string $name, bool $active = true): void
    {
        $commune = new NationalCommune(new NationalCommuneRecord($code, $name, substr($code, 0, 2), '75', ['33680'], 1800), 'test', EventFixtures::now());
        if (!$active) {
            $commune->deactivate(EventFixtures::now());
        }
        $this->em()->persist($commune);
        $this->em()->flush();
    }

    private function inviteUrl(): string
    {
        return $this->eventUrl($this->w->org, $this->fire).'/inviter';
    }

    /**
     * @param array<string, string> $extra
     */
    private function invite(string $insee, string $email = 'contact@mairie.example', string $message = '', array $extra = []): string
    {
        return $this->submit($this->inviteUrl(), ['insee_code' => $insee, 'contact_email' => $email, 'message' => $message] + $extra);
    }

    private function municipalities(): int
    {
        return $this->scalar('SELECT COUNT(*) FROM municipality');
    }

    private function links(string $where = '1 = 1'): int
    {
        return $this->scalar('SELECT COUNT(*) FROM onboarding_invitation_link WHERE '.$where);
    }

    private function audits(string $action): int
    {
        return $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }

    private function lastLink(): InvitationLink
    {
        $this->em()->clear();
        $link = $this->em()->createQueryBuilder()->select('l')->from(InvitationLink::class, 'l')->orderBy('l.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $link instanceof InvitationLink ? $link : self::fail('aucun lien');
    }

    public static function joinUrlOf(Email $email): string
    {
        self::assertSame(1, preg_match('#(https?://[^"\s<]+/invitation/[A-Za-z0-9_-]{43})#', (string) $email->getTextBody(), $m), 'lien absent du texte');

        return $m[1];
    }

    // ═══ Commune du référentiel, non encore inscrite ═════════════════════════

    public function testInvitingAReferentialCommuneNotYetOnCivipassRegistersItAndEmailsTheContact(): void
    {
        $this->referential('33503', 'Saumos');
        $this->loginAs($this->w->adminO);
        $municipalities = $this->municipalities();
        $access = $this->scalar('SELECT COUNT(*) FROM municipality_commercial_access');

        $this->invite('33503', 'mairie@saumos.example', 'Rejoignez la coordination.');

        self::assertSame(303, $this->httpStatus());
        self::assertSame($municipalities + 1, $this->municipalities());
        $registered = $this->em()->getRepository(Municipality::class)->findOneBy(['inseeCode' => '33503']) ?? self::fail('commune non inscrite');
        self::assertSame('Saumos', $registered->name(), 'nom issu du référentiel, jamais saisi');
        self::assertSame('ACTIVE', $registered->status()->value);
        self::assertSame($access, $this->scalar('SELECT COUNT(*) FROM municipality_commercial_access'), 'aucun droit commercial créé');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM municipality_membership WHERE municipality_id = ?', [$registered->id()->toBinary()]), 'aucun membre créé');
        self::assertSame('INVITED', (string) $this->em()->getConnection()->fetchOne('SELECT status FROM event_participation WHERE municipality_id = ?', [$registered->id()->toBinary()]));
        self::assertSame(1, $this->audits('MUNICIPALITY_REGISTERED_FROM_REFERENTIAL'));
        self::assertSame(1, $this->audits('INVITATION_EMAIL_SENT'));
        self::assertStringContainsString('L’invitation a été envoyée à Saumos', $this->get($this->location()));
    }

    public function testTheEmailHasHtmlAndTextWithTheOrganizerTheEventTheMessageAndASecureLink(): void
    {
        $this->referential('33503', 'Saumos');
        $this->loginAs($this->w->adminO);

        $this->invite('33503', 'mairie@saumos.example', 'Rejoignez la coordination du périmètre ouest.');

        $emails = self::emails();
        self::assertCount(1, $emails);
        $email = $emails[0];
        self::assertSame('mairie@saumos.example', $email->getTo()[0]->getAddress());
        self::assertStringContainsString($this->w->org->name(), (string) $email->getSubject());
        self::assertStringContainsString('Incendie du massif forestier', (string) $email->getSubject());
        foreach ([(string) $email->getTextBody(), (string) $email->getHtmlBody()] as $body) {
            self::assertStringContainsString($this->w->org->name(), $body);
            self::assertStringContainsString('Incendie du massif forestier', $body);
            self::assertStringContainsString('Saumos', $body);
            self::assertStringContainsString('Rejoignez la coordination du périmètre ouest.', $body);
            self::assertStringContainsString('Civipass', $body);
            self::assertStringContainsString('gratuit', $body);
        }
        $url = self::joinUrlOf($email);
        self::assertStringStartsWith('http://localhost/invitation/', $url, 'lien construit depuis DEFAULT_URI');
        self::assertStringContainsString($url, (string) $email->getHtmlBody());
    }

    public function testTheLinkUsesTheConfiguredBaseUrlNeverTheHostHeader(): void
    {
        $this->referential('33503', 'Saumos');
        $this->loginAs($this->w->adminO);
        $token = $this->token($this->inviteUrl());

        $this->client->request('POST', $this->inviteUrl(), ['insee_code' => '33503', 'contact_email' => 'x@mairie.example', '_token' => $token], [], ['HTTP_HOST' => 'evil.example']);

        self::assertStringNotContainsString('evil.example', self::bodiesOf(self::emailAt(0)));
        self::assertStringStartsWith('http://localhost/', self::joinUrlOf(self::emailAt(0)));
    }

    public function testOnlyTheHashOfTheTokenIsStoredAndNeverShownInTheAudit(): void
    {
        $this->referential('33503', 'Saumos');
        $this->loginAs($this->w->adminO);

        $this->invite('33503', 'mairie@saumos.example');

        $url = self::joinUrlOf(self::emailAt(0));
        $plain = substr($url, -43);
        $link = $this->lastLink();
        self::assertSame(hash('sha256', $plain), $link->tokenHash());
        self::assertSame(0, $this->links('token_hash = '.$this->em()->getConnection()->quote($plain)), 'jamais le jeton en clair');
        $audits = (string) $this->em()->getConnection()->fetchOne("SELECT GROUP_CONCAT(new_value) FROM audit_log WHERE action LIKE 'INVITATION_%' OR action = 'MUNICIPALITY_REGISTERED_FROM_REFERENTIAL'");
        self::assertStringNotContainsString($plain, $audits);
        self::assertStringNotContainsString('mairie@saumos.example', $audits, 'adresse jamais en clair dans l’audit');
        self::assertStringContainsString('m***@saumos.example', $audits);
        self::assertSame(32, \strlen($link->tokenHash()) / 2, 'SHA-256');
        self::assertEqualsWithDelta(14 * 86400, $link->expiresAt()->getTimestamp() - $link->createdAt()->getTimestamp(), 5, 'lien de contact : 14 jours');
    }

    // ═══ Commune déjà inscrite ═════════════════════════════════════════════

    public function testInvitingAnAlreadyRegisteredCommuneDoesNotRegisterItAgainAndEmailsAnyAddress(): void
    {
        $this->loginAs($this->w->adminO);
        $municipalities = $this->municipalities();

        $this->invite($this->w->guestA->inseeCode()->value, 'quelquun@gmail.example');

        self::assertSame(303, $this->httpStatus());
        self::assertSame($municipalities, $this->municipalities(), 'aucun doublon, aucune commune créée');
        self::assertSame(0, $this->audits('MUNICIPALITY_REGISTERED_FROM_REFERENTIAL'));
        self::assertSame('quelquun@gmail.example', self::emailAt(0)->getTo()[0]->getAddress(), 'aucun domaine imposé');
    }

    public function testTheContactAddressIsFreeAndNotLinkedToTheFutureAdministrator(): void
    {
        $this->loginAs($this->w->adminO);

        $this->invite($this->w->guestA->inseeCode()->value, 'secretariat@autre-domaine.example');

        self::assertSame(303, $this->httpStatus());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM identity_user WHERE email = ?', ['secretariat@autre-domaine.example']), 'l’adresse de contact ne crée aucun compte');
    }

    // ═══ Refus : rien n'est écrit ═══════════════════════════════════════════

    public function testInvalidOrMissingContactAddressesAreRefusedWithoutAnyWrite(): void
    {
        $this->referential('33503', 'Saumos');
        $this->loginAs($this->w->adminO);
        $municipalities = $this->municipalities();
        $participations = $this->scalar('SELECT COUNT(*) FROM event_participation');

        foreach (['', 'pas-une-adresse', 'a@', '@b.example', str_repeat('a', 200).'@b.example'] as $email) {
            $html = $this->invite('33503', $email, 'Message conservé');
            self::assertSame(422, $this->httpStatus(), $email);
            self::assertStringContainsString('adresse e-mail', $html);
            self::assertStringContainsString('Message conservé', $html, 'saisies conservées');
        }
        self::assertSame($municipalities, $this->municipalities());
        self::assertSame($participations, $this->scalar('SELECT COUNT(*) FROM event_participation'));
        self::assertSame([], self::emails());
    }

    public function testACommuneAbsentFromTheReferentialOrNoLongerInItCannotBeInvitedNorRegistered(): void
    {
        $this->referential('33504', 'Disparue', active: false);
        $this->loginAs($this->w->adminO);
        $municipalities = $this->municipalities();

        foreach (['33504', '99999', '33999'] as $code) {
            $html = $this->invite($code);
            self::assertSame(422, $this->httpStatus(), $code);
            self::assertStringContainsString('introuvable ou indisponible', $html);
        }
        self::assertSame($municipalities, $this->municipalities(), 'aucune commune inscrite');
        self::assertSame([], self::emails());
    }

    public function testAnAlreadyInvitedOrParticipatingCommuneIsRefusedAndNoEmailIsSent(): void
    {
        $this->loginAs($this->w->adminO);

        $this->invite($this->w->guestB->inseeCode()->value);

        self::assertSame(422, $this->httpStatus());
        self::assertSame([], self::emails());
        self::assertSame(0, $this->links());
    }

    public function testAnUserWithoutTheRightToInviteRegistersNothingAndSendsNothing(): void
    {
        $this->referential('33503', 'Saumos');
        $municipalities = $this->municipalities();

        $this->loginAs($this->w->instructorO);
        $this->client->request('POST', $this->inviteUrl(), ['insee_code' => '33503', 'contact_email' => 'x@mairie.example', '_token' => 'x']);
        self::assertSame(403, $this->httpStatus());

        $this->loginAs($this->w->adminB);
        $this->client->request('POST', $this->eventUrl($this->w->guestB, $this->fire).'/inviter', ['insee_code' => '33503', 'contact_email' => 'x@mairie.example', '_token' => 'x']);
        self::assertSame(403, $this->httpStatus(), 'participante : pas de droit d’inviter');

        $this->loginAs($this->w->adminX);
        $this->client->request('POST', $this->eventUrl($this->w->other, $this->fire).'/inviter', ['insee_code' => '33503', 'contact_email' => 'x@mairie.example', '_token' => 'x']);
        self::assertSame(404, $this->httpStatus());

        self::assertSame($municipalities, $this->municipalities(), 'aucune commune inscrite par un utilisateur sans droit');
        self::assertSame([], self::emails());
    }

    public function testACommuneInvitedWithoutAnySubscriptionStaysFreeAndCannotCreateEvents(): void
    {
        $this->referential('33503', 'Saumos');
        $this->loginAs($this->w->adminO);
        $this->invite('33503');
        $registered = $this->em()->getRepository(Municipality::class)->findOneBy(['inseeCode' => '33503']) ?? self::fail();

        $policy = static::getContainer()->get(\App\Municipality\Application\EventCreationPolicy::class);

        self::assertTrue($policy->canJoinNewEvent($registered), 'participation gratuite (DM-086)');
        self::assertFalse($policy->canCreateEvent($registered, EventFixtures::now()), 'aucun droit de créer ses propres événements');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM municipality_commercial_access WHERE municipality_id = ?', [$registered->id()->toBinary()]));
    }

    // ═══ Échec d'envoi, renvoi ═════════════════════════════════════════════

    private function failingNotifier(): FlakyInvitationNotifier
    {
        $this->client->disableReboot();
        $flaky = new FlakyInvitationNotifier(new MailInvitationNotifier(static::getContainer()->get(MailerInterface::class), static::getContainer()->get(UrlGeneratorInterface::class), 'http://localhost'));
        static::getContainer()->set(InvitationNotifier::class, $flaky);

        return $flaky;
    }

    public function testAMailFailureKeepsTheInvitationRecordedWithoutDuplicateAndSaysSo(): void
    {
        $this->referential('33503', 'Saumos');
        $this->loginAs($this->w->adminO);
        $this->failingNotifier();

        $this->invite('33503', 'mairie@saumos.example', 'Message');

        self::assertSame(303, $this->httpStatus());
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM event_participation p JOIN municipality m ON m.id = p.municipality_id WHERE m.insee_code = '33503'"), 'l’invitation est enregistrée, une seule fois');
        self::assertSame(1, $this->links("delivery_status = 'FAILED'"));
        self::assertSame(1, $this->audits('INVITATION_EMAIL_FAILED'));
        self::assertSame(0, $this->audits('INVITATION_EMAIL_SENT'));
        $page = $this->get($this->location());
        self::assertStringContainsString('l’e-mail n’a pas pu être envoyé', $page);
        self::assertStringContainsString('Renvoyer l’invitation', $page);
        self::assertStringNotContainsString('SMTP indisponible', $page, 'aucun détail technique affiché');
    }

    public function testResendingAfterAFailureIssuesANewLinkAndRevokesTheOldOneWithoutDuplicatingTheInvitation(): void
    {
        $this->referential('33503', 'Saumos');
        $this->loginAs($this->w->adminO);
        $flaky = $this->failingNotifier();
        $this->invite('33503', 'mairie@saumos.example');
        $failed = $this->lastLink();
        $participationId = $failed->participation()->id()->toRfc4122();
        $flaky->fail = false;

        $this->resend($participationId, '');

        self::assertSame(303, $this->httpStatus());
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM event_participation p JOIN municipality m ON m.id = p.municipality_id WHERE m.insee_code = '33503'"), 'toujours une seule invitation');
        self::assertSame(2, $this->links());
        self::assertSame(1, $this->links('revoked_at IS NOT NULL'), 'l’ancien lien est remplacé');
        self::assertSame(1, $this->links("delivery_status = 'SENT' AND revoked_at IS NULL"));
        self::assertCount(1, self::emails());
        self::assertSame('mairie@saumos.example', self::emailAt(0)->getTo()[0]->getAddress(), 'dernière adresse réutilisée');
    }

    private function resend(string $participationId, string $email): string
    {
        $page = $this->eventUrl($this->w->org, $this->fire).'/communes';
        $this->client->request('GET', $page);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('#action="[^"]*/invitations/'.$participationId.'/renvoyer"[^>]*>.*?name="_token" value="([^"]+)"#s', $html, $m), 'formulaire de renvoi absent');
        $this->client->request('POST', $this->eventUrl($this->w->org, $this->fire).'/invitations/'.$participationId.'/renvoyer', ['_token' => $m[1], 'contact_email' => $email]);

        return (string) $this->client->getResponse()->getContent();
    }

    public function testResendingCanCorrectTheAddressAndIsRestrictedToPendingInvitationsOfThisEvent(): void
    {
        $this->client->disableReboot();
        $this->loginAs($this->w->adminO);
        $this->invite($this->w->guestA->inseeCode()->value, 'ancienne@mairie.example');
        $participationId = $this->lastLink()->participation()->id()->toRfc4122();

        $this->resend($participationId, 'nouvelle@mairie.example');

        self::assertSame(303, $this->httpStatus());
        self::assertSame('nouvelle@mairie.example', self::emailAt(0)->getTo()[0]->getAddress());

        // Participation d'une autre commune de CET événement, déjà active : refusée.
        $active = (string) $this->em()->getConnection()->fetchOne('SELECT BIN_TO_UUID(p.id) FROM event_participation p WHERE p.event_id = ? AND p.municipality_id = ?', [(string) hex2bin(str_replace('-', '', $this->fire)), $this->w->guestB->id()->toBinary()]);
        $this->client->request('POST', $this->eventUrl($this->w->org, $this->fire).'/invitations/'.$active.'/renvoyer', ['_token' => 'x', 'contact_email' => 'x@mairie.example']);
        self::assertSame([], self::emails(), 'jeton invalide : rien n’est renvoyé');

        // Participation d'un AUTRE événement : refusée (404 uniforme / 403).
        $other = (string) $this->em()->getConnection()->fetchOne('SELECT BIN_TO_UUID(p.id) FROM event_participation p WHERE p.event_id = ? LIMIT 1', [(string) hex2bin(str_replace('-', '', $this->accident))]);
        $this->client->request('POST', $this->eventUrl($this->w->org, $this->fire).'/invitations/'.$other.'/renvoyer', ['_token' => 'x', 'contact_email' => 'x@mairie.example']);
        self::assertContains($this->httpStatus(), [303, 403, 404]);
        self::assertSame([], self::emails(), 'participation d’un autre événement : rien n’est envoyé');
        self::assertSame(2, $this->links(), 'seul le renvoi légitime a émis un lien (plus l’envoi initial)');
    }

    public function testTheResendRouteIsRestrictedToTheOrganizerWithTheRightToInvite(): void
    {
        $this->client->disableReboot();
        $this->loginAs($this->w->adminO);
        $this->invite($this->w->guestA->inseeCode()->value, 'ancienne@mairie.example');
        $participationId = $this->lastLink()->participation()->id()->toRfc4122();
        $url = $this->eventUrl($this->w->org, $this->fire).'/invitations/'.$participationId.'/renvoyer';

        foreach ([[$this->w->instructorO, $url, 403], [$this->w->adminB, $this->eventUrl($this->w->guestB, $this->fire).'/invitations/'.$participationId.'/renvoyer', 403], [$this->w->adminX, $this->eventUrl($this->w->other, $this->fire).'/invitations/'.$participationId.'/renvoyer', 404]] as [$user, $target, $status]) {
            $this->loginAs($user);
            $this->client->request('POST', $target, ['_token' => 'x', 'contact_email' => 'x@mairie.example']);
            self::assertSame($status, $this->httpStatus());
            self::assertSame([], self::emails());
        }
        self::assertSame(1, $this->links(), 'aucun lien supplémentaire');
    }

    // ═══ Recherche dynamique ═══════════════════════════════════════════════

    public function testTheSearchFragmentIsServedToTheOrganizerOnly(): void
    {
        $this->referential('33503', 'Saumos');
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->inviteUrl().'/communes?q=Saum');

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('value="33503"', $html);
        self::assertStringContainsString('pas encore inscrite sur Civipass', $html);
        self::assertStringNotContainsString('<html', $html, 'fragment, pas une page');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        foreach ([[$this->w->instructorO, $this->w->org, 403], [$this->w->adminB, $this->w->guestB, 403], [$this->w->adminX, $this->w->other, 404]] as [$user, $municipality, $status]) {
            $this->loginAs($user);
            $this->get($this->eventUrl($municipality, $this->fire).'/inviter/communes?q=Saum');
            self::assertSame($status, $this->httpStatus());
        }
    }

    public function testTheInvitePageSelectsAnUnregisteredCommuneAndAsksForTheContactAddress(): void
    {
        $this->referential('33503', 'Saumos');
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->inviteUrl().'?q=Saumos');

        self::assertStringContainsString('name="insee_code" value="33503"', $html);
        self::assertStringContainsString('name="contact_email"', $html);
        self::assertStringContainsString('pas nécessairement celle du futur administrateur', $html);
        self::assertStringContainsString('data-controller="commune-search"', $html);
    }
}
