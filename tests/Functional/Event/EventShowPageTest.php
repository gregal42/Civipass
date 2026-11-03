<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\Command\UpdateEventCommand;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Event\Application\Service\EventZoneService;
use App\Event\Application\Command\ZoneValidityCommand;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalPagesTestCase;
use App\Tests\Support\UserBuilder;

final class EventShowPageTest extends MunicipalPagesTestCase
{
    private function lifecycle(): EventLifecycleService
    {
        return static::getContainer()->get(EventLifecycleService::class);
    }

    private function proposedZone(string $eventId): string
    {
        return (string) $this->em()->getConnection()->fetchOne(
            'SELECT LOWER(CONCAT(SUBSTR(HEX(z.id),1,8),\'-\',SUBSTR(HEX(z.id),9,4),\'-\',SUBSTR(HEX(z.id),13,4),\'-\',SUBSTR(HEX(z.id),17,4),\'-\',SUBSTR(HEX(z.id),21)))'
            .' FROM event_zone z JOIN event_participation p ON p.id = z.participation_id WHERE p.event_id = ? AND p.municipality_id = ?',
            [str_replace('-', '', $eventId) ? (string) hex2bin(str_replace('-', '', $eventId)) : '', $this->w->org->id()->toBinary()],
        );
    }

    // ── Organisatrice ────────────────────────────────────────────────────────

