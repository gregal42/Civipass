<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence des zones) : une opération de zone, d'activation, de clôture ou groupée ; imprime le
 * résultat ou le refus en JSON. Arguments : userId action targetId [json]. Base de TEST uniquement.
 *
 * Cinquième argument facultatif `marqueur:secondes` : l'opération tient ses verrous (attente avant le commit, après la mutation)
 * et dépose le fichier marqueur dès qu'elle les tient (SleepingAuditTrail).
 *
 * Actions : zone-update (json {label}), zone-confirm, zone-suspend, zone-reactivate, zone-delete, zone-validity (json {mode, slots}),
 * activate, close-event, bulk-suspend / bulk-reactivate (targetId = participation, json {zoneIds}),
 * bulk-validity (json {zoneIds, mode, slots, confirm}).
 */

use App\Event\Application\Command\BulkZoneCommand;
use App\Event\Application\Command\UpdateZoneCommand;
use App\Event\Application\Command\ValiditySlotInput;
use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Application\Result\BulkZoneOperation;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\EventResourceResolver;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventZoneBulkService;
use App\Event\Application\Service\EventZoneService;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Application\Support\ZoneValidityConfigurator;
use App\Event\Domain\EventTypeRepository;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\EventZoneValidityRepository;
use App\Identity\Domain\User;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\SleepingAuditTrail;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container'); // services privés accessibles (comme dans les tests)
$em = $container->get('doctrine')->getManager();
if (!str_contains((string) $em->getConnection()->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée.\n");
    exit(2);
}
$actor = $em->find(User::class, Uuid::fromString($argv[1])) ?? throw new RuntimeException('Utilisateur introuvable.');
$payload = isset($argv[4]) ? json_decode($argv[4], true, 512, \JSON_THROW_ON_ERROR) : [];
$validity = static fn (array $p): ZoneValidityCommand => new ZoneValidityCommand($p['mode'], array_map(
    static fn (array $s): ValiditySlotInput => new ValiditySlotInput($s['kind'], $s['startDate'], $s['startTime'], $s['endTime'], $s['endDate'] ?? null, $s['isoWeekdays'] ?? [], $s['dstChoices'] ?? []),
    $p['slots'] ?? [],
));

try {
    $zones = $container->get(EventZoneService::class);
    $bulk = static fn (): EventZoneBulkService => $container->get(EventZoneBulkService::class);
    $lifecycle = $container->get(EventLifecycleService::class);
    if (isset($argv[5])) {
        [$marker, $seconds] = explode(':', $argv[5]);
        $audit = new EventAuditRecorder(new SleepingAuditTrail($container->get(AuditTrail::class), $marker, (float) $seconds));
        $zones = new EventZoneService(
            $container->get(EventTransactions::class),
            $container->get(ClockInterface::class),
            $container->get(EventResourceResolver::class),
            $container->get(EventAccessPolicy::class),
            $container->get(EventLocker::class),
            $container->get(EventZoneRepository::class),
            new ZoneValidityConfigurator($container->get(EventZoneValidityRepository::class), $audit),
            $audit,
        );
        $bulk = static fn (): EventZoneBulkService => new EventZoneBulkService(
            $container->get(EventTransactions::class),
            $container->get(ClockInterface::class),
            $container->get(EventResourceResolver::class),
            $container->get(EventAccessPolicy::class),
            $container->get(EventLocker::class),
            new ZoneValidityConfigurator($container->get(EventZoneValidityRepository::class), $audit),
            $audit,
        );
        $lifecycle = new EventLifecycleService(
            $container->get(EventTransactions::class),
            $container->get(ClockInterface::class),
            $container->get(EventResourceResolver::class),
            $container->get(EventAccessPolicy::class),
            $container->get(EventLocker::class),
            $container->get(EventTypeRepository::class),
            $container->get(EventZoneRepository::class),
            $audit,
        );
    }
    match ($argv[2]) {
        'zone-update' => $zones->update($actor, $argv[3], new UpdateZoneCommand($payload['label'] ?? null)),
        'zone-confirm' => $zones->confirm($actor, $argv[3]),
        'zone-suspend' => $zones->suspend($actor, $argv[3]),
        'zone-reactivate' => $zones->reactivate($actor, $argv[3]),
        'zone-delete' => $zones->delete($actor, $argv[3]),
        'zone-validity' => $zones->configureValidity($actor, $argv[3], $validity($payload)),
        'activate' => $lifecycle->activate($actor, $argv[3]),
        'close-event' => $lifecycle->close($actor, $argv[3]),
        'bulk-suspend' => $bulk()->apply($actor, $argv[3], new BulkZoneCommand(BulkZoneOperation::Suspend, $payload['zoneIds'])),
        'bulk-reactivate' => $bulk()->apply($actor, $argv[3], new BulkZoneCommand(BulkZoneOperation::Reactivate, $payload['zoneIds'])),
        'bulk-validity' => $bulk()->apply($actor, $argv[3], new BulkZoneCommand(BulkZoneOperation::ApplyValidity, $payload['zoneIds'], $validity($payload), (bool) ($payload['confirm'] ?? false))),
    };
    echo json_encode(['ok' => true], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()], \JSON_THROW_ON_ERROR);
}
