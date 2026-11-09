<?php

declare(strict_types=1);

/**
 * Recette (LOT 05.3.3) : un passage du distributeur avec le VRAI transport Symfony Mailer (MAILER_DSN hérité de l'environnement du processus,
 * typiquement MailCatcher), l'horloge figée au 12 juin 2026 09:00 UTC + NOTIFICATION_TEST_CLOCK_OFFSET secondes. Base de TEST uniquement.
 * Le compositeur est celui du conteneur : le domaine des liens est DEFAULT_URI, comme en exploitation (jamais une valeur de test).
 * Imprime les compteurs, jamais d'adresse ni de secret.
 */

use App\Tests\Support\NotificationKit;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!str_contains((string) $container->get('doctrine')->getManager()->getConnection()->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée.\n");
    exit(2);
}
$clock = new MockClock('2026-06-12 09:00:00 UTC');
$clock->sleep((int) (getenv('NOTIFICATION_TEST_CLOCK_OFFSET') ?: 0));
$keys = NotificationKit::provider(getenv('NOTIFICATION_KEYRING_TEST') ?: null, getenv('NOTIFICATION_ACTIVE_KEY_ID_TEST') ?: null);

try {
    $report = NotificationKit::dispatcher($container, $keys, $clock, $container->get(MailerInterface::class), baseUrl: null)->run(50, 30);
    echo json_encode(['ok' => true, 'outcomes' => $report->outcomes], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e)], \JSON_THROW_ON_ERROR);
}
