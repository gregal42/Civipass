<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Identity\Domain\User;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;

/**
 * RECETTE avec MailCatcher (LOT 05.3.3) : le vrai Symfony Mailer, un vrai serveur SMTP, des messages lus tels que MailCatcher les a reçus.
 * Ignorée sauf si `CIVIPASS_RECETTE_MAILCATCHER=1` et MailCatcher joignable (elle ne fait pas partie de `make quality`).
 *   docker exec -e CIVIPASS_RECETTE_MAILCATCHER=1 saas_civipass_php-fpm php bin/phpunit tests/Integration/Notification/MailCatcherRecetteTest.php.
 */
#[Group('requires-request-schema')]
#[Group('recette')]
final class MailCatcherRecetteTest extends MunicipalDecisionTestCase
{
    private const API = 'http://saas_civipass_mailcatcher:1080';
    private const DSN = 'smtp://saas_civipass_mailcatcher:1025';

    private int $baseline = 0;
    private string $baseUrl = '';

    protected function setUp(): void
    {
        if ('1' !== getenv('CIVIPASS_RECETTE_MAILCATCHER')) {
            self::markTestSkipped('Recette MailCatcher : définir CIVIPASS_RECETTE_MAILCATCHER=1.');
        }
        if (false === @file_get_contents(self::API.'/messages')) {
            self::markTestSkipped('MailCatcher injoignable.');
        }
        parent::setUp();
        // Domaine des liens = DEFAULT_URI de la configuration réelle (.env.local), jamais une valeur de test : la recette vérifie ce que l'agent verra.
        $local = (string) @file_get_contents(\dirname(__DIR__, 3).'/.env.local');
        if (1 !== preg_match('/^DEFAULT_URI=(\S+)$/m', $local, $m)) {
            self::markTestSkipped('DEFAULT_URI absent de .env.local : domaine des liens inconnu.');
        }
        $this->baseUrl = rtrim($m[1], '/');
        putenv('DEFAULT_URI='.$this->baseUrl);
        $this->baseline = $this->lastId(); // la boîte de développement n'est JAMAIS vidée : seuls les messages arrivés ensuite comptent
        [$keyring, $active] = NotificationKit::newKey('k1');
        putenv('NOTIFICATION_KEYRING_TEST='.$keyring);
        putenv('NOTIFICATION_ACTIVE_KEY_ID_TEST='.$active);
    }

