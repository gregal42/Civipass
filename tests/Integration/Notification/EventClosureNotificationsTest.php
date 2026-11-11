<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Application\KeyRingProvider;
use App\Permit\Application\AuthorizationRevocationService;
use App\Tests\Support\ClosedEventScenarioTestCase;
use App\Tests\Support\ClosingProbe;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\RecordingMailer;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Notifications et clôture (LOT 05.6.2, DM-120, Q3 et P3) : à la clôture, tout message en attente (prioritaire compris) est supprimé avec le code
 * `EVENT_CLOSED`, ses secrets et liens jamais remis sont retirés ; aucune prise en charge n'est possible ensuite (gardes G1 avant la création d'un lien,
 * G2 avant l'envoi). Un message dont l'envoi est déjà en cours part : la limite SMTP est MESURÉE ici (test de la fenêtre), jamais promise.
 */
final class EventClosureNotificationsTest extends ClosedEventScenarioTestCase
{
    private KeyRingProvider $keys;
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
        $this->mailer = new RecordingMailer($this->em()->getConnection());
    }

    private function clockService(): ClockInterface
    {
        return static::getContainer()->get(ClockInterface::class);
    }

    /** @return array<string, int> */
    private function dispatch(?\App\Event\Application\EventStatusProbe $probe = null): array
    {
        return NotificationKit::dispatcher(static::getContainer(), $this->keys, $this->clockService(), $this->mailer, null, null, 'http://civipass.test', $probe)->run()->outcomes;
    }

    /** @return array<string, int> nombre de lignes par « état/code » */
    private function states(): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT status, last_error_code, COUNT(*) AS n FROM notification_outbox GROUP BY status, last_error_code');
        $states = [];
        foreach ($rows as $row) {
            $states[$row['status'].'/'.($row['last_error_code'] ?? '-')] = (int) $row['n'];
        }
        ksort($states);

        return $states;
    }

    private function scalarInt(string $sql): int
    {
        return (int) $this->em()->getConnection()->fetchOne($sql);
    }

    private function revoke(): void
    {
        static::getContainer()->get(AuthorizationRevocationService::class)->revoke($this->managed($this->instructor), $this->organizerId, $this->requestId, 'PUBLIC_SAFETY', null, true);
    }

    public function testClosureCancelsEveryPendingMessageIncludingPriorityOnesAndRemovesUndeliveredLinksAndSecrets(): void
    {
        NotificationKit::provisioner(static::getContainer(), $this->keys, $this->clockService())->provisionPending();
        $this->revoke(); // message PRIORITAIRE en attente (révocation)
        $pendingBefore = $this->scalarInt('SELECT COUNT(*) FROM notification_outbox WHERE status = \'PENDING\'');
        self::assertGreaterThanOrEqual(2, $pendingBefore);
        self::assertGreaterThan(0, $this->scalarInt('SELECT COUNT(*) FROM notification_secret'), 'un secret chiffré attend l\'envoi');
        self::assertGreaterThan(0, $this->scalarInt('SELECT COUNT(*) FROM notification_outbox WHERE type = \'AUTHORIZATION_REVOKED\' AND status = \'PENDING\''));
        $links = $this->scalarInt('SELECT COUNT(*) FROM permit_access_token WHERE issuer_type = \'SYSTEM\' AND revoked_at IS NULL');

        $this->close();

        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM notification_outbox WHERE status IN (\'PENDING\', \'RESERVED\')'));
        self::assertSame(['SUPPRESSED/EVENT_CLOSED' => $pendingBefore], $this->states());
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM notification_secret'), 'secrets chiffrés supprimés');
        self::assertSame($links, $this->scalarInt('SELECT COUNT(*) FROM permit_access_token WHERE revoked_at IS NOT NULL AND issuer_type = \'SYSTEM\''), 'liens jamais remis révoqués');
        $audit = json_decode((string) $this->em()->getConnection()->fetchOne('SELECT new_value FROM audit_log WHERE action = \'EVENT_CLOSED\''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($pendingBefore, $audit['cancelledNotifications']);
        self::assertArrayHasKey('revokedInvitations', $audit);
        self::assertStringNotContainsStringIgnoringCase('camille', (string) json_encode($audit), 'aucune donnée personnelle dans l\'audit');
        self::assertSame([], $this->dispatch(), 'plus rien à distribuer');
        self::assertSame([], $this->mailer->tried);
    }

    public function testSentMessagesAndTheirTraceAreKept(): void
    {
        $this->dispatch();
        self::assertNotSame([], $this->mailer->sent, 'au moins un message part avant la clôture');
        $sent = $this->scalarInt('SELECT COUNT(*) FROM notification_outbox WHERE status = \'SENT\'');
        self::assertGreaterThan(0, $sent);

        $this->close();

        self::assertSame($sent, $this->scalarInt('SELECT COUNT(*) FROM notification_outbox WHERE status = \'SENT\''), 'trace de notification conservée');
    }

    public function testG1ANeverCreatesALinkNorSendsForAnEventClosedWithoutTheMassCancellation(): void
    {
        // Clôture directe en base : la mise à jour en masse n'a pas eu lieu (reprise après incident) ; le distributeur seul doit tout neutraliser.
        $pending = $this->scalarInt('SELECT COUNT(*) FROM notification_outbox WHERE status = \'PENDING\'');
        self::assertGreaterThan(0, $pending);
        $this->sql('UPDATE event_event SET status = \'CLOSED\', closed_at = UTC_TIMESTAMP()');
        $this->sql('UPDATE notification_outbox SET available_at = UTC_TIMESTAMP() WHERE status = \'PENDING\''); // tous échus (les messages ordinaires sont normalement différés)
        $links = $this->scalarInt('SELECT COUNT(*) FROM permit_access_token');

        $outcomes = $this->dispatch();

        self::assertSame(['suppressed' => $pending], $outcomes);
        self::assertSame([], $this->mailer->tried, 'aucun envoi');
        self::assertSame($links, $this->scalarInt('SELECT COUNT(*) FROM permit_access_token'), 'aucun lien créé sur un événement clos');
        self::assertSame(['SUPPRESSED/EVENT_CLOSED' => $pending], $this->states());
        self::assertSame($pending, $this->scalarInt('SELECT COUNT(*) FROM audit_log WHERE action = \'NOTIFICATION_SUPPRESSED\''), 'trace d\'audit par message, sans donnée personnelle');
    }

    public function testG2StopsAMessageWhenTheClosureLandsBetweenTheReservationAndTheSending(): void
    {
        $eventId = $this->event->id()->toRfc4122();
        // Appel 1 = G1 (événement encore ouvert) ; la clôture est validée juste avant l'appel 2 = G2, donc APRÈS la réservation du message.
        $probe = new ClosingProbe(static::getContainer()->get(\App\Event\Application\EventStatusProbe::class), 2, function () use ($eventId): void {
            static::getContainer()->get(\App\Event\Application\Service\EventLifecycleService::class)->close($this->managed($this->admin), $eventId);
        });

        $outcomes = $this->dispatch($probe);

        self::assertSame([], $this->mailer->tried, 'G2 : rien n\'est composé ni envoyé');
        self::assertArrayHasKey('suppressed', $outcomes);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT status, last_error_code, attempts FROM notification_outbox WHERE attempts > 0');
        self::assertSame(['SUPPRESSED', 'EVENT_CLOSED'], [$row['status'], $row['last_error_code']]);
        self::assertSame(1, (int) $row['attempts'], 'la réservation avait été prise en charge');
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM notification_secret'));
    }

    public function testAClosureLandingDuringTheSmtpExchangeCannotRecallTheMessage(): void
    {
        // LIMITE DOCUMENTÉE, MESURÉE : aucun verrou n'est tenu pendant l'échange SMTP. Un message dont G2 a réussi part, et son état reste exact (SENT).
        $eventId = $this->event->id()->toRfc4122();
        $closed = false;
        $this->mailer->duringSend = function () use (&$closed, $eventId): void {
            if (!$closed) {
                $closed = true;
                static::getContainer()->get(\App\Event\Application\Service\EventLifecycleService::class)->close($this->managed($this->admin), $eventId);
            }
        };

        $outcomes = $this->dispatch();

        self::assertTrue($closed);
        self::assertSame(1, $outcomes['sent'] ?? 0, 'le message en vol est parti : fenêtre irréductible');
        self::assertCount(1, $this->mailer->sent, 'et il est le SEUL : tout le reste est annulé par la clôture et par les gardes');
        $states = $this->states();
        self::assertSame(1, $states['SENT/-'] ?? 0, 'état exact : le message est SENT, pas SUPPRESSED');
        self::assertSame(array_sum($states) - 1, $states['SUPPRESSED/EVENT_CLOSED'] ?? 0);
    }

    public function testAReservationWithAnExpiredLeaseIsCancelledByTheClosureAndAValidOneIsLeftAlone(): void
    {
        $ids = $this->em()->getConnection()->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM notification_outbox WHERE status = \'PENDING\' ORDER BY id');
        self::assertGreaterThanOrEqual(2, \count($ids));
        $expired = $ids[0];
        $valid = $ids[1];
        $this->sql('UPDATE notification_outbox SET status = \'RESERVED\', attempts = 1, first_attempt_at = UTC_TIMESTAMP(), leased_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = UNHEX(:id)', ['id' => $expired]);
        $this->sql('UPDATE notification_outbox SET status = \'RESERVED\', attempts = 1, first_attempt_at = UTC_TIMESTAMP(), leased_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = UNHEX(:id)', ['id' => $valid]);

        $this->close();

        $status = fn (string $id): string => (string) $this->em()->getConnection()->fetchOne('SELECT CONCAT(status, \'/\', COALESCE(last_error_code, \'-\')) FROM notification_outbox WHERE id = UNHEX(:id)', ['id' => $id]);
        self::assertSame('SUPPRESSED/EVENT_CLOSED', $status($expired), 'processus mort : réservation récupérée et neutralisée');
        self::assertSame('RESERVED/-', $status($valid), 'envoi peut-être en cours : état laissé exact');
    }

    public function testARetryComingBackPendingAfterTheClosureIsNeverSentAndTheClosureIsIdempotent(): void
    {
        $this->close();
        $id = (string) $this->em()->getConnection()->fetchOne('SELECT LOWER(HEX(id)) FROM notification_outbox LIMIT 1');
        // Un envoi en vol échoue après la clôture et repasse en attente (réessai) : simulé par la remise à PENDING d'une ligne.
        $this->sql('UPDATE notification_outbox SET status = \'PENDING\', last_error_code = \'SMTP_TEMPORARY\', processed_at = NULL, available_at = UTC_TIMESTAMP() WHERE id = UNHEX(:id)', ['id' => $id]);

        $this->dispatch();
        $this->dispatch(); // idempotent : deuxième passage sans effet

        self::assertSame([], $this->mailer->tried);
        self::assertSame('SUPPRESSED/EVENT_CLOSED', (string) $this->em()->getConnection()->fetchOne('SELECT CONCAT(status, \'/\', last_error_code) FROM notification_outbox WHERE id = UNHEX(:id)', ['id' => $id]));
    }

    /** @return iterable<string, array{string, string, int, string}> état imposé AVANT le verrou de la ligne, état final attendu, nombre annulé, motif */
    public static function changesBetweenSelectionAndLock(): iterable
    {
        yield 'inchangé : annulé' => ['', 'SUPPRESSED/EVENT_CLOSED', 1, 'la ligne en attente est neutralisée'];
        yield 'envoyé entre-temps : laissé' => ['UPDATE notification_outbox SET status = \'SENT\', processed_at = UTC_TIMESTAMP(), attempts = 1 WHERE id = UNHEX(:id)', 'SENT/-', 0, 'un message déjà parti garde son état exact'];
        yield 'réservé à bail valide : laissé' => ['UPDATE notification_outbox SET status = \'RESERVED\', attempts = 1, first_attempt_at = UTC_TIMESTAMP(), leased_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = UNHEX(:id)', 'RESERVED/-', 0, 'envoi peut-être en cours : la garde G2 et G1 prendront le relais'];
        yield 'réservé à bail expiré : annulé' => ['UPDATE notification_outbox SET status = \'RESERVED\', attempts = 1, first_attempt_at = UTC_TIMESTAMP(), leased_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = UNHEX(:id)', 'SUPPRESSED/EVENT_CLOSED', 1, 'processus mort : récupéré'];
    }

    /**
     * Une ligne qui change d'état ENTRE la sélection des identifiants et son verrou (le distributeur la réserve ou l'envoie) : l'état est relu SOUS le verrou ;
     * jamais d'écrasement d'un message en cours ou envoyé, jamais d'échappement d'une ligne encore en attente.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('changesBetweenSelectionAndLock')]
    public function testARowChangingStateBetweenTheSelectionAndTheLockIsRecheckedUnderItsLock(string $sql, string $expected, int $cancelled, string $why): void
    {
        $c = static::getContainer();
        $real = $c->get(\App\Notification\Domain\NotificationOutboxRepository::class);
        $connection = $this->em()->getConnection();
        $ids = $connection->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM notification_outbox WHERE status = \'PENDING\' ORDER BY id LIMIT 1');
        self::assertCount(1, $ids);
        $target = (string) $ids[0];
        $connection->executeStatement('UPDATE notification_outbox SET status = \'SUPPRESSED\', last_error_code = \'TEST_AUTRE\', processed_at = UTC_TIMESTAMP() WHERE status = \'PENDING\' AND id <> UNHEX(:id)', ['id' => $target]);
        $stub = $this->createStub(\App\Notification\Domain\NotificationOutboxRepository::class);
        $stub->method('lock')->willReturnCallback(function (Uuid $id) use ($real, $sql, $connection, $target) {
            if ('' !== $sql && str_replace('-', '', $id->toRfc4122()) === $target) {
                $connection->executeStatement($sql, ['id' => $target]); // la « concurrence » : changement d'état juste avant le verrou
            }

            return $real->lock($id);
        });
        $neutralizer = new \App\Notification\Application\EventClosureNotifications($connection, $stub, $c->get(\App\Notification\Domain\NotificationSecretRepository::class), $c->get(\App\Permit\Application\SystemAccessLinks::class));

        $report = $c->get(\App\Shared\Application\TransactionRunner::class)->run(fn (): array => $neutralizer->neutralize($this->event->id(), $this->clockService()->now()));

        self::assertSame($cancelled, $report['cancelled'], $why);
        self::assertSame($expected, $connection->fetchOne('SELECT CONCAT(status, \'/\', COALESCE(last_error_code, \'-\')) FROM notification_outbox WHERE id = UNHEX(:id)', ['id' => $target]), $why);
    }

    public function testAReservationWithAValidLeaseLeftByTheClosureIsNeutralisedOnceItsLeaseHasExpiredWithoutAnySending(): void
    {
        $id = (string) $this->em()->getConnection()->fetchOne('SELECT LOWER(HEX(id)) FROM notification_outbox WHERE status = \'PENDING\' ORDER BY id LIMIT 1');
        $this->sql('UPDATE notification_outbox SET status = \'RESERVED\', attempts = 1, first_attempt_at = UTC_TIMESTAMP(), leased_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = UNHEX(:id)', ['id' => $id]);
        $this->close();
        self::assertSame('RESERVED', $this->em()->getConnection()->fetchOne('SELECT status FROM notification_outbox WHERE id = UNHEX(:id)', ['id' => $id]), 'bail valide : laissée par la clôture');

        // Le processus qui la tenait est mort : plus tard, un distributeur la récupère à l\'expiration du bail et la neutralise sans rien envoyer.
        $later = new \Symfony\Component\Clock\MockClock($this->clockService()->now()->modify('+11 minutes'));
        NotificationKit::dispatcher(static::getContainer(), $this->keys, $later, $this->mailer)->run();

        self::assertSame('SUPPRESSED/EVENT_CLOSED', $this->em()->getConnection()->fetchOne('SELECT CONCAT(status, \'/\', last_error_code) FROM notification_outbox WHERE id = UNHEX(:id)', ['id' => $id]));
        self::assertSame([], $this->mailer->tried);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM notification_outbox WHERE status IN (\'PENDING\', \'RESERVED\')'), 'aucune ligne abandonnée après stabilisation');
    }

    public function testASecondClosureIsRefusedWithoutAnyMutation(): void
    {
        $this->close();
        $audit = $this->scalarInt('SELECT COUNT(*) FROM audit_log');
        $states = $this->states();

        try {
            static::getContainer()->get(\App\Event\Application\Service\EventLifecycleService::class)->close($this->managed($this->admin), $this->event->id()->toRfc4122());
            self::fail('Une seconde clôture doit être refusée.');
        } catch (\Throwable $e) {
            self::assertInstanceOf(\App\Shared\Domain\DomainRuleViolation::class, $e);
        }

        self::assertSame($audit, $this->scalarInt('SELECT COUNT(*) FROM audit_log'));
        self::assertSame($states, $this->states());
        self::assertTrue(Uuid::isValid($this->event->id()->toRfc4122()));
    }
}
