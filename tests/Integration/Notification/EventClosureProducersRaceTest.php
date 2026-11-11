<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\ClosedEventScenarioTestCase;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\RecordingMailer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;

/**
 * Course RÉELLE (processus PHP sur MySQL) entre la clôture d'un événement et CHAQUE producteur de notifications (LOT 05.6.2, phase 1) : modification de zones,
 * de plaques, de période, remplacement du QR, délivrance (approbation) et notification manuelle. Un verrou tenu par le test fait attendre le producteur
 * juste avant l'écriture ; la clôture démarre pendant l'attente, puis tout est relâché. Invariants métier vérifiés, pas la seule absence d'exception :
 *  - producteur d'abord : son opération est validée, son message est produit PUIS supprimé par la clôture (`EVENT_CLOSED`), rien ne reste en attente ni réservé ;
 *  - clôture d'abord : le producteur est refusé et AUCUNE ligne d'outbox, révision ou laissez-passer n'est créée.
 * Les messages déjà engagés dans le transport SMTP avant la clôture restent la limite documentée (voir EventClosureNotificationsTest).
 */
final class EventClosureProducersRaceTest extends ClosedEventScenarioTestCase
{
    private const PLATE_ADDED = 'KM-777-LP';

    private ?Connection $hold = null;

    protected function tearDown(): void
    {
        if (null !== $this->hold) {
            try {
                if ($this->hold->isTransactionActive()) {
                    $this->hold->rollBack();
                }
            } finally {
                $this->hold->close();
            }
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function producers(): iterable
    {
        foreach (['zones-add', 'vehicles-add', 'period-extend', 'token-replace', 'manual', 'approve'] as $kind) {
            yield $kind => [$kind];
        }
    }

    private function submit(string $suffix): string
    {
        return static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Race'.$suffix, 'Léa', null, 'race'.$suffix.'@exemple.fr', null, 'private_person', 'home', null, TravelMode::Vehicle, [sprintf('RA-%03d-CE', random_int(100, 999))], [$this->organizerZone->id()->toRfc4122()], true),
            sprintf('race-%s-%08d', $suffix, random_int(1, 99999999)),
        )->requestId;
    }

    /**
     * Prépare le producteur : retourne [kind, utilisateur, dossier, charge JSON, requête SQL du verrou de blocage].
     *
     * @return array{string, string, string, array<string, string>, string}
     */
    private function prepare(string $kind): array
    {
        $instructor = $this->instructor->id()->toRfc4122();
        $permitLock = fn (string $request): string => 'SELECT id FROM permit_permit WHERE request_id = UNHEX(\''.str_replace('-', '', $request).'\') FOR UPDATE';
        switch ($kind) {
            case 'zones-add':
                $extra = F::readyZone($this->em()->find(EventParticipation::class, $this->organizerParticipation->id()) ?? self::fail('Participation absente.'));
                $this->save($extra->createdBy(), $extra);
                $zone = $this->em()->find(EventZone::class, $extra->id()) ?? self::fail('Zone absente.');

                return [$kind, $instructor, $this->requestId, ['zone' => $zone->id()->toRfc4122()], $permitLock($this->requestId)];
            case 'vehicles-add':
                return [$kind, $instructor, $this->requestId, ['plate' => self::PLATE_ADDED], $permitLock($this->requestId)];
            case 'period-extend':
                $request = $this->submit('period');
                static::getContainer()->get(MunicipalInstructionService::class)->approve($this->managed($this->instructor), $this->organizerId, $request, ApprovalDuration::fixed(DurationMode::H24));
                $this->em()->clear();

                return [$kind, $instructor, $request, [], $permitLock($request)];
            case 'token-replace':
                $reference = (string) $this->em()->getConnection()->fetchOne('SELECT reference FROM permit_permit WHERE request_id = UNHEX(:r)', ['r' => str_replace('-', '', $this->requestId)]);

                return [$kind, $this->admin->id()->toRfc4122(), $this->requestId, ['reference' => $reference], $permitLock($this->requestId)];
            case 'manual':
                // Une demande manuelle n'est créée que si le premier message est déjà parti : on le distribue d'abord (sinon elle est dédoublonnée).
                $keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
                NotificationKit::dispatcher(static::getContainer(), $keys, static::getContainer()->get(ClockInterface::class), new RecordingMailer($this->em()->getConnection()))->run();
                $this->em()->clear();

                return [$kind, $instructor, $this->requestId, [], $permitLock($this->requestId)];
            default:
                $request = $this->submit('approve');

                return [$kind, $instructor, $request, [], 'SELECT id FROM request_permit_request_municipality WHERE request_id = UNHEX(\''.str_replace('-', '', $request).'\') AND municipality_id = UNHEX(\''.str_replace('-', '', $this->organizerId).'\') FOR UPDATE'];
        }
    }

    /** @return array{outbox: int, revisions: int, permits: int} */
    private function counts(): array
    {
        $c = $this->em()->getConnection();

        return [
            'outbox' => (int) $c->fetchOne('SELECT COUNT(*) FROM notification_outbox'),
            'revisions' => (int) $c->fetchOne('SELECT COUNT(*) FROM permit_revision'),
            'permits' => (int) $c->fetchOne('SELECT COUNT(*) FROM permit_permit'),
        ];
    }

    /** Laisse au processus le temps d'atteindre le verrou tenu (le privilège PROCESS, nécessaire pour lire les attentes de verrou, n'est pas accordé) ; l'entrelacement est ensuite PROUVÉ par la durée d'attente de la clôture. */
    private function letReachTheLock(): void
    {
        usleep(1800000);
    }

    /**
     * @param array{resource, array<int, resource>, string} $process
     *
     * @return array<string, mixed>
     */
    private function decode(array $process): array
    {
        $result = ParallelPhp::finish($process);
        $decoded = json_decode($result['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $result['stderr']);

        return $decoded;
    }

    #[DataProvider('producers')]
    public function testAProducerValidatedBeforeTheClosureHasItsMessageCancelledByTheClosure(string $kind): void
    {
        [$kind, $user, $request, $payload, $lock] = $this->prepare($kind);
        $before = $this->counts();
        $commune = $this->organizerId;

        $params = $this->em()->getConnection()->getParams();
        $this->hold = DriverManager::getConnection($params);
        $this->hold->beginTransaction();
        $this->hold->executeQuery($lock);

        $producer = ParallelPhp::start('closure-producer.php', [$kind, $user, $commune, $request, json_encode($payload, \JSON_THROW_ON_ERROR)]);
        $this->letReachTheLock();
        $startedClosure = microtime(true);
        $closure = ParallelPhp::start('notification-process.php', ['close', $this->admin->id()->toRfc4122(), $this->event->id()->toRfc4122(), '0']);
        $this->letReachTheLock();
        $this->hold->rollBack();

        $produced = $this->decode($producer);
        $closed = $this->decode($closure);

        self::assertTrue($closed['ok'], 'la clôture aboutit : '.json_encode($closed));
        self::assertGreaterThan(1.0, (float) $closed['committedAt'] - $startedClosure, 'la clôture a ATTENDU le producteur bloqué (l\'entrelacement testé a bien eu lieu)');
        self::assertTrue($produced['ok'], 'le producteur validé avant la clôture aboutit : '.json_encode($produced));
        $after = $this->counts();
        self::assertGreaterThan($before['outbox'], $after['outbox'], 'le producteur a bien produit une intention de notification');
        $connection = $this->em()->getConnection();
        self::assertSame('CLOSED', $connection->fetchOne('SELECT status FROM event_event'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE status IN (\'PENDING\', \'RESERVED\')'), 'aucun message en attente ni réservé après la clôture');
        $new = $connection->fetchAllAssociative('SELECT status, last_error_code FROM notification_outbox ORDER BY id DESC LIMIT '.($after['outbox'] - $before['outbox']));
        foreach ($new as $row) {
            self::assertSame(['SUPPRESSED', 'EVENT_CLOSED'], [$row['status'], $row['last_error_code']], 'le message du producteur est supprimé par la clôture');
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_secret'));
        // Compteurs de clôture : exacts malgré la course (la clôture a attendu le producteur ; rien n'écrit après elle).
        $stored = \App\Event\Domain\ClosureStatistics::fromArray(json_decode((string) $connection->fetchOne('SELECT closure_statistics FROM event_event'), true, 512, \JSON_THROW_ON_ERROR));
        self::assertEquals(static::getContainer()->get(\App\Event\Application\ClosureCounters::class)->count($this->event->id()), $stored, 'compteurs = état final de la base, décision concurrente comprise');
    }

    #[DataProvider('producers')]
    public function testAProducerStartedAfterTheClosureIsRefusedAndCreatesNothing(string $kind): void
    {
        [$kind, $user, $request, $payload] = $this->prepare($kind);
        $this->close();
        $before = $this->counts();
        $states = $this->em()->getConnection()->fetchAllAssociative('SELECT status, last_error_code, COUNT(*) AS n FROM notification_outbox GROUP BY status, last_error_code');

        $outcome = $this->decode(ParallelPhp::start('closure-producer.php', [$kind, $user, $this->organizerId, $request, json_encode($payload, \JSON_THROW_ON_ERROR)]));

        self::assertFalse($outcome['ok'], 'le producteur est refusé sur un événement clos : '.json_encode($outcome));
        self::assertSame($before, $this->counts(), 'aucune ligne d\'outbox, révision ni laissez-passer créée');
        self::assertSame($states, $this->em()->getConnection()->fetchAllAssociative('SELECT status, last_error_code, COUNT(*) AS n FROM notification_outbox GROUP BY status, last_error_code'));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE status IN (\'PENDING\', \'RESERVED\')'));
    }
}
