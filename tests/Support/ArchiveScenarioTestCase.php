<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Archive\Application\ArchiveDesignationService;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Scénario partagé des tests d'archives (LOT 05.6.3) : deux communes (organisatrice et partenaire) avec un dossier approuvé par chacune, une commune étrangère, et pour
 * chaque commune un administrateur, un gestionnaire d'événement, un instructeur ; utilisateurs particuliers : CWS sans appartenance, compte désactivé.
 */
#[Group('requires-request-schema')]
abstract class ArchiveScenarioTestCase extends ClosedEventScenarioTestCase
{
    protected User $partnerAdmin;
    protected User $partnerManager;
    protected User $organizerAdmin2;
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $partner = $this->em()->find(Municipality::class, $this->partnerId) ?? self::fail('Commune absente.');
        $organizer = $this->em()->find(Municipality::class, $this->organizerId) ?? self::fail('Commune absente.');
        [$this->partnerAdmin, $this->partnerManager, $this->organizerAdmin2, $this->superAdmin] = [UserBuilder::aUser()->build(), UserBuilder::aUser()->build(), UserBuilder::aUser()->build(), UserBuilder::aUser()->superAdmin()->build()];
        $this->save(
            $this->partnerAdmin,
            $this->partnerManager,
            $this->organizerAdmin2,
            $this->superAdmin,
            MembershipBuilder::aMembership($this->partnerAdmin, $partner)->withRoles(Role::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->partnerManager, $partner)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->organizerAdmin2, $organizer)->withRoles(Role::MunicipalityAdmin)->build(),
        );
    }

    protected function eventId(): string
    {
        return $this->event->id()->toRfc4122();
    }

    protected function dossierOf(string $municipalityId): string
    {
        return Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM request_permit_request_municipality WHERE municipality_id = UNHEX(:m) ORDER BY id LIMIT 1', ['m' => str_replace('-', '', $municipalityId)]))->toRfc4122();
    }

    protected function archiveUrl(string $municipalityId, string $suffix = ''): string
    {
        return '/communes/'.$municipalityId.'/archives'.$suffix;
    }

    protected function designate(User $manager, string $municipalityId, User $by): void
    {
        static::getContainer()->get(ArchiveDesignationService::class)->designate($this->managed($by), $municipalityId, $this->eventId(), $manager->id()->toRfc4122());
        $this->em()->clear();
    }

    /** Ouvre un dossier par le formulaire de motif (jeton CSRF réel) ; retourne l'identifiant de la consultation ou null si refusé. */
    protected function openDossier(User $user, string $municipalityId, string $dossierId, string $reason = 'LITIGE', string $comment = ''): ?string
    {
        $this->as($user);
        $crawler = $this->client->request('GET', $this->archiveUrl($municipalityId, '/dossiers/'.$dossierId.'/ouvrir'));
        if (200 !== $this->client->getResponse()->getStatusCode()) {
            return null;
        }
        $token = (string) $crawler->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', $this->archiveUrl($municipalityId, '/dossiers/'.$dossierId.'/ouvrir'), ['_token' => $token, 'reason' => $reason, 'comment' => $comment]);
        if (!$this->client->getResponse()->isRedirect()) {
            return null;
        }
        $location = (string) $this->client->getResponse()->headers->get('Location');

        return 1 === preg_match('#/consultations/([0-9a-f-]{36})#', $location, $m) ? $m[1] : null;
    }

    protected function journalRows(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM archive_consultation');
    }

    /** Clôture, puis recule la clôture de `$months` mois (durée de conservation indicative dépassée : aucun effet sur l'accès, seulement sur l'éligibilité à la purge). */
    protected function closeAndAge(int $months): void
    {
        $this->close();
        $this->sql('UPDATE event_event SET closed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL :m MONTH)', ['m' => (string) $months]);
    }
}
