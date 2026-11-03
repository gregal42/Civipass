<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalPagesTestCase;

final class EventListPageTest extends MunicipalPagesTestCase
{
    // ── Accès ────────────────────────────────────────────────────────────────

    public function testAnonymousVisitorsAreSentToTheLoginPage(): void
    {
        foreach ([$this->listUrl($this->w->org), $this->eventUrl($this->w->org, $this->fire), '/communes'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('http://localhost/login', 302, $url);
        }
    }

    public function testAnAuthenticatedMemberSeesTheEventsOfTheirMunicipality(): void
    {
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->listUrl($this->w->org, '?tri=asc'));

        self::assertSame(200, $this->httpStatus());
        foreach (['Incendie du massif forestier', 'Crue de la Berle', 'Accident — Route départementale 5'] as $name) {
            self::assertStringContainsString($name, $html);
        }
        self::assertStringContainsString('Commune de ', $html);
        self::assertStringContainsString('26 événements', $html);
        self::assertStringContainsString('Brouillon', $html);
        self::assertStringContainsString('Clôturé', $html);
        self::assertStringContainsString('EVT-', $html, 'référence publique affichée');
    }

    public function testTheCreateActionIsAvailabilityBasedAndNeverSimulated(): void
    {
        $this->loginAs($this->w->adminO);
        $html = $this->get($this->listUrl($this->w->org));
        self::assertStringContainsString('Créer un événement', $html);
        self::assertStringContainsString('Disponible prochainement', $html, 'permise mais non branchée : indisponible, jamais simulée');
        self::assertStringNotContainsString('href="/communes/'.$this->id($this->w->org).'/evenements/nouveau"', $html);

        $this->loginAs($this->w->instructorO);
        self::assertStringNotContainsString('Créer un événement', $this->get($this->listUrl($this->w->org)), 'un instructeur ne peut pas créer : action absente');
    }

    // ── Recherche, filtres, tri ──────────────────────────────────────────────

    public function testSearchByNameAndByReference(): void
    {
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->listUrl($this->w->org, '?q=Berle'));
        self::assertStringContainsString('Crue de la Berle', $html);
        self::assertStringNotContainsString('Incendie du massif', $html);
        self::assertStringContainsString('1 événement', $html);
        self::assertStringContainsString('Recherche : Berle', $html);

