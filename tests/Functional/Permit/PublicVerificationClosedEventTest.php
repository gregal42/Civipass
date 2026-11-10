<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\UI\Http\PublicVerificationHeadersListener;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Vérification publique après la CLÔTURE de l'événement (décision définitive du 10 novembre 2026) : statut neutre « Événement terminé » UNIQUEMENT, jamais le nom, le
 * prénom, les plaques, les zones, la référence, le nom de l'événement, les communes ni une autre donnée du laissez-passer ; règle côté serveur (lecteur métier), donc la même
 * pour toute représentation demandée (HTML, JSON demandé, suffixe, barre finale, en-têtes de négociation). Inchangés : ancien QR remplacé (410 sans donnée), QR inconnu ou mal
 * formé (404 neutre), événement suspendu (statut de suspension, aucun droit présenté comme utilisable). Une réponse « événement terminé » n'est ni un échec compté ni une redirection.
 * L'interrupteur public n'est activé qu'ICI, dans cet environnement de test isolé.
 */
#[Group('requires-request-schema')]
final class PublicVerificationClosedEventTest extends PublicRequestTestCase
{
    private const ADDRESS = '12 rue SENTINELLE des Lilas';
    private const EMAIL = 'sentinelle.secrete@exemple.test';
    private const PHONE = '0611223344';
    private const PLATES = ['GH-482-KT', 'AB-123-CD'];
    private const ORGANIZER_LABEL = 'Zone organisatrice nord';
    private const PARTNER_LABEL = 'Zone partenaire centre';

    private User $instructor;
    private string $organizerId;
    private string $requestId;
    private ?string $previousSwitch = null;

