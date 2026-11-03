<?php

declare(strict_types=1);

/**
 * Processus de test de concurrence (PublicReferenceConcurrencyTest) : génère N références, chacune dans sa propre
 * transaction validée, puis imprime la liste en JSON. Base de TEST uniquement : refuse toute autre base.
 */

use App\Event\Infrastructure\DoctrinePublicReferenceGenerator;
use Doctrine\DBAL\Connection;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
/** @var Connection $connection */
$connection = $kernel->getContainer()->get('doctrine')->getConnection();
if (!str_contains((string) $connection->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée : test de concurrence réservé à une base _test.\n");
    exit(2);
}

$generator = new DoctrinePublicReferenceGenerator($connection);
$references = [];
for ($i = 0; $i < (int) ($argv[1] ?? 10); ++$i) {
    $connection->beginTransaction();
    try {
        $references[] = $generator->next(new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')))->value;
        usleep(random_int(0, 3000));
        $connection->commit();
    } catch (Throwable $e) {
        $connection->rollBack();
        fwrite(\STDERR, $e->getMessage()."\n");
        exit(1);
    }
}
echo json_encode($references, \JSON_THROW_ON_ERROR);
