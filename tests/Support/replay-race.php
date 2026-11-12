<?php

declare(strict_types=1);

/**
 * Processus de test (course entre deux rejeux, LOT 05.6.7) : UN rejeu réel par les services du conteneur ; imprime le résultat en JSON. Arguments : operatorId approvalRef reportHash.
 * Base de TEST uniquement.
 */

use App\Identity\Domain\User;
use App\Retention\Application\ReplayRefused;
use App\Retention\Application\RetentionReplayService;
use Symfony\Component\Uid\Uuid;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['RETENTION_PURGE_ENABLED'] = '1';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
if (!str_contains((string) $em->getConnection()->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée.\n");
    exit(2);
}
$operator = $em->find(User::class, Uuid::fromString($argv[1])) ?? throw new RuntimeException('Opérateur introuvable.');

try {
    $report = $container->get(RetentionReplayService::class)->run($operator, $argv[2], $argv[3], true);
    echo json_encode(['ok' => true, 'dossiers' => $report->dossiersReplayed, 'shared' => $report->sharedReplayed, 'failed' => $report->failed, 'blocked' => $report->blocked], \JSON_THROW_ON_ERROR);
} catch (ReplayRefused $e) {
    echo json_encode(['ok' => false, 'refused' => $e->reasonCode], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()], \JSON_THROW_ON_ERROR);
}
