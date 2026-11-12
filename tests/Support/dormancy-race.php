<?php

declare(strict_types=1);

/**
 * Processus de test (course entre deux passages d'alerte de dormance, LOT 05.6.5) : une exécution RÉELLE du service ; imprime les effectifs en JSON. Base de TEST uniquement.
 */

use App\Event\Application\Dormancy\EventDormancyAlertService;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!str_contains((string) $container->get('doctrine')->getManager()->getConnection()->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée.\n");
    exit(2);
}

try {
    $report = $container->get(EventDormancyAlertService::class)->run(true);
    echo json_encode(['ok' => true, 'alerted' => $report->alerted, 'failed' => $report->failed, 'reasons' => $report->reasons], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()], \JSON_THROW_ON_ERROR);
}
