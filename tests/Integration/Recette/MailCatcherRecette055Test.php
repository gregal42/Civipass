<?php

declare(strict_types=1);

namespace App\Tests\Integration\Recette;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Identity\Domain\User;
use App\Permit\Application\AuthorizationPeriodService;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\AuthorizationZoneService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitTokenReplacementService;
use App\Permit\Application\PermitVehicleService;
use App\Permit\Application\Result\TokenReplacementRequest;
use App\Permit\Application\Result\VehicleChangeRequest;
use App\Permit\Application\Result\ZoneChangeRequest;
use App\Permit\Domain\PeriodEnd;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\ProposableZones;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * RECETTE (LOT 05.5.6) avec MailCatcher : les messages prioritaires des LOT 05.5.1 à 05.5.5 passent par le VRAI Symfony Mailer et un vrai serveur SMTP, puis sont lus tels que
 * MailCatcher les a reçus (en-têtes, texte, HTML, source MIME). Ce n'est PAS un client de messagerie réel (rendu, dossier courrier indésirable, lecture sur téléphone : recette manuelle).
 * Ignorée sauf si `CIVIPASS_RECETTE_MAILCATCHER=1` et MailCatcher joignable (elle ne fait pas partie de `make quality`) :
 *   docker exec -e CIVIPASS_RECETTE_MAILCATCHER=1 saas_civipass_php-fpm php bin/phpunit tests/Integration/Recette/MailCatcherRecette055Test.php.
 * La boîte de développement n'est JAMAIS vidée : seuls les messages arrivés après le début du test comptent.
 */
#[Group('requires-request-schema')]
#[Group('recette')]
final class MailCatcherRecette055Test extends MunicipalDecisionTestCase
{
    private const API = 'http://saas_civipass_mailcatcher:1080';
    private const DSN = 'smtp://saas_civipass_mailcatcher:1025';
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private int $baseline = 0;
    private EventZone $zoneB;

