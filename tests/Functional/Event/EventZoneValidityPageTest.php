<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Event\Application\Command\ValiditySlotInput;
use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Application\Service\EventZoneService;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalPagesTestCase;
use App\Tests\Support\UserBuilder;

/**
 * Validité des zones par l'interface (MySQL de test) : consultation des créneaux dans le fuseau de l'événement, choix du mode,
 * ajout, modification et suppression d'un créneau, changements d'heure (heure inexistante refusée, heure répétée à choisir),
 * plafond, permissions et isolation. Les écritures passent par EventZoneService::configureValidity ; ces tests vérifient le
 * câblage HTTP, les messages et les cas limites. L'événement du jeu de test est en Europe/Paris.
 */
final class EventZoneValidityPageTest extends MunicipalPagesTestCase
{
    private function zoneId(Municipality $municipality, string $eventId): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT BIN_TO_UUID(z.id) FROM event_zone z JOIN event_participation p ON p.id = z.participation_id WHERE p.event_id = ? AND p.municipality_id = ? AND z.deleted_at IS NULL ORDER BY z.created_at LIMIT 1', [(string) hex2bin(str_replace('-', '', $eventId)), $municipality->id()->toBinary()]);

        return \is_string($id) ? $id : self::fail('zone absente');
    }

    private function url(Municipality|string $municipality, string $eventId, string $zoneId, string $suffix = ''): string
    {
        return $this->eventUrl($municipality, $eventId).'/zones/'.$zoneId.'/validite'.$suffix;
    }

    /** @return list<string> */
    private function activeSlotIds(string $zoneId): array
    {
        return array_map('strval', $this->em()->getConnection()->fetchFirstColumn('SELECT BIN_TO_UUID(id) FROM event_zone_validity WHERE zone_id = UUID_TO_BIN(?) AND deleted_at IS NULL ORDER BY id', [$zoneId]));
    }

    private function mode(string $zoneId): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT validity_mode FROM event_zone WHERE id = UUID_TO_BIN(?)', [$zoneId]);
    }

    private function assertStatus(int $expected, string $message = ''): void
    {
        self::assertSame($expected, $this->httpStatus(), $message);
    }

    private function audits(string $action): int
    {
        return $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }

    /**
     * Jeton et empreinte du formulaire dont l'action se termine par $suffix (la page en porte plusieurs, comme un navigateur).
     *
     * @return array{_token: string, revision: string}
     */
    private function form(string $page, string $suffix): array
    {
        $html = $this->get($page);
        self::assertSame(1, preg_match('#action="[^"]*'.preg_quote($suffix, '#').'"[^>]*>(.*?)</form>#s', $html, $m), 'formulaire '.$suffix.' absent');
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $m[1], $t));
        self::assertSame(1, preg_match('/name="revision" value="([^"]+)"/', $m[1], $r));

        return ['_token' => $t[1], 'revision' => $r[1]];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(string $page, string $suffix, array $fields): string
    {
        $this->client->request('POST', $page.$suffix, $fields + $this->form($page, $suffix));

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * Envoie le formulaire d'une page dédiée (modification, suppression d'un créneau) : jeton et empreinte lus sur la page elle-même.
     *
     * @param array<string, mixed> $fields
     */
    private function postSelf(string $url, array $fields): string
    {
        $html = $this->get($url);
        self::assertSame(200, $this->httpStatus(), 'page '.$url);
        self::assertSame(1, preg_match('#action="'.preg_quote($url, '#').'"[^>]*>(.*?)</form>#s', $html, $m), 'formulaire de la page absent');
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $m[1], $t));
        self::assertSame(1, preg_match('/name="revision" value="([^"]+)"/', $m[1], $r));
        $this->client->request('POST', $url, $fields + ['_token' => $t[1], 'revision' => $r[1]]);

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function continuous(string $formId, string $startDate, string $startTime, string $endDate, string $endTime, array $extra = []): array
    {
        return [$formId => ['mode' => 'continuous'] + $extra, $formId.'_c' => ['start_date' => $startDate, 'start_time' => $startTime, 'end_date' => $endDate, 'end_time' => $endTime]];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function single(string $formId, string $date, string $from, string $to, array $extra = []): array
    {
        return [$formId => ['mode' => 'single', 'date' => $date, 'from_time' => $from, 'to_time' => $to] + $extra];
    }

    /**
     * @param list<string>         $days
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function recurring(string $formId, array $days, string $from, string $to, string $start, string $end, array $extra = []): array
    {
        return [$formId => ['mode' => 'recurring', 'every' => [] === $days ? 'day' : 'days', 'days' => $days, 'rec_from_time' => $from, 'rec_to_time' => $to, 'rec_from' => $start, 'rec_to' => $end] + $extra];
    }

    /**
     * Configure d'emblée la zone de l'organisatrice en périodes spécifiques avec un créneau continu (par le service).
     *
     * @param list<ValiditySlotInput>|null $slots
     */
    private function configured(?array $slots = null): string
    {
        $zoneId = $this->zoneId($this->w->org, $this->fire);
        static::getContainer()->get(EventZoneService::class)->configureValidity($this->user($this->w->adminO), $zoneId, new ZoneValidityCommand('SPECIFIC_PERIODS', $slots ?? [new ValiditySlotInput('CONTINUOUS', '2026-12-01', '08:00', '18:00', '2026-12-02')]));
        $this->em()->clear();

        return $zoneId;
    }

    // ═══ Consultation et états ══════════════════════════════════════════════

    public function testAZoneWithoutValiditySaysItMustBeConfiguredAndOffersTheChoice(): void
    {
        $this->loginAs($this->w->adminO);
        $zoneId = $this->zoneId($this->w->org, $this->fire);

        $html = $this->get($this->url($this->w->org, $this->fire, $zoneId));

        $this->assertStatus(200);
        self::assertStringContainsString('Validité à configurer', $html);
        self::assertStringContainsString('n’ouvre aucun droit d’accès', $html);
        self::assertStringContainsString('Pendant toute la durée de l’événement', $html);
        self::assertStringContainsString('Périodes spécifiques', $html);
        self::assertStringContainsString('heure locale (Europe/Paris)', $html);
        self::assertStringNotContainsString('heure de Paris', $html);
        self::assertSame('UNCONFIGURED', $this->mode($zoneId));
    }

    public function testTheSlotsAreListedInTheEventTimezone(): void
    {
        $zoneId = $this->configured([
            new ValiditySlotInput('CONTINUOUS', '2026-12-01', '08:00', '18:00', '2026-12-02'),
            new ValiditySlotInput('PUNCTUAL', '2026-12-05', '09:30', '11:00'),
            new ValiditySlotInput('RECURRING', '2026-12-10', '22:00', '06:00', '2026-12-31', [1, 3, 5]),
        ]);
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->url($this->w->org, $this->fire, $zoneId));

        self::assertStringContainsString('3 créneaux', $html);
        self::assertStringContainsString('Du 01/12/2026 à 08:00 au 02/12/2026 à 18:00, sans interruption.', $html);
        self::assertStringContainsString('Le 05/12/2026, de 09:30 à 11:00.', $html);
        self::assertStringContainsString('Les lundis, mercredis et vendredis, de 22:00 à 06:00 (le lendemain), du 10/12/2026 au 31/12/2026.', $html);
        self::assertStringContainsString('passe minuit', $html);
        self::assertStringContainsString('Périodes spécifiques', $html);
    }

    public function testTheZonesListShowsTheSlotSummaries(): void
    {
        $this->configured();
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->eventUrl($this->w->org, $this->fire).'/zones');

        self::assertStringContainsString('Du 01/12/2026 à 08:00 au 02/12/2026 à 18:00', $html);
    }

    public function testAManagerAndAParticipantConsultWithoutAnyForm(): void
    {
        $zoneId = $this->configured();

        $this->loginAs($this->w->managerO);
        $html = $this->get($this->url($this->w->org, $this->fire, $zoneId));
        $this->assertStatus(200);
        self::assertStringContainsString('Du 01/12/2026', $html);
        self::assertStringNotContainsString('Ajouter un créneau', $html);
        self::assertStringNotContainsString('<form id="new-form"', $html);

        $this->loginAs($this->w->adminB);
        $html = $this->get($this->url($this->w->guestB, $this->fire, $zoneId));
        $this->assertStatus(200, 'zone d’une autre commune visible : lecture seule');
        self::assertStringContainsString('Du 01/12/2026', $html);
        self::assertStringNotContainsString('Ajouter un créneau', $html);
        self::assertStringNotContainsString('Modifier le créneau', $html);
    }

    public function testAnInvitedCommuneAndAnUnrelatedCommuneGetTheUniform404(): void
    {
        $zoneId = $this->zoneId($this->w->org, $this->accident);

        $this->loginAs($this->w->adminX);
        $this->get($this->url($this->w->other, $this->accident, $zoneId));
        $this->assertStatus(404, 'commune simplement invitée : aucune zone visible');

        $this->get($this->url($this->w->org, $this->accident, $zoneId));
        $this->assertStatus(404);

        $this->loginAs($this->w->superAdmin);
        $this->get($this->url($this->w->org, $this->accident, $zoneId));
        $this->assertStatus(404);
    }

    // ═══ Choix du mode ═══════════════════════════════════════════════════════

    public function testChoosingTheWholeEventDurationConfiguresTheZone(): void
    {
        $this->loginAs($this->w->adminO);
        $zoneId = $this->zoneId($this->w->org, $this->fire);
        $page = $this->url($this->w->org, $this->fire, $zoneId);
        $before = $this->audits('ZONE_VALIDITY_MODE_CHANGED');

        $this->post($page, '/configuration', ['cfg' => ['scope' => 'event']]);

        $this->assertStatus(303);
        self::assertSame('EVENT_DURATION', $this->mode($zoneId));
        self::assertSame($before + 1, $this->audits('ZONE_VALIDITY_MODE_CHANGED'));
        $html = $this->get($page);
        self::assertStringContainsString('La zone est valable pendant toute la durée de l’événement', $html);
        self::assertStringContainsString('Aucun créneau n’est nécessaire', $html);
    }

    public function testChoosingSpecificPeriodsNeedsAFirstSlotAndStoresIt(): void
    {
        $this->loginAs($this->w->adminO);
        $zoneId = $this->zoneId($this->w->org, $this->fire);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $this->post($page, '/configuration', ['cfg' => ['scope' => 'periods', 'mode' => 'continuous']]);
        $this->assertStatus(422, 'créneau incomplet');
        self::assertSame('UNCONFIGURED', $this->mode($zoneId));

        $this->post($page, '/configuration', $this->continuous('cfg', '2026-12-01', '08:00', '2026-12-02', '18:00', ['scope' => 'periods']));

        $this->assertStatus(303);
        self::assertSame('SPECIFIC_PERIODS', $this->mode($zoneId));
        self::assertCount(1, $this->activeSlotIds($zoneId));
        self::assertStringContainsString('Du 01/12/2026 à 08:00 au 02/12/2026 à 18:00', $this->get($page));
    }

    public function testSwitchingBackToTheWholeDurationDeletesTheSlotsLogically(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);
        $before = $this->audits('ZONE_VALIDITY_SLOT_DELETED');

        $this->post($page, '/configuration', ['cfg' => ['scope' => 'event']]);

        $this->assertStatus(303);
        self::assertSame('EVENT_DURATION', $this->mode($zoneId));
        self::assertSame([], $this->activeSlotIds($zoneId));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM event_zone_validity WHERE zone_id = UUID_TO_BIN(?) AND deleted_at IS NOT NULL', [$zoneId]), 'historique conservé (DM-075)');
        self::assertSame($before + 1, $this->audits('ZONE_VALIDITY_SLOT_DELETED'));
    }

    // ═══ Ajout, modification, suppression ═══════════════════════════════════

    public function testAddingAPunctualAndARecurringSlot(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $this->post($page, '/creneaux', $this->single('new', '2026-12-05', '09:00', '11:00'));
        $this->assertStatus(303);
        $this->post($page, '/creneaux', $this->recurring('new', ['1', '2'], '07:00', '19:00', '2026-12-07', '2026-12-18'));
        $this->assertStatus(303);
        self::assertStringContainsString('Le créneau a été ajouté.', $this->get($this->location()), 'confirmation après redirection');

        self::assertCount(3, $this->activeSlotIds($zoneId));
        $html = $this->get($page);
        self::assertStringContainsString('Le 05/12/2026, de 09:00 à 11:00.', $html);
        self::assertStringContainsString('Les lundis et mardis, de 07:00 à 19:00, du 07/12/2026 au 18/12/2026.', $html);
    }

    public function testEveryDayRecurrenceStoresAllTheDays(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $this->post($page, '/creneaux', $this->recurring('new', [], '07:00', '19:00', '2026-12-07', '2026-12-18'));

        $this->assertStatus(303);
        self::assertStringContainsString('Tous les jours, de 07:00 à 19:00', $this->get($page));
    }

    public function testInvalidSlotsAreRefusedWith422AndTheInputsAreKept(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $html = $this->post($page, '/creneaux', $this->single('new', '2026-12-05', '11:00', '09:00'));
        $this->assertStatus(422);
        self::assertStringContainsString('heure de début doit précéder', html_entity_decode($html, \ENT_QUOTES));
        self::assertStringContainsString('value="2026-12-05"', $html, 'saisies conservées');

        $this->post($page, '/creneaux', $this->continuous('new', '2026-12-05', '10:00', '2026-12-04', '09:00'));
        $this->assertStatus(422, 'fin avant le début');

        $this->post($page, '/creneaux', $this->recurring('new', [], '10:00', '10:00', '2026-12-05', '2026-12-09'));
        $this->assertStatus(422, 'récurrence : heures égales');

        $this->post($page, '/creneaux', $this->recurring('new', [], '10:00', '12:00', '2026-12-09', '2026-12-05'));
        $this->assertStatus(422, 'récurrence : fin avant début');

        $this->post($page, '/creneaux', $this->recurring('new', [], '10:00', '12:00', '2026-01-01', '2037-01-01'));
        $this->assertStatus(422, 'récurrence : plus de 3 660 jours');

        $this->post($page, '/creneaux', ['new' => ['mode' => 'recurring', 'every' => 'days', 'rec_from_time' => '10:00', 'rec_to_time' => '12:00', 'rec_from' => '2026-12-01', 'rec_to' => '2026-12-09']]);
        $this->assertStatus(422, 'aucun jour sélectionné');

        $this->post($page, '/creneaux', ['new' => ['mode' => 'inconnu']]);
        $this->assertStatus(422);
        self::assertCount(1, $this->activeSlotIds($zoneId), 'aucun créneau ajouté');
    }

    public function testEditingASlotReplacesItAndKeepsTheHistory(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);
        [$slotId] = $this->activeSlotIds($zoneId);
        $created = $this->audits('ZONE_VALIDITY_SLOT_CREATED');

        $this->postSelf($page.'/creneaux/'.$slotId.'/modifier', $this->continuous('edit', '2026-12-01', '07:00', '2026-12-03', '19:00'));

        $this->assertStatus(303);
        $ids = $this->activeSlotIds($zoneId);
        self::assertCount(1, $ids);
        self::assertNotSame($slotId, $ids[0]);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM event_zone_validity WHERE id = UUID_TO_BIN(?) AND deleted_at IS NOT NULL', [$slotId]), 'ancien créneau supprimé logiquement');
        self::assertSame($created + 1, $this->audits('ZONE_VALIDITY_SLOT_CREATED'));
        self::assertStringContainsString('Le créneau a été modifié.', $this->get($this->location()));
        $html = $this->get($page);
        self::assertStringContainsString('Du 01/12/2026 à 07:00 au 03/12/2026 à 19:00', $html);
    }

    public function testEditingWithoutChangeWritesNothing(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);
        [$slotId] = $this->activeSlotIds($zoneId);
        $audits = $this->scalar('SELECT COUNT(*) FROM audit_log');

        $this->postSelf($page.'/creneaux/'.$slotId.'/modifier', $this->continuous('edit', '2026-12-01', '08:00', '2026-12-02', '18:00'));

        $this->assertStatus(303);
        self::assertSame([$slotId], $this->activeSlotIds($zoneId));
        self::assertSame($audits, $this->scalar('SELECT COUNT(*) FROM audit_log'), 'configuration identique : ni écriture ni audit (DM-088)');
        self::assertStringContainsString('Aucune modification', $this->get($this->location()));
    }

    public function testDeletingASlotKeepsTheOthersAndTheSoleSlotCannotBeDeleted(): void
    {
        $zoneId = $this->configured([
            new ValiditySlotInput('CONTINUOUS', '2026-12-01', '08:00', '18:00', '2026-12-02'),
            new ValiditySlotInput('PUNCTUAL', '2026-12-05', '09:30', '11:00'),
        ]);
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);
        [$first, $second] = $this->activeSlotIds($zoneId);

        $this->postSelf($page.'/creneaux/'.$first.'/supprimer', []);
        $this->assertStatus(303);
        $remaining = $this->activeSlotIds($zoneId);
        self::assertCount(1, $remaining);
        $html = $this->get($page);
        self::assertStringNotContainsString('Du 01/12/2026', $html);
        self::assertStringContainsString('Le 05/12/2026', $html);
        self::assertStringContainsString('garde au moins un créneau', $html);
        self::assertStringNotContainsString('>Supprimer<', $html, 'pas de suppression proposée pour le dernier créneau');

        $html = $this->postSelf($page.'/creneaux/'.$remaining[0].'/supprimer', []);
        $this->assertStatus(422, 'refus du moteur : une zone en périodes spécifiques garde au moins un créneau');
        self::assertStringContainsString('exige au moins un créneau', $html);
        self::assertSame($remaining, $this->activeSlotIds($zoneId));
        unset($second);
    }

    // ═══ Changements d'heure ════════════════════════════════════════════════

    public function testANonexistentLocalTimeIsRefusedWithTheDateAndNothingIsSaved(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $html = $this->post($page, '/creneaux', $this->single('new', '2027-03-28', '02:30', '03:30'));

        $this->assertStatus(422);
        self::assertStringContainsString('n\'existe pas', html_entity_decode($html, \ENT_QUOTES));
        self::assertStringContainsString('2027-03-28', $html);
        self::assertStringContainsString('Europe/Paris', $html);
        self::assertStringContainsString('value="02:30"', $html, 'saisie conservée, aucun ajustement automatique');
        self::assertCount(1, $this->activeSlotIds($zoneId));
    }

    public function testARepeatedLocalTimeAsksForAnExplicitChoiceThenStoresIt(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $html = $this->post($page, '/creneaux', $this->single('new', '2026-10-25', '02:30', '04:00'));

        $this->assertStatus(422);
        self::assertStringContainsString('name="new[dst][2026-10-25][start]"', $html);
        self::assertStringContainsString('Première occurrence', $html);
        self::assertStringContainsString('UTC+02:00', $html);
        self::assertStringContainsString('UTC+01:00', $html);
        self::assertStringContainsString('(heure d’été)', $html);
        self::assertStringContainsString('(heure d’hiver)', $html);
        self::assertStringNotContainsString('value="second" checked', $html, 'aucun choix par défaut');
        self::assertStringNotContainsString('value="first" checked', $html);
        self::assertCount(1, $this->activeSlotIds($zoneId), 'rien d’enregistré sans choix');

        $this->post($page, '/creneaux', $this->single('new', '2026-10-25', '02:30', '04:00', ['dst' => ['2026-10-25' => ['start' => 'second']]]));

        $this->assertStatus(303);
        self::assertCount(2, $this->activeSlotIds($zoneId));
        $stored = $this->em()->getConnection()->fetchOne('SELECT dst_choices FROM event_zone_validity WHERE zone_id = UUID_TO_BIN(?) AND deleted_at IS NULL AND kind = ?', [$zoneId, 'PUNCTUAL']);
        self::assertStringContainsString('"second"', (string) $stored);
        $html = $this->get($page);
        self::assertStringContainsString('la deuxième occurrence est retenue', $html);
    }

    public function testTheChoiceIsKeptWhenTheFormIsRedisplayedAfterAnotherError(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $html = $this->post($page, '/creneaux', $this->single('new', '2026-10-25', '02:30', '02:15', ['dst' => ['2026-10-25' => ['start' => 'first']]]));

        $this->assertStatus(422);
        self::assertStringContainsString('doit précéder', $html);
    }

    public function testARecurrenceCrossingTheClockChangeAsksForTheChoiceOfTheAffectedDate(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $html = $this->post($page, '/creneaux', $this->recurring('new', [], '02:30', '04:00', '2026-10-24', '2026-10-26'));

        $this->assertStatus(422);
        self::assertStringContainsString('name="new[dst][2026-10-25][start]"', $html);
        self::assertStringNotContainsString('name="new[dst][2026-10-24]', $html);
        self::assertCount(1, $this->activeSlotIds($zoneId));
    }

    public function testARecurrenceOnTheNonexistentHourNamesTheProblematicDate(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $html = $this->post($page, '/creneaux', $this->recurring('new', [], '02:30', '04:00', '2027-03-27', '2027-03-29'));

        $this->assertStatus(422);
        self::assertStringContainsString('2027-03-28', $html);
        self::assertCount(1, $this->activeSlotIds($zoneId));
    }

    public function testEditingASlotWithAStoredChoiceShowsItAndKeepsItWhenTheDateDoesNotChange(): void
    {
        $zoneId = $this->configured([new ValiditySlotInput('PUNCTUAL', '2026-10-25', '02:30', '04:00', null, [], [['date' => '2026-10-25', 'field' => 'start', 'occurrence' => 'first']])]);
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);
        [$slotId] = $this->activeSlotIds($zoneId);

        $html = $this->get($page);
        self::assertStringContainsString('la première occurrence est retenue', $html);

        $this->postSelf($page.'/creneaux/'.$slotId.'/modifier', $this->single('edit', '2026-10-25', '02:30', '05:00', ['dst' => ['2026-10-25' => ['start' => 'first']]]));
        $this->assertStatus(303);
        self::assertStringContainsString('de 02:30 à 05:00', $this->get($page));
    }

    // ═══ Plafond de créneaux ═════════════════════════════════════════════════

    public function testTheCeilingOf500SlotsIsEnforced(): void
    {
        $slots = [];
        $day = new \DateTimeImmutable('2027-01-01');
        for ($i = 0; $i < 500; ++$i) {
            $slots[] = new ValiditySlotInput('PUNCTUAL', $day->modify('+'.$i.' days')->format('Y-m-d'), '09:00', '10:00');
        }
        $zoneId = $this->configured($slots);
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $html = $this->post($page, '/creneaux', $this->single('new', '2029-01-01', '09:00', '10:00'));

        $this->assertStatus(422);
        self::assertStringContainsString('limitée à 500 créneaux', $html);
        self::assertCount(500, $this->activeSlotIds($zoneId));
    }

    // ═══ Permissions, isolation, jetons ══════════════════════════════════════

    public function testOtherCommunesAndRolesCannotWriteTheValidityOfAZone(): void
    {
        $zoneId = $this->configured();
        $page = $this->url($this->w->org, $this->fire, $zoneId);
        $this->loginAs($this->w->adminO);
        $fields = $this->form($page, '/creneaux') + $this->single('new', '2026-12-05', '09:00', '10:00');

        $this->loginAs($this->w->adminB);
        $this->client->request('POST', $this->url($this->w->guestB, $this->fire, $zoneId, '/creneaux'), $fields);
        $this->assertStatus(403, 'zone visible mais d’une autre commune');

        $this->loginAs($this->w->managerO);
        $this->client->request('POST', $page.'/creneaux', $fields);
        $this->assertStatus(403);

        $this->loginAs($this->w->instructorO);
        $this->client->request('POST', $page.'/configuration', $fields);
        $this->assertStatus(403);

        $this->loginAs($this->w->adminX);
        $this->client->request('POST', $this->url($this->w->other, $this->fire, $zoneId, '/creneaux'), $fields);
        $this->assertStatus(404);

        self::assertCount(1, $this->activeSlotIds($zoneId));
    }

    public function testAForgedSlotOrZoneIdentifierIsRefused(): void
    {
        $zoneId = $this->configured();
        $page = $this->url($this->w->org, $this->fire, $zoneId);
        $this->loginAs($this->w->adminO);
        $fields = $this->form($page, '/creneaux');

        $this->client->request('POST', $page.'/creneaux/'.self::randomUuid().'/modifier', $fields + $this->single('edit', '2026-12-05', '09:00', '10:00'));
        $this->assertStatus(404, 'créneau inconnu');
        $this->client->request('GET', $page.'/creneaux/'.self::randomUuid().'/supprimer');
        $this->assertStatus(404);

        $this->client->request('POST', $this->url($this->w->org, $this->fire, self::randomUuid(), '/creneaux'), $fields + $this->single('new', '2026-12-05', '09:00', '10:00'));
        $this->assertStatus(404, 'zone inconnue');

        $other = $this->zoneId($this->w->org, $this->accident);
        $this->client->request('POST', $this->url($this->w->org, $this->fire, $other, '/creneaux'), $fields + $this->single('new', '2026-12-05', '09:00', '10:00'));
        $this->assertStatus(404, 'zone d’un autre événement');

        self::assertCount(1, $this->activeSlotIds($zoneId));
    }

    public function testAddingASlotBeforeChoosingSpecificPeriodsIsForbidden(): void
    {
        $zoneId = $this->zoneId($this->w->org, $this->fire);
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $this->client->request('POST', $page.'/creneaux', $this->single('new', '2026-12-05', '09:00', '10:00') + ['_token' => 'x', 'revision' => 'x']);

        $this->assertStatus(403);
        self::assertSame('UNCONFIGURED', $this->mode($zoneId));
    }

    public function testAForgedCsrfTokenChangesNothing(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $html = $this->post($page, '/creneaux', $this->single('new', '2026-12-05', '09:00', '10:00') + ['_token' => 'forge']);

        $this->assertStatus(422);
        self::assertStringContainsString('Le formulaire a expiré', $html);
        self::assertCount(1, $this->activeSlotIds($zoneId));
    }

    public function testAStaleRevisionIsRefusedWithAConflictAndNothingIsOverwritten(): void
    {
        $zoneId = $this->configured();
        $this->loginAs($this->w->adminO);
        $page = $this->url($this->w->org, $this->fire, $zoneId);
        $stale = $this->form($page, '/creneaux');

        // Un autre administrateur modifie la validité entre l'affichage et l'envoi.
        static::getContainer()->get(EventZoneService::class)->configureValidity($this->user($this->w->adminO), $zoneId, new ZoneValidityCommand('SPECIFIC_PERIODS', [new ValiditySlotInput('PUNCTUAL', '2026-12-09', '09:00', '10:00')]));
        $this->em()->clear();
        $other = $this->activeSlotIds($zoneId);

        $html = $this->post($page, '/creneaux', $this->single('new', '2026-12-05', '09:00', '10:00') + $stale);

        $this->assertStatus(409);
        self::assertStringContainsString('modifiée par un autre traitement', $html);
        self::assertStringContainsString('Relire la configuration actuelle', $html);
        self::assertStringNotContainsString('Enregistrer le créneau', $html, 'aucune nouvelle tentative proposée sur des données périmées');
        self::assertSame($other, $this->activeSlotIds($zoneId), 'aucune écriture');
    }

    private function secondAdmin(): User
    {
        $user = UserBuilder::aUser()->build();
        $this->em()->persist($user);
        $this->em()->persist(MembershipBuilder::aMembership($user, $this->em()->find(Municipality::class, $this->w->org->id()) ?? self::fail())->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $this->em()->flush();

        return $user;
    }

    public function testTwoAdministratorsOnTheSameConfigurationTheSecondGetsAConflictAndTheFirstChangeSurvives(): void
    {
        $zoneId = $this->configured();
        $second = $this->secondAdmin();
        $page = $this->url($this->w->org, $this->fire, $zoneId);

        $this->loginAs($this->w->adminO);
        $firstForm = $this->form($page, '/creneaux');
        $this->loginAs($second);
        $secondForm = $this->form($page, '/creneaux');
        self::assertSame($firstForm['revision'], $secondForm['revision'], 'même configuration affichée');

        $this->loginAs($this->w->adminO);
        $this->client->request('POST', $page.'/creneaux', $this->single('new', '2026-12-05', '09:00', '10:00') + $firstForm);
        $this->assertStatus(303);
        $afterFirst = $this->activeSlotIds($zoneId);
        self::assertCount(2, $afterFirst);

        $this->loginAs($second);
        $html = $this->post($page, '/creneaux', $this->single('new', '2026-12-06', '09:00', '10:00') + $secondForm);

        $this->assertStatus(409);
        self::assertStringContainsString('modifiée par un autre traitement', $html);
        self::assertSame($afterFirst, $this->activeSlotIds($zoneId), 'la modification du premier n’est pas écrasée');
        $dates = $this->em()->getConnection()->fetchFirstColumn('SELECT DATE_FORMAT(start_date, \'%Y-%m-%d\') FROM event_zone_validity WHERE zone_id = UUID_TO_BIN(?) AND deleted_at IS NULL ORDER BY start_date', [$zoneId]);
        self::assertSame(['2026-12-01', '2026-12-05'], $dates);
    }

    public function testAnEditFormOfAReplacedConfigurationGetsAConflictNotA404(): void
    {
        $zoneId = $this->configured();
        $second = $this->secondAdmin();
        $page = $this->url($this->w->org, $this->fire, $zoneId);
        [$slotId] = $this->activeSlotIds($zoneId);
        $editUrl = $page.'/creneaux/'.$slotId.'/modifier';

        $this->loginAs($second);
        $staleForm = $this->form($editUrl, '/modifier'); // le second administrateur ouvre la modification du créneau

        $this->loginAs($this->w->adminO);
        $this->postSelf($editUrl, $this->continuous('edit', '2026-12-01', '07:00', '2026-12-02', '19:00')); // le premier le remplace
        $this->assertStatus(303);
        $replaced = $this->activeSlotIds($zoneId);

        $this->loginAs($second);
        $this->client->request('POST', $editUrl, $this->continuous('edit', '2026-12-01', '06:00', '2026-12-02', '20:00') + $staleForm);

        $this->assertStatus(409, 'formulaire d’une configuration remplacée depuis : conflit, pas « inconnu »');
        self::assertSame($replaced, $this->activeSlotIds($zoneId));
        self::assertStringContainsString('à 07:00', $this->get($page), 'modification du premier intacte');
    }

    public function testNothingCanBeWrittenOnAClosedEvent(): void
    {
        $zoneId = $this->zoneId($this->w->org, $this->flood);
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->url($this->w->org, $this->flood, $zoneId));
        $this->assertStatus(200, 'consultation possible');
        self::assertStringNotContainsString('Enregistrer la validité', $html);

        $this->client->request('POST', $this->url($this->w->org, $this->flood, $zoneId, '/configuration'), ['cfg' => ['scope' => 'event'], '_token' => 'x', 'revision' => 'x']);
        $this->assertStatus(403);
        self::assertSame('UNCONFIGURED', $this->mode($zoneId));
    }

    public function testTheValidityRoutesRequireAFullAuthentication(): void
    {
        $zoneId = $this->zoneId($this->w->org, $this->fire);

        $this->client->request('GET', $this->url($this->w->org, $this->fire, $zoneId));
        $this->assertStatus(302);
        self::assertStringContainsString('/login', $this->location());

        $this->client->request('POST', $this->url($this->w->org, $this->fire, $zoneId, '/creneaux'));
        $this->assertStatus(302);
    }
}
