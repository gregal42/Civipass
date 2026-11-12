<?php

declare(strict_types=1);

/**
 * Processus de test (course entre purges et gel, LOT 05.6.4) : UNE opération réelle par les services du conteneur ; imprime le résultat en JSON. Arguments : kind actorUserId
 * dossierId. kind : purge (dossier puis, si dû, données partagées) | place-hold. Base de TEST uniquement.
 */

use App\Archive\Application\LegalHoldService;
use App\Identity\Domain\User;
use App\Retention\Application\DossierPurgeService;
use App\Retention\Application\PurgeOutcome;
use App\Retention\Application\SharedPurgeService;
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
$actor = $em->find(User::class, Uuid::fromString($argv[2])) ?? throw new RuntimeException('Utilisateur introuvable.');

try {
    if ('purge' === $argv[1]) {
        $outcome = $container->get(DossierPurgeService::class)->purge($argv[3], true, $actor);
        $shared = null;
        if (PurgeOutcome::PURGED === $outcome->status && $outcome->sharedDue && null !== $outcome->requestId) {
            $shared = $container->get(SharedPurgeService::class)->purge($outcome->requestId, true, $actor)->status;
        }
        echo json_encode(['ok' => true, 'status' => $outcome->status, 'reason' => $outcome->reason, 'shared' => $shared], \JSON_THROW_ON_ERROR);
    } else {
        $container->get(LegalHoldService::class)->place($actor, $argv[4], $argv[3], 'LITIGE', null);
        echo json_encode(['ok' => true, 'status' => 'HELD'], \JSON_THROW_ON_ERROR);
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e)], \JSON_THROW_ON_ERROR);
}
