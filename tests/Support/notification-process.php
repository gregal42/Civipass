<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence des notifications, LOT 05.3.2) : `provision <outboxId>`, `purge`, `manual`, `record`, `dispatch` ou `dispatch-crash` (le processus meurt après l'acceptation SMTP ; boîte aux lettres : NOTIFICATION_TEST_MAILBOX). Trousseau lu dans
 * NOTIFICATION_KEYRING_TEST / NOTIFICATION_ACTIVE_KEY_ID_TEST (héritage d'environnement, jamais dans argv). Horloge : 12 juin 2026 09:00 UTC
 * + `NOTIFICATION_TEST_CLOCK_OFFSET` secondes. Base de TEST uniquement. Imprime un JSON sans secret.
 */

use App\Tests\Support\NotificationKit;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
if (!str_contains((string) $em->getConnection()->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée.\n");
    exit(2);
}
$clock = new MockClock('2026-06-12 09:00:00 UTC');
$clock->sleep((int) (getenv('NOTIFICATION_TEST_CLOCK_OFFSET') ?: 0));
$keys = NotificationKit::provider(getenv('NOTIFICATION_KEYRING_TEST') ?: null, getenv('NOTIFICATION_ACTIVE_KEY_ID_TEST') ?: null);

try {
    $result = match ($argv[1]) {
        'dispatch', 'dispatch-crash' => ['ok' => true, 'outcomes' => NotificationKit::dispatcher($container, $keys, $clock, new App\Tests\Support\FileMailbox((string) getenv('NOTIFICATION_TEST_MAILBOX'), 'dispatch-crash' === $argv[1], (int) (getenv('NOTIFICATION_TEST_SEND_DELAY_US') ?: 0)))->run(50, 30)->outcomes],
        'manual' => (static function () use ($container, $clock, $argv, $em): array {
            // manual <userId> <municipalityId> <requestId> <nonce> : demande manuelle d'un agent (service réel, horloge figée).
            $agent = $em->find(App\Identity\Domain\User::class, Uuid::fromString($argv[2])) ?? throw new RuntimeException('Utilisateur introuvable.');

            return ['ok' => true, 'outcome' => NotificationKit::access($container, $clock)->requestNotification($agent, $argv[3], $argv[4], $argv[5])->value];
        })(),
        'record' => (static function () use ($container, $argv): array {
            // record <permitId> <occurredAt UTC> : enregistre une extension sous le verrou du laissez-passer (contrat du port), dans sa propre transaction.
            $permit = Uuid::fromString($argv[2]);
            $container->get(App\Shared\Application\TransactionRunner::class)->run(static function () use ($container, $permit, $argv): void {
                $container->get(App\Permit\Domain\PermitRepository::class)->lock($permit);
                usleep(120000);
                $container->get(App\Permit\Application\NotificationIntentRecorder::class)->record(new App\Permit\Domain\NotificationIntent(App\Permit\Domain\NotificationKind::AuthorizationAdded, $permit, Uuid::v7(), new DateTimeImmutable($argv[3], new DateTimeZone('UTC')), true));
            });

            return ['ok' => true];
        })(),
        'provision' => ['ok' => true, 'outcome' => NotificationKit::provisioner($container, $keys, $clock)->provision(Uuid::fromString($argv[2]))->value],
        'purge-outbox' => ['ok' => true, 'report' => NotificationKit::outboxPurger($container, $clock)->purge()],
        'purge' => ['ok' => true, 'report' => NotificationKit::purger($container, $clock)->purge()],
    };
    echo json_encode($result, \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e)], \JSON_THROW_ON_ERROR);
}
