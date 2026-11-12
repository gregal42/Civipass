<?php

declare(strict_types=1);

namespace App\Tests\Functional\Archive;

use App\Archive\Application\StatisticsCoordinationService;
use App\Event\Application\ClosureStatisticsReader;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ArchiveScenarioTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Habilitation de coordination statistique (L5-36, arbitrage A1) : ventilation par commune de plein droit pour l'administrateur de l'organisatrice, pour un
 * gestionnaire SEULEMENT avec une habilitation explicite, DISTINCTE de la désignation d'archive (l'une n'ouvre jamais l'autre).
 */
final class StatisticsCoordinationTest extends ArchiveScenarioTestCase
{
    private function service(): StatisticsCoordinationService
    {
        return static::getContainer()->get(StatisticsCoordinationService::class);
    }

    /** @return list<array{municipalityId: string, dossiers: int}>|null */
    private function breakdownFor(\App\Identity\Domain\User $user): ?array
    {
        $view = static::getContainer()->get(ClosureStatisticsReader::class)->forViewer($this->managed($user), $this->organizerId, $this->eventId());

        return $view?->global?->communes;
    }

    private function grantTo(\App\Identity\Domain\User $user): void
    {
        $this->service()->grant($this->managed($this->admin), $this->organizerId, $this->eventId(), $user->id()->toRfc4122());
        $this->em()->clear();
    }

    public function testTheBreakdownIsReadByRightByTheAdministratorAndByAnEventManagerOnlyWithAnExplicitGrant(): void
    {
        $this->depositAndApprove(5);
        $this->close(); // 6 dossiers par commune : ventilation publiable

        self::assertNotNull($this->breakdownFor($this->admin), 'MUNICIPALITY_ADMIN de l\'organisatrice : de plein droit');
        self::assertNull($this->breakdownFor($this->manager), 'EVENT_MANAGER sans habilitation : pas de ventilation (mais le global reste lisible)');
        self::assertNotNull(static::getContainer()->get(ClosureStatisticsReader::class)->forViewer($this->managed($this->manager), $this->organizerId, $this->eventId())?->global);

        $this->grantTo($this->manager);
        self::assertSame([6, 6], array_column($this->breakdownFor($this->manager) ?? [], 'dossiers'));

        $grant = Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM archive_statistics_coordination'))->toRfc4122();
        $this->service()->revoke($this->managed($this->admin), $this->organizerId, $grant);
        self::assertNull($this->breakdownFor($this->manager), 'habilitation révoquée');
    }

    public function testTheCoordinationAndTheArchiveDesignationAreTwoIndependentPermissions(): void
    {
        $this->depositAndApprove(5);
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);

        $this->grantTo($this->manager);
        self::assertNotNull($this->breakdownFor($this->manager));
        self::assertNull($this->openDossier($this->manager, $this->organizerId, $dossier), 'la coordination statistique n\'ouvre AUCUNE archive');
        $this->as($this->manager);
        $this->client->request('GET', $this->archiveUrl($this->organizerId, '/evenements/'.$this->eventId()));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->designate($this->manager, $this->organizerId, $this->admin);
        self::assertNotNull($this->openDossier($this->manager, $this->organizerId, $dossier));
        // Révoquer la coordination ne retire pas la désignation, et inversement.
        $this->service()->revoke($this->managed($this->admin), $this->organizerId, Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM archive_statistics_coordination'))->toRfc4122());
        self::assertNull($this->breakdownFor($this->manager));
        self::assertNotNull($this->openDossier($this->manager, $this->organizerId, $dossier), 'la désignation d\'archive subsiste');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM archive_manager_designation WHERE active_marker = 1'));
    }

    public function testOnlyTheAdministratorOfTheOrganizerGrantsAndOnlyToAnEventManagerOfTheOrganizer(): void
    {
        foreach ([[$this->manager, $this->organizerId], [$this->instructor, $this->organizerId], [$this->partnerAdmin, $this->partnerId], [$this->partnerAdmin, $this->organizerId], [$this->outsider, $this->organizerId], [$this->superAdmin, $this->organizerId]] as [$actor, $commune]) {
            try {
                $this->service()->grant($this->managed($actor), $commune, $this->eventId(), $this->manager->id()->toRfc4122());
                self::fail('Habilitation accordée par un utilisateur non habilité ou par une commune non organisatrice.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([$this->partnerManager, $this->instructor, $this->admin, $this->outsider] as $bad) {
            try {
                $this->service()->grant($this->managed($this->admin), $this->organizerId, $this->eventId(), $bad->id()->toRfc4122());
                self::fail('Cible invalide.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        $this->grantTo($this->manager);
        $this->expectException(DomainRuleViolation::class);
        $this->service()->grant($this->managed($this->admin), $this->organizerId, $this->eventId(), $this->manager->id()->toRfc4122());
    }

    public function testLosingTheRoleRevokesTheGrantDefinitivelyAndTheRecheckHoldsEvenWithoutTheHook(): void
    {
        $this->depositAndApprove(5);
        $this->close();
        $this->grantTo($this->manager);
        $commune = $this->em()->find(Municipality::class, $this->organizerId) ?? self::fail('Commune absente.');
        $membership = static::getContainer()->get(MembershipRepository::class)->findOneByUserAndMunicipality($this->managed($this->manager), $commune) ?? self::fail('Appartenance absente.');

        static::getContainer()->get(MembershipManager::class)->changeRoles($membership, [Role::Instructor], $this->managed($this->admin));
        $this->em()->clear();
        self::assertSame('ROLE_LOST', $this->em()->getConnection()->fetchOne('SELECT revocation_cause FROM archive_statistics_coordination'));
        static::getContainer()->get(MembershipManager::class)->changeRoles(static::getContainer()->get(MembershipRepository::class)->findOneByUserAndMunicipality($this->managed($this->manager), $commune) ?? self::fail(), [Role::EventManager], $this->managed($this->admin));
        $this->em()->clear();
        self::assertNull($this->breakdownFor($this->manager), 'le retour du rôle ne rétablit pas l\'habilitation');

        // Sans crochet : une habilitation « active » en base d'un utilisateur qui n'a plus le rôle ne donne rien.
        $this->grantTo($this->manager);
        $this->sql('UPDATE municipality_membership SET roles = :r WHERE user_id = UNHEX(:u) AND municipality_id = UNHEX(:m)', ['r' => '["INSTRUCTOR"]', 'u' => str_replace('-', '', $this->manager->id()->toRfc4122()), 'm' => str_replace('-', '', $this->organizerId)]);
        self::assertNull($this->breakdownFor($this->manager));
    }

    public function testTheCoordinationPageIsReservedToTheOrganizerAdministratorWithAUniform404Otherwise(): void
    {
        $this->close();
        $url = '/communes/'.$this->organizerId.'/evenements/'.$this->eventId().'/statistiques/habilitations';

        $this->as($this->admin);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        foreach ([$this->manager, $this->instructor, $this->outsider] as $user) {
            $this->as($user);
            $this->client->request('GET', $url);
            self::assertSame(404, $this->client->getResponse()->getStatusCode());
        }
        $this->as($this->partnerAdmin);
        $this->client->request('GET', '/communes/'.$this->partnerId.'/evenements/'.$this->eventId().'/statistiques/habilitations');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'une commune participante ne coordonne pas');
    }
}
