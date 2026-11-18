<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\Application\ForcedEventClosure;
use App\Identity\Application\CwsPermissionChecker;
use App\Identity\Application\CwsPermissionManager;
use App\Identity\Domain\CwsPermission;
use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ControlTestCase;

/** LOT 08.6 : habilitations CWS persistées et clôture exceptionnelle d'un événement (DM-065, DM-139). */
final class ForcedClosureTest extends ControlTestCase
{
    private User $root;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->root = $this->createAccount('root@cws.example', superAdmin: true);
        $this->other = $this->createAccount('autre@cws.example', superAdmin: true);
    }

    private function manager(): CwsPermissionManager
    {
        return static::getContainer()->get(CwsPermissionManager::class);
    }

    private function grant(?User $to = null, ?User $by = null): void
    {
        $this->manager()->grant($to ?? $this->root, CwsPermission::ForceCloseEvent, $by ?? $this->other, 'Astreinte de crise 2026');
        $this->em()->clear();
    }

    private function u(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail();
    }

    private function checker(): CwsPermissionChecker
    {
        return static::getContainer()->get(CwsPermissionChecker::class);
    }

    private function eventStatus(): string
    {
        return (string) $this->scalar('SELECT status FROM event_event');
    }

    private function closeUrl(): string
    {
        return '/admin/cws/evenements/'.$this->event->id()->toRfc4122().'/cloture';
    }

    private function reference(): string
    {
        return (string) $this->scalar('SELECT public_reference FROM event_event');
    }

    /** @return array<string, string> */
    private function form(string $reason = 'Organisatrice injoignable depuis 48 h', ?string $reference = null, string $password = self::PASSWORD): array
    {
        $this->client->request('GET', $this->closeUrl());
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->attr('value') ?? '';

        return ['_token' => $token, 'reason' => $reason, 'reference' => $reference ?? $this->reference(), 'password' => $password];
    }

    // ---- Permissions ---------------------------------------------------------------------------------------------------------------

    public function testNothingIsGrantedByDefaultNotEvenToSuperAdmins(): void
    {
        self::assertFalse($this->checker()->isGranted($this->u($this->root), CwsPermission::ForceCloseEvent));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM identity_cws_permission'));
    }

    public function testGrantIsRefusedForSelfNonSuperAdminAndShortReason(): void
    {
        $simple = $this->createAccount('simple@example.test');
        $cases = [
            'auto-attribution' => [$this->root, $this->root, 'Astreinte de crise 2026'],
            'motif trop court' => [$this->root, $this->other, 'court'],
            'bénéficiaire non super-administrateur' => [$simple, $this->other, 'Astreinte de crise 2026'],
            'attributeur non super-administrateur' => [$this->root, $simple, 'Astreinte de crise 2026'],
        ];
        foreach ($cases as $label => [$to, $by, $reason]) {
            try {
                $this->manager()->grant($this->u($to), CwsPermission::ForceCloseEvent, $this->u($by), $reason);
                self::fail($label.' devait être refusée');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM identity_cws_permission'));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'CWS_PERMISSION_GRANTED'"));
    }

    public function testGrantThenRevokeIsImmediateAndKeepsHistory(): void
    {
        $this->grant();
        self::assertTrue($this->checker()->isGranted($this->u($this->root), CwsPermission::ForceCloseEvent));
        self::assertFalse($this->checker()->isGranted($this->u($this->root), CwsPermission::Impersonate), 'une permission n\'en donne pas une autre');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'CWS_PERMISSION_GRANTED'"));
        $audit = (string) $this->scalar("SELECT new_value FROM audit_log WHERE action = 'CWS_PERMISSION_GRANTED'");
        self::assertStringContainsString('Astreinte de crise 2026', $audit);

        try {
            $this->manager()->grant($this->u($this->root), CwsPermission::ForceCloseEvent, $this->u($this->other), 'Astreinte de crise 2026');
            self::fail('déjà active');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('déjà active', $e->getMessage());
        }

        $this->manager()->revoke($this->u($this->root), CwsPermission::ForceCloseEvent, $this->u($this->other));
        $this->em()->clear();
        self::assertFalse($this->checker()->isGranted($this->u($this->root), CwsPermission::ForceCloseEvent));
        $this->grant();
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM identity_cws_permission'), 'historique conservé, une seule attribution active');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM identity_cws_permission WHERE active_key IS NOT NULL'));
    }

    public function testDatabaseRefusesTwoActiveGrantsForTheSameAccountAndPermission(): void
    {
        $this->grant();
        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM identity_cws_permission');
        self::assertIsArray($row);
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em()->getConnection()->insert('identity_cws_permission', ['id' => \Symfony\Component\Uid\Uuid::v7()->toBinary()] + array_diff_key($row, ['id' => 1]));
    }

    public function testDeactivatedOrDemotedSuperAdminLosesTheFunctionImmediately(): void
    {
        $this->grant();
        $this->sql('UPDATE identity_user SET super_admin = 0 WHERE id = :id', ['id' => $this->root->id()->toBinary()]);
        $this->em()->clear();
        self::assertFalse($this->checker()->isGranted($this->u($this->root), CwsPermission::ForceCloseEvent));
    }

    // ---- Accès ---------------------------------------------------------------------------------------------------------------------

    public function testWithoutPermissionTheFunctionDoesNotExist(): void
    {
        $this->client->loginUser($this->u($this->root));
        foreach (['/admin/cws/evenements/cloture', $this->closeUrl()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->client->request('POST', $this->closeUrl(), ['reason' => 'Organisatrice injoignable depuis 48 h', 'reference' => $this->reference(), 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('ACTIVE', $this->eventStatus());
    }

    public function testMunicipalAdministratorsAndAnonymousCannotReachIt(): void
    {
        $this->client->request('GET', $this->closeUrl());
        self::assertResponseRedirects('/login');
        $this->client->loginUser($this->user($this->adminO));
        $this->client->request('GET', $this->closeUrl());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->closeUrl(), ['reference' => $this->reference()]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('ACTIVE', $this->eventStatus());
    }

    public function testThePageShowsCountersWithoutAnyPersonalData(): void
    {
        $this->makePermit(['org']);
        $this->grant();
        $this->client->loginUser($this->u($this->root));
        $this->client->request('GET', $this->closeUrl());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->reference(), $html);
        foreach (['MARTIN', 'Camille', 'GH-482-KT', self::EMAIL, self::PHONE, self::ADDRESS, 'Motif confidentiel'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
        self::assertStringContainsString('1 demande', $html);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    // ---- Garde-fous ----------------------------------------------------------------------------------------------------------------

    public function testEveryGuardRefusesAndNothingChanges(): void
    {
        $this->grant();
        $this->client->loginUser($this->u($this->root));
        $cases = [
            'mot de passe' => $this->form(password: 'mauvais mot de passe'),
            'motif absent' => $this->form(reason: ''),
            'motif trop court' => $this->form(reason: 'trop court'),
            'référence' => $this->form(reference: 'EVT-FAUX-0000'),
            'référence vide' => $this->form(reference: ''),
            'jeton CSRF' => ['_token' => 'forge'] + $this->form(),
        ];
        foreach ($cases as $label => $fields) {
            $this->client->request('POST', $this->closeUrl(), $fields);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $label);
            self::assertSame('ACTIVE', $this->eventStatus(), $label.' : rien ne doit changer');
            self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'EVENT_FORCED_CLOSURE'"), $label);
        }
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM event_participation WHERE status = 'CLOSED'"));
    }

    public function testAttemptsAreRateLimited(): void
    {
        $this->grant();
        $this->client->loginUser($this->u($this->root));
        for ($i = 0; $i < 7; ++$i) {
            $this->client->request('POST', $this->closeUrl(), $this->form(password: 'mauvais'));
        }
        self::assertStringContainsString('Trop de tentatives', (string) $this->client->getResponse()->getContent());
        // Même avec le bon mot de passe : plafond atteint, rien n'est clôturé.
        $this->client->request('POST', $this->closeUrl(), $this->form());
        self::assertSame('ACTIVE', $this->eventStatus());
    }

    // ---- Exécution -----------------------------------------------------------------------------------------------------------------

    public function testSuccessfulForcedClosureUsesTheNormalEngineAndNotifiesAdministrators(): void
    {
        $permitId = $this->makePermit(['org', 'partner']);
        $token = $this->tokenOf($permitId);
        [$device] = $this->activeDevice();
        $this->grant();
        $this->client->loginUser($this->u($this->root));

        $this->client->request('POST', $this->closeUrl(), $this->form(reason: 'Organisatrice injoignable depuis 48 h, accord de la préfecture'));

        self::assertResponseRedirects('/admin/cws/evenements/cloture');
        self::assertSame('CLOSED', $this->eventStatus());
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM event_participation WHERE status <> 'CLOSED'"), 'toutes les participations sont clôturées');
        self::assertNotNull($this->scalar('SELECT closure_statistics FROM event_event'), 'statistiques de clôture enregistrées par le moteur normal');

        // Audit : opérateur réel, cause, motif ; ni mot de passe ni donnée personnelle.
        $closed = $this->em()->getConnection()->fetchAssociative("SELECT * FROM audit_log WHERE action = 'EVENT_CLOSED'");
        self::assertIsArray($closed);
        self::assertSame('SUPPORT', $closed['origin']);
        self::assertSame($this->root->id()->toBinary(), $closed['operator_id']);
        self::assertStringContainsString('CWS_FORCED', (string) $closed['new_value']);
        $forced = $this->em()->getConnection()->fetchAssociative("SELECT * FROM audit_log WHERE action = 'EVENT_FORCED_CLOSURE'");
        self::assertIsArray($forced);
        self::assertStringContainsString('accord de la préfecture', (string) $forced['new_value']);
        self::assertStringContainsString('previousStatus', (string) $forced['new_value']);
        $everything = json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM audit_log'), \JSON_INVALID_UTF8_SUBSTITUTE);
        foreach ([self::PASSWORD, 'MARTIN', 'Camille', 'GH-482-KT', self::EMAIL, self::PHONE, 'Motif confidentiel'] as $secret) {
            self::assertStringNotContainsString((string) $secret, (string) $everything, 'jamais '.$secret.' dans l\'audit');
        }

        // Notification : administrateurs communaux des communes participantes, jamais le motif, jamais une commune étrangère.
        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        $mail = self::emailAt(0);
        $to = array_map(static fn ($a): string => $a->getAddress(), $mail->getTo());
        sort($to);
        $expected = [$this->adminO->email(), $this->adminP->email()];
        sort($expected);
        self::assertSame($expected, $to);
        self::assertStringNotContainsString('préfecture', (string) $mail->getTextBody());
        self::assertStringNotContainsString('injoignable', (string) $mail->getTextBody());

        // Conséquences terrain : le jeu hors connexion est vide et marqué clos ; le laissez-passer n'ouvre plus de droit.
        $this->fresh();
        $response = $this->syncer->sync($this->em()->getRepository(\App\Control\Domain\ControlDevice::class)->find($device->id()) ?? self::fail(), null);
        self::assertTrue($response['ev']['closed']);
        self::assertSame([], $response['lines']);
        self::assertNotEmpty($token);
    }

    public function testAnAlreadyClosedEventIsRefused(): void
    {
        $this->grant();
        $this->sql("UPDATE event_event SET status = 'CLOSED'");
        $this->client->loginUser($this->u($this->root));
        $this->client->request('GET', $this->closeUrl());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAMunicipalAdministratorCannotUseTheServiceDirectly(): void
    {
        $this->expectException(OperationNotAllowed::class);
        static::getContainer()->get(ForcedEventClosure::class)->close($this->user($this->adminO), $this->event->id()->toRfc4122(), self::PASSWORD, 'Organisatrice injoignable depuis 48 h', $this->reference());
    }

    public function testARevokedPermissionBeforeExecutionWins(): void
    {
        $this->grant();
        $form = null;
        $this->client->loginUser($this->u($this->root));
        $form = $this->form();
        $this->manager()->revoke($this->u($this->root), CwsPermission::ForceCloseEvent, $this->u($this->other));
        $this->em()->clear();
        $this->client->request('POST', $this->closeUrl(), $form);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('ACTIVE', $this->eventStatus());
    }

    public function testNoCommunalRightIsCreated(): void
    {
        $before = (int) $this->scalar('SELECT COUNT(*) FROM municipality_membership');
        $this->grant();
        $this->client->loginUser($this->u($this->root));
        $this->client->request('POST', $this->closeUrl(), $this->form());
        self::assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM municipality_membership'));
        $this->client->request('GET', '/communes/'.$this->org->id()->toRfc4122().'/evenements');
        self::assertResponseStatusCodeSame(404, 'aucun accès communal implicite pour CWS (DM-005)');
    }
}
