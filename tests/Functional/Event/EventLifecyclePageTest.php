<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventZoneService;
use App\Tests\Support\MunicipalPagesTestCase;

/**
 * Cycle de vie d'un événement par l'interface (MySQL de test) : activation (prérequis relu par le moteur), suspension (motif
 * obligatoire), réactivation, clôture globale irréversible, permissions, isolation et jetons. Les écritures passent par
 * EventLifecycleService ; ces tests vérifient le câblage HTTP, les messages et les cas limites.
 */
final class EventLifecyclePageTest extends MunicipalPagesTestCase
{
    private function url(string $eventId, string $action, ?string $municipality = null): string
    {
        return $this->eventUrl($municipality ?? $this->w->org, $eventId).'/cycle/'.$action;
    }

    private function eventStatus(string $eventId): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT status FROM event_event WHERE id = ?', [(string) hex2bin(str_replace('-', '', $eventId))]);
    }

    /** @return list<string> */
    private function participationStatuses(string $eventId): array
    {
        return array_map('strval', $this->em()->getConnection()->fetchFirstColumn('SELECT status FROM event_participation WHERE event_id = ? ORDER BY status', [(string) hex2bin(str_replace('-', '', $eventId))]));
    }

    private function audits(string $action): int
    {
        return $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }

    private function assertStatus(int $expected, string $message = ''): void
    {
        self::assertSame($expected, $this->httpStatus(), $message);
    }

    /** Zone proposée de l'organisatrice, confirmée et configurée : l'événement peut être activé. */
    private function makeReady(string $eventId): string
    {
        $id = (string) $this->em()->getConnection()->fetchOne('SELECT BIN_TO_UUID(z.id) FROM event_zone z JOIN event_participation p ON p.id = z.participation_id WHERE p.event_id = ? AND p.municipality_id = ?', [(string) hex2bin(str_replace('-', '', $eventId)), $this->w->org->id()->toBinary()]);
        $admin = $this->user($this->w->adminO);
        static::getContainer()->get(EventZoneService::class)->confirm($admin, $id);
        static::getContainer()->get(EventZoneService::class)->configureValidity($admin, $id, new ZoneValidityCommand('EVENT_DURATION'));
        $this->em()->clear();

        return $id;
    }

    private function activate(string $eventId): void
    {
        $this->makeReady($eventId);
        static::getContainer()->get(EventLifecycleService::class)->activate($this->user($this->w->adminO), $eventId);
        $this->em()->clear();
    }

    /** Jeton du formulaire du cycle de vie dont l'action se termine par /cycle/{action}, lu sur la fiche. */
    private function formToken(string $formUrl, ?string $action = null): string
    {
        $html = $this->get($formUrl);
        self::assertSame(1, preg_match('#action="[^"]*/cycle/'.preg_quote((string) $action, '#').'"[^>]*>.*?name="_token" value="([^"]+)"#s', $html, $m), 'formulaire '.$action.' absent');

        return $m[1];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function transition(string $eventId, string $action, array $fields = []): string
    {
        $page = $this->eventUrl($this->w->org, $eventId);
        $this->client->request('POST', $this->url($eventId, $action), $fields + ['_token' => $this->formToken($page, $action)]);

        return (string) $this->client->getResponse()->getContent();
    }

    // ═══ Activation ═══════════════════════════════════════════════════════════

    public function testAnEventWithAReadyZoneIsActivatedThroughItsConfirmationPanel(): void
    {
        $this->makeReady($this->fire);
        $this->loginAs($this->w->adminO);
        $page = $this->get($this->eventUrl($this->w->org, $this->fire));
        self::assertStringContainsString('Activer l’événement…', $page);
        self::assertStringContainsString('Activer « Incendie du massif forestier »', $page, 'panneau de confirmation nominatif');
        self::assertStringContainsString('ne peuvent plus être modifiés', $page, 'conséquences annoncées avant le bouton');
        $before = $this->audits('EVENT_ACTIVATED');

        $this->transition($this->fire, 'activer');

        $this->assertStatus(303);
        self::assertSame('ACTIVE', $this->eventStatus($this->fire));
        self::assertSame($before + 1, $this->audits('EVENT_ACTIVATED'));
        $html = $this->get($this->location());
        self::assertStringContainsString('L’événement est activé.', $html);
        self::assertStringNotContainsString('Activer l’événement', $html);
        self::assertStringContainsString('Suspendre l’événement…', $html);
    }

    public function testAnEventWithoutAReadyZoneOffersNoActivationForm(): void
    {
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->eventUrl($this->w->org, $this->fire));

        self::assertStringContainsString('Aucune zone n’est prête', $html);
        self::assertStringNotContainsString('/cycle/activer', $html);
        self::assertStringContainsString('Confirmez au moins une zone active et configurez sa validité.', $html);
    }

    public function testTheEngineRefusesTheActivationWhenTheReadyZoneStoppedBeingReady(): void
    {
        $zone = $this->makeReady($this->fire);
        $this->loginAs($this->w->adminO);
        $page = $this->eventUrl($this->w->org, $this->fire);
        $token = $this->formToken($page, 'activer');
        // Entre l'affichage et l'envoi, la seule zone prête est suspendue : le prérequis est relu SOUS verrou par le moteur.
        static::getContainer()->get(EventZoneService::class)->suspend($this->user($this->w->adminO), $zone);
        $this->em()->clear();

        $this->client->request('POST', $this->url($this->fire, 'activer'), ['_token' => $token]);
        $html = html_entity_decode((string) $this->client->getResponse()->getContent(), \ENT_QUOTES);

        $this->assertStatus(422);
        self::assertStringContainsString('Aucune zone n\'est prête pour l\'activation', $html);
        self::assertSame('DRAFT', $this->eventStatus($this->fire));
    }

    // ═══ Suspension et réactivation ═══════════════════════════════════════════

    public function testSuspendingNeedsAReasonThenSuspendsAndReactivates(): void
    {
        $this->activate($this->fire);
        $this->loginAs($this->w->adminO);
        $page = $this->eventUrl($this->w->org, $this->fire);

        $html = $this->transition($this->fire, 'suspendre', ['reason' => '   ']);
        $this->assertStatus(422);
        self::assertStringContainsString('Indiquez le motif de la suspension.', $html);
        self::assertSame('ACTIVE', $this->eventStatus($this->fire));

        $this->transition($this->fire, 'suspendre', ['reason' => 'Vent fort : accès interdits']);
        $this->assertStatus(303);
        self::assertSame('SUSPENDED', $this->eventStatus($this->fire));
        self::assertSame(1, $this->audits('EVENT_SUSPENDED'));
        $suspended = $this->get($this->location());
        self::assertStringContainsString('L’événement est suspendu.', $suspended, 'confirmation après redirection');
        self::assertStringContainsString('Réactiver l’événement', $suspended);
        self::assertStringNotContainsString('Vent fort', $suspended, 'le motif interne n’est pas exposé');
        self::assertSame(['ACTIVE', 'ACTIVE'], $this->participationStatuses($this->fire), 'les participations ne sont pas suspendues');

        $this->transition($this->fire, 'reactiver');
        $this->assertStatus(303);
        self::assertSame('ACTIVE', $this->eventStatus($this->fire));
        self::assertSame(1, $this->audits('EVENT_REACTIVATED'));
    }

    public function testTheSuspensionReasonIsKeptWhenThePageIsRedisplayed(): void
    {
        $this->activate($this->fire);
        $this->loginAs($this->w->adminO);

        $html = $this->transition($this->fire, 'suspendre', ['reason' => 'Motif conservé'] + ['_token' => 'forge']);

        $this->assertStatus(422);
        self::assertStringContainsString('Le formulaire a expiré', $html);
        self::assertStringContainsString('Motif conservé', $html);
        self::assertSame('ACTIVE', $this->eventStatus($this->fire));
    }

    // ═══ Clôture ═══════════════════════════════════════════════════════════════

    public function testClosingIsGlobalIrreversibleAndAnnouncedBeforeTheButton(): void
    {
        $this->activate($this->fire);
        $this->loginAs($this->w->adminO);
        $page = $this->get($this->eventUrl($this->w->org, $this->fire));
        self::assertStringContainsString('2 participations ouvertes (la vôtre comprise) sont clôturées', $page);
        self::assertStringContainsString('ne pourra plus être réactivé', $page);

        $closed = $this->audits('EVENT_CLOSED'); // l'événement « Crue » est déjà clôturé par le jeu de test

        $this->transition($this->fire, 'cloturer');

        $this->assertStatus(303);
        self::assertSame('CLOSED', $this->eventStatus($this->fire));
        self::assertSame(['CLOSED', 'CLOSED'], $this->participationStatuses($this->fire), 'toutes les participations sont clôturées dans la même transaction');
        self::assertSame($closed + 1, $this->audits('EVENT_CLOSED'));
        $html = $this->get($this->location());
        self::assertStringContainsString('Événement clôturé', $html);
        self::assertStringNotContainsString('/cycle/', $html, 'plus aucune action : irréversible');

        $this->client->request('POST', $this->url($this->fire, 'reactiver'), ['_token' => 'x']);
        $this->assertStatus(403);
        $this->client->request('POST', $this->url($this->fire, 'cloturer'), ['_token' => 'x']);
        $this->assertStatus(403, 'un événement clôturé ne se clôture pas deux fois');
        self::assertSame($closed + 1, $this->audits('EVENT_CLOSED'));
    }

    public function testADraftEventAndASuspendedEventCanBeClosed(): void
    {
        $this->loginAs($this->w->adminO);
        $this->transition($this->accident, 'cloturer');
        $this->assertStatus(303);
        self::assertSame('CLOSED', $this->eventStatus($this->accident));
        self::assertSame(['CLOSED', 'CLOSED'], $this->participationStatuses($this->accident), 'l’invitation en attente est clôturée aussi (DM-092)');

        $this->activate($this->fire);
        static::getContainer()->get(EventLifecycleService::class)->suspend($this->user($this->w->adminO), $this->fire, 'Test');
        $this->em()->clear();
        $this->loginAs($this->w->adminO);
        $this->transition($this->fire, 'cloturer');
        $this->assertStatus(303);
        self::assertSame('CLOSED', $this->eventStatus($this->fire));
    }

    // ═══ Permissions, isolation, jetons ═══════════════════════════════════════

    public function testAnEventManagerMayManageTheLifecycleButNotAnInstructor(): void
    {
        $this->makeReady($this->fire);

        $this->loginAs($this->w->instructorO);
        $html = $this->get($this->eventUrl($this->w->org, $this->fire));
        self::assertStringNotContainsString('/cycle/', $html, 'aucun bouton pour un rôle sans droit');
        $this->client->request('POST', $this->url($this->fire, 'activer'), ['_token' => 'x']);
        $this->assertStatus(403);

        $this->loginAs($this->w->managerO);
        $this->transition($this->fire, 'activer');
        $this->assertStatus(303);
        self::assertSame('ACTIVE', $this->eventStatus($this->fire));
    }

    public function testOtherCommunesCannotChangeTheLifecycleOfAnEventTheyDoNotOrganize(): void
    {
        $this->activate($this->fire);
        $this->loginAs($this->w->adminO);
        $token = $this->formToken($this->eventUrl($this->w->org, $this->fire), 'suspendre');

        $this->loginAs($this->w->adminB); // participante : voit l'événement, ne l'organise pas
        $this->client->request('POST', $this->url($this->fire, 'suspendre', $this->id($this->w->guestB)), ['reason' => 'x', '_token' => $token]);
        $this->assertStatus(403);

        $this->loginAs($this->w->adminX); // commune étrangère à cet événement
        $this->client->request('POST', $this->url($this->fire, 'suspendre', $this->id($this->w->other)), ['reason' => 'x', '_token' => $token]);
        $this->assertStatus(404);
        $this->client->request('POST', $this->url($this->fire, 'suspendre'), ['reason' => 'x', '_token' => $token]);
        $this->assertStatus(404, 'URL de la commune organisatrice par une commune étrangère');

        $this->loginAs($this->w->superAdmin);
        $this->client->request('POST', $this->url($this->fire, 'cloturer'), ['_token' => 'x']);
        $this->assertStatus(404, 'aucun droit communal implicite');

        $this->loginAs($this->w->adminX); // simplement invitée à l'événement « accident »
        $this->client->request('POST', $this->url($this->accident, 'cloturer', $this->id($this->w->other)), ['_token' => 'x']);
        $this->assertStatus(403);
        self::assertSame('ACTIVE', $this->eventStatus($this->fire));
        self::assertSame('DRAFT', $this->eventStatus($this->accident));
    }

    public function testAForgedTokenIsRefusedAndNothingChanges(): void
    {
        $this->activate($this->fire);
        $this->loginAs($this->w->adminO);

        $this->client->request('POST', $this->url($this->fire, 'cloturer'), ['_token' => 'forge']);

        $this->assertStatus(422);
        self::assertSame('ACTIVE', $this->eventStatus($this->fire));
        self::assertSame(['ACTIVE', 'ACTIVE'], $this->participationStatuses($this->fire));
    }

    public function testATokenOfAnotherActionOrAnotherEventIsRefused(): void
    {
        $this->activate($this->fire);
        $this->loginAs($this->w->adminO);
        $suspendToken = $this->formToken($this->eventUrl($this->w->org, $this->fire), 'suspendre');

        $this->client->request('POST', $this->url($this->fire, 'cloturer'), ['_token' => $suspendToken]);

        $this->assertStatus(422, 'un jeton est propre à son action et à son événement');
        self::assertSame('ACTIVE', $this->eventStatus($this->fire));
    }

    public function testTheLifecycleRoutesRequireAFullAuthenticationAndPostOnly(): void
    {
        $this->client->request('POST', $this->url($this->fire, 'cloturer'), ['_token' => 'x']);
        $this->assertStatus(302);
        self::assertStringContainsString('/login', $this->location());

        $this->loginAs($this->w->adminO);
        $this->client->request('GET', $this->url($this->fire, 'cloturer'));
        $this->assertStatus(405);
        self::assertSame('DRAFT', $this->eventStatus($this->fire));
    }

    public function testAnActionOutsideTheCurrentStateIsRefused(): void
    {
        $this->loginAs($this->w->adminO);
        $page = $this->eventUrl($this->w->org, $this->fire);
        $token = $this->formToken($page, 'cloturer');

        foreach (['suspendre', 'reactiver'] as $action) {
            $this->client->request('POST', $this->url($this->fire, $action), ['reason' => 'x', '_token' => $token]);
            $this->assertStatus(403, $action.' sur un brouillon');
        }
        self::assertSame('DRAFT', $this->eventStatus($this->fire));
    }
}
