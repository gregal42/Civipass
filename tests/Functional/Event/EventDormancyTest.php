<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Event\Application\Dormancy\DormantEvent;
use App\Event\Application\Dormancy\EventDormancyAlertService;
use App\Event\Application\Dormancy\EventDormancyDetector;
use App\Event\Application\Dormancy\DormancySettings;
use App\Event\Application\Dormancy\EventDormancySnoozeService;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DormancyScenarioTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Dormance des événements (LOT 05.6.5, D3) : détection exacte, destinataires, anti-répétition, report motivé, échec d'envoi et reprise ; jamais de clôture, jamais de droit modifié.
 */
final class EventDormancyTest extends DormancyScenarioTestCase
{
    private function assertNothingChanged(): void
    {
        $row = $this->eventRow();
        self::assertSame('ACTIVE', $row['status'], 'aucun changement de statut');
        self::assertNull($row['ends_at'], 'aucune date de fin posée');
        self::assertNull($row['closed_at'], 'aucune clôture');
    }

    // ------------------------------------------------------------------ détection

    public function testAnActiveEventWithoutEndAndWithoutActivityIsDetectedWithItsInactivity(): void
    {
        $found = $this->dormant();

        self::assertCount(1, $found);
        self::assertSame(DormantEvent::ALERTABLE, $found[0]->state);
        self::assertGreaterThanOrEqual(99, $found[0]->inactiveDays);
        self::assertSame($this->organizerId, $found[0]->organizerId);
    }

    public function testASuspendedEventIsDetectedButDraftClosedAndEventsWithAnEndAreNot(): void
    {
        $this->sql('UPDATE event_event SET status = \'SUSPENDED\'');
        self::assertCount(1, $this->dormant(), 'suspendu : signalé');
        $this->sql('UPDATE event_event SET status = \'DRAFT\'');
        self::assertSame([], $this->dormant(), 'brouillon : jamais');
        $this->sql('UPDATE event_event SET status = \'ACTIVE\', ends_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 DAY)');
        self::assertSame([], $this->dormant(), 'avec date de fin : jamais');
        $this->sql('UPDATE event_event SET status = \'CLOSED\', ends_at = NULL, closed_at = UTC_TIMESTAMP()');
        self::assertSame([], $this->dormant(), 'clos : jamais');
    }

    public function testRecentActivityOfEachKindKeepsTheEventOutOfTheAlert(): void
    {
        self::assertCount(1, $this->dormant());
        foreach (self::SOURCES as $label => $touch) {
            $this->age(100);
            $this->sql($touch);
            self::assertSame([], $this->dormant(), 'activité récente « '.$label.' » : non signalé');
        }
        $this->age(89);
        self::assertSame([], $this->dormant(), 'sous le seuil de 90 jours');
        $this->age(91);
        self::assertCount(1, $this->dormant(), 'au-delà du seuil');
    }

    public function testTheThresholdIsAParameter(): void
    {
        $this->age(40);
        self::assertSame([], $this->dormant());
        $settings = new DormancySettings(30, 30, 30);
        $detector = new EventDormancyDetector($this->em()->getConnection(), static::getContainer()->get(ClockInterface::class), $settings);
        self::assertCount(1, $detector->scan());
    }

    // ------------------------------------------------------------------ alerte : destinataires, contenu, aucun effet sur l'événement

