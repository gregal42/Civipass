<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\PermitAccessService;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\PdfInspector;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * Écrans « Modifier les zones » et « Corriger un libellé » (LOT 05.5.3) dans le navigateur simulé : actions proposées selon l'état, aperçu SANS écriture, opération
 * groupée, dernière zone, motifs, CSRF, rôles, zones forgées (404 uniforme), suspension de l'événement, correction par synchronisation avec confirmation, historique,
 * contrôle public, page du bénéficiaire et PDF réel après les opérations. L'interrupteur public n'est activé qu'ICI, dans cet environnement de test isolé.
 */
#[Group('requires-request-schema')]
final class PermitZoneScreenTest extends PublicRequestTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private User $instructor;
    private User $manager;
    private User $outsider;
    private EventZone $zoneB;
    private EventZone $zoneC;
    private string $organizerId;
    private string $requestId;
    private ?string $previousSwitch = null;

    protected function setUp(): void
    {
        $this->previousSwitch = $_SERVER['PUBLIC_VERIFICATION_ENABLED'] ?? null;
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = '1';
        putenv('PUBLIC_VERIFICATION_ENABLED=1');
        parent::setUp();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $other = MunicipalityBuilder::aMunicipality()->withInseeCode('33063')->build();
        $this->organizerId = $organizer->id()->toRfc4122();
        [$this->instructor, $this->manager, $this->outsider] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 3));
        $participation = $this->em()->find(EventParticipation::class, $this->organizerParticipation->id()) ?? self::fail();
        $this->zoneB = F::readyZone($participation);
        $this->zoneC = F::readyZone($participation);
        $this->save(
            $other,
            $this->instructor,
            $this->manager,
            $this->outsider,
            $this->zoneB->createdBy(),
            $this->zoneB,
            $this->zoneC->createdBy(),
            $this->zoneC,
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->manager, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->outsider, $other)->withRoles(Role::Instructor)->build(),
        );
        foreach ([[$this->organizerZone, 'Zone A'], [$this->zoneB, 'Zone B'], [$this->zoneC, 'Zone C'], [$this->partnerZone, 'Zone partenaire']] as [$zone, $label]) {
            $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => $label, 'id' => $zone->id()->toBinary()]);
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->requestId = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', 'camille.secret@exemple.fr', '0611223344', 'private_person', 'home', null, TravelMode::Vehicle, ['GH-482-KT'], [$this->organizerZone->id()->toRfc4122()], true),
            sprintf('zone-screen-%016d', random_int(1, 999999)),
        )->requestId;
        static::getContainer()->get(MunicipalInstructionService::class)->approve($this->managed($this->instructor), $this->organizerId, $this->requestId, ApprovalDuration::untilClosure());
    }

    protected function tearDown(): void
    {
        $value = $this->previousSwitch ?? '0';
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = $value;
        putenv('PUBLIC_VERIFICATION_ENABLED='.$value);
        parent::tearDown();
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
        return '/communes/'.($commune ?? $this->organizerId).'/demandes/'.$this->requestId.'/droits'.$suffix;
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array{int, int} lignes de zones de l'autorisation et révisions */
    private function counts(): array
    {
        $c = $this->em()->getConnection();

        return [(int) $c->fetchOne('SELECT COUNT(*) FROM permit_authorization_zone'), (int) $c->fetchOne('SELECT COUNT(*) FROM permit_revision')];
    }

    private function attached(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_authorization_zone WHERE removed_at IS NULL');
    }

    private function token(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
    }

    private function csrf(string $path): string
    {
        $crawler = $this->client->request('GET', $this->url($path));

        return (string) $crawler->filter('input[name=_token]')->attr('value');
    }

    /** @param array<string, mixed> $fields */
    private function submitZones(string $path, array $fields): void
    {
        $token = $this->csrf($path);
        $this->client->request('POST', $this->url($path), $fields + ['_token' => $token]);
    }

    private function id(EventZone $zone): string
    {
        return $zone->id()->toRfc4122();
    }

    // ------------------------------------------------------------------ écrans

    public function testTheRightsScreenListsTheZonesAndOffersTheActions(): void
    {
        $this->as($this->instructor);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        foreach (['Zones de votre autorisation', 'Zone A', 'Modifier les zones'] as $expected) {
            self::assertStringContainsString($expected, $this->html(), $expected);
        }
        self::assertStringNotContainsString('Corriger un libellé', $this->html(), 'tous les libellés sont à jour');
        self::assertStringNotContainsString('Zone partenaire', $this->html(), 'les zones d\'une autre commune ne sont jamais montrées');
    }

    public function testTheFormPreviewsWithoutWritingThenAppliesAGroupedOperation(): void
    {
        $this->as($this->instructor);
        $before = $this->counts();

        $this->client->request('GET', $this->url('/zones'));
        self::assertResponseIsSuccessful();
        foreach (['Retirer des zones', 'Ajouter des zones', 'Zone B', 'Zone C', 'Besoin terminé', 'Nouveau besoin d’accès', 'Voir ce qui changerait'] as $expected) {
            self::assertStringContainsString($expected, $this->html(), $expected);
        }
        self::assertStringNotContainsString('Confirmer la modification des zones', $this->html(), 'pas de confirmation sans aperçu');

        $this->submitZones('/zones', ['add' => [$this->id($this->zoneB), $this->id($this->zoneC)], 'step' => 'preview']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ce qui changerait', $this->html());
        self::assertStringContainsString('Zones rattachées après l’opération', $this->html());
        self::assertStringContainsString('Confirmer la modification des zones', $this->html());
        self::assertSame($before, $this->counts(), 'l\'aperçu n\'écrit rien');

        $this->submitZones('/zones', ['add' => [$this->id($this->zoneB), $this->id($this->zoneC)], 'remove' => [$this->id($this->organizerZone)], 'remove_reason' => 'NEED_ENDED', 'comment' => self::COMMENT, 'step' => 'apply']);

        self::assertResponseRedirects($this->url());
        self::assertSame(2, $this->attached(), 'état final : B et C rattachées, A retirée');
        self::assertSame([3, 3], $this->counts(), 'une révision par zone modifiée');
        $this->client->followRedirect();
        $html = $this->html();
        foreach (['Zones modifiées', 'Zone ajoutée', 'Zone retirée', 'Retirée le', self::COMMENT] as $expected) {
            self::assertStringContainsString($expected, $html, $expected);
        }
        self::assertStringContainsString('prévenu sans attendre', $html, 'un retrait : notification prioritaire');
    }

    public function testTheLastAttachedZoneCannotBeRemovedAndTheScreenSaysWhy(): void
    {
        $this->as($this->instructor);

        $this->client->request('GET', $this->url('/zones'));
        self::assertStringContainsString('Une seule zone reste rattachée', $this->html());

        $this->submitZones('/zones', ['remove' => [$this->id($this->organizerZone)], 'remove_reason' => 'NEED_ENDED', 'step' => 'apply']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('révoquez', $this->html());
        self::assertSame(1, $this->attached());
    }

    public function testReasonsAndGroupedValidationKeepTheInputAndWriteNothing(): void
    {
        $this->as($this->instructor);
        $this->submitZones('/zones', ['add' => [$this->id($this->zoneB)], 'step' => 'apply']);
        self::assertResponseRedirects($this->url(), null, 'ajout sans motif : permis');

        $before = $this->counts();
        $this->submitZones('/zones', ['remove' => [$this->id($this->zoneB)], 'step' => 'apply', 'comment' => 'Mon commentaire conservé']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choisissez le motif du retrait', $this->html());
        self::assertStringContainsString('Mon commentaire conservé', $this->html());

        $this->submitZones('/zones', ['remove' => [$this->id($this->zoneB)], 'remove_reason' => 'OTHER', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('commentaire est obligatoire', $this->html());

        $this->submitZones('/zones', ['step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('au moins une zone', $this->html());
        self::assertSame($before, $this->counts());
    }

    public function testAReinstatementIsOfferedAfterARemovalAndKeepsTheRow(): void
    {
        $this->as($this->instructor);
        $this->submitZones('/zones', ['add' => [$this->id($this->zoneB)], 'step' => 'apply']);
        $this->submitZones('/zones', ['remove' => [$this->id($this->zoneB)], 'remove_reason' => 'NEED_ENDED', 'step' => 'apply']);

        $this->client->request('GET', $this->url('/zones'));
        self::assertStringContainsString('Réintégrer des zones retirées', $this->html());
        $this->submitZones('/zones', ['reinstate' => [$this->id($this->zoneB)], 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('motif de la réintégration', $this->html());

        $this->submitZones('/zones', ['reinstate' => [$this->id($this->zoneB)], 'reinstate_reason' => 'REMOVED_IN_ERROR', 'step' => 'apply']);

        self::assertResponseRedirects($this->url());
        self::assertSame(2, $this->attached());
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_authorization_zone'), 'la même ligne : aucune duplication');
        $this->client->followRedirect();
        self::assertStringContainsString('Zone réintégrée', $this->html());
    }

    public function testTheZoneListWritesTheStateInFrenchAndNeverTheTechnicalValue(): void
    {
        $this->as($this->instructor);
        $this->submitZones('/zones', ['add' => [$this->id($this->zoneB)], 'step' => 'apply']);
        $this->sql("UPDATE event_zone SET status = 'SUSPENDED' WHERE id = :id", ['id' => $this->zoneB->id()->toBinary()]);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Rattachée · Valide actuellement', $this->html(), 'zone rattachée et accessible');
        self::assertStringContainsString('Rattachée · Zone suspendue', $this->html(), 'zone rattachée mais PAS accessible : la différence est lisible');
        foreach (['zone suspended', 'zone_suspended', '· valid<'] as $technical) {
            self::assertStringNotContainsString($technical, $this->html(), 'jamais la valeur technique : '.$technical);
        }
    }

    // ------------------------------------------------------------------ suspension, libellés

    public function testASuspendedEventBlocksAdditionsButNotRemovalsOrLabelCorrections(): void
    {
        $this->as($this->instructor);
        $this->submitZones('/zones', ['add' => [$this->id($this->zoneB)], 'step' => 'apply']);
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");

        $this->client->request('GET', $this->url('/zones'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('aucune zone ne peut être ajoutée ni réintégrée', $this->html());
        self::assertStringNotContainsString('Ajouter des zones', $this->html());

        $this->submitZones('/zones', ['add' => [$this->id($this->zoneC)], 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('suspendu', $this->html());

        $this->submitZones('/zones', ['remove' => [$this->id($this->zoneB)], 'remove_reason' => 'NEED_ENDED', 'step' => 'apply']);
        self::assertResponseRedirects($this->url(), null, 'le retrait reste possible');
    }

    public function testALabelIsCorrectedBySynchronizationAfterConfirmationWithoutFreeText(): void
    {
        $this->as($this->instructor);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => 'Zone A corrigée', 'id' => $this->organizerZone->id()->toBinary()]);

        $this->client->request('GET', $this->url());
        self::assertStringContainsString('Corriger un libellé', $this->html());
        self::assertStringContainsString('libellé actuel de la zone : « Zone A corrigée »', $this->html());
        $this->client->request('GET', $this->url('/zones/libelles'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Zone A → Zone A corrigée', $this->html());
        self::assertStringNotContainsString('<textarea', $this->html(), 'aucun texte libre');
        self::assertStringNotContainsString('name="label"', $this->html());

        $this->submitZones('/zones/libelles', ['zone' => [$this->id($this->organizerZone)], 'reason' => 'TYPO']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Confirmez que le périmètre géographique est inchangé', $this->html());

        $this->submitZones('/zones/libelles', ['zone' => [$this->id($this->organizerZone)], 'reason' => 'TYPO', 'confirmed' => '1']);

        self::assertResponseRedirects($this->url());
        self::assertSame('Zone A corrigée', $this->em()->getConnection()->fetchOne('SELECT zone_label FROM permit_authorization_zone'));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM notification_outbox WHERE type IN ('ZONES_CHANGED', 'ZONES_REMOVED')"), 'aucune notification');
        $this->client->followRedirect();
        self::assertStringContainsString('Libellé de zone corrigé', $this->html());
    }

    // ------------------------------------------------------------------ sécurité

    public function testAForgedCsrfTokenAndForgedZonesChangeNothing(): void
    {
        $this->as($this->instructor);
        $before = $this->counts();

        $this->client->request('POST', $this->url('/zones'), ['_token' => 'forgé', 'add' => [$this->id($this->zoneB)], 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le formulaire a expiré', $this->html());

        foreach ([$this->id($this->partnerZone), '0199aaaa-0000-7000-8000-000000000000', 'pas-un-uuid'] as $forged) {
            $this->submitZones('/zones', ['add' => [$forged], 'step' => 'apply']);
            self::assertResponseStatusCodeSame(404, 'zone forgée : refus uniforme : '.$forged);
        }
        self::assertSame($before, $this->counts());
    }

    public function testRolesAndPerimeterAreEnforcedWithAUniformRefusal(): void
    {
        $this->as($this->manager);
        $this->client->request('GET', $this->url('/zones'));
        self::assertResponseStatusCodeSame(403);

        $this->as($this->outsider);
        foreach (['/zones', '/zones/libelles'] as $path) {
            $this->client->request('GET', $this->url($path));
            self::assertResponseStatusCodeSame(404);
            $this->client->request('POST', $this->url($path), ['_token' => 'x', 'step' => 'apply']);
            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame([1, 0], $this->counts());
    }

    // ------------------------------------------------------------------ contrôle public, bénéficiaire, PDF

    public function testThePublicControlTheBeneficiaryPageAndTheRealPdfFollowTheChangeWithoutAnyReason(): void
    {
        $this->as($this->instructor);
        $secret = static::getContainer()->get(PermitAccessService::class)->issue($this->managed($this->instructor), $this->organizerId, $this->requestId)->secret;
        $this->submitZones('/zones', ['add' => [$this->id($this->zoneB)], 'step' => 'apply']);
        $this->submitZones('/zones', ['add' => [$this->id($this->zoneC)], 'remove' => [$this->id($this->organizerZone)], 'remove_reason' => 'OTHER', 'comment' => self::COMMENT, 'step' => 'apply']);
        $token = $this->token();

        $this->client->request('GET', '/v/'.$token);
        self::assertResponseStatusCodeSame(200);
        $public = $this->html();
        self::assertStringContainsString('Zone B', $public);
        self::assertStringContainsString('Zone C', $public);
        self::assertStringContainsString('Zones retirées', $public);
        self::assertStringContainsString('2 zones actives sur 2', $public, 'B et C ; la zone retirée est hors compteur');
        foreach ([self::COMMENT, 'Autre motif', 'OTHER', 'NEED_ENDED'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $public, 'aucun motif public : '.$forbidden);
        }
        self::assertSame($token, $this->token(), 'le QR ne change pas');

        $this->client->request('GET', '/laissez-passer/'.$secret);
        self::assertStringContainsString('Zone B', $this->html());
        self::assertStringContainsString('Zone C', $this->html());

        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');
        self::assertResponseIsSuccessful();
        $text = (new PdfInspector((string) $this->client->getResponse()->getContent()))->text();
        foreach (['ZoneB', 'ZoneC'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertStringNotContainsString('ZoneA', $text, 'une zone retirée n\'est plus listée dans un nouveau PDF');
    }
}
