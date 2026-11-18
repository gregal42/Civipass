<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RefusalReasonInitializer;
use App\Request\Application\RequestSubmissionService;
use App\Request\Infrastructure\DoctrineRefusalReasonRepository;
use App\Shared\Application\TransactionRunner;
use App\Request\Domain\TravelMode;
use App\Tests\Support\ControlTestCase;

/** LOT 08.1 : accueil communal : événements, demandes en attente et récentes de LA commune, accès rapides, isolation, rôles, aucune donnée d'une autre commune. */
final class MunicipalHomeTest extends ControlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        (new RefusalReasonInitializer(new DoctrineRefusalReasonRepository($this->em()), static::getContainer()->get(TransactionRunner::class)))->initialize();
    }

    private function home(string $municipalityId): void
    {
        $this->client->request('GET', '/communes/'.$municipalityId);
    }

    private function pending(string $last, string $plate): string
    {
        $id = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), $last, 'Camille', '12 rue des Lilas', 'c'.random_int(1, 99999).'@exemple.fr', '0611223344', 'private_person', 'home', 'Motif', TravelMode::Vehicle, [$plate], [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], true),
            sprintf('ds-%016d', random_int(1, 9_999_999)),
        )->requestId;
        $this->em()->clear();

        return $id;
    }

    public function testLandingRedirectsToHomeAndAnonymousToLogin(): void
    {
        $this->home($this->org->id()->toRfc4122());
        self::assertResponseRedirects('/login');
        $this->client->loginUser($this->user($this->instructorO));
        $this->client->request('GET', '/communes');
        self::assertResponseRedirects('/communes/'.$this->org->id()->toRfc4122());
    }

    public function testInstructorSeesPendingCountsAndRecentDecisions(): void
    {
        $this->pending('DURAND', 'AA-111-AA');
        $this->pending('DUPONT', 'BB-222-BB');
        $decided = $this->pending('MARTIN', 'CC-333-CC');
        static::getContainer()->get(MunicipalInstructionService::class)->refuse($this->user($this->instructorO), $this->org->id()->toRfc4122(), $decided, 'duplicate', null);
        $this->em()->clear();

        $this->client->loginUser($this->user($this->instructorO));
        $this->home($this->org->id()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Demandes en attente');
        self::assertSelectorTextContains('#home-recent', 'Demandes récemment traitées');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('2 demandes en attente', $html);
        self::assertStringContainsString('MARTIN', $html);
        self::assertStringContainsString('Refusée', $html);
        self::assertStringNotContainsString('DURAND', $html, 'une demande en attente n\'est pas « traitée »');
        self::assertSelectorExists('a[href="/communes/'.$this->org->id()->toRfc4122().'/controle"]', 'accès rapide Contrôle');
    }

    public function testRecentDecisionsAreLimitedToSevenDaysAndTenRows(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $id = $this->pending('NOM'.$i, sprintf('AB-%03d-CD', $i));
            static::getContainer()->get(MunicipalInstructionService::class)->refuse($this->user($this->instructorO), $this->org->id()->toRfc4122(), $id, 'duplicate', null);
        }
        $this->em()->clear();
        $this->sql('UPDATE request_permit_request_decision SET decided_at = :old WHERE id = (SELECT id FROM (SELECT id FROM request_permit_request_decision ORDER BY id LIMIT 1) x)', ['old' => (new \DateTimeImmutable('-8 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);

        $this->client->loginUser($this->user($this->instructorO));
        $this->home($this->org->id()->toRfc4122());
        self::assertCount(10, $this->client->getCrawler()->filter('#home-recent ~ .cp-table-wrap tbody tr'), 'au plus 10 lignes');
    }

    public function testNonInstructorGetsNoRequestBlocks(): void
    {
        $this->pending('DURAND', 'AA-111-AA');
        $this->client->loginUser($this->user($this->adminO));
        $this->home($this->org->id()->toRfc4122());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('DURAND', $html);
        self::assertStringNotContainsString('Demandes en attente', $html);
        self::assertStringContainsString('Événements en cours', $html);
    }

    public function testOtherCommuneDecisionsAndCountsNeverAppear(): void
    {
        $id = $this->pending('DURAND', 'AA-111-AA');
        static::getContainer()->get(MunicipalInstructionService::class)->approve($this->user($this->instructorP), $this->partner->id()->toRfc4122(), $id, \App\Request\Domain\ApprovalDuration::untilClosure());
        $this->em()->clear();

        $this->client->loginUser($this->user($this->instructorO));
        $this->home($this->org->id()->toRfc4122());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Approuvée', $html, 'la décision de la commune partenaire n\'apparaît pas');
        self::assertStringContainsString('1 demande en attente', $html, 'seule la part de MA commune compte');
    }

    public function testForeignCommuneIsRefusedUniformly(): void
    {
        $this->client->loginUser($this->user($this->adminX));
        $this->home($this->org->id()->toRfc4122());
        $a = $this->client->getResponse()->getStatusCode();
        $this->home('00000000-0000-4000-8000-000000000000');
        self::assertSame($a, $this->client->getResponse()->getStatusCode());
        self::assertContains($a, [403, 404]);
    }

    public function testEmptyStateWithoutEvent(): void
    {
        $this->sql("UPDATE event_event SET status = 'CLOSED'");
        $this->client->loginUser($this->user($this->adminO));
        $this->home($this->org->id()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucun événement en cours');
        self::assertSelectorTextContains('main', 'Aucun événement à venir');
    }
}