    public function testOnlyActiveAdministratorsAndOrganizersOfTheOrganizingMunicipalityAreAlertedWithoutPersonalData(): void
    {
        $this->sql('UPDATE identity_user SET active = 0 WHERE id = UNHEX(:u)', ['u' => str_replace('-', '', $this->organizerAdmin2->id()->toRfc4122())]);
        $before = $this->em()->getConnection()->fetchAllAssociative('SELECT user_id, municipality_id, roles, active FROM municipality_membership ORDER BY id');

        $report = $this->alerts();

        self::assertSame(1, $report->alerted);
        $mails = $this->sentEmails();
        self::assertCount(1, $mails);
        $to = array_map(static fn ($a): string => $a->getAddress(), $mails[0]->getTo());
        sort($to);
        $expected = [$this->admin->email(), $this->manager->email()];
        sort($expected);
        self::assertSame($expected, $to, 'administrateur et organisateur de l\'organisatrice, actifs ; ni instructeur, ni partenaire, ni compte désactivé, ni autre commune');
        $text = (string) $mails[0]->getTextBody();
        self::assertStringContainsString($this->event->publicReference()->value, $text);
        self::assertStringContainsString('ne clôture jamais', $text);
        self::assertStringContainsString('/communes/'.$this->organizerId.'/evenements/'.$this->eventId(), $text, 'lien vers la fiche de l\'événement');
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $text);
        }
        self::assertSame($before, $this->em()->getConnection()->fetchAllAssociative('SELECT user_id, municipality_id, roles, active FROM municipality_membership ORDER BY id'), 'aucun rôle ni appartenance modifié');
        $this->assertNothingChanged();
        self::assertSame(1, $this->rows('audit_log', 'action = \'DORMANCY_ALERT_SENT\''));
        self::assertSame(0, $this->rows('audit_log', 'action = \'DORMANCY_ALERT_SENT\' AND (new_value LIKE \'%@%\')'), 'audit sans adresse');
    }

    public function testSimulationAndNoRecipientNeverReserveAnything(): void
    {
        $simulated = $this->alerts(false);
        self::assertSame(1, $simulated->alerted);
        self::assertSame([], $this->sentEmails());
        self::assertSame(0, $this->rows('event_event', 'dormancy_alerted_at IS NOT NULL'));

        $this->sql('UPDATE municipality_membership SET active = 0');
        $report = $this->alerts();
        self::assertSame(0, $report->alerted);
        self::assertSame(1, $report->reasons['NO_RECIPIENT'] ?? 0);
        self::assertSame(0, $this->rows('event_event', 'dormancy_alerted_at IS NOT NULL'), 'sans destinataire, rien n\'est réservé');
    }

    // ------------------------------------------------------------------ anti-répétition

    public function testAnEventIsAlertedOnceThenOnlyAgainAfterTheReminderDelay(): void
    {
        self::assertSame(1, $this->alerts()->alerted);
        self::assertSame(0, $this->alerts()->alerted, 'deuxième passage : rien');
        self::assertCount(1, $this->sentEmails());
        self::assertSame(DormantEvent::ALERTED_RECENTLY, $this->dormant()[0]->state);

        $this->sql('UPDATE event_event SET dormancy_alerted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 29 DAY)');
        $this->sql('UPDATE audit_log SET occurred_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 29 DAY) WHERE action = \'DORMANCY_ALERT_SENT\'');
        self::assertSame(0, $this->alerts()->alerted, '29 jours : trop tôt');
        $this->sql('UPDATE event_event SET dormancy_alerted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY)');
        $this->sql('UPDATE audit_log SET occurred_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY) WHERE action = \'DORMANCY_ALERT_SENT\'');
        self::assertSame(1, $this->alerts()->alerted, '31 jours : rappel');
        self::assertCount(2, $this->sentEmails());
        self::assertSame(2, $this->rows('audit_log', 'action = \'DORMANCY_ALERT_SENT\''));
        $this->assertNothingChanged();
    }

    // ------------------------------------------------------------------ échec d'envoi et reprise

    private function failingService(): EventDormancyAlertService
    {
        $c = static::getContainer();
        $failing = new class () implements MailerInterface {
            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                throw new TransportException('SMTP indisponible');
            }
        };

        return new EventDormancyAlertService($c->get(EventDormancyDetector::class), $this->em()->getConnection(), $c->get(TransactionRunner::class), $c->get(AuditTrail::class), $failing, $c->get('router'), $c->get(ClockInterface::class), $c->get(DormancySettings::class), 'noreply@exemple.fr', 'https://civipass.exemple.fr');
    }

    public function testASendFailureRestoresTheReservationTracesTheFailureAndTheNextRunResumes(): void
    {
        $report = $this->failingService()->run(true);
        $this->em()->clear();

        self::assertSame(1, $report->failed);
        self::assertSame(0, $report->alerted);
        self::assertSame(0, $this->rows('event_event', 'dormancy_alerted_at IS NOT NULL'), 'la réservation est restaurée : un échec n\'est pas un envoi');
        self::assertSame(0, $this->rows('audit_log', 'action = \'DORMANCY_ALERT_SENT\''));
        self::assertSame(1, $this->rows('audit_log', 'action = \'DORMANCY_ALERT_FAILED\''));
        self::assertSame(DormantEvent::ALERTABLE, $this->dormant()[0]->state);

        self::assertSame(1, $this->alerts()->alerted, 'la reprise envoie');
        self::assertCount(1, $this->sentEmails());
        self::assertSame(1, $this->rows('audit_log', 'action = \'DORMANCY_ALERT_SENT\''));
    }

    public function testAnUnconfirmedReservationIsNotASendAndIsResumedOnlyAfterItsLease(): void
    {
        // Plantage simulé entre la prise et l'envoi : réservation sans ligne d'audit « envoyée ».
        $this->sql('UPDATE event_event SET dormancy_alerted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)');
        self::assertSame(DormantEvent::LEASE_PENDING, $this->dormant()[0]->state);
        self::assertSame(0, $this->alerts()->alerted, 'bail valide : une autre exécution s\'en charge');
        self::assertSame([], $this->sentEmails());

        $this->sql('UPDATE event_event SET dormancy_alerted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 MINUTE)');
        self::assertSame(DormantEvent::RETRY, $this->dormant()[0]->state);
        self::assertSame(1, $this->alerts()->alerted, 'bail expiré : reprise');
        self::assertCount(1, $this->sentEmails());
        self::assertSame(DormantEvent::ALERTED_RECENTLY, $this->dormant()[0]->state);
    }

    // ------------------------------------------------------------------ report motivé

    private function snooze(): EventDormancySnoozeService
    {
        return static::getContainer()->get(EventDormancySnoozeService::class);
    }

    public function testTheAdministratorAndTheOrganizerSnoozeWithAMandatoryReasonForThirtySixtyOrNinetyDays(): void
    {
        foreach ([[30, $this->admin], [60, $this->manager], [90, $this->admin]] as [$days, $user]) {
            $until = $this->snooze()->snooze($this->managed($user), $this->organizerId, $this->eventId(), $days, 'ONGOING_NEED');
            $expected = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('+%d days', $days));
            self::assertEqualsWithDelta($expected->getTimestamp(), $until->getTimestamp(), 5, 'toujours « maintenant + durée », jamais cumulée');
            self::assertSame(DormantEvent::SNOOZED, $this->dormant()[0]->state);
            self::assertSame(0, $this->alerts()->alerted, 'reporté : aucune alerte');
        }
        self::assertSame(3, $this->rows('audit_log', 'action = \'DORMANCY_SNOOZED\''));
        self::assertSame([], $this->sentEmails());
        $this->assertNothingChanged();

        $this->sql('UPDATE event_event SET dormancy_snoozed_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
        self::assertSame(1, $this->alerts()->alerted, 'report expiré : l\'alerte reprend');
    }

    public function testASnoozeNeedsAValidDurationAndReasonAndAnInactiveEvent(): void
    {
        $actor = $this->managed($this->admin);
        foreach ([[45, 'ONGOING_NEED'], [0, 'ONGOING_NEED'], [120, 'ONGOING_NEED'], [30, ''], [30, 'INVENTE']] as [$days, $reason]) {
            try {
                $this->snooze()->snooze($actor, $this->organizerId, $this->eventId(), $days, $reason);
                self::fail('Report invalide accepté.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->rows('audit_log', 'action = \'DORMANCY_SNOOZED\''));
        self::assertSame(0, $this->rows('event_event', 'dormancy_snoozed_until IS NOT NULL'));

        $this->sql('UPDATE event_event SET updated_at = UTC_TIMESTAMP()');
        $this->expectException(DomainRuleViolation::class);
        $this->snooze()->snooze($actor, $this->organizerId, $this->eventId(), 30, 'ONGOING_NEED');
    }

    public function testNobodyElseCanSnoozeEvenWithForgedIdentifiers(): void
    {
        foreach ([
            [$this->instructor, $this->organizerId],
            [$this->partnerAdmin, $this->partnerId],
            [$this->partnerAdmin, $this->organizerId],
            [$this->partnerManager, $this->partnerId],
            [$this->outsider, $this->outsiderCommuneId],
            [$this->outsider, $this->organizerId],
            [$this->superAdmin, $this->organizerId],
        ] as [$user, $commune]) {
            try {
                $this->snooze()->snooze($this->managed($user), $commune, $this->eventId(), 30, 'ONGOING_NEED');
                self::fail('Report par un utilisateur non habilité.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->rows('event_event', 'dormancy_snoozed_until IS NOT NULL'));
    }

    // ------------------------------------------------------------------ écran : bandeau et formulaire

    private function show(string $municipalityId): string
    {
        $this->client->request('GET', '/communes/'.$municipalityId.'/evenements/'.$this->eventId());

        return (string) $this->client->getResponse()->getContent();
    }

    public function testTheBannerIsVisibleOnlyToTheAdministratorAndTheOrganizerOfTheOrganizingMunicipality(): void
    {
        foreach ([$this->admin, $this->manager] as $user) {
            $this->as($user);
            $body = $this->show($this->organizerId);
            self::assertStringContainsString('sans date de fin et sans activité', $body);
            self::assertStringContainsString('Reporter le rappel', $body);
        }
        foreach ([[$this->instructor, $this->organizerId], [$this->partnerInstructor, $this->partnerId], [$this->partnerAdmin, $this->partnerId]] as [$user, $commune]) {
            $this->as($user);
            self::assertStringNotContainsString('sans date de fin et sans activité', $this->show($commune));
        }
        $this->sql('UPDATE event_event SET updated_at = UTC_TIMESTAMP()');
        $this->as($this->admin);
        self::assertStringNotContainsString('sans date de fin et sans activité', $this->show($this->organizerId), 'événement actif : pas de bandeau');
    }

    public function testTheSnoozeFormChecksTheRightBeforeTheTokenThenSnoozes(): void
    {
        $post = '/communes/'.$this->organizerId.'/evenements/'.$this->eventId().'/dormance/reporter';
        foreach ([[$this->instructor, $this->organizerId], [$this->partnerAdmin, $this->partnerId], [$this->outsider, $this->organizerId]] as [$user, $commune]) {
            $this->as($user);
            $this->client->request('POST', '/communes/'.$commune.'/evenements/'.$this->eventId().'/dormance/reporter', ['_token' => 'x', 'days' => '30', 'reason' => 'ONGOING_NEED']);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'refus avant le jeton CSRF, uniforme');
        }
        $this->as($this->manager);
        $crawler = $this->client->request('GET', '/communes/'.$this->organizerId.'/evenements/'.$this->eventId());
        $token = (string) $crawler->filter('form[action="'.$post.'"] input[name=_token]')->attr('value');

        $this->client->request('POST', $post, ['_token' => 'invalide', 'days' => '30', 'reason' => 'ONGOING_NEED']);
        self::assertResponseRedirects();
        self::assertSame(0, $this->rows('event_event', 'dormancy_snoozed_until IS NOT NULL'), 'jeton invalide : rien');
        $this->client->request('POST', $post, ['_token' => $token, 'days' => '30', 'reason' => '']);
        self::assertSame(0, $this->rows('event_event', 'dormancy_snoozed_until IS NOT NULL'), 'sans motif : rien');
        $this->client->request('POST', $post, ['_token' => $token, 'days' => '60', 'reason' => 'SEASONAL_PAUSE']);
        self::assertResponseRedirects();
        self::assertSame(1, $this->rows('event_event', 'dormancy_snoozed_until IS NOT NULL'));
        $this->assertNothingChanged();
    }

    // ------------------------------------------------------------------ jamais de clôture

    public function testNothingInTheDormancyFlowEverClosesAnEventOrChangesARight(): void
    {
        $rights = static fn (self $t): array => $t->em()->getConnection()->fetchAllAssociative('SELECT (SELECT COUNT(*) FROM permit_authorization WHERE revoked_at IS NOT NULL) AS revoked, (SELECT COUNT(*) FROM permit_permit) AS permits, (SELECT COUNT(*) FROM event_participation WHERE status = \'ACTIVE\') AS participations');
        $before = $rights($this);

        $this->alerts();
        $this->alerts();
        $this->snooze()->snooze($this->managed($this->admin), $this->organizerId, $this->eventId(), 90, 'AWAITING_END_DATE');
        $this->sql('UPDATE event_event SET dormancy_snoozed_until = NULL, dormancy_alerted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 DAY)');
        $this->alerts();

        $this->assertNothingChanged();
        self::assertSame($before, $rights($this));
        self::assertSame(0, $this->rows('audit_log', 'action IN (\'EVENT_CLOSED\', \'EVENT_STATUS_CHANGED\')'));
    }
}
