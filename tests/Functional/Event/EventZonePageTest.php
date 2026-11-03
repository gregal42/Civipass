<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Event\Application\Command\CreateZoneCommand;
use App\Event\Application\Service\EventZoneService;
use App\Municipality\Domain\Municipality;
use App\Tests\Support\MunicipalPagesTestCase;

/**
 * Gestion des zones par l'interface (MySQL de test) : consultation selon la visibilité, création, modification, confirmation,
 * suspension, réactivation, suppression, isolation entre communes et identifiants forgés. Les écritures passent par
 * EventZoneService ; ces tests vérifient le câblage HTTP, les permissions et les statuts de réponse.
 */
final class EventZonePageTest extends MunicipalPagesTestCase
{
    private function zonesUrl(Municipality|string $municipality, string $eventId): string
    {
        return $this->eventUrl($municipality, $eventId).'/zones';
    }

    private function zoneUrl(Municipality|string $municipality, string $eventId, string $zoneId, string $suffix = ''): string
    {
        return $this->zonesUrl($municipality, $eventId).'/'.$zoneId.$suffix;
    }

    private function zoneId(Municipality $municipality, string $eventId, ?string $label = null): string
    {
        $sql = 'SELECT BIN_TO_UUID(z.id) FROM event_zone z JOIN event_participation p ON p.id = z.participation_id WHERE p.event_id = ? AND p.municipality_id = ? AND z.deleted_at IS NULL'.(null === $label ? '' : ' AND z.label = ?').' ORDER BY z.created_at LIMIT 1';
        $params = [(string) hex2bin(str_replace('-', '', $eventId)), $municipality->id()->toBinary()];
        if (null !== $label) {
            $params[] = $label;
        }
        $id = $this->em()->getConnection()->fetchOne($sql, $params);

        return \is_string($id) ? $id : self::fail('zone absente');
    }

    private function participationId(Municipality $municipality, string $eventId): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT BIN_TO_UUID(id) FROM event_participation WHERE event_id = ? AND municipality_id = ?', [(string) hex2bin(str_replace('-', '', $eventId)), $municipality->id()->toBinary()]);