    protected function setUp(): void
    {
        if ('1' !== getenv('CIVIPASS_RECETTE_MAILCATCHER')) {
            self::markTestSkipped('Recette MailCatcher : définir CIVIPASS_RECETTE_MAILCATCHER=1.');
        }
        if (false === @file_get_contents(self::API.'/messages')) {
            self::markTestSkipped('MailCatcher injoignable.');
        }
        parent::setUp();
        $local = (string) @file_get_contents(\dirname(__DIR__, 3).'/.env.local');
        if (1 !== preg_match('/^DEFAULT_URI=(\S+)$/m', $local, $m)) {
            self::markTestSkipped('DEFAULT_URI absent de .env.local.');
        }
        putenv('DEFAULT_URI='.rtrim($m[1], '/'));
        $this->baseline = $this->lastId();
        [$keyring, $active] = NotificationKit::newKey('k1');
        putenv('NOTIFICATION_KEYRING_TEST='.$keyring);
        putenv('NOTIFICATION_ACTIVE_KEY_ID_TEST='.$active);
        $organizer = $this->em->find(EventParticipation::class, $this->organizerParticipation->id()) ?? self::fail();
        $this->zoneB = F::readyZone($organizer);
        $this->save($this->zoneB->createdBy(), $this->zoneB);
        foreach ([[$this->organizerZone, 'Zone A'], [$this->zoneB, 'Zone B']] as [$zone, $label]) {
            $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => $label, 'id' => $zone->id()->toBinary()]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['NOTIFICATION_KEYRING_TEST', 'NOTIFICATION_ACTIVE_KEY_ID_TEST', 'NOTIFICATION_TEST_CLOCK_OFFSET', 'MAILER_DSN', 'DEFAULT_URI'] as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    private function api(string $path): string
    {
        return (string) @file_get_contents(self::API.$path, false, stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 5]]));
    }

    private function lastId(): int
    {
        /** @var list<array<string, mixed>> $list */
        $list = json_decode($this->api('/messages'), true, 512, \JSON_THROW_ON_ERROR);

        return [] === $list ? 0 : max(array_map(static fn (array $m): int => (int) $m['id'], $list));
    }

    /** @return list<array<string, mixed>> messages arrivés depuis le début du test, du plus ancien au plus récent */
    private function messages(): array
    {
        /** @var list<array<string, mixed>> $list */
        $list = json_decode($this->api('/messages'), true, 512, \JSON_THROW_ON_ERROR);
        $mine = array_values(array_filter($list, fn (array $m): bool => (int) $m['id'] > $this->baseline));
        usort($mine, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $mine;
    }

    private function source(int $id): string
    {
        return quoted_printable_decode($this->api('/messages/'.$id.'.source'));
    }

    private function dispatch(string $dsn, int $offsetSeconds): void
    {
        putenv('MAILER_DSN='.$dsn);
        putenv('NOTIFICATION_TEST_CLOCK_OFFSET='.$offsetSeconds);
        $raw = ParallelPhp::run('recette-dispatch.php', [[]])[0];
        $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['ok'] ?? false, $raw['stdout'].' '.$raw['stderr']);
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function approved(): string
    {
        $id = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            '12 rue des Lilas 29200 Brest',
            'lea@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER', 'TY-456-UI'],
            [$this->organizerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));

        return $id;
    }

    /** @return list<string> */
    private function secrets(): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT token FROM permit_verification_token'));
    }

    private function assertSafe(string $source, string $context): void
    {
        $decoded = $source;
        foreach ([...$this->secrets(), ...array_map('strtoupper', $this->secrets()), 'QW-123-ER', 'QW123ER', 'TY-456-UI', 'TY456UI', 'Lilas', 'Brest', self::COMMENT, 'OTHER', 'Autre motif', '/v/'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $decoded, $context.' : donnée interdite dans le message reçu : '.$forbidden);
        }
    }

    public function testEveryPriorityMessageOfTheOperationsReachesMailCatcherThroughTheRealMailerWithoutSensitiveData(): void
    {
        $requestId = $this->approved();
        $c = self::getContainer();
        $organizer = $this->organizer->id()->toRfc4122();
        $agent = $this->managed($this->instructor);
        $this->dispatch(self::DSN, 700); // mise à disposition
        $reference = (string) $this->em->getConnection()->fetchOne('SELECT reference FROM permit_permit');

        // 1. zones : ajout puis retrait de la zone d'origine (opération prioritaire qui couvre l'ajout)
        $zones = new AuthorizationZoneService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $c->get(EventZoneRepository::class), $c->get(ProposableZones::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class));
        $zones->change($agent, $organizer, $requestId, new ZoneChangeRequest([$this->zoneB->id()->toRfc4122()], [], [], 'OTHER', null, null, self::COMMENT));
        $zones->change($agent, $organizer, $requestId, new ZoneChangeRequest([], [$this->organizerZone->id()->toRfc4122()], [], null, 'OTHER', null, self::COMMENT));
        $this->dispatch(self::DSN, 800);

        // 2. plaques : retrait (prioritaire)
        $plateId = Uuid::fromBinary((string) $this->em->getConnection()->fetchOne("SELECT id FROM permit_vehicle WHERE registration = 'TY456UI'"))->toRfc4122();
        (new PermitVehicleService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $c->get(PermitRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->change($agent, $organizer, $requestId, new VehicleChangeRequest([], [$plateId], [], [], null, 'OTHER', null, null, self::COMMENT, true));
        $this->dispatch(self::DSN, 900);

        // 3. QR : remplacement (prioritaire)
        (new PermitTokenReplacementService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $c->get(PermitRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->replace($this->managed($this->adminOnly), $organizer, $requestId, new TokenReplacementRequest(Uuid::v7()->toRfc4122(), 'OTHER', self::COMMENT, true));
        $this->dispatch(self::DSN, 1000);

        // 4. période : raccourcissement (prioritaire)
        (new AuthorizationPeriodService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRepository::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->shorten($agent, $organizer, $requestId, PeriodEnd::custom('2026-06-13', '12:00'), 'OTHER', self::COMMENT, true);
        $this->dispatch(self::DSN, 1100);

        // 5. révocation (prioritaire)
        (new AuthorizationRevocationService($c->get(EventTransactions::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->revoke($agent, $organizer, $requestId, 'OTHER', self::COMMENT, true);
        $this->dispatch(self::DSN, 1200);

        $messages = $this->messages();
        $subjects = array_map(static fn (array $m): string => (string) $m['subject'], $messages);
        self::assertSame([
            sprintf('Votre laissez-passer %s est disponible', $reference),
            sprintf('Votre laissez-passer %s : zones retirées', $reference),
            sprintf('Votre laissez-passer %s : véhicules retirés ou remplacés', $reference),
            sprintf('Votre laissez-passer %s : QR code remplacé', $reference),
            sprintf('Votre laissez-passer %s : fin des droits avancée', $reference),
            sprintf('Votre laissez-passer %s : une autorisation a été retirée', $reference),
        ], $subjects, 'un message par événement prioritaire, dans l\'ordre ; l\'ajout de zone, couvert par le retrait, ne part pas seul');
        foreach ($messages as $i => $message) {
            self::assertSame(['<lea@exemple.fr>'], $message['recipients']);
            $source = $this->source((int) $message['id']);
            self::assertStringContainsString('Auto-Submitted: auto-generated', $source);
            self::assertStringContainsString('multipart/alternative', $source, 'texte et HTML');
            self::assertStringContainsString('Message-ID: <', $source);
            if ($i > 0) {
                self::assertStringNotContainsString('/laissez-passer/', $source, 'seul le premier message porte le lien du bénéficiaire');
            }
            $this->assertSafe($source, $subjects[$i]);
        }
        $qr = $this->source((int) $messages[3]['id']);
        self::assertStringContainsString('ne fonctionnent plus', $qr);
        self::assertSame(['SENT', 'SENT', 'SENT', 'SENT', 'SENT'], array_slice($this->em->getConnection()->fetchFirstColumn("SELECT status FROM notification_outbox WHERE type <> 'ZONES_CHANGED' ORDER BY occurred_at, id"), 0, 5));
    }

    public function testASmtpOutageOnTheQrMessageIsRetriedOnceWithTheSameMessageId(): void
    {
        $requestId = $this->approved();
        $c = self::getContainer();
        $this->dispatch(self::DSN, 700);
        $count = \count($this->messages());
        (new PermitTokenReplacementService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $c->get(PermitRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->replace($this->managed($this->adminOnly), $this->organizer->id()->toRfc4122(), $requestId, new TokenReplacementRequest(Uuid::v7()->toRfc4122(), 'LOST', null, true));

        $this->dispatch('smtp://127.0.0.1:1', 800); // aucun serveur : panne temporaire
        $row = $this->em->getConnection()->fetchAssociative("SELECT status, attempts, last_error_code FROM notification_outbox WHERE type = 'QR_REPLACED'");
        self::assertSame(['PENDING', '1', 'SMTP_TEMPORARY'], [$row['status'], (string) $row['attempts'], $row['last_error_code']]);
        self::assertCount($count, $this->messages(), 'rien n\'est parti pendant la panne');

        $this->dispatch(self::DSN, 900); // retour du service, après le délai de reprise
        $this->dispatch(self::DSN, 1000); // un passage de plus : rien ne repart

        $messages = $this->messages();
        self::assertCount($count + 1, $messages, 'le message part UNE seule fois après la reprise');
        self::assertSame('SENT', $this->em->getConnection()->fetchOne("SELECT status FROM notification_outbox WHERE type = 'QR_REPLACED'"));
        $this->assertSafe($this->source((int) end($messages)['id']), 'QR après reprise');
    }
}
