<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\BeneficiaryPassReader;
use App\Permit\Application\PermitAccessService;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * Écran « Droits du laissez-passer », révocation, lien bénéficiaire et PDF après révocation, page publique (LOT 05.5.1) dans le navigateur simulé :
 * rôles, périmètre (404 uniforme), CSRF, confirmation, saisies conservées, révocation définitive, ligne neutre pour les autres communes, lien
 * bénéficiaire « Droits révoqués », refus de génération du PDF, PDF sans mention figée de validité, motif jamais public. L'interrupteur de la
 * vérification publique n'est activé qu'ICI, dans cet environnement de test isolé.
 */
#[Group('requires-request-schema')]
final class PermitRightsScreenTest extends PublicRequestTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';
    private const EMAIL = 'camille.secret@exemple.fr';

    private User $instructor;
    private User $manager;
    private User $partnerInstructor;
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
        if (!$this->em()->getConnection()->createSchemaManager()->tablesExist(['permit_revision'])) {
            self::markTestSkipped('Schéma des révisions non migré (LOT 05.5.1) : test exécutable après la migration validée.');
        }
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $other = MunicipalityBuilder::aMunicipality()->withInseeCode('33063')->build();
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
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', self::EMAIL, '0611223344', 'private_person', 'home', null, TravelMode::Vehicle, ['GH-482-KT', 'AB-123-CD'], [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], true),
            sprintf('rights-screen-%016d', random_int(1, 999999)),
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

    private function approvePartner(): void
    {
        static::getContainer()->get(MunicipalInstructionService::class)->approve($this->managed($this->partnerInstructor), $this->partnerId, $this->requestId, ApprovalDuration::untilClosure());
    }

    private function revocationToken(): string
    {
        $crawler = $this->client->request('GET', $this->url('/revocation'));

        return (string) $crawler->filter('input[name=_token]')->attr('value');
    }

    /** @param array<string, string> $fields */
    private function postRevocation(array $fields, ?string $commune = null): void
    {
        $token = $this->revocationToken();
        $this->client->request('POST', $this->url('/revocation', $commune), $fields + ['_token' => $token]);
    }

    private function revokedCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_authorization WHERE revoked_at IS NOT NULL');
    }

    private function token(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
    }

    // ------------------------------------------------------------------ écran et périmètre

    public function testTheHistoryStatesThatTheOrderOfTwoRevisionsInTheSameSecondIsNotGuaranteed(): void
    {
        $this->as($this->instructor);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('même seconde', $this->html(), 'aucun ordre artificiellement certain n\'est affiché');
        self::assertStringContainsString('n’est jamais utilisé pour calculer les droits', $this->html());
    }

    public function testTheRightsScreenShowsTheOwnAuthorizationAndNothingPersonalOrTechnical(): void
    {
        $this->as($this->instructor);

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        $html = $this->html();
        foreach (['Droits du laissez-passer', 'Votre autorisation', $this->organizerZone->label(), 'Valide actuellement', 'Révoquer l’autorisation de ma commune', 'Aucune révision pour l’instant', 'MARTIN'] as $expected) {
            self::assertStringContainsString($expected, $html, $expected);
        }
        foreach ([self::EMAIL, '0611223344', '12 rue des Lilas', $this->token(), 'AUTHORIZATION_REVOKED', 'permit_revision'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, 'aucune donnée superflue : '.$forbidden);
        }
        // LOT 05.5.4 : les plaques communes sont montrées à l'agent habilité (il choisit celle à modifier) ; ce n'est plus une donnée superflue de cet écran.
        self::assertStringContainsString('GH-482-KT', $html);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        self::assertStringNotContainsString($this->partnerParticipation->municipality()->name(), $html, 'une commune sans autorisation n\'est pas nommée');
    }

    public function testRolesAndPerimeterAreEnforcedWithAUniformRefusal(): void
    {
        $this->as($this->manager);
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(403, 'EVENT_MANAGER : aucune révision');

        $this->as($this->outsider);
        $this->client->request('GET', $this->url('', $this->partnerId));
        self::assertResponseStatusCodeSame(404, 'commune sans adhésion');

        $this->as($this->partnerInstructor);
        $this->client->request('GET', $this->url('', $this->partnerId));
        self::assertResponseStatusCodeSame(404, 'sa commune n\'a pas (encore) d\'autorisation sur ce laissez-passer');
        $this->client->request('GET', $this->url('', $this->organizerId));
        self::assertResponseStatusCodeSame(404, 'la commune d\'une autre n\'est pas la sienne');

        $this->as($this->instructor);
        $this->client->request('GET', '/communes/'.$this->organizerId.'/demandes/0199aaaa-0000-7000-8000-000000000000/droits');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAnonymousVisitorIsSentToTheLogin(): void
    {
        $this->client->request('GET', $this->url());

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // ------------------------------------------------------------------ formulaire de révocation

    public function testTheRevocationFormListsTheCataloguedReasonsInFrenchWithAMandatoryConfirmation(): void
    {
        $this->as($this->instructor);

        $this->client->request('GET', $this->url('/revocation'));

        self::assertResponseIsSuccessful();
        $html = $this->html();
        foreach (['Fausse déclaration', 'Conditions d’éligibilité non remplies', 'Sécurité ou ordre public', 'Délivrée par erreur', 'Doublon', 'Usage non conforme', 'Demande du bénéficiaire', 'Autre motif', 'maxlength="200"', 'Je confirme que cette révocation est définitive', 'Révocation définitive'] as $expected) {
            self::assertStringContainsString($expected, $html, $expected);
        }
        self::assertSame(8, substr_count($html, 'name="reason"'));
        self::assertStringContainsString('Interne : jamais public ni envoyé au bénéficiaire', $html);
        self::assertSame(0, $this->revokedCount(), 'la simple consultation ne révoque rien');
    }

    public function testAForgedCsrfTokenRevokesNothing(): void
    {
        $this->as($this->instructor);

        $this->client->request('POST', $this->url('/revocation'), ['_token' => 'forgé', 'reason' => 'DUPLICATE', 'confirmed' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le formulaire a expiré', $this->html());
        self::assertSame(0, $this->revokedCount());
    }

    public function testAnInvalidFormKeepsTheInputAndRevokesNothing(): void
    {
        $this->as($this->instructor);

        $this->postRevocation(['reason' => 'OTHER', 'comment' => '', 'confirmed' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Un commentaire est obligatoire pour', $this->html());
        self::assertStringContainsString('checked', $this->html(), 'le motif choisi est conservé');

        $this->postRevocation(['comment' => 'Mon commentaire conservé', 'confirmed' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choisissez un motif de révocation', $this->html());
        self::assertStringContainsString('Mon commentaire conservé', $this->html(), 'le commentaire saisi est conservé');

        $this->postRevocation(['reason' => 'DUPLICATE']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Confirmez la révocation', $this->html());

        $this->postRevocation(['reason' => 'DUPLICATE', 'comment' => str_repeat('x', 201), 'confirmed' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->revokedCount());
    }

    // ------------------------------------------------------------------ révocation

    public function testARevocationIsDefinitiveVisibleAndNeverReplayable(): void
    {
        $this->as($this->instructor);

        $this->postRevocation(['reason' => 'OTHER', 'comment' => self::COMMENT, 'confirmed' => '1']);

        self::assertResponseRedirects($this->url());
        self::assertSame(1, $this->revokedCount());
        $this->client->followRedirect();
        $html = $this->html();
        foreach (['Autorisation révoquée le', 'Révoquée', 'Autre motif', self::COMMENT, 'Historique des révisions'] as $expected) {
            self::assertStringContainsString($expected, $html, $expected);
        }
        self::assertStringNotContainsString('Révoquer l’autorisation de ma commune', $html, 'plus de bouton : la révocation est définitive');

        // Rejeu et accès direct au formulaire : refusé, rien de plus n'est écrit.
        $this->client->request('GET', $this->url('/revocation'));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('révocation est définitive', $this->html());
        $this->client->request('POST', $this->url('/revocation'), ['_token' => 'x', 'reason' => 'DUPLICATE', 'confirmed' => '1']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_revision'));
    }

    public function testAnotherCommuneSeesOnlyANeutralLine(): void
    {
        $this->approvePartner();
        $this->as($this->instructor);
        $this->postRevocation(['reason' => 'OTHER', 'comment' => self::COMMENT, 'confirmed' => '1']);

        $this->as($this->partnerInstructor);
        $this->client->request('GET', $this->url('', $this->partnerId));

        self::assertResponseIsSuccessful();
        $html = $this->html();
        self::assertStringContainsString('Autorisation révoquée', $html, 'la ligne neutre : type');
        self::assertStringContainsString($this->organizerParticipation->municipality()->name(), $html, 'la ligne neutre : commune');
        foreach ([self::COMMENT, 'Autre motif', 'OTHER'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, 'ni motif ni commentaire d\'une autre commune : '.$forbidden);
        }
        self::assertStringContainsString('Révoquer l’autorisation de ma commune', $html, 'son autorisation à elle n\'est pas touchée');
    }

    // ------------------------------------------------------------------ page publique

    public function testThePublicPageReflectsTheRevocationAtOnceWithTheSameQrAndNoReason(): void
    {
        $this->approvePartner();
        $token = $this->token();
        $this->as($this->instructor);
        $this->postRevocation(['reason' => 'OTHER', 'comment' => self::COMMENT, 'confirmed' => '1']);

        $this->client->request('GET', '/v/'.$token);

        self::assertResponseStatusCodeSame(200);
        $html = $this->html();
        self::assertStringContainsString('1 zone active sur 2', $html);
        self::assertStringContainsString('Révoquée', $html);
        foreach ([self::COMMENT, 'Autre motif', 'OTHER', self::EMAIL] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, 'le motif n\'est jamais public');
        }
        self::assertSame($token, $this->token(), 'le QR ne change pas');
    }

    public function testThePublicPageSaysRevokedWhenEveryAuthorizationIsRevoked(): void
    {
        $this->as($this->instructor);
        $this->postRevocation(['reason' => 'DUPLICATE', 'confirmed' => '1']);

        $this->client->request('GET', '/v/'.$this->token());

        self::assertStringContainsString('>Droits révoqués<', $this->html());
    }

    // ------------------------------------------------------------------ lien bénéficiaire et PDF

    private function secret(): string
    {
        return static::getContainer()->get(PermitAccessService::class)->issue($this->managed($this->instructor), $this->organizerId, $this->requestId)->secret;
    }

    public function testTheBeneficiaryLinkStaysConsultableAndSaysRightsAreRevokedWithoutAnyPdf(): void
    {
        $secret = $this->secret();
        $this->client->request('GET', '/laissez-passer/'.$secret);
        self::assertStringContainsString('Télécharger le PDF', $this->html(), 'avant révocation : PDF proposé');
        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));

        $this->as($this->instructor);
        $this->postRevocation(['reason' => 'DUPLICATE', 'confirmed' => '1']);
        $this->client->request('GET', '/laissez-passer/'.$secret);

        self::assertResponseIsSuccessful();
        $html = $this->html();
        self::assertStringContainsString('Droits révoqués', $html);
        self::assertStringContainsString('pdf-unavailable', $html);
        self::assertStringNotContainsString('Télécharger le PDF', $html);
        self::assertStringContainsString('seul le contrôle du QR code fait foi', $html);
        self::assertStringContainsString('Révoquée', $html, 'la zone reste lisible avec son état');

        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');
        self::assertResponseStatusCodeSame(409);
        self::assertNotSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('Droits révoqués : aucun PDF', $this->html());
        self::assertStringContainsString('Seul le contrôle du QR code fait foi', $this->html());
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testThePdfStaysAvailableWhileAnotherCommuneKeepsItsRights(): void
    {
        $this->approvePartner();
        $secret = $this->secret();
        $this->as($this->instructor);
        $this->postRevocation(['reason' => 'DUPLICATE', 'confirmed' => '1']);

        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testThePdfTemplateWritesNoFrozenValidityAndSaysOnlyTheQrIsAuthoritative(): void
    {
        $secret = $this->secret();
        $pass = static::getContainer()->get(BeneficiaryPassReader::class)->find($secret, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) ?? self::fail('Page absente.');

        $html = static::getContainer()->get('twig')->render('permit/pass_pdf.html.twig', ['pass' => $pass, 'qr' => 'data:image/png;base64,AAAA', 'generated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))]);

        self::assertStringContainsString('Seul le contrôle du QR code fait foi', $html);
        self::assertStringContainsString($this->organizerZone->label(), $html);
        foreach (['Valide actuellement', 'Valide', 'Expirée', 'Révoquée', 'Pas encore valide', 'Zone suspendue', 'Droits valables', 'Jusqu’au', 'Du '] as $frozen) {
            self::assertStringNotContainsString($frozen, $html, 'aucune mention figée de validité : '.$frozen);
        }
    }
}
