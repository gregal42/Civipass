<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * Monde des pages de l'espace municipal (MySQL de test) :
 *  - `org` organise « Incendie du massif forestier » (brouillon, `guestB` invitée puis acceptante), « Crue de la Berle » (clôturé),
 *    « Accident — Route départementale 5 » (brouillon, `other` simplement invitée) et 23 événements de remplissage (pagination) ;
 *  - utilisateurs : adminO / managerO / instructorO (org), adminB (guestB), adminX (other), superAdmin (aucun membership).
 */
abstract class MunicipalPagesTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected ServiceWorld $w;
    protected string $fire;
    protected string $flood;
    protected string $accident;

    protected function setUp(): void
    {
        $this->client = $this->freshClient();
        $this->w = ServiceWorld::persist($this->em(), static::getContainer());
        $admin = $this->user($this->w->adminO);
        $this->fire = $this->createEvent($admin, 'fire', 'Incendie du massif forestier', '2026-12-01 08:00:00');
        $this->flood = $this->createEvent($admin, 'flood', 'Crue de la Berle', '2026-11-20 08:00:00');
        $this->accident = $this->createEvent($admin, 'accident', 'Accident — Route départementale 5', '2026-12-10 08:00:00');
        for ($i = 1; $i <= 23; ++$i) {
            $this->createEvent($admin, 'other', sprintf('Remplissage %02d', $i), sprintf('2027-01-%02d 08:00:00', $i));
        }
        $participations = static::getContainer()->get(EventParticipationService::class);
        $invitation = $participations->invite($admin, new InviteMunicipalityCommand($this->fire, $this->w->guestB->id()->toRfc4122()));
        $participations->accept($this->user($this->w->adminB), $invitation->participationId);
        $participations->invite($admin, new InviteMunicipalityCommand($this->accident, $this->w->other->id()->toRfc4122()));
        static::getContainer()->get(EventLifecycleService::class)->close($admin, $this->flood);
        $this->em()->clear();
    }

    protected function user(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    protected function createEvent(User $actor, string $type, string $name, string $startsAt): string
    {
        return static::getContainer()->get(EventCreationService::class)->create(
            $actor,
            $this->w->org->id()->toRfc4122(),
            new CreateEventCommand($type, $name, new \DateTimeImmutable($startsAt, new \DateTimeZone('UTC'))),
        )->eventId;
    }

    protected function loginAs(User $user): void
    {
        $this->client->loginUser($this->user($user));
    }

    protected function id(Municipality $municipality): string
    {
        return $municipality->id()->toRfc4122();
    }

    protected function listUrl(Municipality|string $municipality, string $query = ''): string
    {
        return '/communes/'.($municipality instanceof Municipality ? $this->id($municipality) : $municipality).'/evenements'.$query;
    }

    protected function eventUrl(Municipality|string $municipality, string $eventId): string
    {
        return $this->listUrl($municipality).'/'.$eventId;
    }

    protected function get(string $url): string
    {
        $this->client->request('GET', $url);

        return (string) $this->client->getResponse()->getContent();
    }

    protected function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected static function randomUuid(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    /** Jeton CSRF d'un formulaire, lu sur sa page (comme un navigateur). */
    protected function token(string $formUrl): string
    {
        $html = $this->get($formUrl);
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $html, $m), 'jeton CSRF absent du formulaire');

        return $m[1];
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function submit(string $url, array $fields, ?string $token = null, ?string $tokenPage = null): string
    {
        $this->client->request('POST', $url, $fields + ['_token' => $token ?? $this->token($tokenPage ?? $url)]);

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @param list<mixed> $params
     */
    protected function scalar(string $sql, array $params = []): int
    {
        return (int) $this->em()->getConnection()->fetchOne($sql, $params);
    }

    protected function location(): string
    {
        return (string) $this->client->getResponse()->headers->get('Location');
    }

    protected function otherConnection(): Connection
    {
        return DriverManager::getConnection($this->em()->getConnection()->getParams());
    }
}