        $reference = (string) $this->em()->getConnection()->fetchOne('SELECT public_reference FROM event_event WHERE name = ?', ['Accident — Route départementale 5']);
        $html = $this->get($this->listUrl($this->w->org, '?q='.$reference));
        self::assertStringContainsString('Accident — Route départementale 5', $html);
        self::assertStringNotContainsString('Crue de la Berle', $html);
    }

    public function testSearchWildcardsAreTakenLiterally(): void
    {
        $this->loginAs($this->w->adminO);

        foreach (['%', '_', '%massif%'] as $wildcard) {
            $html = $this->get($this->listUrl($this->w->org, '?q='.rawurlencode($wildcard)));
            self::assertStringContainsString('Aucun événement ne correspond', $html, $wildcard);
        }
    }

    public function testFilterByStatusAndByType(): void
    {
        $this->loginAs($this->w->adminO);

        $closed = $this->get($this->listUrl($this->w->org, '?statut=closed'));
        self::assertStringContainsString('Crue de la Berle', $closed);
        self::assertStringNotContainsString('Incendie du massif', $closed);
        self::assertStringContainsString('Statut : Clôturé', $closed);

        $accident = $this->get($this->listUrl($this->w->org, '?type=accident'));
        self::assertStringContainsString('Accident — Route départementale 5', $accident);
        self::assertStringNotContainsString('Crue de la Berle', $accident);
        self::assertStringContainsString('Type : ', $accident);

        $combined = $this->get($this->listUrl($this->w->org, '?statut=draft&type=fire'));
        self::assertStringContainsString('Incendie du massif forestier', $combined);
        self::assertStringNotContainsString('Accident — Route', $combined);
    }

    public function testNoResultAndInvalidParametersAreHandledWithoutError(): void
    {
        $this->loginAs($this->w->adminO);

        self::assertStringContainsString('Aucun événement ne correspond', $this->get($this->listUrl($this->w->org, '?q=introuvable')));

        // Valeurs inconnues ou hostiles : ignorées (aucun filtre), jamais exécutées.
        foreach (['?statut=nimporte', '?type=drop', '?tri=sideways', '?page=abc', '?page=-3', '?q[]=x', '?statut[]=closed', '?page[]=2', '?page=99999999999'] as $query) {
            $html = $this->get($this->listUrl($this->w->org, $query));
            self::assertSame(200, $this->httpStatus(), $query);
            self::assertStringContainsString('26 événements', $html, $query);
        }
    }

    public function testSortingByStartDate(): void
    {
        $this->loginAs($this->w->adminO);

        // Trois événements aux débuts distincts : Crue (20/11/2026), Incendie (01/12/2026), Accident (10/12/2026).
        $descending = $this->get($this->listUrl($this->w->org, '?q=Berle'));
        self::assertStringContainsString('aria-sort="descending"', $this->get($this->listUrl($this->w->org)), 'plus récent d\'abord par défaut');
        self::assertStringContainsString('aria-sort="ascending"', $this->get($this->listUrl($this->w->org, '?tri=asc')));
        self::assertStringContainsString('Crue de la Berle', $descending);

        $ascending = $this->get($this->listUrl($this->w->org, '?tri=asc'));
        $crue = strpos($ascending, 'Crue de la Berle');
        $incendie = strpos($ascending, 'Incendie du massif forestier');
        $accident = strpos($ascending, 'Accident — Route départementale 5');
        self::assertNotFalse($crue);
        self::assertNotFalse($incendie);
        self::assertNotFalse($accident);
        self::assertTrue($crue < $incendie && $incendie < $accident, 'ordre croissant des dates de début');
    }

    // ── Pagination côté serveur ──────────────────────────────────────────────

    public function testServerSidePaginationKeepsFiltersAndClampsThePage(): void
    {
        $this->loginAs($this->w->adminO);

        $first = $this->get($this->listUrl($this->w->org));
        self::assertSame(20, substr_count($first, '<tbody>') > 0 ? substr_count($first, 'cp-table__link') : 0, '20 lignes sur la première page');
        self::assertStringContainsString('1–20 sur 26', $first);
        self::assertStringContainsString('page=2', $first);

        $second = $this->get($this->listUrl($this->w->org, '?page=2'));
        self::assertSame(6, substr_count($second, 'cp-table__link'));
        self::assertStringContainsString('21–26 sur 26', $second);

        $beyond = $this->get($this->listUrl($this->w->org, '?page=999'));
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('21–26 sur 26', $beyond, 'page hors limites : dernière page');

        $filtered = $this->get($this->listUrl($this->w->org, '?q=Remplissage'));
        self::assertStringContainsString('q=Remplissage', $filtered, 'les filtres sont conservés dans les liens de pagination');
        self::assertStringContainsString('1–20 sur 23', $filtered);
    }

    // ── Isolation entre communes ─────────────────────────────────────────────

    public function testAMunicipalityOnlySeesItsOwnAndLinkedEvents(): void
    {
        // guestB participe à l'événement « Incendie » seulement ; other est invitée à « Accident » seulement.
        $this->loginAs($this->w->adminB);
        $html = $this->get($this->listUrl($this->w->guestB));
        self::assertStringContainsString('Incendie du massif forestier', $html);
        self::assertStringNotContainsString('Crue de la Berle', $html);
        self::assertStringNotContainsString('Accident — Route', $html);
        self::assertStringContainsString('Participante', $html);

        $this->loginAs($this->w->adminX);
        $html = $this->get($this->listUrl($this->w->other));
        self::assertStringContainsString('Accident — Route départementale 5', $html);
        self::assertStringContainsString('Invitée', $html);
        self::assertStringNotContainsString('Incendie du massif', $html);
    }

    public function testAForeignOrForgedMunicipalityIdentifierIsRefusedUniformly(): void
    {
        $this->loginAs($this->w->adminX);
        $bodies = [];
        foreach ([$this->w->org->id()->toRfc4122(), $this->w->guestB->id()->toRfc4122(), self::randomUuid()] as $municipalityId) {
            $bodies[] = $this->get($this->listUrl($municipalityId));
            self::assertSame(404, $this->httpStatus(), $municipalityId);
        }
        self::assertSame(1, \count(array_unique($bodies)), 'même réponse : aucune énumération des communes');
        self::assertStringNotContainsString('Incendie', $bodies[0]);

        $this->client->request('GET', '/communes/not-a-uuid/evenements');
        self::assertSame(404, $this->httpStatus());
    }

    public function testSuperAdministratorHasNoImplicitMunicipalAccess(): void
    {
        $this->loginAs($this->w->superAdmin);

        $this->get($this->listUrl($this->w->org));
        self::assertSame(404, $this->httpStatus());
        $html = $this->get('/communes');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Aucune commune', $html);
        $this->get($this->eventUrl($this->w->org, $this->fire));
        self::assertSame(404, $this->httpStatus());
    }

    public function testASuspendedMunicipalityIsReadableWithItsBannerAndAnInactiveOneIsNot(): void
    {
        $this->em()->getConnection()->executeStatement("UPDATE municipality SET status = 'SUSPENDED' WHERE id = ?", [$this->w->guestB->id()->toBinary()]);
        $this->loginAs($this->w->adminB);
        $html = $this->get($this->listUrl($this->w->guestB));
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('commune suspendue', $html);
        self::assertStringContainsString('Incendie du massif forestier', $html);

        $this->em()->getConnection()->executeStatement("UPDATE municipality SET status = 'INACTIVE' WHERE id = ?", [$this->w->guestB->id()->toBinary()]);
        $this->loginAs($this->w->adminB);
        $this->get($this->listUrl($this->w->guestB));
        self::assertSame(404, $this->httpStatus());
    }

    public function testADeactivatedAccountCannotBrowse(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE identity_user SET active = 0 WHERE id = ?', [$this->w->adminO->id()->toBinary()]);
        $this->loginAs($this->w->adminO);

        $this->get($this->listUrl($this->w->org));

        self::assertNotSame(200, $this->httpStatus());
    }

    // ── Point d'entrée et commune active ─────────────────────────────────────

    public function testTheHomePageLeadsAMunicipalUserToTheirOnlyMunicipality(): void
    {
        $this->loginAs($this->w->adminO);

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/communes', 302);
        $this->client->request('GET', '/communes');
        self::assertResponseRedirects($this->listUrl($this->w->org), 302);
    }

    public function testASuperAdministratorKeepsTheHomePage(): void
    {
        $this->loginAs($this->w->superAdmin);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testAUserOfSeveralMunicipalitiesChoosesAndTheLastOneIsRemembered(): void
    {
        $guestB = $this->em()->find(Municipality::class, $this->w->guestB->id()) ?? self::fail();
        $this->em()->persist(MembershipBuilder::aMembership($this->user($this->w->adminO), $guestB)->withRoles(MunicipalityRole::Instructor)->build());
        $this->em()->flush();
        $this->loginAs($this->w->adminO);

        $chooser = $this->get('/communes');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString($this->w->guestB->name(), $chooser);
        self::assertStringContainsString($this->w->org->name(), $chooser);
        self::assertStringContainsString('Agent instructeur', $chooser);

        $this->get($this->listUrl($this->w->guestB));
        $this->client->request('GET', '/communes');
        self::assertResponseRedirects($this->listUrl($this->w->guestB), 302, 'dernière commune consultée');
        $this->client->request('GET', '/communes?changer=1');
        self::assertResponseIsSuccessful();
    }

    public function testTheShellShowsTheMunicipalityTheRoleAndTheUser(): void
    {
        $this->loginAs($this->w->instructorO);
        $html = $this->get($this->listUrl($this->w->org));

        self::assertStringContainsString('Commune active', $html);
        self::assertStringContainsString($this->w->org->name(), $html);
        self::assertStringContainsString('Agent instructeur', $html);
        self::assertStringContainsString('name="_csrf_token"', $html, 'déconnexion en POST avec jeton CSRF');
    }
}
