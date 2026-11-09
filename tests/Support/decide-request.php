<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence des décisions municipales) : une approbation ou un refus ; imprime le résultat ou le refus en
 * JSON. Arguments : userId municipalityId requestId action(approve|refuse) [json] [marqueur:secondes] [action d'audit à attendre]. Base de TEST uniquement.
 * L'horloge est figée au 12 juin 2026 09:00 UTC (événement du 10 au 20 juin). Avec `marqueur:secondes`, l'opération tient ses
 * verrous (attente à l'écriture d'audit, avant le commit) et dépose le fichier marqueur dès qu'elle les tient.
 */

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventZoneValidityRepository;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Request\Application\MunicipalDecisionApplier;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\PermitRequestRepository;
use App\Request\Infrastructure\DoctrineRefusalReasonRepository;
use App\Tests\Support\PermitIssuerFactory;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\SleepingAuditTrail;
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
$actor = $em->find(User::class, Uuid::fromString($argv[1])) ?? throw new RuntimeException('Utilisateur introuvable.');
$payload = isset($argv[5]) ? json_decode($argv[5], true, 512, \JSON_THROW_ON_ERROR) : [];

try {
    $audit = $container->get(AuditTrail::class);
    if (isset($argv[6])) {
        [$marker, $seconds] = explode(':', $argv[6]);
        $audit = new SleepingAuditTrail($audit, $marker, (float) $seconds, $argv[7] ?? null);
    }
    $service = new MunicipalInstructionService(
        $container->get(EventTransactions::class),
        $container->get(EventLocker::class),
        new MockClock('2026-06-12 09:00:00 UTC'),
        $container->get(MunicipalityContext::class),
        $container->get(PermitRequestRepository::class),
        new DoctrineRefusalReasonRepository($em),
        new MunicipalDecisionApplier($audit, $container->get(EventZoneValidityRepository::class), PermitIssuerFactory::create($container, $audit)),
    );
    $result = match ($argv[4]) {
        'approve' => $service->approve($actor, $argv[2], $argv[3], ApprovalDuration::fixed(DurationMode::from($payload['mode'] ?? 'H24'))),
        'refuse' => $service->refuse($actor, $argv[2], $argv[3], $payload['reason'] ?? 'duplicate', null),
    };
    echo json_encode(['ok' => true, 'outcome' => $result->outcome->value], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage(), 'cause' => $e instanceof App\Shared\Domain\OperationConflict ? $e->cause->value : null], \JSON_THROW_ON_ERROR);
}