    public function testTheOrganizerSeesTheFullRecordOfADraftEvent(): void
    {
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->eventUrl($this->w->org, $this->fire));

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Incendie du massif forestier', $html);
        self::assertStringContainsString('EVT-', $html, 'référence');
        self::assertStringContainsString('Brouillon', $html);
        self::assertStringContainsString('Organisatrice', $html);
        self::assertStringContainsString($this->w->org->name(), $html);
        self::assertStringContainsString('Europe/Paris', $html, 'fuseau horaire');
        self::assertStringContainsString('01/12/2026 à 09:00', $html, 'début affiché dans le fuseau de l\'événement (08:00 UTC)');
        self::assertStringContainsString($this->w->guestB->name(), $html, 'participations visibles');
        self::assertStringContainsString('Cycle de vie', $html);
        self::assertStringContainsString('Aucune zone n’est prête', $html, 'garde d\'activation (DM-059)');
    }

    public function testActionsAreComputedByThePolicyAndNeverSimulated(): void
    {
        $this->loginAs($this->w->adminO);
        $html = $this->get($this->eventUrl($this->w->org, $this->fire));

        foreach (['Modifier', 'Inviter une commune', 'Activer l’événement', 'Clôturer l’événement'] as $label) {
            self::assertStringContainsString($label, $html, $label);
        }
        self::assertStringContainsString('Disponible prochainement', $html, 'actions non branchées : indisponibles, jamais simulées');
        self::assertStringContainsString('href="/communes/'.$this->id($this->w->org).'/evenements/'.$this->fire.'/modifier"', $html, 'Modifier est branchée : lien réel');
        self::assertStringNotContainsString('Suspendre l’événement', $html, 'sans objet pour un brouillon');
        self::assertStringNotContainsString('Réactiver l’événement', $html);
        self::assertStringNotContainsString('<form method="post" action="/communes', $html, 'aucune action simulée : aucun formulaire d\'écriture');
        self::assertDoesNotMatchRegularExpression('#<a[^>]+class="cp-btn[^"]*"[^>]+href="[^"]*(activer|suspendre|cloturer|inviter)#i', $html);
    }

    public function testTheChecklistTurnsGreenOnceAZoneIsReadyAndActionsFollowTheState(): void
    {
        $admin = $this->user($this->w->adminO);
        $zone = $this->proposedZone($this->fire);
        $zones = static::getContainer()->get(EventZoneService::class);
        $zones->confirm($admin, $zone);
        $zones->configureValidity($admin, $zone, new ZoneValidityCommand('EVENT_DURATION'));

        $this->loginAs($this->w->adminO);
        self::assertStringContainsString('l’événement peut être activé', $this->get($this->eventUrl($this->w->org, $this->fire)));

        $this->lifecycle()->activate($this->user($this->w->adminO), $this->fire);
        $this->loginAs($this->w->adminO);
        $active = $this->get($this->eventUrl($this->w->org, $this->fire));
        self::assertStringContainsString('Suspendre l’événement', $active);
        self::assertStringNotContainsString('Activer l’événement', $active);
        self::assertStringNotContainsString('Aucune zone n’est prête', $active, 'la checklist ne concerne que le brouillon');

        $this->lifecycle()->suspend($this->user($this->w->adminO), $this->fire, 'Alerte');
        $this->loginAs($this->w->adminO);
        $suspended = $this->get($this->eventUrl($this->w->org, $this->fire));
        self::assertStringContainsString('Événement suspendu', $suspended);
        self::assertStringContainsString('Réactiver l’événement', $suspended);
        self::assertStringNotContainsString('Alerte', $suspended, 'le motif interne de suspension n\'est pas exposé');
    }

    public function testAClosedEventIsReadOnlyWithoutAnyAction(): void
    {
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->eventUrl($this->w->org, $this->flood));

        self::assertStringContainsString('Événement clôturé', $html);
        self::assertStringContainsString('Consultation seule', $html);
        foreach (['Modifier', 'Inviter une commune', 'Clôturer l’événement', 'Disponible prochainement'] as $absent) {
            self::assertStringNotContainsString($absent, $html, $absent);
        }
    }

    // ── Lecture seule et rôles ───────────────────────────────────────────────

    public function testAnInstructorIsReadOnlyAndSeesTheCoordinationLevelOnly(): void
    {
        $this->loginAs($this->w->instructorO);

        $html = $this->get($this->eventUrl($this->w->org, $this->fire));

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Consultation seule', $html);
        self::assertStringContainsString('ne permet pas de modifier cet événement', $html);
        foreach (['Modifier', 'Inviter une commune', 'Activer l’événement', 'Clôturer l’événement', 'Disponible prochainement'] as $absent) {
            self::assertStringNotContainsString($absent, $html, 'action absente pour un rôle sans droit : '.$absent);
        }
        self::assertStringContainsString($this->w->guestB->name(), $html, 'coordination : communes participantes');
        self::assertStringNotContainsString('Suspension et clôture', $html, 'aucune section vide : sans action visible, pas de titre');
        self::assertStringNotContainsString('Activation', $html);
    }

    public function testTheLifecycleDoesNotPresentUnbuiltFeaturesAsOperational(): void
    {
        $this->loginAs($this->w->adminO);

        $html = strtolower($this->get($this->eventUrl($this->w->org, $this->fire)));

        self::assertStringContainsString('cycle de vie', $html);
        foreach (['formulaire public', 'demandes instruites', 'laissez-passer', 'aucune demande', 'droits inutilisables'] as $misleading) {
            self::assertStringNotContainsString($misleading, $html, $misleading);
        }
    }

    public function testInternalInvitationDataIsHiddenFromTheCoordinationLevel(): void
    {
        $participations = static::getContainer()->get(EventParticipationService::class);
        $participations->invite($this->user($this->w->adminO), new InviteMunicipalityCommand($this->fire, $this->w->other->id()->toRfc4122(), 'Message interne de l’organisatrice'));
        $pending = $this->w->other->name();

        $this->loginAs($this->w->adminO);
        $full = $this->get($this->eventUrl($this->w->org, $this->fire));
        self::assertStringContainsString($pending, $full, 'U5 : l\'administrateur voit les invitations en attente');

        foreach ([$this->w->instructorO, $this->w->adminB] as $viewer) {
            $this->loginAs($viewer);
            $html = $this->get($this->eventUrl($viewer === $this->w->adminB ? $this->w->guestB : $this->w->org, $this->fire));
            self::assertSame(200, $this->httpStatus());
            self::assertStringNotContainsString($pending, $html, 'coordination : jamais les invitations en attente d\'autres communes');
            self::assertStringNotContainsString('Message interne', $html);
        }
    }

    // ── Participante et invitée ──────────────────────────────────────────────

    public function testAParticipatingMunicipalitySeesItsRelationAndItsOwnParticipationActions(): void
    {
        $this->lifecycle()->update($this->user($this->w->adminO), $this->fire, new UpdateEventCommand(description: 'Feu de forêt au nord du massif'));
        $this->loginAs($this->w->adminB);

        $html = $this->get($this->eventUrl($this->w->guestB, $this->fire));

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Participante', $html);
        self::assertStringContainsString('Feu de forêt au nord du massif', $html);
        self::assertStringContainsString('Votre participation', $html);
        self::assertStringContainsString('Suspendre ma participation', $html);
        self::assertStringContainsString('Clôturer ma participation', $html);
        self::assertStringNotContainsString('Modifier', $html, 'seule l\'organisatrice modifie l\'événement');
        self::assertStringNotContainsString('Activer l’événement', $html);
    }

    public function testASimplyInvitedMunicipalitySeesOnlyItsInvitation(): void
    {
        $this->lifecycle()->update($this->user($this->w->adminO), $this->accident, new UpdateEventCommand(description: 'Description réservée aux participantes'));
        $this->loginAs($this->w->adminX);

        $html = $this->get($this->eventUrl($this->w->other, $this->accident));

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Invitée', $html);
        self::assertStringContainsString('Invitation de '.$this->w->org->name(), $html);
        self::assertStringContainsString('Accepter l’invitation', $html);
        self::assertStringContainsString('Disponible prochainement', $html);
        self::assertStringNotContainsString('Description réservée', $html, 'informations minimales (DM-084)');
        self::assertStringNotContainsString('Cycle de vie', $html);
        self::assertStringNotContainsString('id="communes"', $html, 'jamais la liste des autres communes');
    }

    public function testOnlyAnAdministratorMayAcceptAndTheScreenSaysSo(): void
    {
        $manager = UserBuilder::aUser()->build();
        $this->em()->persist($manager);
        $this->em()->persist(MembershipBuilder::aMembership($manager, $this->em()->find(Municipality::class, $this->w->other->id()) ?? self::fail())->withRoles(MunicipalityRole::EventManager)->build());
        $this->em()->flush();
        $this->loginAs($manager);

        $html = $this->get($this->eventUrl($this->w->other, $this->accident));

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Seul un administrateur communal peut accepter', $html);
        self::assertStringNotContainsString('Disponible prochainement', $html, 'le gestionnaire ne peut pas accepter : action absente');
    }

    public function testTheHistoryOfAClosedEventKeepsTheMunicipalitiesThatTookPart(): void
    {
        $this->lifecycle()->close($this->user($this->w->adminO), $this->fire);

        $this->loginAs($this->w->adminB);
        $html = $this->get($this->eventUrl($this->w->guestB, $this->fire));

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Événement clôturé', $html);
        self::assertStringContainsString('A participé', $html, 'U6 : historique conservé');
        self::assertStringContainsString($this->w->org->name(), $html);
    }

    // ── Isolation et identifiants forgés ─────────────────────────────────────

    public function testAnEventOfAnotherMunicipalityIsInaccessibleWhateverTheIdentifiersInTheUrl(): void
    {
        $bodies = [];
        // adminB ne participe pas à « Crue » ; adminX n'a aucun lien avec « Incendie ».
        $cases = [
            [$this->w->adminB, $this->w->guestB, $this->flood],
            [$this->w->adminX, $this->w->other, $this->fire],
            [$this->w->adminX, $this->w->org, $this->fire],
            [$this->w->adminX, $this->w->other, self::randomUuid()],
            [$this->w->adminO, $this->w->org, self::randomUuid()],
            [$this->w->adminO, $this->w->guestB, $this->fire],
        ];
        foreach ($cases as [$user, $municipality, $eventId]) {
            $this->loginAs($user);
            $bodies[] = $this->get($this->eventUrl($municipality, $eventId));
            self::assertSame(404, $this->httpStatus(), $municipality->name().' / '.$eventId);
        }
        self::assertSame(1, \count(array_unique($bodies)), 'même réponse pour tous les refus');

        $this->loginAs($this->w->adminO);
        $this->client->request('GET', $this->listUrl($this->w->org).'/not-a-uuid');
        self::assertSame(404, $this->httpStatus());
    }

    public function testTheSuperAdministratorGetsNoImplicitAccessToAnEvent(): void
    {
        $this->loginAs($this->w->superAdmin);

        $this->get($this->eventUrl($this->w->org, $this->fire));

        self::assertSame(404, $this->httpStatus());
    }

    public function testASuspendedMunicipalityStaysReadOnlyWithoutActions(): void
    {
        $this->em()->getConnection()->executeStatement("UPDATE municipality SET status = 'SUSPENDED' WHERE id = ?", [$this->w->org->id()->toBinary()]);
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->eventUrl($this->w->org, $this->fire));

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('commune suspendue', $html);
        self::assertStringContainsString('Votre commune est suspendue', $html);
        self::assertStringNotContainsString('Disponible prochainement', $html);
    }

    public function testThePageLinksBackToTheListAndShowsTheEventContext(): void
    {
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->eventUrl($this->w->org, $this->fire));

        self::assertStringContainsString('href="'.$this->listUrl($this->w->org).'"', $html);
        self::assertStringContainsString('Rubriques de l’événement', $html);
        self::assertStringContainsString('Événement', $html);
    }
}
