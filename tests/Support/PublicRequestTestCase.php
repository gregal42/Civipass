<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Request\Application\RequestReferentialInitializer;
use App\Tests\Support\EventFixtures as F;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Monde de test du parcours public : événement actif, deux communes participantes (une zone proposable chacune), une commune
 * invitée, une zone à configurer ; helpers pour avancer de page en page avec un vrai jeton CSRF.
 */
#[Group('requires-request-schema')]
abstract class PublicRequestTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected Event $event;
    protected EventParticipation $organizerParticipation;
    protected EventParticipation $partnerParticipation;
    protected EventZone $organizerZone;
    protected EventZone $partnerZone;
    protected string $base;

    protected function setUp(): void
    {
        $this->client = $this->freshClient();
        if (!$this->em()->getConnection()->createSchemaManager()->tablesExist(['request_motif', 'request_idempotency_key', 'event_event'])) {
            self::markTestSkipped('Schéma du module Request non migré (make test-db).');
        }
        static::getContainer()->get(RequestReferentialInitializer::class)->initialize();
        $organizer = MunicipalityBuilder::aMunicipality()->build();
        $partner = MunicipalityBuilder::aMunicipality()->withInseeCode('33527')->build();
        $invited = MunicipalityBuilder::aMunicipality()->withInseeCode('33505')->build();
        $creator = UserBuilder::aUser()->build();
        $type = F::type();
        $this->event = F::draft($organizer, $creator, $type);
        $this->event->activate(F::now());
        $this->organizerParticipation = F::organizerParticipation($this->event);
        $this->partnerParticipation = F::invited($this->event, $partner, $creator);
        $this->partnerParticipation->accept(F::now());
        $invitedParticipation = F::invited($this->event, $invited, $creator);
        $this->organizerZone = F::readyZone($this->organizerParticipation);
        $this->partnerZone = F::readyZone($this->partnerParticipation);
        $invitedZone = F::readyZone($this->partnerParticipation); // zone d'une participation... remplacée ci-dessous
        $this->save(
            $organizer,
            $partner,
            $invited,
            $creator,
            $type,
            $this->event,
            $this->organizerParticipation,
            $this->partnerParticipation,
            $invitedParticipation,
            $this->organizerZone->createdBy(),
            $this->organizerZone,
            $this->partnerZone->createdBy(),
            $this->partnerZone,
            $invitedZone->createdBy(),
            $invitedZone
        );
        $this->sql('UPDATE event_zone SET validity_mode = \'UNCONFIGURED\', label = \'Zone à configurer\' WHERE id = UNHEX(:id)', ['id' => str_replace('-', '', $invitedZone->id()->toRfc4122())]);
        $this->base = '/demandes/'.$this->event->publicReference()->value;
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir').'/var/storage_test');
        parent::tearDown();
    }

    protected function save(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
    }

    /**
     * @param array<string, string> $params
     */
    protected function sql(string $sql, array $params = []): void
    {
        $this->em()->getConnection()->executeStatement($sql, $params);
        $this->em()->clear(); // le premier appel HTTP partage l'EntityManager du test : pas d'état périmé
    }

    protected function rows(string $table): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /**
     * GET de l'étape (jeton compris) puis POST des valeurs.
     *
     * @param array<string, mixed>              $values
     * @param array<string, list<UploadedFile>> $files
     */
    protected function post(string $step, array $values, array $files = []): void
    {
        $crawler = $this->client->request('GET', $this->base.'/'.$step);
        self::assertResponseIsSuccessful('étape '.$step.' inaccessible');
        $token = $crawler->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', $this->base.'/'.$step, ['_token' => $token] + $values, $files);
    }

    protected function identity(): void
    {
        $this->post('identite', ['last' => 'Martin', 'first' => 'Camille', 'email' => 'camille@exemple.fr']);
    }

    protected function situation(): void
    {
        $this->post('situation', ['category' => 'private_person', 'motif' => 'home']);
    }

    /**
     * @param list<EventZone> $zones
     */
    protected function zones(array $zones): void
    {
        $this->post('zones', ['zones' => [$this->organizerParticipation->id()->toRfc4122() => ['selected' => '1', 'zones' => array_map(static fn (EventZone $z): string => $z->id()->toRfc4122(), $zones)]]]);
    }

    protected function upToTravel(): void
    {
        $this->identity();
        $this->situation();
        $this->zones([$this->organizerZone]);
        self::assertResponseRedirects($this->base.'/deplacement');
    }
}
