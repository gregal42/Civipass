<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence, LOT 09.2) : `register <code>` enregistre un appareil avec un code ; `approve <deviceId> <pairing> <adminId> <municipalityId>` approuve.
 * Imprime {"ok":true} ou le refus en JSON. Base de TEST uniquement.
 */

use App\Control\Application\DeviceApprovalService;
use App\Control\Application\DeviceRegistrationService;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use Symfony\Component\Uid\Uuid;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['OFFLINE_CONTROL_ENABLED'] = $_ENV['OFFLINE_CONTROL_ENABLED'] = '1';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
if (!str_contains((string) $em->getConnection()->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée.\n");
    exit(2);
}

try {
    if ('register' === $argv[1]) {
        $container->get(DeviceRegistrationService::class)->register($argv[2], 'Appareil '.getmypid(), 'ANDROID', null, 'v4:198.51.100.'.(getmypid() % 250 + 1));
    } else {
        $admin = $em->find(User::class, Uuid::fromString($argv[4])) ?? throw new RuntimeException('Administrateur introuvable.');
        $municipality = $em->find(Municipality::class, Uuid::fromString($argv[5])) ?? throw new RuntimeException('Commune introuvable.');
        $container->get(DeviceApprovalService::class)->approve($municipality, Uuid::fromString($argv[2]), $argv[3], $admin);
    }
    echo json_encode(['ok' => true], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()], \JSON_THROW_ON_ERROR);
}
