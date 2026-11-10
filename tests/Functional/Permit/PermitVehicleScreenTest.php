<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\PermitAccessService;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\PdfInspector;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Écran « Modifier les plaques » (LOT 05.5.4) dans le navigateur simulé : portée globale annoncée (autres communes nommées) et confirmation exigée, aperçu SANS écriture,
 * opération groupée, dernière plaque (retrait refusé, remplacement permis), motifs, commentaire sans plaque, CSRF, rôles, plaques forgées (404 uniforme), suspension de
 * l'événement, laissez-passer à pied ou à vélo (404), historique de la commune et ligne neutre des autres, contrôle public, page du bénéficiaire et PDF réel après les
 * opérations, QR inchangé. L'interrupteur public n'est activé qu'ICI, dans cet environnement de test isolé.
 */
#[Group('requires-request-schema')]
final class PermitVehicleScreenTest extends PublicRequestTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private User $instructor;
    private User $partnerInstructor;
    private User $manager;
    private User $outsider;
    private string $organizerId;
    private string $partnerId;
    private string $requestId;
    private ?string $previousSwitch = null;

    protected function setUp(): void
    {
        $this->previousSwitch = $_SERVER['PUBLIC_VERIFICATION_ENABLED'] ?? null;
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = '1';
        putenv('PUBLIC_VERIFICATION_ENABLED=1');
        parent::setUp();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $other = MunicipalityBuilder::aMunicipality()->withInseeCode('33063')->build();
        $this->organizerId = $organizer->id()->toRfc4122();
        $this->partnerId = $partner->id()->toRfc4122();
        [$this->instructor, $this->partnerInstructor, $this->manager, $this->outsider] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 4));
        $this->save(
            $other,
            $this->instructor,
            $this->partnerInstructor,
            $this->manager,
            $this->outsider,
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->partnerInstructor, $partner)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->manager, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->outsider, $other)->withRoles(Role::Instructor)->build(),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->requestId = $this->approve(TravelMode::Vehicle, ['GH-482-KT', 'AB-123-CD']);
    }

    protected function tearDown(): void
    {
        $value = $this->previousSwitch ?? '0';
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = $value;
        putenv('PUBLIC_VERIFICATION_ENABLED='.$value);
        parent::tearDown();
    }

    /** @param list<string> $plates */
    private function approve(TravelMode $mode, array $plates): string
    {
        $id = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', 'camille.secret@exemple.fr', '0611223344', 'private_person', 'home', null, $mode, $plates, [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], true),
            sprintf('vehicle-screen-%016d', random_int(1, 999999)),
        )->requestId;
        $instruction = static::getContainer()->get(MunicipalInstructionService::class);
        $instruction->approve($this->managed($this->instructor), $this->organizerId, $id, ApprovalDuration::untilClosure());
        $instruction->approve($this->managed($this->partnerInstructor), $this->partnerId, $id, ApprovalDuration::untilClosure());

        return $id;
    }

    private function managed(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function as(User $user): void
    {
        $this->client->loginUser($this->managed($user));
    }

    private function url(string $suffix = '', ?string $commune = null, ?string $request = null): string
    {
        return '/communes/'.($commune ?? $this->organizerId).'/demandes/'.($request ?? $this->requestId).'/droits'.$suffix;
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array{int, int, int} plaques, lignes de révision de plaque, révisions */
    private function counts(): array
    {
        $c = $this->em()->getConnection();

        return [(int) $c->fetchOne('SELECT COUNT(*) FROM permit_vehicle'), (int) $c->fetchOne('SELECT COUNT(*) FROM permit_revision_vehicle'), (int) $c->fetchOne('SELECT COUNT(*) FROM permit_revision')];
    }

    /** @return list<string> */
    private function active(): array
    {
        return array_map('strval', $this->em()->getConnection()->fetchFirstColumn('SELECT registration FROM permit_vehicle WHERE removed_at IS NULL ORDER BY position'));
    }

    private function token(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
    }

    private function idOf(string $registration): string
    {
        return Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM permit_vehicle WHERE registration = :r', ['r' => $registration]))->toRfc4122();
    }

    /** @param array<string, mixed> $fields */
    private function submit(array $fields, ?string $commune = null, ?string $request = null): void
    {
        $crawler = $this->client->request('GET', $this->url('/vehicules', $commune, $request));
        $token = (string) $crawler->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', $this->url('/vehicules', $commune, $request), $fields + ['_token' => $token]);
    }

    // ------------------------------------------------------------------ écrans

    public function testTheRightsScreenListsThePlatesAnnouncesTheCommonScopeAndOffersTheAction(): void
    {
        $this->as($this->instructor);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        foreach (['Plaques du laissez-passer', 'GH-482-KT', 'AB-123-CD', 'communes', $this->partnerParticipation->municipality()->name(), 'Modifier les plaques'] as $expected) {
            self::assertStringContainsString($expected, $this->html(), $expected);
        }
    }

    public function testTheFormAnnouncesTheScopePreviewsWithoutWritingThenAppliesAGroupedOperationAfterConfirmation(): void
    {
        $this->as($this->instructor);
        $before = $this->counts();

        $this->client->request('GET', $this->url('/vehicules'));
        self::assertResponseIsSuccessful();
        foreach (['Modifier les plaques', 'Cette modification s’appliquera aussi aux communes', $this->partnerParticipation->municipality()->name(), 'Voir ce qui changerait', 'Je confirme que cette modification s’applique à toutes les communes', 'Motif du retrait', 'Motif du remplacement'] as $expected) {
            self::assertStringContainsString($expected, $this->html(), $expected);
        }
        self::assertStringNotContainsString('Confirmer la modification des plaques', $this->html(), 'pas de confirmation sans aperçu');

        $this->submit(['add' => ['ef-456-gh', ''], 'step' => 'preview']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ce qui changerait', $this->html());
        self::assertStringContainsString('EF-456-GH', $this->html(), 'la plaque normalisée est affichée');
        self::assertStringContainsString('Confirmer la modification des plaques', $this->html());
        self::assertSame($before, $this->counts(), 'l\'aperçu n\'écrit rien');

        $this->submit(['add' => ['ef-456-gh'], 'remove' => [$this->idOf('AB123CD')], 'remove_reason' => 'VEHICLE_NO_LONGER_USED', 'comment' => self::COMMENT, 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Confirmez', $this->html(), 'la portée globale doit être confirmée');
        self::assertSame($before, $this->counts());
        self::assertStringContainsString(self::COMMENT, $this->html(), 'saisies conservées');

        $this->submit(['add' => ['ef-456-gh'], 'remove' => [$this->idOf('AB123CD')], 'remove_reason' => 'VEHICLE_NO_LONGER_USED', 'comment' => self::COMMENT, 'confirm_global' => '1', 'step' => 'apply']);

        self::assertResponseRedirects($this->url());
        self::assertSame(['GH482KT', 'EF456GH'], $this->active());
        self::assertSame([3, 2, 2], $this->counts(), 'une révision par plaque modifiée');
        $this->client->followRedirect();
        $html = $this->html();
        foreach (['Plaques modifiées', 'Plaque ajoutée', 'Plaque retirée', 'Retirée le', self::COMMENT, 'prévenu sans attendre'] as $expected) {
            self::assertStringContainsString($expected, $html, $expected);
        }
    }

    public function testTheLastActivePlateCannotBeRemovedButCanBeReplaced(): void
    {
        $this->as($this->instructor);
        $this->submit(['remove' => [$this->idOf('AB123CD')], 'remove_reason' => 'ENTRY_ERROR', 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseRedirects($this->url());

        $this->client->request('GET', $this->url('/vehicules'));
        self::assertStringContainsString('Une seule plaque reste active', $this->html());
        $this->submit(['remove' => [$this->idOf('GH482KT')], 'remove_reason' => 'ENTRY_ERROR', 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('au moins une plaque', $this->html());
        self::assertSame(['GH482KT'], $this->active());

        $this->submit(['replace' => [$this->idOf('GH482KT') => 'zz 999 zz'], 'replace_reason' => 'VEHICLE_CHANGED', 'confirm_global' => '1', 'step' => 'apply']);

        self::assertResponseRedirects($this->url());
        self::assertSame(['ZZ999ZZ'], $this->active());
        $this->client->followRedirect();
        self::assertStringContainsString('Plaque remplacée (ancienne plaque)', $this->html());
        self::assertStringContainsString('Plaque remplacée (nouvelle plaque)', $this->html());
        self::assertStringContainsString('remplacement n° 1', $this->html(), 'le remplacement se reconstitue à l\'écran');
    }

    public function testReasonsInvalidPlatesAndACommentWithAPlateKeepTheInputAndWriteNothing(): void
    {
        $this->as($this->instructor);
        $before = $this->counts();

        $this->submit(['remove' => [$this->idOf('AB123CD')], 'confirm_global' => '1', 'step' => 'apply', 'comment' => 'Mon commentaire conservé']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choisissez le motif du retrait', $this->html());
        self::assertStringContainsString('Mon commentaire conservé', $this->html());

        $this->submit(['remove' => [$this->idOf('AB123CD')], 'remove_reason' => 'OTHER', 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('commentaire est obligatoire', $this->html());

        $this->submit(['add' => ['B-AB 1234'], 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Plaque d&#039;immatriculation invalide', $this->html());
        self::assertStringContainsString('B-AB 1234', $this->html(), 'saisie conservée');

        $this->submit(['add' => ['EF-456-GH'], 'add_reason' => 'OTHER', 'comment' => 'ancienne plaque AB-123-CD', 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('aucune plaque d&#039;immatriculation', $this->html());

        $this->submit(['step' => 'apply', 'confirm_global' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('au moins une plaque', $this->html());
        self::assertSame($before, $this->counts());
    }

    public function testAReinstatementIsOfferedAfterARemovalAndReusesTheRow(): void
    {
        $this->as($this->instructor);
        $this->submit(['remove' => [$this->idOf('AB123CD')], 'remove_reason' => 'ENTRY_ERROR', 'confirm_global' => '1', 'step' => 'apply']);

        $this->client->request('GET', $this->url('/vehicules'));
        self::assertStringContainsString('Réactiver des plaques retirées', $this->html());
        $this->submit(['add' => ['AB-123-CD'], 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('réactivez-la', $this->html());
        $this->submit(['reinstate' => [$this->idOf('AB123CD')], 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('motif de la réactivation', $this->html());

        $this->submit(['reinstate' => [$this->idOf('AB123CD')], 'reinstate_reason' => 'REMOVED_IN_ERROR', 'confirm_global' => '1', 'step' => 'apply']);

        self::assertResponseRedirects($this->url());
        self::assertSame(['GH482KT', 'AB123CD'], $this->active());
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_vehicle'), 'la même ligne : aucune duplication');
        $this->client->followRedirect();
        self::assertStringContainsString('Plaque réactivée', $this->html());
    }

    // ------------------------------------------------------------------ suspension

    public function testASuspendedEventBlocksAdditionsButNotRemovals(): void
    {
        $this->as($this->instructor);
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");

        $this->client->request('GET', $this->url('/vehicules'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('pas actif', $this->html());
        self::assertStringNotContainsString('Ajouter des plaques', $this->html());
        $before = $this->counts();
        $this->submit(['add' => ['EF-456-GH'], 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('suspendu', $this->html());
        self::assertSame($before, $this->counts());

        $this->submit(['remove' => [$this->idOf('AB123CD')], 'remove_reason' => 'ENTRY_ERROR', 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseRedirects($this->url());
        self::assertSame(['GH482KT'], $this->active());
    }

    // ------------------------------------------------------------------ sécurité

    public function testAForgedCsrfTokenAndForgedPlatesChangeNothing(): void
    {
        $this->as($this->instructor);
        $before = $this->counts();

        $this->client->request('POST', $this->url('/vehicules'), ['_token' => 'forgé', 'add' => ['EF-456-GH'], 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le formulaire a expiré', $this->html());

        foreach (['0199aaaa-0000-7000-8000-000000000000', 'pas-un-uuid'] as $forged) {
            $this->submit(['remove' => [$forged], 'remove_reason' => 'ENTRY_ERROR', 'confirm_global' => '1', 'step' => 'apply']);
            self::assertResponseStatusCodeSame(404, 'plaque forgée : refus uniforme : '.$forged);
        }
        self::assertSame($before, $this->counts());
    }

    public function testRolesAndPerimeterAreEnforcedWithAUniformRefusal(): void
    {
        $this->as($this->manager);
        $this->client->request('GET', $this->url('/vehicules'));
        self::assertResponseStatusCodeSame(403, 'EVENT_MANAGER : aucune modification de plaque');

        $this->as($this->outsider);
        $this->client->request('GET', $this->url('/vehicules'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->url('/vehicules'), ['_token' => 'x', 'step' => 'apply', 'add' => ['EF-456-GH'], 'confirm_global' => '1']);
        self::assertResponseStatusCodeSame(404);
        // un agent qui force l'identifiant de l'autre commune dans l'URL : 404
        $this->as($this->instructor);
        $this->client->request('GET', $this->url('/vehicules', $this->partnerId));
        self::assertResponseStatusCodeSame(404);
        self::assertSame([2, 0, 0], $this->counts());
    }

    public function testAFootBikePermitHasNoPlateScreenNorPlatePanel(): void
    {
        $footBike = $this->approve(TravelMode::FootBike, []);
        $this->as($this->instructor);

        $this->client->request('GET', $this->url('', null, $footBike));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Plaques du laissez-passer', $this->html());

        $this->client->request('GET', $this->url('/vehicules', null, $footBike));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->url('/vehicules', null, $footBike), ['_token' => 'x', 'add' => ['EF-456-GH'], 'confirm_global' => '1', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_vehicle'));
    }

    // ------------------------------------------------------------------ historique, autre commune

    public function testTheOtherCommuneSeesANeutralLineAndTheCommonPlatesButNeverTheReasonCommentOrAgent(): void
    {
        $this->as($this->instructor);
        $this->submit(['add' => ['EF-456-GH'], 'add_reason' => 'OTHER', 'comment' => self::COMMENT, 'confirm_global' => '1', 'step' => 'apply']);
        $this->submit(['replace' => [$this->idOf('EF456GH') => 'IJ-789-KL'], 'replace_reason' => 'ENTRY_ERROR', 'confirm_global' => '1', 'step' => 'apply']);

        $this->as($this->partnerInstructor);
        $this->client->request('GET', $this->url('', $this->partnerId));
        self::assertResponseIsSuccessful();
        $html = $this->html();
        self::assertStringContainsString('Véhicules modifiés', $html, 'ligne neutre');
        self::assertStringContainsString($this->organizerParticipation->municipality()->name(), $html);
        foreach ([self::COMMENT, 'Erreur de saisie', 'ENTRY_ERROR', 'Plaque ajoutée', 'Plaque remplacée', 'remplacement n°'] as $leak) {
            self::assertStringNotContainsString($leak, $html, 'aucune fuite intercommunale : '.$leak);
        }
        self::assertStringContainsString('IJ-789-KL', $html, 'les plaques sont communes : l\'autre commune voit l\'état courant');
    }

    // ------------------------------------------------------------------ contrôle public, bénéficiaire, PDF

    public function testThePublicControlTheBeneficiaryPageAndTheRealPdfFollowTheChangeWithoutAnyReasonAndTheQrIsUnchanged(): void
    {
        $this->as($this->instructor);
        $secret = static::getContainer()->get(PermitAccessService::class)->issue($this->managed($this->instructor), $this->organizerId, $this->requestId)->secret;
        $token = $this->token();
        $this->submit(['add' => ['EF-456-GH'], 'confirm_global' => '1', 'step' => 'apply']);
        $this->submit(['remove' => [$this->idOf('AB123CD')], 'remove_reason' => 'OTHER', 'comment' => self::COMMENT, 'confirm_global' => '1', 'step' => 'apply']);

        $this->client->request('GET', '/v/'.$token);
        self::assertResponseStatusCodeSame(200);
        $public = $this->html();
        self::assertStringContainsString('EF-456-GH', $public);
        self::assertStringContainsString('GH-482-KT', $public);
        self::assertStringNotContainsString('AB-123-CD', $public, 'une plaque retirée n\'est plus reconnue au contrôle');
        foreach ([self::COMMENT, 'Autre motif', 'OTHER'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $public, 'aucun motif public : '.$forbidden);
        }
        self::assertSame($token, $this->token(), 'le QR ne change pas');

        $this->client->request('GET', '/laissez-passer/'.$secret);
        self::assertStringContainsString('EF-456-GH', $this->html());
        self::assertStringNotContainsString('AB-123-CD', $this->html());
        self::assertStringNotContainsString(self::COMMENT, $this->html());

        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');
        self::assertResponseIsSuccessful();
        $text = (new PdfInspector((string) $this->client->getResponse()->getContent()))->text();
        self::assertStringContainsString('EF-456-GH', $text);
        self::assertStringContainsString('GH-482-KT', $text);
        self::assertStringNotContainsString('AB-123-CD', $text, 'une plaque retirée n\'est plus listée dans un nouveau PDF');
        self::assertStringNotContainsString(self::COMMENT, $text);
    }
}
