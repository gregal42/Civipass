<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\PermitAccessService;
use App\Permit\Application\QrCodeRenderer;
use App\Permit\Application\VerificationUrlFactory;
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

/**
 * Écran « Remplacer le QR code » (LOT 05.5.5) dans le navigateur simulé : effet global annoncé (autres communes nommées) et confirmé, MUNICIPALITY_ADMIN seul (403, 404
 * uniforme), motif obligatoire, CSRF, double envoi sans second remplacement, remplacement rapproché (référence ressaisie), absence d'e-mail, événement suspendu autorisé et clos
 * refusé, ancien QR en HTTP 410 sans aucune donnée, nouveau QR aux droits corrects, lien bénéficiaire et PDF RÉEL à jour (QR intégré = celui de l'URL du nouveau jeton), historique
 * neutre des autres communes, aucun jeton dans une page, un message ou un commentaire. L'interrupteur public n'est activé qu'ICI, dans cet environnement de test isolé.
 */
#[Group('requires-request-schema')]
final class PermitQrScreenTest extends PublicRequestTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';
    private const EMAIL = 'camille.secret@exemple.fr';

    private User $admin;
    private User $instructor;
    private User $manager;
    private User $partnerAdmin;
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
        [$this->admin, $this->instructor, $this->manager, $this->partnerAdmin, $this->outsider] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 5));
        $this->save(
            $other,
            $this->admin,
            $this->instructor,
            $this->manager,
            $this->partnerAdmin,
            $this->outsider,
            MembershipBuilder::aMembership($this->admin, $organizer)->withRoles(Role::MunicipalityAdmin, Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->manager, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->partnerAdmin, $partner)->withRoles(Role::MunicipalityAdmin, Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->outsider, $other)->withRoles(Role::MunicipalityAdmin)->build(),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->requestId = $this->approve(self::EMAIL, null);
    }

    protected function tearDown(): void
    {
        $value = $this->previousSwitch ?? '0';
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = $value;
        putenv('PUBLIC_VERIFICATION_ENABLED='.$value);
        parent::tearDown();
    }

    private function approve(?string $email, ?string $phone): string
    {
        $id = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', $email, $phone, 'private_person', 'home', null, TravelMode::Vehicle, ['GH-482-KT'], [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], true),
            sprintf('qr-screen-%016d', random_int(1, 999999)),
        )->requestId;
        $instruction = static::getContainer()->get(MunicipalInstructionService::class);
        $instruction->approve($this->managed($this->instructor), $this->organizerId, $id, ApprovalDuration::untilClosure());
        $instruction->approve($this->managed($this->partnerAdmin), $this->partnerId, $id, ApprovalDuration::untilClosure());

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

    /** @return list<string> du plus ancien au plus récent */
    private function tokens(): array
    {
        return array_map('strval', $this->em()->getConnection()->fetchFirstColumn('SELECT token FROM permit_verification_token ORDER BY created_at, id'));
    }

    private function activeToken(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token WHERE active_permit_id IS NOT NULL');
    }

    private function revisions(): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM permit_revision WHERE type = 'TOKEN_REPLACED'");
    }

    /** @param array<string, mixed> $fields */
    private function submit(array $fields, ?string $commune = null): void
    {
        $crawler = $this->client->request('GET', $this->url('/qr', $commune));
        $token = (string) $crawler->filter('input[name=_token]')->attr('value');
        $operation = (string) $crawler->filter('input[name=operation_id]')->attr('value');
        $this->client->request('POST', $this->url('/qr', $commune), $fields + ['_token' => $token, 'operation_id' => $operation]);
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return ['reason' => 'LOST', 'confirm_global' => '1'];
    }

    // ------------------------------------------------------------------ écrans

    public function testTheRightsScreenOffersTheReplacementToAnAdminAndExplainsWhyNotToOthers(): void
    {
        $this->as($this->admin);
        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        foreach (['QR code du laissez-passer', 'Remplacer le QR code', 'commun'] as $expected) {
            self::assertStringContainsString($expected, $this->html(), $expected);
        }
        self::assertStringNotContainsString($this->activeToken(), $this->html(), 'jamais le jeton à l\'écran');

        $this->as($this->instructor);
        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('réservé aux administrateurs', $this->html());
        self::assertStringNotContainsString('Remplacer le QR code</', $this->html());
    }

    public function testTheFormAnnouncesTheGlobalEffectAndNamesTheOtherCommunes(): void
    {
        $this->as($this->admin);

        $this->client->request('GET', $this->url('/qr'));

        self::assertResponseIsSuccessful();
        foreach (['Le QR code est commun à toutes les communes', $this->partnerParticipation->municipality()->name(), 'cesse définitivement de fonctionner', 'QR remplacé', 'Motif du remplacement', 'Je confirme que le QR code de toutes les communes', 'QR code perdu'] as $expected) {
            self::assertStringContainsString($expected, $this->html(), $expected);
        }
        self::assertStringNotContainsString('Référence du laissez-passer (ressaisie)', $this->html(), 'pas de remplacement récent : pas de référence à ressaisir');
    }

    // ------------------------------------------------------------------ remplacement, 410, nouveau QR

    public function testTheReplacementMakesTheOldQrGoneAndTheNewQrServeTheSameRightsWithoutAnyTokenOnScreen(): void
    {
        $this->as($this->admin);
        $old = $this->activeToken();
        $this->client->request('GET', '/v/'.$old);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('GH-482-KT', $this->html());

        $this->submit($this->valid() + ['comment' => self::COMMENT, 'reason' => 'SUSPECTED_MISUSE']);

        self::assertResponseRedirects($this->url());
        $new = $this->activeToken();
        self::assertNotSame($old, $new);
        $this->client->followRedirect();
        $page = $this->html();
        foreach (['QR code remplacé (remplacement n° 1)', 'L’ancien QR code ne fonctionne plus', 'prévenu sans attendre', 'QR code remplacé', 'Remplacement n° 1'] as $expected) {
            self::assertStringContainsString($expected, $page, $expected);
        }
        foreach ([$old, $new, strtoupper($old), strtoupper($new)] as $token) {
            self::assertStringNotContainsString($token, $page, 'jamais un jeton à l\'écran');
        }

        $this->client->request('GET', '/v/'.$old);
        self::assertResponseStatusCodeSame(410);
        foreach (['Martin', 'Camille', 'GH-482-KT', 'LP-', $this->organizerParticipation->municipality()->name(), $old] as $leak) {
            self::assertStringNotContainsString($leak, $this->html(), 'HTTP 410 sans aucune donnée : '.$leak);
        }
        $this->client->request('GET', '/v/'.$new);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('GH-482-KT', $this->html());
        self::assertStringContainsString($this->organizerParticipation->municipality()->name(), $this->html(), 'les mêmes droits');
        self::assertStringNotContainsString(self::COMMENT, $this->html());
    }

    public function testTheBeneficiaryLinkShowsTheNewQrAndAnUpToDatePdfWhileAnOldPdfQrIsGone(): void
    {
        $this->as($this->admin);
        $secret = static::getContainer()->get(PermitAccessService::class)->issue($this->managed($this->admin), $this->organizerId, $this->requestId)->secret;
        $old = $this->activeToken();
        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');
        $oldPdf = new PdfInspector((string) $this->client->getResponse()->getContent());

        $this->submit($this->valid());
        $new = $this->activeToken();

        $this->client->request('GET', '/laissez-passer/'.$secret);
        self::assertResponseIsSuccessful();
        $renderer = static::getContainer()->get(QrCodeRenderer::class);
        $urls = static::getContainer()->get(VerificationUrlFactory::class);
        self::assertStringContainsString(trim(preg_replace('/<\?xml[^>]*\?>/', '', $renderer->svg($urls->forToken($new))) ?? ''), $this->html(), 'la page du bénéficiaire affiche le QR du nouveau jeton');
        self::assertStringNotContainsString(trim(preg_replace('/<\?xml[^>]*\?>/', '', $renderer->svg($urls->forToken($old))) ?? ''), $this->html(), 'jamais l\'ancien QR');
        self::assertStringContainsString('QR code en vigueur', $this->html());

        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');
        self::assertResponseIsSuccessful();
        $newPdf = new PdfInspector((string) $this->client->getResponse()->getContent());
        $qr = static fn (PdfInspector $p): array => array_values(array_filter($p->images(), static fn (array $i): bool => $i['width'] === $i['height'] && $i['width'] >= 300))[0]['dark'] ?? self::fail('QR absent du PDF.');
        $dark = static function (string $png): array {
            $image = imagecreatefromstring($png) ?: self::fail('PNG illisible.');
            $rows = [];
            for ($y = 0; $y < imagesy($image); ++$y) {
                $row = [];
                for ($x = 0; $x < imagesx($image); ++$x) {
                    $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    $row[] = ($c['red'] + $c['green'] + $c['blue']) / 3 < 128;
                }
                $rows[] = $row;
            }

            return $rows;
        };
        self::assertSame($dark($renderer->png($urls->forToken($new))), $qr($newPdf), 'le PDF actualisé porte, pixel pour pixel, le QR du nouveau jeton');
        self::assertSame($dark($renderer->png($urls->forToken($old))), $qr($oldPdf), 'contrôle : le PDF téléchargé avant portait l\'ancien QR (désormais en 410)');
        self::assertNotSame($qr($oldPdf), $qr($newPdf));
        self::assertStringContainsString('nefonctionneraplus', (string) preg_replace('/\s+/u', '', $newPdf->text()), 'le PDF dit que son QR cesse de fonctionner après un remplacement');
        foreach ([$old, $new] as $token) {
            self::assertStringNotContainsString($token, $newPdf->text(), 'le jeton n\'est jamais écrit en clair dans le PDF');
        }
    }

    // ------------------------------------------------------------------ validations

    public function testTheReasonTheConfirmationAndTheCommentAreEnforcedAndNothingIsWrittenOtherwise(): void
    {
        $this->as($this->admin);
        $token = $this->activeToken();

        $this->submit(['confirm_global' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choisissez le motif du remplacement', $this->html());

        $this->submit(['reason' => 'LOST']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Confirmez', $this->html());

        $this->submit(['reason' => 'OTHER', 'confirm_global' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('commentaire est obligatoire', $this->html());

        $this->submit($this->valid() + ['comment' => 'copie du QR : '.$token]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('aucun jeton', $this->html());
        self::assertStringNotContainsString($token, $this->html(), 'le jeton n\'est jamais recopié dans la page d\'erreur');

        self::assertSame(0, $this->revisions());
        self::assertSame($token, $this->activeToken());
    }

    public function testADoubleSubmissionOfTheSameFormReplacesOnce(): void
    {
        $this->as($this->admin);
        $crawler = $this->client->request('GET', $this->url('/qr'));
        $fields = $this->valid() + ['_token' => (string) $crawler->filter('input[name=_token]')->attr('value'), 'operation_id' => (string) $crawler->filter('input[name=operation_id]')->attr('value')];

        $this->client->request('POST', $this->url('/qr'), $fields);
        self::assertResponseRedirects($this->url());
        $token = $this->activeToken();
        $this->client->request('POST', $this->url('/qr'), $fields);

        self::assertResponseRedirects($this->url());
        $this->client->followRedirect();
        self::assertStringContainsString('avait déjà été effectué', $this->html());
        self::assertSame(1, $this->revisions());
        self::assertSame($token, $this->activeToken());
        self::assertCount(2, $this->tokens());
    }

    public function testASecondReplacementWithinTheHourAsksForTheReferenceAndKeepsTheInput(): void
    {
        $this->as($this->admin);
        $this->submit($this->valid());
        $reference = (string) $this->em()->getConnection()->fetchOne('SELECT reference FROM permit_permit');

        $this->client->request('GET', $this->url('/qr'));
        self::assertStringContainsString('Référence du laissez-passer (ressaisie)', $this->html());
        $this->submit($this->valid() + ['comment' => 'Mon commentaire conservé']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('ressaisissez la référence', $this->html());
        self::assertStringContainsString('Mon commentaire conservé', $this->html());
        self::assertSame(1, $this->revisions());

        $this->submit($this->valid() + ['reference' => $reference]);

        self::assertResponseRedirects($this->url());
        self::assertSame(2, $this->revisions());
        $this->client->followRedirect();
        self::assertStringContainsString('rapproché du précédent', $this->html());
    }

    public function testWithoutAnEmailTheAgentIsToldHowToDeliverTheNewQr(): void
    {
        $noEmail = $this->approve(null, '0611223344');
        $this->as($this->admin);
        $crawler = $this->client->request('GET', $this->url('/qr', null, $noEmail));
        $this->client->request('POST', $this->url('/qr', null, $noEmail), $this->valid() + ['_token' => (string) $crawler->filter('input[name=_token]')->attr('value'), 'operation_id' => (string) $crawler->filter('input[name=operation_id]')->attr('value')]);

        self::assertResponseRedirects($this->url('', null, $noEmail));
        $this->client->followRedirect();
        self::assertStringContainsString('Aucune adresse e-mail', $this->html());
        self::assertStringContainsString('Liens et notification du bénéficiaire', $this->html());
        self::assertStringContainsString('PDF à jour', $this->html());
    }

    // ------------------------------------------------------------------ états de l'événement

    public function testASuspendedEventAllowsTheReplacementAndAClosedEventBlocksIt(): void
    {
        $this->as($this->admin);
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");
        $this->submit($this->valid());
        self::assertResponseRedirects($this->url());
        self::assertSame(1, $this->revisions());

        $this->sql("UPDATE event_event SET status = 'CLOSED'");
        $this->client->request('GET', $this->url('/qr'));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('clos', $this->html());
        $this->client->request('POST', $this->url('/qr'), $this->valid() + ['_token' => 'x', 'operation_id' => 'x']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->revisions());
    }

    // ------------------------------------------------------------------ sécurité

    public function testRolesPerimeterAndCsrfAreEnforcedWithAUniformRefusal(): void
    {
        $before = $this->activeToken();
        foreach ([$this->instructor, $this->manager] as $user) {
            $this->as($user);
            $this->client->request('GET', $this->url('/qr'));
            self::assertResponseStatusCodeSame($user === $this->instructor ? 409 : 403, 'rôle insuffisant');
            $this->client->request('POST', $this->url('/qr'), $this->valid() + ['_token' => 'x', 'operation_id' => 'x']);
            self::assertContains($this->client->getResponse()->getStatusCode(), [403, 409]);
        }
        $this->as($this->outsider);
        $this->client->request('GET', $this->url('/qr'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->url('/qr'), $this->valid() + ['_token' => 'x', 'operation_id' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->as($this->admin);
        $this->client->request('GET', $this->url('/qr', $this->partnerId));
        self::assertResponseStatusCodeSame(404, 'identifiant de commune forcé dans l\'URL');

        $this->client->request('POST', $this->url('/qr'), $this->valid() + ['_token' => 'forgé', 'operation_id' => '0199aaaa-0000-7000-8000-000000000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le formulaire a expiré', $this->html());
        self::assertSame($before, $this->activeToken());
        self::assertSame(0, $this->revisions());
    }

    public function testTheOtherCommuneSeesANeutralLineAndNeverTheReasonCommentOrAgent(): void
    {
        $this->as($this->admin);
        $this->submit($this->valid() + ['reason' => 'OTHER', 'comment' => self::COMMENT]);

        $this->as($this->partnerAdmin);
        $this->client->request('GET', $this->url('', $this->partnerId));
        self::assertResponseIsSuccessful();
        $html = $this->html();
        self::assertStringContainsString('QR code remplacé', $html, 'ligne neutre');
        self::assertStringContainsString($this->organizerParticipation->municipality()->name(), $html);
        foreach ([self::COMMENT, 'Autre motif', 'OTHER', 'Remplacement n°', $this->activeToken()] as $leak) {
            self::assertStringNotContainsString($leak, $html, 'aucune fuite intercommunale : '.$leak);
        }
    }

    // ------------------------------------------------------------------ confidentialité de la page publique après clôture

    /**
     * Décision définitive du 10 novembre 2026 (remplace le constat L5-21) : après la clôture, le QR en vigueur n'affiche qu'un statut neutre « Événement terminé » (ni nom, ni
     * prénom, ni plaque, ni zone) ; un jeton retiré répond toujours 410 sans donnée.
     */
    public function testAfterTheEventIsClosedTheCurrentQrShowsOnlyANeutralStatusWhileARetiredOneStaysGone(): void
    {
        $this->as($this->admin);
        $old = $this->activeToken();
        $this->submit($this->valid());
        $new = $this->activeToken();
        $this->sql("UPDATE event_event SET status = 'CLOSED', closed_at = UTC_TIMESTAMP()");

        $this->client->request('GET', '/v/'.$new);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('>Événement terminé<', $this->html());
        foreach (['GH-482-KT', 'MARTIN', 'Martin', 'Camille', 'zones actives', $this->organizerParticipation->municipality()->name()] as $leak) {
            self::assertStringNotContainsString($leak, mb_strtoupper($leak) === $leak ? mb_strtoupper($this->html()) : $this->html(), 'après clôture : '.$leak);
        }

        $this->client->request('GET', '/v/'.$old);
        self::assertResponseStatusCodeSame(410);
        self::assertStringNotContainsString('GH-482-KT', $this->html());
    }
}
