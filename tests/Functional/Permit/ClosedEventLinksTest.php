<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Event\Domain\EventParticipation;
use App\Notification\Application\KeyRingProvider;
use App\Onboarding\Domain\InvitationLink;
use App\Onboarding\Domain\InvitationLinkKind;
use App\Permit\Application\BeneficiaryPassReader;
use App\Tests\Support\ClosedEventScenarioTestCase;
use App\Tests\Support\NotificationKit;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Liens et clôture (LOT 05.6.2, DM-114 amendé, DM-120) : le lien bénéficiaire (page et PDF) est fermé à la clôture avec la même réponse qu'un lien inconnu,
 * les invitations non consommées sont révoquées, aucun lien n'est créé sur un événement clos.
 */
final class ClosedEventLinksTest extends ClosedEventScenarioTestCase
{
    private function svc(string $class): object
    {
        return static::getContainer()->get($class);
    }

    public function testTheBeneficiaryLinkAndItsPdfAreClosedAtClosureWithTheSameAnswerAsAnUnknownLink(): void
    {
        $this->client->request('GET', '/laissez-passer/'.$this->linkSecret);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Incendie du massif forestier', (string) $this->client->getResponse()->getContent(), 'avant la clôture, le lien est servi');

        $this->close();
        $this->client->request('GET', '/laissez-passer/'.$this->linkSecret);
        [$status, $body] = [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()];
        $this->client->request('GET', '/laissez-passer/'.str_repeat('A', 43));
        $unknown = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/laissez-passer/'.$this->linkSecret.'/pdf');

        self::assertSame(404, $status);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame($unknown, $body, 'même réponse qu\'un lien inconnu');
        self::assertSame('0', (string) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_access_token WHERE revoked_at IS NOT NULL'), 'ligne conservée, fermeture par prédicat');
    }

    public function testTheBeneficiaryReaderReturnsNothingForAClosedEventWhateverTheClock(): void
    {
        $reader = $this->svc(BeneficiaryPassReader::class);
        $now = $this->svc(ClockInterface::class)->now();
        self::assertNotNull($reader->find($this->linkSecret, $now));

        $this->close();

        self::assertNull($reader->find($this->linkSecret, $now));
        self::assertNull($reader->find($this->linkSecret, $now->modify('-1 year')), 'quelle que soit l\'horloge');
    }

    public function testUnconsumedInvitationsAreRevokedAndConsumedOnesAreLeftAsTheyWere(): void
    {
        $participation = $this->em()->find(EventParticipation::class, Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM event_participation WHERE status = \'INVITED\' LIMIT 1')));
        self::assertNotNull($participation);
        $now = $this->svc(ClockInterface::class)->now();
        $open = new InvitationLink($participation, InvitationLinkKind::Contact, 'contact@mairie.example', hash('sha256', 'ouvert'), $now);
        $consumed = new InvitationLink($participation, InvitationLinkKind::Administrator, 'admin@mairie.example', hash('sha256', 'consomme'), $now);
        $consumed->markConsumed($now);
        $this->save($open, $consumed);

        $this->close();

        $row = fn (string $hash): array => $this->em()->getConnection()->fetchAssociative('SELECT revoked_at, consumed_at FROM onboarding_invitation_link WHERE token_hash = :h', ['h' => hash('sha256', $hash)]) ?: [];
        self::assertNotNull($row('ouvert')['revoked_at'], 'lien non consommé révoqué');
        self::assertNull($row('consomme')['revoked_at'], 'lien consommé inchangé');
        self::assertNotNull($row('consomme')['consumed_at']);
        $audit = json_decode((string) $this->em()->getConnection()->fetchOne('SELECT new_value FROM audit_log WHERE action = \'EVENT_CLOSED\''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(1, $audit['revokedInvitations']);
    }

    public function testProvisioningNeverCreatesALinkOnAClosedEvent(): void
    {
        $keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
        $links = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_access_token');
        $this->sql('UPDATE event_event SET status = \'CLOSED\', closed_at = UTC_TIMESTAMP()'); // clôture sans neutralisation en masse (reprise après incident)

        $outcomes = NotificationKit::provisioner(static::getContainer(), $keys, $this->svc(ClockInterface::class))->provisionPending();

        self::assertSame($links, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_access_token'), 'aucun lien créé');
        self::assertArrayNotHasKey('PROVISIONED', array_change_key_case($outcomes, \CASE_UPPER));
        self::assertInstanceOf(KeyRingProvider::class, $keys);
    }
}
