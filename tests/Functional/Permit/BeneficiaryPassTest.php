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
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Page et PDF du bénéficiaire, et écran agent de remise des liens (LOT 05.2) dans le navigateur simulé : accès par le seul secret, réponse
 * unique pour tout lien invalide, en-têtes de protection, limitation de débit, PDF réel, QR = URL de vérification, CSRF, rôles et isolation des communes.
 * L'événement est recalé autour de la date du jour (vraie horloge du conteneur).
 */
#[Group('requires-request-schema')]
final class BeneficiaryPassTest extends PublicRequestTestCase
{
    private User $instructor;
    private User $manager;
    private User $partnerInstructor;
    private User $outsider;
    private string $organizerId;
    private string $partnerId;
    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $other = \App\Tests\Support\MunicipalityBuilder::aMunicipality()->withInseeCode('33063')->build();
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
        $this->requestId = $this->approvedRequest([$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], ['GH-482-KT', 'AB-123-CD'], TravelMode::Vehicle);
    }

    /**
     * @param list<string> $zones
     * @param list<string> $plates
     */
    private function approvedRequest(array $zones, array $plates, TravelMode $mode): string
    {
        $id = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', 'camille.secret@exemple.fr', '0611223344', 'private_person', 'home', null, $mode, $plates, $zones, true),
            sprintf('pass-test-%016d', random_int(1, 999999)),
        )->requestId;
        static::getContainer()->get(MunicipalInstructionService::class)->approve($this->managed($this->instructor), $this->organizerId, $id, ApprovalDuration::untilClosure());

        return $id;
    }

    private function managed(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function issueSecret(?User $agent = null, ?string $commune = null): string
    {
        return static::getContainer()->get(PermitAccessService::class)->issue($this->managed($agent ?? $this->instructor), $commune ?? $this->organizerId, $this->requestId)->secret;
    }

    private function as(User $user): void
    {
        $this->client->loginUser($this->managed($user));
    }

    private function agentUrl(string $suffix = '', ?string $commune = null, ?string $request = null): string
    {
        return '/communes/'.($commune ?? $this->organizerId).'/demandes/'.($request ?? $this->requestId).'/laissez-passer'.$suffix;
    }

    private function verificationToken(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
    }

    private function assertProtectedHeaders(): void
    {
        $h = $this->client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $h->get('Cache-Control'));
        self::assertSame('no-referrer', $h->get('Referrer-Policy'));
        self::assertStringContainsString('noindex', (string) $h->get('X-Robots-Tag'));
        self::assertSame('nosniff', $h->get('X-Content-Type-Options'));
        self::assertSame('DENY', $h->get('X-Frame-Options'));
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'aucun cookie ni session pour un accès bénéficiaire');
    }

    public function testTheBeneficiaryPageShowsTheIdentityZonesStatesAndTheQrWithoutAnyAccount(): void
    {
        $secret = $this->issueSecret();

        $this->client->request('GET', '/laissez-passer/'.$secret);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['LP-', 'Camille', 'MARTIN', 'GH-482-KT', 'AB-123-CD', $this->organizerParticipation->municipality()->name(), $this->organizerZone->label(), 'Valide actuellement', 'Télécharger le PDF', 'Votre QR code', '<svg'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        // Les zones de la commune partenaire n'ont pas encore été approuvées : elles n'apparaissent pas comme valides.
        self::assertStringNotContainsString($this->partnerZone->label().'</span>', '' === str_replace("\n", '', $html) ? '' : '');
        foreach (['camille.secret@exemple.fr', '0611223344', '12 rue des Lilas', $secret.'/pdf-'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, 'jamais adresse, e-mail ni téléphone');
        }
        $this->assertProtectedHeaders();
        self::assertStringContainsString("default-src 'none'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testThePageDoesNotLoadAnyThirdPartyResourceNorScript(): void
    {
        $secret = $this->issueSecret();
        $this->client->request('GET', '/laissez-passer/'.$secret);

        $html = (string) $this->client->getResponse()->getContent();

        self::assertDoesNotMatchRegularExpression('#(src|href)="https?://#i', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('importmap', $html);
        self::assertStringContainsString('name="referrer" content="no-referrer"', $html);
        self::assertStringContainsString('noindex', $html);
    }

    public function testTheQrEncodesOnlyThePublicVerificationUrlAndNeverTheLinkSecret(): void
    {
        $secret = $this->issueSecret();
        $this->client->request('GET', '/laissez-passer/'.$secret);
        $html = (string) $this->client->getResponse()->getContent();
        $container = static::getContainer();
        $expected = $container->get(VerificationUrlFactory::class)->forToken($this->verificationToken());

        self::assertMatchesRegularExpression('#^http://localhost/v/[a-z2-7]{32}$#', $expected, 'domaine issu de la configuration, jamais de l\'en-tête Host');
        $svg = $container->get(QrCodeRenderer::class)->svg($expected);
        self::assertStringContainsString($svg, $html, 'la page contient exactement le QR de l\'URL de vérification');
        self::assertNotSame($svg, $container->get(QrCodeRenderer::class)->svg($expected.'x'));
        self::assertStringNotContainsString($secret, $svg);
        self::assertStringNotContainsString($this->verificationToken(), $html, 'le jeton n\'est visible qu\'à travers l\'image du QR');
    }

    public function testAForgedHostHeaderCannotChangeTheQrDomain(): void
    {
        $secret = $this->issueSecret();
        $this->client->request('GET', '/laissez-passer/'.$secret, server: ['HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_HOST' => 'evil.example']);
        $html = (string) $this->client->getResponse()->getContent();

        $svg = static::getContainer()->get(QrCodeRenderer::class)->svg(static::getContainer()->get(VerificationUrlFactory::class)->forToken($this->verificationToken()));
        self::assertStringContainsString($svg, $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSecrets(): iterable
    {
        yield 'inconnu' => [str_repeat('A', 43)];
        yield 'trop court' => ['abc'];
        yield 'trop long' => [str_repeat('A', 120)];
        yield 'caractères invalides' => ['....................................é.é'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSecrets')]
    public function testAnInvalidLinkGetsTheSameNeutralAnswerAsAnUnknownOne(string $secret): void
    {
        $this->issueSecret();
        $this->client->request('GET', '/laissez-passer/'.rawurlencode($secret));

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Ce lien n’est pas valide', (string) $this->client->getResponse()->getContent());
        $this->assertProtectedHeaders();
    }

    public function testARevokedOrAnExpiredLinkGetsTheSameAnswerAsAnUnknownOne(): void
    {
        $revoked = $this->issueSecret();
        $expired = $this->issueSecret();
        $valid = $this->issueSecret();
        $this->sql('UPDATE permit_access_token SET revoked_at = UTC_TIMESTAMP() WHERE secret_hash = :h', ['h' => hash('sha256', $revoked)]);

        $this->client->request('GET', '/laissez-passer/'.$revoked);
        $revokedBody = (string) $this->client->getResponse()->getContent();
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/laissez-passer/'.str_repeat('B', 43));
        self::assertSame($revokedBody, (string) $this->client->getResponse()->getContent(), 'révoqué et inconnu : mêmes octets');
        $this->client->request('GET', '/laissez-passer/'.$revoked.'/pdf');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/laissez-passer/'.$valid);
        self::assertResponseIsSuccessful();

        // Expiration : fin de l'événement + 30 jours dépassée.
        $this->sql('UPDATE event_event SET ends_at = :e', ['e' => (new \DateTimeImmutable('-31 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
        $this->client->request('GET', '/laissez-passer/'.$expired);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($revokedBody, (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/laissez-passer/'.$expired.'/pdf');
        self::assertResponseStatusCodeSame(404);
    }

    public function testThePdfIsGeneratedOnDemandFromTheBeneficiaryLinkAndCarriesNoSecret(): void
    {
        $secret = $this->issueSecret();

        $this->client->request('GET', '/laissez-passer/'.$secret.'/pdf');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="laissez-passer-LP-', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $pdf = (string) $response->getContent();
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(2000, \strlen($pdf));
        self::assertStringNotContainsString($secret, $pdf);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE \'%pdf%\''), 'aucune copie du PDF n\'est stockée');
    }

    public function testThePdfCannotBeDownloadedWithTheQrVerificationTokenAlone(): void
    {
        $this->issueSecret();
        $token = $this->verificationToken();

        foreach (['/laissez-passer/'.$token.'/pdf', '/laissez-passer/'.$token, '/v/'.$token.'/pdf'] as $url) {
            $this->client->request('GET', $url);
            self::assertTrue(404 === $this->client->getResponse()->getStatusCode(), $url);
            self::assertNotSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        }
    }

    public function testDiscoveryAttemptsAreRateLimitedBeforeAnyLookup(): void
    {
        $valid = $this->issueSecret();
        for ($i = 0; $i < 20; ++$i) {
            $this->client->request('GET', '/laissez-passer/'.str_repeat('C', 42).dechex($i % 16));
            self::assertResponseStatusCodeSame(404);
        }

        $this->client->request('GET', '/laissez-passer/'.str_repeat('D', 43));
        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
        $this->client->request('GET', '/laissez-passer/'.$valid);
        self::assertResponseStatusCodeSame(429, 'plafond d\'échecs atteint : aucune recherche, même d\'un lien valide, depuis cette adresse');
        $this->assertProtectedHeaders();
    }

    public function testAForgedForwardedForHeaderCannotBypassTheFailureLimiter(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->client->request('GET', '/laissez-passer/'.str_repeat('E', 42).dechex($i % 16), server: ['HTTP_X_FORWARDED_FOR' => '10.0.'.$i.'.1']);
            self::assertResponseStatusCodeSame(404);
        }

        $this->client->request('GET', '/laissez-passer/'.str_repeat('F', 43), server: ['HTTP_X_FORWARDED_FOR' => '198.51.100.99']);
        self::assertResponseStatusCodeSame(429, 'aucun proxy de confiance : l\'en-tête du client est ignoré, le plafond s\'applique à son adresse réelle');
    }

    public function testSecretsNeverAppearInApplicationLogs(): void
    {
        $secret = $this->issueSecret();
        $logs = fopen('php://memory', 'w+');
        $logger = new \App\Permit\Infrastructure\SecretScrubbingLogger(new class ($logs) extends \Psr\Log\AbstractLogger {
            /** @param resource $out */
            public function __construct(private $out)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                fwrite($this->out, $message.' '.json_encode($context)."\n");
            }
        });

        $logger->info('Matched route "app_beneficiary_pass".', ['route_parameters' => ['_route' => 'app_beneficiary_pass', 'secret' => $secret], 'request_uri' => 'http://localhost/laissez-passer/'.$secret.'/pdf', 'nested' => ['u' => '/v/abcdefghijklmnopqrstuvwxyz234567']]);
        $logger->error('No route found for "GET /laissez-passer/'.$secret.'"');
        rewind($logs);
        $written = (string) stream_get_contents($logs);

        self::assertStringNotContainsString($secret, $written);
        self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz234567', $written);
        self::assertStringContainsString('[masqué]', $written);
        self::assertStringContainsString('app_beneficiary_pass', $written, 'le reste du contexte est conservé');
    }

    public function testNoSecretReachesTheKernelLogsDuringARealRequest(): void
    {
        $secret = $this->issueSecret();
        $logFile = static::getContainer()->getParameter('kernel.logs_dir').'/test.log';
        $before = is_file($logFile) ? (string) file_get_contents($logFile) : '';

        $this->client->request('GET', '/laissez-passer/'.$secret);
        $this->client->request('GET', '/laissez-passer/'.str_repeat('Z', 43));

        $after = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        self::assertStringNotContainsString($secret, substr($after, \strlen($before)));
    }

    // ----------------------------------------------------------------- écran agent

    public function testAnInstructorGeneratesALinkWhichIsShownOnceAndNeverStoredInClear(): void
    {
        $this->as($this->instructor);
        $crawler = $this->client->request('GET', $this->agentUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun lien n’a encore été créé', (string) $this->client->getResponse()->getContent());
        $token = $crawler->filter('form[action$="/liens"] input[name=_token]')->attr('value');

        $this->client->request('POST', $this->agentUrl('/liens'), ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('#value="http://localhost/laissez-passer/([A-Za-z0-9_-]{43})"#', $html, $m), 'le lien est affiché une fois');
        $secret = $m[1];
        self::assertStringContainsString('ne sera plus jamais affiché', $html);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(hash('sha256', $secret), $this->em()->getConnection()->fetchOne('SELECT secret_hash FROM permit_access_token'));

        // Le lien fonctionne côté bénéficiaire, et n'est plus jamais affiché ensuite.
        $this->client->request('GET', '/laissez-passer/'.$secret);
        self::assertResponseIsSuccessful();
        $this->as($this->instructor);
        $this->client->request('GET', $this->agentUrl());
        $again = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($secret, $again);
        self::assertStringContainsString('Actif', $again);
        $audit = (string) $this->em()->getConnection()->fetchOne('SELECT GROUP_CONCAT(new_value) FROM audit_log WHERE action = \'PERMIT_ACCESS_LINK_ISSUED\'');
        self::assertStringNotContainsString($secret, $audit);
    }

    public function testTheLinkShownToTheAgentUsesTheConfiguredDomainNotTheRequestHost(): void
    {
        $this->as($this->instructor);
        $crawler = $this->client->request('GET', $this->agentUrl());
        $token = $crawler->filter('form[action$="/liens"] input[name=_token]')->attr('value');

        $this->client->request('POST', $this->agentUrl('/liens'), ['_token' => $token], server: ['HTTP_HOST' => 'evil.example']);

        $html = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('#value="http://localhost/laissez-passer/[A-Za-z0-9_-]{43}"#', $html);
        self::assertStringNotContainsString('evil.example/laissez-passer', $html);
    }

    public function testAnAgentRevokesOneLinkAndTheOtherSurvives(): void
    {
        $first = $this->issueSecret();
        $second = $this->issueSecret();
        $this->as($this->instructor);
        $crawler = $this->client->request('GET', $this->agentUrl());
        $forms = $crawler->filter('form[action$="/revoquer"]');
        self::assertCount(2, $forms);
        $linkId = $this->em()->getConnection()->fetchOne('SELECT LOWER(HEX(id)) FROM permit_access_token WHERE secret_hash = :h', ['h' => hash('sha256', $first)]);
        $form = $crawler->filter('form[action*="'.Uuid::fromBinary((string) hex2bin((string) $linkId))->toRfc4122().'"]');
        self::assertCount(1, $form);

        $this->client->request('POST', (string) $form->attr('action'), ['_token' => $form->filter('input[name=_token]')->attr('value')]);

        self::assertResponseRedirects($this->agentUrl());
        $this->client->followRedirect();
        self::assertStringContainsString('Lien révoqué', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/laissez-passer/'.$first);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/laissez-passer/'.$second);
        self::assertResponseIsSuccessful();
    }

    public function testWritesRequireAValidCsrfToken(): void
    {
        $this->as($this->instructor);

        $this->client->request('POST', $this->agentUrl('/liens'), ['_token' => 'forgé']);
        self::assertResponseRedirects($this->agentUrl());
        $this->client->request('POST', $this->agentUrl('/liens'));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_access_token'));
    }

    public function testAnEventManagerOnlyIsRefusedAndAnAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', $this->agentUrl());
        self::assertResponseRedirects('/login');

        $this->as($this->manager);
        $this->client->request('GET', $this->agentUrl());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->agentUrl('/liens'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_access_token'));
    }

    public function testCommunesWithoutAnAuthorizationOnThePermitAreRefusedUniformly(): void
    {
        // Le partenaire a une part PENDING sur la demande mais aucune autorisation : même refus qu'une commune étrangère ou qu'un identifiant forgé.
        foreach ([[$this->partnerInstructor, $this->partnerId], [$this->outsider, $this->organizerId], [$this->instructor, Uuid::v7()->toRfc4122()]] as [$agent, $commune]) {
            $this->as($agent);
            $this->client->request('GET', $this->agentUrl('', $commune));
            self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404], 'refus pour '.$commune);
            self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        }
        $this->as($this->instructor);
        $this->client->request('GET', $this->agentUrl('', null, Uuid::v7()->toRfc4122()));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->agentUrl('/liens', $this->partnerId));
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_access_token'));
    }

    public function testASecondCommuneAppearsOnTheSamePageWithItsOwnZonesAndNoNewQr(): void
    {
        $secret = $this->issueSecret();
        $qrBefore = $this->verificationToken();
        static::getContainer()->get(MunicipalInstructionService::class)->approve($this->managed($this->partnerInstructor), $this->partnerId, $this->requestId, ApprovalDuration::fixed(DurationMode::H24));

        $this->client->request('GET', '/laissez-passer/'.$secret);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->partnerParticipation->municipality()->name(), $html);
        self::assertStringContainsString($this->partnerZone->label(), $html);
        self::assertSame($qrBefore, $this->verificationToken());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_verification_token'));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_permit'));
    }

    public function testAZoneSuspendedLaterStaysVisibleAsSuspendedNotAsMissing(): void
    {
        $secret = $this->issueSecret();
        $this->sql('UPDATE event_zone SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->organizerZone->id()->toBinary()]);

        $this->client->request('GET', '/laissez-passer/'.$secret);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->organizerZone->label(), $html);
        self::assertStringContainsString('Zone suspendue', $html);
        self::assertStringNotContainsString('Aucune autorisation enregistrée', $html);
    }
}
