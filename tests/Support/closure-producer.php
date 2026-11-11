<?php

declare(strict_types=1);

/**
 * Processus de test (course entre la clôture d'un événement et les producteurs de notifications, LOT 05.6.2) : UNE opération réelle, par les services du
 * conteneur (horloge réelle, aucun doublon d'audit) ; imprime le résultat ou le refus en JSON.
 * Arguments : kind userId municipalityId requestId [json]. kind : zones-add | vehicles-add | period-extend | token-replace | manual | approve.
 * Le JSON porte les paramètres propres au producteur (`zone`, `plate`, `reference`, `nonce`). Base de TEST uniquement.
 */

use App\Identity\Domain\User;
use App\Permit\Application\AuthorizationPeriodService;
use App\Permit\Application\AuthorizationZoneService;
use App\Permit\Application\PermitAccessService;
use App\Permit\Application\PermitTokenReplacementService;
use App\Permit\Application\PermitVehicleService;
use App\Permit\Application\Result\TokenReplacementRequest;
use App\Permit\Application\Result\VehicleChangeRequest;
use App\Permit\Application\Result\ZoneChangeRequest;
use App\Permit\Domain\PeriodEnd;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Domain\ApprovalDuration;
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
$payload = isset($argv[5]) ? json_decode($argv[5], true, 512, \JSON_THROW_ON_ERROR) : [];

try {
    $result = match ($argv[1]) {
        'zones-add' => $container->get(AuthorizationZoneService::class)->change($actor, $argv[3], $argv[4], new ZoneChangeRequest(adds: [$payload['zone']], addReason: 'NEW_NEED'))::class,
        'vehicles-add' => $container->get(PermitVehicleService::class)->change($actor, $argv[3], $argv[4], new VehicleChangeRequest(adds: [$payload['plate']], addReason: 'NEW_VEHICLE', confirmedGlobalScope: true))::class,
        'period-extend' => $container->get(AuthorizationPeriodService::class)->extend($actor, $argv[3], $argv[4], PeriodEnd::fromInput('H72', null, null), 'NEED_EXTENDED', null)::class,
        'token-replace' => $container->get(PermitTokenReplacementService::class)->replace($actor, $argv[3], $argv[4], new TokenReplacementRequest(Uuid::v7()->toRfc4122(), 'LOST', null, true, $payload['reference']))::class,
        'manual' => $container->get(PermitAccessService::class)->requestNotification($actor, $argv[3], $argv[4], Uuid::v7()->toRfc4122())->value,
        'approve' => $container->get(MunicipalInstructionService::class)->approve($actor, $argv[3], $argv[4], ApprovalDuration::untilClosure())::class,
    };
    echo json_encode(['ok' => true, 'outcome' => $result], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e)], \JSON_THROW_ON_ERROR);
}