        return \is_string($id) ? $id : self::fail('participation absente');
    }

    private function createZone(Municipality $municipality, string $eventId, string $label, string $type = 'DISTRICT'): string
    {
        $admin = $municipality === $this->w->org ? $this->w->adminO : $this->w->adminB;
        static::getContainer()->get(EventZoneService::class)->create($this->user($admin), $this->participationId($municipality, $eventId), new CreateZoneCommand($label, $type));
        $this->em()->clear();

        return $this->zoneId($municipality, $eventId, $label);
    }

    private function assertStatus(int $expected, string $message = ''): void
    {
        self::assertSame($expected, $this->httpStatus(), $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function zoneStatus(string $zoneId): array
    {
        $row = $this->em()->getConnection()->fetchAssociative('SELECT status, confirmed_at, deleted_at, label, description FROM event_zone WHERE id = UUID_TO_BIN(?)', [$zoneId]);

        return \is_array($row) ? $row : self::fail('zone absente');
    }

    private function audits(string $action): int
    {
        return $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }

    private function actionToken(string $page, string $action): string
    {
        $html = $this->get($page);
        self::assertSame(1, preg_match('#action="[^"]*/'.$action.'"[^>]*>.*?name="_token" value="([^"]+)"#s', $html, $m), 'formulaire '.$action.' absent');

        return $m[1];
    }

    // ═══ Consultation ═══════════════════════════════════════════════════════

    public function testTheOrganizerSeesItsProposedWholeMunicipalityZoneAsNotConfirmed(): void
    {
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->zonesUrl($this->w->org, $this->fire));

        $this->assertStatus(200);
        self::assertStringContainsString('Non confirmée', $html);
        self::assertStringNotContainsString('À confirmer', $html);
        self::assertStringContainsString('Confirmer cette zone', $html);
        self::assertStringContainsString('Ajouter une zone', $html);
        self::assertStringContainsString('Zones', $html);
    }

    public function testAParticipantSeesItsOwnZonesAndTheOtherCommunesZonesInReadOnly(): void
    {
        $organizerZone = $this->createZone($this->w->org, $this->fire, 'Quartier du port');
        $this->loginAs($this->w->adminB);

        $html = $this->get($this->zonesUrl($this->w->guestB, $this->fire));

        $this->assertStatus(200);
        self::assertStringContainsString('Quartier du port', $html);
        self::assertStringContainsString('Zone gérée par '.$this->w->org->name(), $html);
        self::assertStringContainsString('(votre commune)', $html);

        $this->get($this->zoneUrl($this->w->guestB, $this->fire, $organizerZone));
        $this->assertStatus(200);
        $detail = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Supprimer la zone', $detail);
        self::assertStringNotContainsString('Confirmer la zone', $detail);
        self::assertStringNotContainsString('>Modifier<', $detail);
    }

    public function testAnInvitedCommuneSeesNoZone(): void
    {
        $this->loginAs($this->w->adminX);

        $html = $this->get($this->zonesUrl($this->w->other, $this->accident));

        $this->assertStatus(200);
        self::assertStringContainsString('Zones non disponibles', $html);
        self::assertStringNotContainsString('Ajouter une zone', $html);
    }

    public function testAnUnrelatedCommuneGetsTheUniform404AndASuperAdminWithoutMembershipToo(): void
    {
        $this->loginAs($this->w->adminX);
        $this->get($this->zonesUrl($this->w->other, $this->fire));
        $this->assertStatus(404);

        $this->get($this->zonesUrl($this->w->org, $this->fire));
        $this->assertStatus(404, 'URL d’une autre commune : uniforme');

        $this->loginAs($this->w->superAdmin);
        $this->get($this->zonesUrl($this->w->org, $this->fire));
        $this->assertStatus(404, 'aucun droit communal implicite');
    }

    public function testManagersAndInstructorsConsultWithoutWriteActions(): void
    {
        foreach ([$this->w->managerO, $this->w->instructorO] as $user) {
            $this->loginAs($user);
            $html = $this->get($this->zonesUrl($this->w->org, $this->fire));
            $this->assertStatus(200);
            self::assertStringNotContainsString('Ajouter une zone', $html);
            self::assertStringNotContainsString('Confirmer cette zone', $html);

            $this->get($this->zonesUrl($this->w->org, $this->fire).'/nouvelle');
            $this->assertStatus(403);
        }
    }

    // ═══ Création ═══════════════════════════════════════════════════════════

    public function testCreatingAZoneConfirmsItImmediatelyAndAudits(): void
    {
        $this->loginAs($this->w->adminO);
        $url = $this->zonesUrl($this->w->org, $this->fire).'/nouvelle';
        $before = $this->audits('ZONE_CREATED');

        $this->submit($url, ['label' => 'Quartier du port', 'type' => 'DISTRICT', 'description' => 'Autour du port']);

        $this->assertStatus(303);
        $zoneId = $this->zoneId($this->w->org, $this->fire, 'Quartier du port');
        self::assertStringContainsString($zoneId, $this->location());
        $row = $this->zoneStatus($zoneId);
        self::assertSame('ACTIVE', $row['status']);
        self::assertNotNull($row['confirmed_at'], 'zone manuelle : confirmée dès la création (DM-051)');
        self::assertSame('Autour du port', $row['description']);
        self::assertSame($before + 1, $this->audits('ZONE_CREATED'));
        self::assertStringContainsString('La zone a été créée.', $this->get($this->location()));
    }

    public function testTheFormHasNoStatusFieldAndInvalidInputsAreKeptWith422(): void
    {
        $this->loginAs($this->w->adminO);
        $url = $this->zonesUrl($this->w->org, $this->fire).'/nouvelle';
        $form = $this->get($url);
        self::assertStringNotContainsString('name="status"', $form);
        self::assertStringNotContainsString('name="state"', $form);

        $html = $this->submit($url, ['label' => '', 'type' => '', 'description' => 'Garde-moi'], null, $url);

        $this->assertStatus(422);
        self::assertStringContainsString('Garde-moi', $html);
        self::assertStringContainsString('cp-field-error', $html);
    }

    public function testASecondWholeMunicipalityZoneIsRefusedWith422AndNothingIsCreated(): void
    {
        $this->loginAs($this->w->adminO);
        $url = $this->zonesUrl($this->w->org, $this->fire).'/nouvelle';
        $count = $this->scalar('SELECT COUNT(*) FROM event_zone');

        $this->submit($url, ['label' => 'Commune entière bis', 'type' => 'WHOLE_MUNICIPALITY', 'whole_confirmed' => '1']);

        $this->assertStatus(422);
        self::assertSame($count, $this->scalar('SELECT COUNT(*) FROM event_zone'));
    }

    public function testAForgedCsrfTokenIsRefusedAndNothingIsCreated(): void
    {
        $this->loginAs($this->w->adminO);
        $url = $this->zonesUrl($this->w->org, $this->fire).'/nouvelle';
        $count = $this->scalar('SELECT COUNT(*) FROM event_zone');

        $html = $this->submit($url, ['label' => 'Zone', 'type' => 'STREET'], 'forge');

        $this->assertStatus(422);
        self::assertStringContainsString('Le formulaire a expiré', $html);
        self::assertSame($count, $this->scalar('SELECT COUNT(*) FROM event_zone'));
    }

    public function testAParticipantCreatesZonesOnItsOwnParticipationOnlyEvenWithForgedIdentifiers(): void
    {
        $this->loginAs($this->w->adminB);
        $url = $this->zonesUrl($this->w->guestB, $this->fire).'/nouvelle';

        $this->submit($url, ['label' => 'Rue de la Gare', 'type' => 'STREET', 'participation_id' => $this->participationId($this->w->org, $this->fire), 'municipality_id' => $this->id($this->w->org)]);

        $this->assertStatus(303);
        $this->zoneId($this->w->guestB, $this->fire, 'Rue de la Gare');
        $this->em()->clear();
        $count = $this->scalar('SELECT COUNT(*) FROM event_zone z JOIN event_participation p ON p.id = z.participation_id WHERE z.label = ? AND p.municipality_id = ?', ['Rue de la Gare', $this->w->org->id()->toBinary()]);
        self::assertSame(0, $count);
    }

    public function testCreationIsForbiddenOnAClosedEventAndForOtherCommunes(): void
    {
        $this->loginAs($this->w->adminO);
        $this->get($this->zonesUrl($this->w->org, $this->flood).'/nouvelle');
        $this->assertStatus(403);

        $this->loginAs($this->w->adminX);
        $this->get($this->zonesUrl($this->w->other, $this->fire).'/nouvelle');
        $this->assertStatus(404);
        $this->get($this->zonesUrl($this->w->other, $this->accident).'/nouvelle');
        $this->assertStatus(403, 'commune simplement invitée');
    }

    // ═══ Modification ═══════════════════════════════════════════════════════

    public function testUpdatingAZoneKeepsItsTypeAndIsAudited(): void
    {
        $zoneId = $this->createZone($this->w->org, $this->fire, 'Quartier du port');
        $this->loginAs($this->w->adminO);
        $url = $this->zoneUrl($this->w->org, $this->fire, $zoneId, '/modifier');
        $form = $this->get($url);
        self::assertStringContainsString('Quartier du port', $form);
        self::assertStringNotContainsString('name="type"', $form, 'type non modifiable (DM-080)');
        $before = $this->audits('ZONE_UPDATED');

        $this->submit($url, ['label' => 'Port et quais', 'type' => 'STREET', 'description' => ''], null, $url);

        $this->assertStatus(303);
        self::assertSame('Port et quais', $this->zoneStatus($zoneId)['label']);
        self::assertSame($before + 1, $this->audits('ZONE_UPDATED'));
        $type = $this->em()->getConnection()->fetchOne('SELECT type FROM event_zone WHERE id = UUID_TO_BIN(?)', [$zoneId]);
        self::assertSame('DISTRICT', $type);
    }

    public function testUpdatingWithoutChangeSaysNothingChanged(): void
    {
        $zoneId = $this->createZone($this->w->org, $this->fire, 'Quartier du port');
        $this->loginAs($this->w->adminO);
        $url = $this->zoneUrl($this->w->org, $this->fire, $zoneId, '/modifier');
        $before = $this->audits('ZONE_UPDATED');

        $this->submit($url, ['label' => 'Quartier du port', 'description' => ''], null, $url);

        $this->assertStatus(303);
        self::assertSame($before, $this->audits('ZONE_UPDATED'));
        self::assertStringContainsString('Aucune modification', $this->get($this->location()));
    }

    public function testAnotherCommuneCannotReachNorEditAZoneItDoesNotOwn(): void
    {
        $zoneId = $this->createZone($this->w->org, $this->fire, 'Quartier du port');
        $this->loginAs($this->w->adminB);

        $this->get($this->zoneUrl($this->w->guestB, $this->fire, $zoneId, '/modifier'));
        $this->assertStatus(403, 'zone visible mais d’une autre commune');

        $this->client->request('POST', $this->zoneUrl($this->w->guestB, $this->fire, $zoneId, '/modifier'), ['label' => 'Piraté', 'description' => '', '_token' => 'x']);
        $this->assertStatus(403);
        self::assertSame('Quartier du port', $this->zoneStatus($zoneId)['label']);

        $this->loginAs($this->w->adminX);
        $this->get($this->zoneUrl($this->w->other, $this->fire, $zoneId));
        $this->assertStatus(404);
        $this->get($this->zoneUrl($this->w->guestB, $this->fire, $zoneId));
        $this->assertStatus(404);
    }

    public function testAManagerCannotUpdateAZone(): void
    {
        $zoneId = $this->createZone($this->w->org, $this->fire, 'Quartier du port');
        $this->loginAs($this->w->managerO);

        $this->get($this->zoneUrl($this->w->org, $this->fire, $zoneId, '/modifier'));

        $this->assertStatus(403);
    }

    public function testAZoneIdFromAnotherEventIsRefused(): void
    {
        $other = $this->createZone($this->w->org, $this->accident, 'Zone accident');
        $this->loginAs($this->w->adminO);

        $this->get($this->zoneUrl($this->w->org, $this->fire, $other));
        $this->assertStatus(404);
        $this->get($this->zoneUrl($this->w->org, $this->fire, self::randomUuid(), '/modifier'));
        $this->assertStatus(404);
        $this->get($this->zoneUrl($this->w->org, $this->fire, 'pas-un-uuid'));
        $this->assertStatus(404);
    }

    // ═══ Actions : confirmer, suspendre, réactiver, supprimer ═══════════════

    public function testConfirmingTheProposedWholeMunicipalityZone(): void
    {
        $zoneId = $this->zoneId($this->w->org, $this->fire);
        $this->loginAs($this->w->adminO);
        $page = $this->zoneUrl($this->w->org, $this->fire, $zoneId);
        $token = $this->actionToken($page, 'confirmer');
        $before = $this->audits('ZONE_CONFIRMED');

        $this->client->request('POST', $page.'/confirmer', ['_token' => $token]);

        $this->assertStatus(303);
        self::assertNotNull($this->zoneStatus($zoneId)['confirmed_at']);
        self::assertSame($before + 1, $this->audits('ZONE_CONFIRMED'));
        $html = $this->get($this->location());
        self::assertStringContainsString('La zone a été confirmée.', $html);
        self::assertStringNotContainsString('Non confirmée', $html);
    }

    public function testSuspendingThenReactivatingAZone(): void
    {
        $zoneId = $this->createZone($this->w->org, $this->fire, 'Quartier du port');
        $this->loginAs($this->w->adminO);
        $page = $this->zoneUrl($this->w->org, $this->fire, $zoneId);

        $this->client->request('POST', $page.'/suspendre', ['_token' => $this->actionToken($page, 'suspendre')]);
        $this->assertStatus(303);
        self::assertSame('SUSPENDED', $this->zoneStatus($zoneId)['status']);
        self::assertStringContainsString('Suspendue', $this->get($page));

        $this->client->request('POST', $page.'/reactiver', ['_token' => $this->actionToken($page, 'reactiver')]);
        $this->assertStatus(303);
        self::assertSame('ACTIVE', $this->zoneStatus($zoneId)['status']);
    }

    public function testDeletingAZoneIsLogicalAndAudited(): void
    {
        $zoneId = $this->createZone($this->w->org, $this->fire, 'Quartier du port');
        $this->loginAs($this->w->adminO);
        $page = $this->zoneUrl($this->w->org, $this->fire, $zoneId);
        $before = $this->audits('ZONE_DELETED');

        $this->client->request('POST', $page.'/supprimer', ['_token' => $this->actionToken($page, 'supprimer')]);

        $this->assertStatus(303);
        self::assertSame($this->zonesUrl($this->w->org, $this->fire), $this->location());
        self::assertNotNull($this->zoneStatus($zoneId)['deleted_at'], 'suppression logique (DM-052)');
        self::assertSame($before + 1, $this->audits('ZONE_DELETED'));
        self::assertStringNotContainsString('Quartier du port', $this->get($this->location()));
        $this->get($page);
        $this->assertStatus(404);
    }

    public function testAnActionWithAForgedTokenChangesNothing(): void
    {
        $zoneId = $this->createZone($this->w->org, $this->fire, 'Quartier du port');
        $this->loginAs($this->w->adminO);
        $page = $this->zoneUrl($this->w->org, $this->fire, $zoneId);

        $this->client->request('POST', $page.'/supprimer', ['_token' => 'forge']);

        $this->assertStatus(422);
        self::assertNull($this->zoneStatus($zoneId)['deleted_at']);
    }

    public function testActionsAreRefusedToOtherCommunesAndRolesAndNeverOnGet(): void
    {
        $zoneId = $this->createZone($this->w->org, $this->fire, 'Quartier du port');
        $page = $this->zoneUrl($this->w->org, $this->fire, $zoneId);
        $this->loginAs($this->w->adminO);
        $token = $this->actionToken($page, 'supprimer');

        $this->loginAs($this->w->adminB);
        $this->client->request('POST', $this->zoneUrl($this->w->guestB, $this->fire, $zoneId, '/supprimer'), ['_token' => $token]);
        $this->assertStatus(403);

        $this->loginAs($this->w->managerO);
        $this->client->request('POST', $page.'/supprimer', ['_token' => $token]);
        $this->assertStatus(403);

        $this->loginAs($this->w->adminX);
        $this->client->request('POST', $this->zoneUrl($this->w->other, $this->fire, $zoneId, '/supprimer'), ['_token' => $token]);
        $this->assertStatus(404);

        $this->loginAs($this->w->adminO);
        $this->client->request('GET', $page.'/supprimer');
        self::assertContains($this->httpStatus(), [404, 405]);
        self::assertNull($this->zoneStatus($zoneId)['deleted_at']);
    }

    public function testActionsOnAClosedEventAreForbidden(): void
    {
        $zoneId = $this->zoneId($this->w->org, $this->flood);
        $this->loginAs($this->w->adminO);

        $this->client->request('POST', $this->zoneUrl($this->w->org, $this->flood, $zoneId, '/confirmer'), ['_token' => 'x']);

        $this->assertStatus(403);
        self::assertNull($this->zoneStatus($zoneId)['confirmed_at']);
    }

    public function testWriteRoutesRequireAFullAuthentication(): void
    {
        $zoneId = $this->zoneId($this->w->org, $this->fire);
        $this->client->request('POST', $this->zoneUrl($this->w->org, $this->fire, $zoneId, '/confirmer'), ['_token' => 'x']);
        $this->assertStatus(302);
        self::assertStringContainsString('/login', $this->location());
    }

    public function testTheZonesOfAnInvitedCommuneAreNotListedUntilItAccepts(): void
    {
        $this->loginAs($this->w->adminO);
        $name = $this->w->other->name();
        $otherZone = $this->zoneId($this->w->other, $this->accident);

        $html = $this->get($this->zonesUrl($this->w->org, $this->accident));
        self::assertStringNotContainsString($name, $html);
        $this->get($this->zoneUrl($this->w->org, $this->accident, $otherZone));
        $this->assertStatus(404);
        self::assertStringContainsString($name, $this->get($this->eventUrl($this->w->org, $this->accident).'/communes'), 'toujours visible en coordination');

        $this->loginAs($this->w->adminX);
        $page = $this->eventUrl($this->w->other, $this->accident);
        $this->client->request('POST', $page.'/participation/accepter', ['_token' => $this->actionToken($page, 'accepter')]);
        $this->assertStatus(303);

        $this->loginAs($this->w->adminO);
        self::assertStringContainsString($name, $this->get($this->zonesUrl($this->w->org, $this->accident)));
    }

    public function testAReadOnlyZoneCardHasNoActionMenuOnlyTheTitleLink(): void
    {
        $this->loginAs($this->w->adminB);
        $html = $this->get($this->zonesUrl($this->w->guestB, $this->fire));

        self::assertStringContainsString('Zone gérée par '.$this->w->org->name(), $html);
        preg_match_all('/id="(zone-[^"]*-menu)" role="menu"/', $html, $menus);
        self::assertCount(1, $menus[1], 'un seul menu : la zone de la commune, qui a des actions '.json_encode($menus[1]));

        $this->loginAs($this->w->managerO);
        $html = $this->get($this->zonesUrl($this->w->org, $this->fire));
        self::assertStringNotContainsString('Actions sur la zone', $html, 'aucune action autorisée : pas de menu « ⋯ »');
        self::assertStringContainsString('zone-', $html);
    }
}
