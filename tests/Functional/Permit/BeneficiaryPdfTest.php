<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\PermitAccessService;
use App\Permit\Application\QrCodeRenderer;
use App\Permit\Application\VerificationUrlFactory;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\PdfInspector;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * PDF RÉELLEMENT généré (LOT 05.5.1, DM-119 §6), lu octet par octet et non via son gabarit : l'avertissement « seul le contrôle du QR code fait
 * foi » y est écrit, aucune affirmation figée de validité, un QR exploitable (les pixels intégrés sont exactement ceux du QR de l'URL de vérification
 * de CE laissez-passer, et pas ceux d'un autre), une autorisation révoquée n'y est pas listée, et aucun PDF n'est généré quand il ne reste aucun droit
 * actif ou à venir.
 */
#[Group('requires-request-schema')]
final class BeneficiaryPdfTest extends PublicRequestTestCase
{
    private const EMAIL = 'camille.secret@exemple.fr';
    private const ORGANIZER_LABEL = 'Zone organisatrice nord';
    private const PARTNER_LABEL = 'Zone partenaire centre';

    private User $instructor;
    private User $partnerInstructor;
    private string $organizerId;
    private string $partnerId;
    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em()->getConnection()->createSchemaManager()->tablesExist(['permit_revision'])) {
            self::markTestSkipped('Schéma des révisions non migré (LOT 05.5.1).');
        }
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $this->organizerId = $organizer->id()->toRfc4122();
        $this->partnerId = $partner->id()->toRfc4122();
        [$this->instructor, $this->partnerInstructor] = [UserBuilder::aUser()->build(), UserBuilder::aUser()->build()];
        $this->save(
            $this->instructor,
            $this->partnerInstructor,
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->partnerInstructor, $partner)->withRoles(Role::Instructor)->build(),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => self::ORGANIZER_LABEL, 'id' => $this->organizerZone->id()->toBinary()]);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => self::PARTNER_LABEL, 'id' => $this->partnerZone->id()->toBinary()]);
        $this->requestId = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', self::EMAIL, '0611223344', 'private_person', 'home', null, TravelMode::Vehicle, ['GH-482-KT', 'AB-123-CD'], [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], true),
            sprintf('pdf-test-%016d', random_int(1, 999999)),
        )->requestId;
        $instruction = static::getContainer()->get(MunicipalInstructionService::class);
        $instruction->approve($this->managed($this->instructor), $this->organizerId, $this->requestId, ApprovalDuration::untilClosure());
        $instruction->approve($this->managed($this->partnerInstructor), $this->partnerId, $this->requestId, ApprovalDuration::untilClosure());
    }

    private function managed(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function secret(): string
    {
        return static::getContainer()->get(PermitAccessService::class)->issue($this->managed($this->instructor), $this->organizerId, $this->requestId)->secret;
    }

    private function token(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
    }

    private function revoke(User $agent, string $commune): void
    {
        static::getContainer()->get(AuthorizationRevocationService::class)->revoke($this->managed($agent), $commune, $this->requestId, 'DUPLICATE', null, true);
    }

    private function pdf(string $secret): PdfInspector
    {
        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));

        return new PdfInspector((string) $this->client->getResponse()->getContent());
    }

    private static function squash(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }

    // ------------------------------------------------------------------ contenu écrit

    public function testTheGeneratedPdfSaysOnlyTheQrCheckIsAuthoritativeAndCarriesNoFrozenValidity(): void
    {
        $text = $this->pdf($this->secret())->text();

        self::assertNotSame('', $text, 'le texte du PDF doit être lisible par l\'inspecteur');
        foreach (['Seul le contrôle du QR code fait foi', 'LP-', 'Camille', 'MARTIN', 'GH-482-KT', 'AB-123-CD', self::ORGANIZER_LABEL, self::PARTNER_LABEL, $this->organizerParticipation->municipality()->name(), $this->partnerParticipation->municipality()->name()] as $expected) {
            self::assertStringContainsString(self::squash($expected), $text, 'écrit dans le PDF : '.$expected);
        }
        foreach (['Valide actuellement', 'Valide', 'Expirée', 'Révoquée', 'Pas encore valide', 'Zone suspendue', 'Hors période', 'Droits valables', 'Jusqu', 'actuellement valable', self::EMAIL, '0611223344', '12 rue des Lilas'] as $forbidden) {
            self::assertStringNotContainsString(self::squash($forbidden), $text, 'aucune affirmation figée de validité ni donnée superflue : '.$forbidden);
        }
        self::assertStringNotContainsString($this->token(), $text, 'le jeton n\'est jamais écrit en clair');
    }

    public function testARevokedAuthorizationIsNotListedInANewPdf(): void
    {
        $secret = $this->secret();
        $this->revoke($this->instructor, $this->organizerId);

        $text = $this->pdf($secret)->text();

        self::assertStringContainsString(self::squash(self::PARTNER_LABEL), $text);
        self::assertStringContainsString(self::squash($this->partnerParticipation->municipality()->name()), $text);
        self::assertStringNotContainsString(self::squash(self::ORGANIZER_LABEL), $text, 'une commune qui a retiré son autorisation n\'est pas présentée comme concernée');
        self::assertStringNotContainsString(self::squash($this->organizerParticipation->municipality()->name()), $text);
        self::assertStringContainsString(self::squash('Seul le contrôle du QR code fait foi'), $text);
    }

    // ------------------------------------------------------------------ QR exploitable

    /** @return list<list<bool>> pixels sombres de l'image de référence (PNG du rendu) */
    private function darkPixels(string $png): array
    {
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
    }

    public function testTheEmbeddedQrIsExactlyTheVerificationQrOfThisPermitAndNotAnotherOne(): void
    {
        $inspector = $this->pdf($this->secret());
        $qrImages = array_values(array_filter($inspector->images(), static fn (array $i): bool => $i['width'] === $i['height'] && $i['width'] >= 300));
        self::assertCount(1, $qrImages, 'un seul QR, de taille lisible');
        $embedded = $qrImages[0]['dark'];
        $renderer = static::getContainer()->get(QrCodeRenderer::class);
        $urls = static::getContainer()->get(VerificationUrlFactory::class);

        $expected = $this->darkPixels($renderer->png($urls->forToken($this->token())));
        $other = $this->darkPixels($renderer->png($urls->forToken(str_repeat('a', 32))));

        self::assertSame($expected, $embedded, 'le QR intégré est, pixel pour pixel, celui de l\'URL de vérification de ce laissez-passer');
        self::assertNotSame($other, $embedded, 'contrôle négatif : le QR d\'un autre jeton est différent');
        $darkShare = array_sum(array_map(static fn (array $row): int => \count(array_filter($row)), $embedded)) / ($qrImages[0]['width'] ** 2);
        self::assertGreaterThan(0.2, $darkShare, 'un QR noir sur blanc contrasté');
        self::assertLessThan(0.7, $darkShare);
        self::assertFalse($embedded[0][0], 'zone calme blanche autour du QR');
    }

    // ------------------------------------------------------------------ refus de génération

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function nothingLeft(): iterable
    {
        yield 'toutes les autorisations révoquées' => ['revoked', 'Droits révoqués : aucun PDF', 'Seul le contrôle du QR code fait foi'];
        yield 'autorisations expirées' => ['expired', 'Droits terminés : aucun PDF', 'Seul le contrôle du QR code fait foi'];
    }

    /** LOT 05.6.2 : à la clôture le lien est fermé : ni page ni PDF, même réponse (404) qu'un lien inconnu. */
    public function testAClosedEventClosesTheLinkSoNoPdfIsGeneratedAtAll(): void
    {
        $secret = $this->secret();
        $this->sql("UPDATE event_event SET status = 'CLOSED', closed_at = UTC_TIMESTAMP()");

        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');

        self::assertResponseStatusCodeSame(404);
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsNotWith('%PDF', $body);
        self::assertStringContainsString('Ce lien n’est pas valide', $body);
        self::assertStringNotContainsString($secret, $body);
    }

    #[DataProvider('nothingLeft')]
    public function testNoPdfIsGeneratedWhenNoActiveOrFutureRightRemains(string $cause, string $title, string $warning): void
    {
        $secret = $this->secret();
        match ($cause) {
            'revoked' => array_map(fn (array $c) => $this->revoke(...$c), [[$this->instructor, $this->organizerId], [$this->partnerInstructor, $this->partnerId]]),
            'expired' => $this->sql("UPDATE permit_authorization SET duration_mode = 'CUSTOM', valid_from = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY), valid_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)"),
            default => self::fail('Cause inconnue : '.$cause),
        };

        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');

        self::assertResponseStatusCodeSame(409);
        self::assertNotSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsNotWith('%PDF', $body);
        self::assertStringContainsString($title, $body);
        self::assertStringContainsString($warning, $body);
        self::assertStringNotContainsString($secret, $body);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAPdfIsStillGeneratedWhenTheRightsAreOnlyUpcoming(): void
    {
        $secret = $this->secret();
        $this->sql("UPDATE permit_authorization SET duration_mode = 'CUSTOM', valid_from = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 DAY), valid_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 DAY)");

        $text = $this->pdf($secret)->text();

        self::assertStringContainsString(self::squash(self::PARTNER_LABEL), $text, 'un droit à venir reste un droit : le PDF est disponible');
    }
}
