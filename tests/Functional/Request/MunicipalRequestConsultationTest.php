<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Request\Application\Command\AttachmentReference;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Application\TemporaryUploadService;
use App\Request\Domain\TravelMode;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\SampleFiles as S;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Consultation municipale (incrément 4) : liste, fiche et téléchargement des justificatifs, en lecture seule. Monde : une demande
 * intercommunale (zones de l'organisatrice ET de la partenaire, un PDF commun) et des agents INSTRUCTOR par commune.
 */
#[Group('requires-request-schema')]
final class MunicipalRequestConsultationTest extends PublicRequestTestCase
{
    private User $instructorO;
    private User $instructorP;
    private User $adminO;
    private User $foreign;
    private User $mixed;
    private Municipality $foreignCommune;
    private string $requestId;
    private string $attachmentId;
    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sql('UPDATE event_zone SET label = :l WHERE id = UNHEX(:id)', ['l' => 'Rue des Tilleuls', 'id' => $this->hex($this->organizerZone->id())]);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = UNHEX(:id)', ['l' => 'Quai du Port', 'id' => $this->hex($this->partnerZone->id())]);
        // Les UPDATE ci-dessus vident l'EntityManager : les communes sont relues avant d'être rattachées aux adhésions.
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $this->foreignCommune = MunicipalityBuilder::aMunicipality()->withInseeCode('13055')->build();
        [$this->instructorO, $this->instructorP, $this->adminO, $this->foreign, $this->mixed] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 5));
        $this->save(
            $this->foreignCommune,
            $this->instructorO,
            $this->instructorP,
            $this->adminO,
            $this->foreign,
            $this->mixed,
            MembershipBuilder::aMembership($this->instructorO, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->instructorP, $partner)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->adminO, $organizer)->withRoles(Role::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->foreign, $this->foreignCommune)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->mixed, $organizer)->withRoles(Role::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->mixed, $partner)->withRoles(Role::Instructor)->build(),
        );
        $this->requestId = $this->deposit('Martin', 'Camille', [$this->organizerZone, $this->partnerZone], withPdf: true);
        $this->attachmentId = Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM request_permit_request_attachment'))->toRfc4122();
    }

    /**
     * @param list<\App\Event\Domain\EventZone> $zones
     */
    private function deposit(string $last, string $first, array $zones, bool $withPdf = false, string $category = 'private_person', ?\App\Event\Domain\Event $event = null): string
    {
        $attachments = [];
        if ($withPdf) {
            $stored = static::getContainer()->get(TemporaryUploadService::class)->store(S::temporaryFile(S::pdf()), 'attestation "domicile"/../secret.pdf');
            $attachments[] = new AttachmentReference($stored->temporaryKey, 'attestation "domicile"/../secret.pdf');
        }
        $submitted = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            ($event ?? $this->event)->id()->toRfc4122(),
            $last,
            $first,
            null,
            'camille@exemple.fr',
            null,
            $category,
            'home',
            null,
            TravelMode::Vehicle,
            ['GH-482-KT'],
            array_map(static fn ($z): string => $z->id()->toRfc4122(), $zones),
            true,
            null,
            $attachments,
        ), sprintf('test-attempt-%016d', ++$this->keys));
        $this->em()->clear();

        return $submitted->requestId;
    }

    private function hex(Uuid $id): string
    {
        return str_replace('-', '', $id->toRfc4122());
    }

    private function as(User $user): void
    {
        $this->client->loginUser($this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.'));
    }

    private function communeOf(User $user): string
    {
        return match ($user) {
            $this->instructorO, $this->adminO => $this->organizerParticipation->municipality()->id()->toRfc4122(),
            $this->foreign => $this->foreignCommune->id()->toRfc4122(),
            default => $this->partnerParticipation->municipality()->id()->toRfc4122(),
        };
    }

    private function listUrl(User $user, string $query = ''): string
    {
        return '/communes/'.$this->communeOf($user).'/demandes'.$query;
    }

    private function showUrl(User $user, ?string $requestId = null): string
    {
        return '/communes/'.$this->communeOf($user).'/demandes/'.($requestId ?? $this->requestId);
    }

    private function downloadUrl(User $user, ?string $requestId = null, ?string $attachmentId = null): string
    {
        return $this->showUrl($user, $requestId).'/justificatifs/'.($attachmentId ?? $this->attachmentId);
    }

    public function testAnonymousUsersAreSentToTheLogin(): void
    {
        $this->client->request('GET', $this->listUrl($this->instructorO));
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheInstructorSeesTheListWithOnlyTheZonesOfTheirOwnMunicipality(): void
    {
        $this->as($this->instructorO);
        $this->client->request('GET', $this->listUrl($this->instructorO));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'DEM-');
        self::assertSelectorTextContains('main', 'MARTIN Camille');
        self::assertSelectorTextContains('main', 'Particuliers');
        self::assertSelectorTextContains('main', 'À traiter');
        self::assertSelectorTextContains('main', 'Rue des Tilleuls');
        self::assertSelectorTextNotContains('main', 'Quai du Port');
        self::assertSelectorExists('a[href="'.$this->showUrl($this->instructorO).'"]');
    }

    public function testTheSameRequestIsSeenFromEachMunicipalityWithItsOwnZonesOnly(): void
    {
        $this->as($this->instructorP);
        $this->client->request('GET', $this->listUrl($this->instructorP));
        self::assertSelectorTextContains('main', 'Quai du Port');
        self::assertSelectorTextNotContains('main', 'Rue des Tilleuls');

        $this->client->request('GET', $this->showUrl($this->instructorP));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Quai du Port');
        self::assertSelectorTextNotContains('main', 'Rue des Tilleuls');
    }

    public function testTheDetailShowsTheDeclaredDataAndTheSharedAttachmentsWithoutAnyStorageDetail(): void
    {
        $this->as($this->instructorO);
        $this->client->request('GET', $this->showUrl($this->instructorO));
        $html = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        foreach (['MARTIN Camille', 'camille@exemple.fr', 'Particuliers', 'Accès au domicile', 'En véhicule', 'GH-482-KT', 'Rue des Tilleuls', 'À traiter', 'secret.pdf', 'PDF'] as $expected) {
            self::assertStringContainsString($expected, $html, $expected);
        }
        self::assertStringNotContainsString('Quai du Port', $html);
        self::assertSelectorExists('a[href="'.$this->downloadUrl($this->instructorO).'"]');
        $key = (string) $this->em()->getConnection()->fetchOne('SELECT storage_key FROM request_permit_request_attachment');
        self::assertStringNotContainsString($key, $html);
        self::assertStringNotContainsString('storage', $html);
        // Les justificatifs sont communs : la commune partenaire voit la même pièce.
        $this->as($this->instructorP);
        $this->client->request('GET', $this->showUrl($this->instructorP));
        self::assertSelectorExists('a[href="'.$this->downloadUrl($this->instructorP).'"]');
    }

    public function testAnAttachmentIsDownloadedFromPrivateStorageWithSafeHeaders(): void
    {
        $this->as($this->instructorP);
        $this->client->request('GET', $this->downloadUrl($this->instructorP));

        self::assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        self::assertSame('application/pdf', $headers->get('Content-Type'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringStartsWith('attachment;', (string) $headers->get('Content-Disposition'));
        self::assertStringNotContainsString('/', explode('filename=', (string) $headers->get('Content-Disposition'))[1] ?? '');
        self::assertSame(S::pdf(), $this->client->getInternalResponse()->getContent());
    }

    public function testAMemberWithoutTheInstructorRoleIsRefusedEverywhere(): void
    {
        $this->as($this->adminO);
        foreach ([$this->listUrl($this->adminO), $this->showUrl($this->adminO), $this->downloadUrl($this->adminO)] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testTheInstructorRoleIsCheckedInTheSelectedMunicipalityNotGlobally(): void
    {
        $this->as($this->mixed); // administrateur de l'organisatrice, instructeur de la partenaire seulement
        $organizer = $this->organizerParticipation->municipality()->id()->toRfc4122();
        $partner = $this->partnerParticipation->municipality()->id()->toRfc4122();

        foreach (['', '/'.$this->requestId, '/'.$this->requestId.'/justificatifs/'.$this->attachmentId] as $suffix) {
            $this->client->request('GET', '/communes/'.$organizer.'/demandes'.$suffix);
            self::assertResponseStatusCodeSame(403, 'organisatrice '.$suffix);
            $this->client->request('GET', '/communes/'.$partner.'/demandes'.$suffix);
            self::assertResponseIsSuccessful('partenaire '.$suffix);
        }
    }

    public function testAnotherMunicipalityCannotReachTheFileEvenWithTheRightIdentifiers(): void
    {
        $this->as($this->foreign);
        $foreignBase = '/communes/'.$this->foreignCommune->id()->toRfc4122().'/demandes';
        $organizerBase = '/communes/'.$this->organizerParticipation->municipality()->id()->toRfc4122().'/demandes';
        foreach ([
            $organizerBase, $organizerBase.'/'.$this->requestId, $organizerBase.'/'.$this->requestId.'/justificatifs/'.$this->attachmentId, // commune d'autrui
            $foreignBase.'/'.$this->requestId, $foreignBase.'/'.$this->requestId.'/justificatifs/'.$this->attachmentId, // sa commune, dossier d'autrui
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->client->request('GET', $foreignBase);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'MARTIN');
    }

    public function testForgedIdentifiersAndReferencesAreRefusedUniformly(): void
    {
        $other = $this->deposit('Durand', 'Lou', [$this->partnerZone], withPdf: true);
        $otherAttachment = Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM request_permit_request_attachment WHERE id <> UNHEX(:id)', ['id' => str_replace('-', '', $this->attachmentId)]))->toRfc4122();
        $this->as($this->instructorO);
        $reference = (string) $this->em()->getConnection()->fetchOne('SELECT public_reference FROM request_permit_request LIMIT 1');

        foreach ([
            $this->showUrl($this->instructorO, $other), // demande sans portion dans l'organisatrice
            $this->downloadUrl($this->instructorO, $other, $otherAttachment),
            $this->downloadUrl($this->instructorO, $this->requestId, $otherAttachment), // pièce d'une autre demande
            $this->downloadUrl($this->instructorO, $this->requestId, Uuid::v4()->toRfc4122()),
            $this->showUrl($this->instructorO, Uuid::v4()->toRfc4122()),
            $this->showUrl($this->instructorO, $reference), // une référence DEM n'est pas un identifiant
            $this->listUrl($this->instructorO, '?evenement='.Uuid::v4()->toRfc4122()),
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testAnInvitedOnlyParticipationGivesNoAccess(): void
    {
        $this->sql('UPDATE event_participation SET status = \'INVITED\' WHERE id = UNHEX(:id)', ['id' => $this->hex($this->partnerParticipation->id())]);
        $this->as($this->instructorP);
        $this->client->request('GET', $this->showUrl($this->instructorP));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->downloadUrl($this->instructorP));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->listUrl($this->instructorP));
        self::assertSelectorTextContains('main', 'Aucun événement');
    }

    public function testASuspendedParticipationStaysReadableWithABanner(): void
    {
        $this->sql('UPDATE event_participation SET status = \'SUSPENDED\' WHERE id = UNHEX(:id)', ['id' => $this->hex($this->partnerParticipation->id())]);
        $this->as($this->instructorP);
        $this->client->request('GET', $this->showUrl($this->instructorP));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'suspendue');
    }

    public function testSearchCategorySortAndPaginationAreAppliedOnTheServer(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->deposit(sprintf('Dupont%02d', $i), 'Alice', [$this->organizerZone], category: 'professional');
        }
        $this->as($this->instructorO);

        $crawler = $this->client->request('GET', $this->listUrl($this->instructorO));
        self::assertCount(20, $crawler->filter('tbody tr'));
        self::assertStringContainsString('DUPONT21', $crawler->filter('tbody tr')->first()->text(), 'dépôt le plus récent en premier');
        self::assertSelectorTextContains('main', '22 demandes');

        $crawler = $this->client->request('GET', $this->listUrl($this->instructorO, '?page=2'));
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertStringContainsString('MARTIN', $crawler->filter('tbody tr')->last()->text(), 'la plus ancienne en dernier');

        $crawler = $this->client->request('GET', $this->listUrl($this->instructorO, '?q=dupont07'));
        self::assertCount(1, $crawler->filter('tbody tr'));
        $crawler = $this->client->request('GET', $this->listUrl($this->instructorO, '?q=camille+martin'));
        self::assertCount(1, $crawler->filter('tbody tr'));
        $crawler = $this->client->request('GET', $this->listUrl($this->instructorO, '?q='.urlencode((string) $this->em()->getConnection()->fetchOne("SELECT public_reference FROM request_permit_request WHERE last_name = 'Martin'"))));
        self::assertCount(1, $crawler->filter('tbody tr'));
        $crawler = $this->client->request('GET', $this->listUrl($this->instructorO, '?q=%25'));
        self::assertCount(0, $crawler->filter('tbody tr'), 'les jokers LIKE sont neutralisés');

        $crawler = $this->client->request('GET', $this->listUrl($this->instructorO, '?categorie=private_person'));
        self::assertCount(1, $crawler->filter('tbody tr'));
        $crawler = $this->client->request('GET', $this->listUrl($this->instructorO, '?categorie=inconnue&page=abc'));
        self::assertResponseIsSuccessful();
        self::assertCount(20, $crawler->filter('tbody tr'), 'filtre inconnu et page invalide ignorés');
        $this->client->request('GET', $this->listUrl($this->instructorO, '?q=zzzz'));
        self::assertSelectorTextContains('main', 'Aucune demande ne correspond');
    }

    public function testTheListIsFilteredByEvent(): void
    {
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $second = F::draft($organizer, $this->em()->find(User::class, $this->event->createdBy()->id()), $this->em()->find(\App\Event\Domain\EventType::class, $this->event->type()->id()));
        $second->activate(F::now());
        $participation = F::organizerParticipation($second);
        $zone = F::readyZone($participation);
        $this->save($second, $participation, $zone->createdBy(), $zone);
        $this->sql('UPDATE event_event SET name = \'Crue de la Berle\' WHERE id = UNHEX(:id)', ['id' => $this->hex($second->id())]);
        $this->sql('UPDATE event_zone SET label = \'Chemin du Gué\' WHERE id = UNHEX(:id)', ['id' => $this->hex($zone->id())]);
        $this->deposit('Autre', 'Evenement', [$zone], event: $second);
        $this->as($this->instructorO);

        $this->client->request('GET', $this->listUrl($this->instructorO, '?evenement='.$second->id()->toRfc4122()));
        self::assertSelectorTextContains('main', 'AUTRE Evenement');
        self::assertSelectorTextNotContains('main', 'MARTIN');
        $this->client->request('GET', $this->listUrl($this->instructorO, '?evenement='.$this->event->id()->toRfc4122()));
        self::assertSelectorTextContains('main', 'MARTIN');
        self::assertSelectorTextNotContains('main', 'AUTRE');
    }

    public function testConsultingNeverChangesTheData(): void
    {
        $tables = ['request_permit_request', 'request_permit_request_municipality', 'request_permit_request_zone', 'request_permit_request_vehicle', 'request_permit_request_attachment', 'audit_log'];
        $snapshot = fn (): array => array_map(fn (string $t): string => md5(json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM '.$t), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE)), $tables);
        $before = $snapshot();

        $this->as($this->instructorO);
        foreach ([$this->listUrl($this->instructorO), $this->showUrl($this->instructorO), $this->downloadUrl($this->instructorO)] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
        }
        self::assertSame($before, $snapshot());
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $this->client->request($method, $this->showUrl($this->instructorO));
            self::assertResponseStatusCodeSame(405, $method);
        }
    }
}