    protected function tearDown(): void
    {
        foreach (['NOTIFICATION_KEYRING_TEST', 'NOTIFICATION_ACTIVE_KEY_ID_TEST', 'NOTIFICATION_TEST_CLOCK_OFFSET', 'MAILER_DSN', 'DEFAULT_URI'] as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    private function api(string $method, string $path): string
    {
        return (string) @file_get_contents(self::API.$path, false, stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 5]]));
    }

    private function lastId(): int
    {
        /** @var list<array<string, mixed>> $list */
        $list = json_decode($this->api('GET', '/messages'), true, 512, \JSON_THROW_ON_ERROR);

        return [] === $list ? 0 : max(array_map(static fn (array $m): int => (int) $m['id'], $list));
    }

    /** @return list<array<string, mixed>> messages reçus depuis le début du test */
    private function messages(): array
    {
        /** @var list<array<string, mixed>> $list */
        $list = json_decode($this->api('GET', '/messages'), true, 512, \JSON_THROW_ON_ERROR);

        return array_values(array_filter($list, fn (array $m): bool => (int) $m['id'] > $this->baseline));
    }

    private function dispatch(string $dsn, int $offsetSeconds): void
    {
        putenv('MAILER_DSN='.$dsn);
        putenv('NOTIFICATION_TEST_CLOCK_OFFSET='.$offsetSeconds);
        $raw = ParallelPhp::run('recette-dispatch.php', [[]])[0];
        $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['ok'] ?? false, $raw['stdout'].' '.$raw['stderr']);
    }

    /** Approuve la demande par la commune organisatrice ; retourne l'identifiant de la demande. */
    private function approveOrganizer(): string
    {
        $request = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
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
            ['QW-123-ER'],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $one = $this->em->find(User::class, $this->instructor->id()) ?? $this->instructor;
        $this->instruction()->approve($one, $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));

        return $request;
    }

    private function approvePartner(string $request): void
    {
        $two = $this->em->find(User::class, $this->partnerInstructor->id()) ?? $this->partnerInstructor;
        $this->instruction()->approve($two, $this->partner->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
    }

    public function testBothMessagesReachMailCatcherThroughTheRealMailer(): void
    {
        $request = $this->approveOrganizer();

        $this->dispatch(self::DSN, 0);      // première approbation (aucun délai) : message de mise à disposition
        $this->approvePartner($request);    // commune supplémentaire APRÈS la composition du premier message : extension nécessaire
        $this->dispatch(self::DSN, 700);    // après les 10 minutes de silence

        $messages = $this->messages();
        self::assertCount(2, $messages);
        usort($messages, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        self::assertSame('Votre laissez-passer LP-2026-0001 est disponible', $messages[0]['subject']);
        self::assertSame('Votre laissez-passer LP-2026-0001 : droits étendus', $messages[1]['subject']);
        foreach ($messages as $message) {
            self::assertSame(['<lea@exemple.fr>'], $message['recipients']);
            self::assertSame('<no-reply@civipass.invalid>', $message['sender']);
        }
        $first = $this->api('GET', '/messages/'.$messages[0]['id'].'.source');
        $outboxId = bin2hex((string) $this->em->getConnection()->fetchOne('SELECT id FROM notification_outbox WHERE type = \'PERMIT_ISSUED\''));
        self::assertStringContainsString('Message-ID: <'.substr($outboxId, 0, 8).'-', $first);
        self::assertStringContainsString('Auto-Submitted: auto-generated', $first);
        self::assertStringContainsString('multipart/alternative', $first, 'texte et HTML');
        self::assertStringNotContainsString('<img', $first);
        self::assertSame(1, preg_match('#(https?://[^\s"/]+)/laissez-passer/([A-Za-z0-9_-]{43})#', quoted_printable_decode($first), $m), 'lien du bénéficiaire');
        self::assertSame($this->baseUrl, $m[1], 'le lien pointe vers le domaine configuré de l\'application (DEFAULT_URI)');
        self::assertStringNotContainsString('civipass.test', $first);
        self::assertSame(hash('sha256', $m[2]), $this->em->getConnection()->fetchOne('SELECT secret_hash FROM permit_access_token'), 'le lien reçu est celui créé');
        foreach (['QW-123-ER', 'QW123ER', 'Lilas', 'Brest'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, quoted_printable_decode($first));
        }
        $second = quoted_printable_decode($this->api('GET', '/messages/'.$messages[1]['id'].'.source'));
        self::assertStringNotContainsString('/laissez-passer/', $second, 'aucun nouveau lien');
        self::assertSame(1, $this->rows('permit_access_token'));
        self::assertSame(['SENT', 'SENT'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM notification_outbox ORDER BY type'));
        self::assertSame(0, $this->rows('notification_secret'));
    }

    public function testASmtpOutageIsRetriedWithTheSameMessageIdAndTheSameLink(): void
    {
        $this->approveOrganizer();

        $this->dispatch('smtp://127.0.0.1:1', 0); // aucun serveur SMTP : panne temporaire
        $row = $this->em->getConnection()->fetchAssociative('SELECT status, attempts, last_error_code FROM notification_outbox WHERE type = \'PERMIT_ISSUED\'');
        self::assertSame(['PENDING', '1', 'SMTP_TEMPORARY'], [$row['status'], (string) $row['attempts'], $row['last_error_code']]);
        self::assertCount(0, $this->messages());
        $hash = $this->em->getConnection()->fetchOne('SELECT secret_hash FROM permit_access_token');

        $this->dispatch(self::DSN, 120); // retour du service, après le délai d'une minute

        $messages = $this->messages();
        self::assertCount(1, $messages);
        $source = quoted_printable_decode($this->api('GET', '/messages/'.$messages[0]['id'].'.source'));
        self::assertSame(1, preg_match('#(https?://[^\s"/]+)/laissez-passer/([A-Za-z0-9_-]{43})#', $source, $m));
        self::assertSame($this->baseUrl, $m[1]);
        self::assertSame($hash, hash('sha256', $m[2]), 'même lien après la panne');
        self::assertSame(1, $this->rows('permit_access_token'));
        self::assertSame('SENT', $this->em->getConnection()->fetchOne('SELECT status FROM notification_outbox WHERE type = \'PERMIT_ISSUED\''));
    }
}
