<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Identity\Domain\User;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\UI\Console\DispatchCommand;
use App\Notification\UI\Console\StatusCommand;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Tester\CommandTester;

/** Supervision et commande de distribution (LOT 05.3.3, D14) : codes de sortie 0 / 1 / 2, aucune donnée personnelle ni secret dans les sorties. */
#[Group('requires-request-schema')]
final class NotificationStatusCommandTest extends MunicipalDecisionTestCase
{
    private function statusTester(?string $keyring = 'valid'): CommandTester
    {
        $provider = 'valid' === $keyring ? NotificationKit::provider(...NotificationKit::newKey('k1')) : NotificationKit::provider(null, null);

        return new CommandTester(new StatusCommand(self::getContainer()->get(NotificationOutboxRepository::class), $provider, $this->clock));
    }

    private function approve(): void
    {
        $user = $this->em->find(User::class, $this->instructor->id()) ?? $this->instructor;
        $request = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            '12 rue des Lilas',
            'lea@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER'],
            [$this->organizerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $this->instruction()->approve($user, $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
    }

    public function testAnEmptyOutboxWithAValidKeyRingIsOk(): void
    {
        $tester = $this->statusTester();

        self::assertSame(StatusCommand::OK, $tester->execute([]));
        self::assertStringContainsString('OK', $tester->getDisplay());
    }

    public function testAMissingKeyRingIsCritical(): void
    {
        $tester = $this->statusTester('none');

        self::assertSame(StatusCommand::CRITICAL, $tester->execute([]));
        self::assertStringContainsString('KEYRING_EMPTY', $tester->getDisplay());
    }

    public function testAFreshDueMessageIsOkButAnOldOneMeansTheDistributorIsLate(): void
    {
        $this->approve();
        self::assertSame(StatusCommand::OK, $this->statusTester()->execute([]), 'message échu depuis 0 s');

        $this->clock->sleep(901);
        $tester = $this->statusTester();
        self::assertSame(StatusCommand::CRITICAL, $tester->execute([]));
        self::assertStringContainsString('le distributeur ne passe plus', $tester->getDisplay());
        self::assertSame(StatusCommand::OK, $this->statusTester()->execute(['--max-due-age' => '1000']), 'seuil configurable');
    }

    public function testAStuckReservationIsCritical(): void
    {
        $this->approve();
        $this->sql('UPDATE notification_outbox SET status = \'RESERVED\', attempts = 1, leased_until = :l', ['l' => $this->clock->now()->modify('-6 minutes')->format('Y-m-d H:i:s')]);

        $tester = $this->statusTester();

        self::assertSame(StatusCommand::CRITICAL, $tester->execute([]));
        self::assertStringContainsString('réservation(s) bloquée(s)', $tester->getDisplay());
        self::assertSame(StatusCommand::OK, $this->statusTester()->execute(['--lease-grace' => '3600', '--max-due-age' => '99999']), 'bail récent : toléré');
    }

    public function testAnAbandonedMessageIsAWarningWithAConfigurableThreshold(): void
    {
        $this->approve();
        $this->sql('UPDATE notification_outbox SET status = \'ABANDONED\', last_error_code = \'SMTP_PERMANENT\', processed_at = :now', ['now' => $this->clock->now()->format('Y-m-d H:i:s')]);

        $tester = $this->statusTester();

        self::assertSame(StatusCommand::WARNING, $tester->execute([]));
        self::assertStringContainsString('SMTP_PERMANENT', $tester->getDisplay());
        self::assertSame(StatusCommand::OK, $this->statusTester()->execute(['--max-failed' => '1']));
        $this->clock->sleep(2 * 86400);
        self::assertSame(StatusCommand::OK, $this->statusTester()->execute([]), 'hors fenêtre de 24 h');
    }

    public function testMessagesWaitingForAKeyAreCriticalAndOverdueSecretsToo(): void
    {
        $this->approve();
        $this->sql('UPDATE notification_outbox SET last_error_code = \'CRYPTO_UNAVAILABLE\', available_at = :later', ['later' => $this->clock->now()->modify('+1 day')->format('Y-m-d H:i:s')]);
        self::assertSame(StatusCommand::CRITICAL, $this->statusTester()->execute([]));

        $this->sql('UPDATE notification_outbox SET last_error_code = NULL');
        $this->sql('INSERT INTO notification_secret (id, outbox_id, access_token_id, key_id, nonce, ciphertext, created_at, expires_at) SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), id, id, \'k1\', REPEAT(\'n\', 24), REPEAT(\'c\', 59), :past, :past FROM notification_outbox', ['past' => $this->clock->now()->modify('-3 days')->format('Y-m-d H:i:s')]);
        $tester = $this->statusTester();
        self::assertSame(StatusCommand::CRITICAL, $tester->execute([]));
        self::assertStringContainsString('secret(s) échu(s)', $tester->getDisplay());
    }

    public function testTheOutputNeverContainsPersonalDataOrSecrets(): void
    {
        $this->approve();
        $this->sql('UPDATE notification_outbox SET status = \'ABANDONED\', last_error_code = \'SMTP_PERMANENT\', processed_at = :now', ['now' => $this->clock->now()->format('Y-m-d H:i:s')]);
        $tester = $this->statusTester('none');
        $tester->execute([]);
        $display = $tester->getDisplay();

        foreach (['lea@exemple.fr', 'Dupont', 'Léa', 'Lilas', 'QW-123-ER', 'LP-2026', '@'] as $personal) {
            self::assertStringNotContainsString($personal, $display);
        }
    }

    public function testTheDispatchCommandPrintsCountersOnlyAndSucceeds(): void
    {
        $this->approve();
        $mailer = new RecordingMailer($this->em->getConnection());
        $dispatcher = NotificationKit::dispatcher(self::getContainer(), NotificationKit::provider(...NotificationKit::newKey('k1')), $this->clock, $mailer);
        $tester = new CommandTester(new DispatchCommand($dispatcher));

        self::assertSame(0, $tester->execute(['--limit' => '10', '--max-seconds' => '20']));

        self::assertStringContainsString('sent', $tester->getDisplay());
        self::assertCount(1, $mailer->sent);
        foreach (['lea@exemple.fr', 'laissez-passer/', 'LP-2026', 'Léa'] as $leak) {
            self::assertStringNotContainsString($leak, $tester->getDisplay());
        }
    }
}
