<?php

declare(strict_types=1);

/**
 * Processus de test (courses sur les droits des personnes, LOT 05.6.6) : UNE opération réelle par les services du conteneur ; imprime le résultat en JSON.
 * Arguments : kind actorUserId municipalityId requestId [decision refusal]. kind : download | decide. Base de TEST uniquement.
 */

use App\Identity\Domain\User;
use App\Rights\Application\RightsExportService;
use App\Rights\Application\RightsRequestService;
use App\Shared\Domain\DomainRuleViolation;
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
$actor = $em->find(User::class, Uuid::fromString($argv[2])) ?? throw new RuntimeException('Utilisateur introuvable.');

try {
    if ('download' === $argv[1]) {
        $file = $container->get(RightsExportService::class)->download($actor, $argv[3], $argv[4]);
        @unlink($file['path']);
        echo json_encode(['ok' => true, 'status' => 'DOWNLOADED'], \JSON_THROW_ON_ERROR);
    } else {
        $container->get(RightsRequestService::class)->decide($actor, $argv[3], $argv[4], $argv[5], '' === ($argv[6] ?? '') ? null : $argv[6], false);
        echo json_encode(['ok' => true, 'status' => 'DECIDED'], \JSON_THROW_ON_ERROR);
    }
} catch (DomainRuleViolation $e) {
    echo json_encode(['ok' => false, 'refused' => true], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()], \JSON_THROW_ON_ERROR);
}