    protected function setUp(): void
    {
        $this->previousSwitch = $_SERVER['PUBLIC_VERIFICATION_ENABLED'] ?? null;
        self::switchTo('1');
        parent::setUp();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $this->organizerId = $organizer->id()->toRfc4122();
        $this->instructor = UserBuilder::aUser()->build();
        $partnerAgent = UserBuilder::aUser()->build();
        $this->save(
            $this->instructor,
            $partnerAgent,
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($partnerAgent, $partner)->withRoles(Role::Instructor)->build(),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => self::ORGANIZER_LABEL, 'id' => $this->organizerZone->id()->toBinary()]);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => self::PARTNER_LABEL, 'id' => $this->partnerZone->id()->toBinary()]);
        $this->requestId = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', self::ADDRESS, self::EMAIL, self::PHONE, 'private_person', 'home', null, TravelMode::Vehicle, self::PLATES, [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], true),
            sprintf('closed-test-%016d', random_int(1, 999999)),
        )->requestId;
        $instruction = static::getContainer()->get(MunicipalInstructionService::class);
        $instruction->approve($this->managed($this->instructor), $this->organizerId, $this->requestId, ApprovalDuration::untilClosure());
        $instruction->approve($this->managed($partnerAgent), $partner->id()->toRfc4122(), $this->requestId, ApprovalDuration::untilClosure());
    }

    protected function tearDown(): void
    {
        self::switchTo($this->previousSwitch ?? '0');
        parent::tearDown();
    }

    private static function switchTo(string $value): void
    {
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = $value;
        putenv('PUBLIC_VERIFICATION_ENABLED='.$value);
    }

    private function managed(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function token(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token WHERE active_permit_id IS NOT NULL');
    }

    private function close(): void
    {
        $this->sql("UPDATE event_event SET status = 'CLOSED', closed_at = UTC_TIMESTAMP()");
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** @return list<string> tout ce que la page publique ne doit plus jamais dire après la clôture */
    private function personalData(): array
    {
        return [
            'Martin', 'MARTIN', 'Camille', 'CAMILLE', ...self::PLATES, 'GH482KT', 'AB123CD', self::ORGANIZER_LABEL, self::PARTNER_LABEL, self::ADDRESS, self::EMAIL, self::PHONE,
            'LP-', $this->event->name(), $this->organizerParticipation->municipality()->name(), $this->partnerParticipation->municipality()->name(), $this->requestId,
            'zones actives', 'Valide actuellement', 'cp-verdict', 'Droits actifs', 'Droits révoqués', 'Droits expirés',
        ];
    }

    private function assertNeutralClosedPage(string $html, string $context): void
    {
        self::assertStringContainsString('>Événement terminé<', $html, $context);
        self::assertStringContainsString('ne donne plus aucune information', $html, $context);
        foreach ([...$this->personalData(), $this->token()] as $leak) {
            self::assertStringNotContainsString($leak, $html, $context.' : donnée interdite après clôture : '.$leak);
        }
        $headers = $this->client->getResponse()->headers;
        self::assertSame('no-store, private', $headers->get('Cache-Control'), $context);
        self::assertSame(PublicVerificationHeadersListener::CSP, $headers->get('Content-Security-Policy'), $context);
        self::assertSame('noindex, nofollow, noarchive', $headers->get('X-Robots-Tag'), $context);
        self::assertFalse($headers->has('Location'), $context.' : aucune redirection');
        self::assertFalse($headers->has('Set-Cookie'), $context.' : aucun cookie');
    }

    public function testBeforeTheClosureThePageShowsTheDataAndAfterItOnlyANeutralStatus(): void
    {
        $token = $this->token();
        $this->client->request('GET', '/v/'.$token);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('GH-482-KT', $this->html(), 'témoin : avant la clôture la page est complète');
        self::assertStringContainsString('MARTIN', mb_strtoupper($this->html()));

        $this->close();
        $this->client->request('GET', '/v/'.$token);

        self::assertResponseStatusCodeSame(200);
        $this->assertNeutralClosedPage($this->html(), 'après clôture');
        self::assertSame($token, $this->token(), 'la clôture ne change pas le QR');
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function representations(): iterable
    {
        yield 'HTML' => ['', []];
        yield 'barre finale' => ['/', []];
        yield 'JSON demandé par Accept' => ['', ['HTTP_ACCEPT' => 'application/json']];
        yield 'JSON et HTML' => ['', ['HTTP_ACCEPT' => 'application/json, text/html;q=0.8']];
        yield 'XML demandé' => ['', ['HTTP_ACCEPT' => 'application/xml']];
        yield 'PDF demandé' => ['', ['HTTP_ACCEPT' => 'application/pdf']];
        yield 'requête AJAX' => ['', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']];
        yield 'langue anglaise' => ['', ['HTTP_ACCEPT_LANGUAGE' => 'en']];
        yield 'Turbo' => ['', ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']];
    }

    /** @param array<string, string> $server */
    #[DataProvider('representations')]
    public function testNoRepresentationOfAClosedEventCarriesAnyPersonalData(string $suffix, array $server): void
    {
        $token = $this->token();
        $this->close();

        $this->client->request('GET', '/v/'.$token.$suffix, [], [], $server);

        self::assertResponseStatusCodeSame(200);
        $this->assertNeutralClosedPage($this->html(), 'représentation '.json_encode($server, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'), 'aucune représentation JSON n\'existe');
    }

    public function testASuffixedOrQueriedAddressNeverLeaksEither(): void
    {
        $token = $this->token();
        $this->close();

        foreach (['/v/'.$token.'.json', '/v/'.$token.'/data', '/api/v/'.$token, '/v/'.$token.'/pdf'] as $path) {
            $this->client->request('GET', $path);
            self::assertContains($this->client->getResponse()->getStatusCode(), [404, 405], $path);
            foreach ($this->personalData() as $leak) {
                self::assertStringNotContainsString($leak, $this->html(), $path.' : '.$leak);
            }
        }
        $this->client->request('GET', '/v/'.$token.'?format=json&debug=1');
        self::assertResponseStatusCodeSame(200);
        $this->assertNeutralClosedPage($this->html(), 'paramètres de requête');
    }

    public function testAnOldReplacedQrStaysGoneWithNoDataAndAnUnknownQrStaysNeutralAfterTheClosure(): void
    {
        $old = $this->token();
        $this->sql('UPDATE permit_verification_token SET active_permit_id = NULL, retired_at = UTC_TIMESTAMP() WHERE token = :t', ['t' => $old]);
        $this->close();

        $this->client->request('GET', '/v/'.$old);
        self::assertResponseStatusCodeSame(410);
        self::assertStringContainsString('Ce QR code a été remplacé', $this->html());
        foreach ($this->personalData() as $leak) {
            self::assertStringNotContainsString($leak, $this->html(), 'QR remplacé : '.$leak);
        }
        self::assertStringNotContainsString('Événement terminé', $this->html(), 'le QR remplacé n\'annonce pas l\'état de l\'événement');

        foreach ([str_repeat('a', 32), 'pas-un-jeton'] as $unknown) {
            $this->client->request('GET', '/v/'.$unknown);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('Événement terminé', $this->html(), 'un QR inconnu ne révèle rien de l\'événement');
        }
    }

    public function testAClosedEventResponseIsNeitherARedirectionNorACountedFailure(): void
    {
        $token = $this->token();
        $this->close();

        // bien plus que le plafond de jetons inconnus (20 par défaut) : une réponse « événement terminé » n'est pas un échec
        foreach (range(1, 30) as $i) {
            $this->client->request('GET', '/v/'.$token);
            self::assertResponseStatusCodeSame(200, 'consultation '.$i);
        }
    }

    public function testASuspendedEventShowsASuspensionStatusAndNoRightAsUsable(): void
    {
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");

        $this->client->request('GET', '/v/'.$this->token());

        self::assertResponseStatusCodeSame(200);
        $html = $this->html();
        self::assertStringContainsString('>Droits suspendus<', $html);
        self::assertStringContainsString('0 zones actives sur 2', $html);
        self::assertStringNotContainsString('Valide actuellement', $html, 'aucun droit présenté comme utilisable pendant la suspension');
        self::assertStringNotContainsString('cp-verdict--valid', $html);
        self::assertStringNotContainsString('>Événement terminé<', $html);
    }
}
