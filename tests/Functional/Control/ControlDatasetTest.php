<?php

declare(strict_types=1);

namespace App\Tests\Functional\Control;

use App\Control\Application\Dataset\DatasetCodec;
use App\Control\Application\DeviceNotOperational;
use App\Control\Domain\DeactivationReason;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\PermitTokenReplacementService;
use App\Permit\Application\Result\TokenReplacementRequest;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\ControlTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\UserBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Jeu de données du contrôle hors connexion (LOT 09.3) : isolation par commune, minimisation (aucun jeton, aucune coordonnée), état des droits matérialisé en fenêtres
 * UTC par le moteur unique, empreintes révoquées/remplacées bornées, événement clos, synchronisation différentielle vérifiable, aucun jeu pour un appareil non approuvé.
 */
final class ControlDatasetTest extends ControlTestCase
{
    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, array<string, mixed>> enregistrements décodés par clé
     */
    private function records(array $response): array
    {
        $records = [];
        foreach ($response['lines'] ?? [] as $line) {
            $record = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $records[$record['k']] = $record;
        }

        return $records;
    }

    /**
     * @param list<string>|null $buckets
     *
     * @return array<string, mixed>
     */
    private function sync(\App\Control\Domain\ControlDevice $device, ?array $buckets = null): array
    {
        return $this->syncer->sync($this->em()->find(\App\Control\Domain\ControlDevice::class, $device->id()) ?? self::fail(), $buckets);
    }

    public function testTheDatasetOfTheCommuneContainsOnlyTheMinimalDataOfItsPermits(): void
    {
        $token = $this->tokenOf($this->makePermit(['org']));
        [$device] = $this->activeDevice();

        $response = $this->sync($device);
        $records = $this->records($response);
        $key = hash('sha256', $token);

        self::assertSame('full', $response['mode']);
        self::assertSame(1, $response['n']);
        self::assertSame(['k', 't', 'r', 'f', 'l', 'p', 'z'], array_keys($records[$key]));
        self::assertSame('P', $records[$key]['t']);
        self::assertSame('Camille', $records[$key]['f']);
        self::assertSame('MARTIN', $records[$key]['l']);
        self::assertMatchesRegularExpression('/^LP-\d{4}-\d+$/', $records[$key]['r']);
        self::assertNotEmpty($records[$key]['p']);
        $zone = $records[$key]['z'][0];
        self::assertSame('V', $zone['s']);
        self::assertNotEmpty($zone['w']);
        $now = $this->clock->now()->getTimestamp();
        self::assertTrue($zone['w'][0][0] <= $now && $now < $zone['w'][0][1], 'la fenêtre couvre l’instant courant');
        $raw = (string) json_encode($response, \JSON_UNESCAPED_UNICODE);
        foreach ([$token, self::EMAIL, self::PHONE, self::ADDRESS, 'Motif confidentiel', 'camille.secret'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $raw, 'donnée interdite dans le jeu : '.$forbidden);
        }
        self::assertSame($response['root'], DatasetCodec::root($response['lines']));
    }

    public function testEachCommuneReceivesOnlyItsOwnPermitsAndItsOwnZones(): void
    {
        $orgOnly = $this->tokenOf($this->makePermit(['org'], 'DURAND', 'Alice', 'AA-111-AA'));
        $partnerOnly = $this->tokenOf($this->makePermit(['partner'], 'LEROY', 'Bruno', 'BB-222-BB'));
        $both = $this->tokenOf($this->makePermit(['org', 'partner'], 'PETIT', 'Chloé', 'CC-333-CC'));
        [$orgDevice] = $this->activeDevice($this->org, $this->adminO);
        [$partnerDevice] = $this->activeDevice($this->partner, $this->adminP);

        $org = $this->records($this->sync($orgDevice));
        $partner = $this->records($this->sync($partnerDevice));

        self::assertEqualsCanonicalizing([hash('sha256', $orgOnly), hash('sha256', $both)], array_keys($org));
        self::assertEqualsCanonicalizing([hash('sha256', $partnerOnly), hash('sha256', $both)], array_keys($partner));
        self::assertArrayNotHasKey(hash('sha256', $partnerOnly), $org, 'le laissez-passer de la partenaire est inconnu de l’appareil de l’organisatrice');
        $orgZones = array_column($org[hash('sha256', $both)]['z'], 'l');
        $partnerZones = array_column($partner[hash('sha256', $both)]['z'], 'l');
        self::assertSame(['Zone organisatrice'], $orgZones);
        self::assertSame(['Zone partenaire'], $partnerZones, 'chaque appareil ne voit que les zones de sa commune');
    }

    public function testAnotherCommunesDeviceSecretNeverYieldsTheDataOfThisOne(): void
    {
        $this->makePermit(['org']);
        [, $partnerSecret] = $this->activeDevice($this->partner, $this->adminP);
        $partnerDevice = static::getContainer()->get(\App\Control\Domain\ControlDeviceRepository::class)->findBySecretHash(hash('sha256', $partnerSecret)) ?? self::fail();

        self::assertSame([], $this->records($this->sync($partnerDevice)), 'l’appareil de la partenaire ne reçoit rien de la commune organisatrice');
    }

    public function testNoDatasetIsEverBuiltForAPendingDeactivatedOrExpiredDevice(): void
    {
        $this->makePermit(['org']);
        $issued = $this->issue(maxDevices: 3);
        $pending = $this->register($issued->code->display(), 'En attente');
        try {
            $this->syncer->sync($this->em()->find(\App\Control\Domain\ControlDevice::class, $pending->device->id()) ?? self::fail(), null);
            self::fail('Un appareil en attente ne reçoit aucun jeu.');
        } catch (DeviceNotOperational $e) {
            self::assertSame('PENDING', $e->state);
        }
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'CONTROL_SYNC_REFUSED'"), 'l’attente normale d’approbation n’est pas un refus à tracer');

        [$device] = $this->activeDevice(label: 'Autre');
        $this->approvals->deactivateDevice($this->org, $device->id(), DeactivationReason::Lost, $this->user($this->adminO));
        $this->em()->clear();
        try {
            $this->sync($device);
            self::fail('Désactivé : refus attendu.');
        } catch (DeviceNotOperational $e) {
            self::assertSame('DEACTIVATED', $e->state);
        }
        try {
            $this->sync($device);
        } catch (DeviceNotOperational) {
        }
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'CONTROL_SYNC_REFUSED'"), 'au plus une trace par heure et par appareil');

        [$expiring] = $this->activeDevice(label: 'Expirant');
        $this->clock->modify('+73 hours');
        $this->expectException(DeviceNotOperational::class);
        $this->sync($expiring);
    }

    public function testARevokedAuthorizationBecomesATombstoneWithoutAnyName(): void
    {
        $requestId = $this->makePermit(['org']);
        $token = $this->tokenOf($requestId);
        [$device] = $this->activeDevice();
        static::getContainer()->get(AuthorizationRevocationService::class)->revoke($this->user($this->instructorO), $this->org->id()->toRfc4122(), $requestId, 'PUBLIC_SAFETY', null, true);

        $response = $this->sync($device);
        $records = $this->records($response);
        $key = hash('sha256', $token);

        self::assertSame(['k' => $key, 't' => 'R'], $records[$key], 'empreinte et état seulement : ni nom, ni plaque, ni zone');
        self::assertStringNotContainsString('MARTIN', (string) json_encode($response));
    }

    public function testAReplacedQrKeepsTheOldHashAsReplacedAndTheNewOneValid(): void
    {
        $requestId = $this->makePermit(['org']);
        $old = $this->tokenOf($requestId);
        [$device] = $this->activeDevice();
        static::getContainer()->get(PermitTokenReplacementService::class)->replace($this->user($this->adminO), $this->org->id()->toRfc4122(), $requestId, new TokenReplacementRequest(Uuid::v7()->toRfc4122(), 'OTHER', 'Photographie du QR diffusée', true));
        $new = $this->tokenOf($requestId);

        $records = $this->records($this->sync($device));

        self::assertNotSame($old, $new);
        self::assertSame('X', $records[hash('sha256', $old)]['t']);
        self::assertSame('P', $records[hash('sha256', $new)]['t']);
    }

    public function testATombstoneDisappearsOnceThePermitCanNoLongerBePresented(): void
    {
        $requestId = $this->makePermit(['org']);
        $token = $this->tokenOf($requestId);
        [$device] = $this->activeDevice();
        static::getContainer()->get(AuthorizationRevocationService::class)->revoke($this->user($this->instructorO), $this->org->id()->toRfc4122(), $requestId, 'PUBLIC_SAFETY', null, true);
        self::assertArrayHasKey(hash('sha256', $token), $this->records($this->sync($device)));

        $this->sql("UPDATE permit_authorization SET valid_from = :f, valid_until = :u, duration_mode = 'CUSTOM' WHERE 1 = 1", ['f' => $this->clock->now()->modify('-5 days')->format('Y-m-d H:i:s'), 'u' => $this->clock->now()->modify('-2 days')->format('Y-m-d H:i:s')]);

        self::assertArrayNotHasKey(hash('sha256', $token), $this->records($this->sync($device)), 'conservation bornée : fin de la dernière période dépassée');
    }

    public function testAClosedEventYieldsAnEmptyDatasetMarkedClosed(): void
    {
        $this->makePermit(['org']);
        [$device] = $this->activeDevice();
        $this->sql("UPDATE event_event SET status = 'CLOSED'");

        $response = $this->sync($device);

        self::assertTrue($response['ev']['closed']);
        self::assertSame([], $response['lines']);
    }

    public function testASuspendedZoneIsMaterializedAsSuspendedWithoutWindows(): void
    {
        $token = $this->tokenOf($this->makePermit(['org']));
        [$device] = $this->activeDevice();
        $this->sql("UPDATE event_zone SET status = 'SUSPENDED' WHERE id = :id", ['id' => $this->organizerZone->id()->toBinary()]);

        $zone = $this->records($this->sync($device))[hash('sha256', $token)]['z'][0];

        self::assertSame('S', $zone['s']);
        self::assertSame([], $zone['w']);
    }

    public function testTheHorizonIsGridAlignedCoversTheTrustWindowAndClipsTheWindows(): void
    {
        $this->makePermit(['org']);
        [$device] = $this->activeDevice(hard: 7_200, warn: 3_600);

        $response = $this->sync($device);
        $now = $this->clock->now()->getTimestamp();

        self::assertSame(0, $response['hor'] % 3_600, 'horizon aligné sur la grille d’une heure');
        self::assertGreaterThanOrEqual($now + 7_200, $response['hor'], 'l’horizon couvre au moins la durée de confiance');
        self::assertLessThanOrEqual($now + 7_200 + 3_600, $response['hor']);
        foreach ($this->records($response) as $record) {
            foreach ($record['z'] as $zone) {
                foreach ($zone['w'] as [$start, $end]) {
                    self::assertLessThanOrEqual($response['hor'], $end, 'aucun droit n’est affirmé au-delà de l’horizon');
                    self::assertGreaterThanOrEqual(intdiv($now, 3_600) * 3_600, $start);
                }
            }
        }
        self::assertSame(['w' => 3_600, 'h' => 7_200, 'm' => 'STANDARD'], $response['tr']);
    }

    public function testTheDatasetCarriesTheCommunesTrustParametersAndTheDeviceExpiry(): void
    {
        [$device] = $this->activeDevice(mode: \App\Control\Domain\AssuranceMode::Strict, hard: 14_400, warn: 7_200);

        $response = $this->sync($device);

        self::assertSame(['w' => 7_200, 'h' => 14_400, 'm' => 'STRICT'], $response['tr']);
        self::assertSame($device->expiresAt()->getTimestamp(), $response['dev']['exp']);
        self::assertSame($this->org->name(), $response['mu']);
    }

    public function testUnchangedDeltaAndFullModesAreVerifiable(): void
    {
        $first = $this->makePermit(['org'], 'DURAND', 'Alice', 'AA-111-AA');
        [$device] = $this->activeDevice();
        $full = $this->sync($device);
        self::assertSame('full', $full['mode']);

        $unchanged = $this->sync($device, $full['bk']);
        self::assertSame('unchanged', $unchanged['mode']);
        self::assertSame($full['root'], $unchanged['root']);
        self::assertArrayNotHasKey('lines', $unchanged);

        $this->makePermit(['org'], 'LEROY', 'Bruno', 'BB-222-BB');
        static::getContainer()->get(AuthorizationRevocationService::class)->revoke($this->user($this->instructorO), $this->org->id()->toRfc4122(), $first, 'PUBLIC_SAFETY', null, true);
        $delta = $this->sync($device, $full['bk']);
        self::assertSame('delta', $delta['mode']);
        self::assertLessThanOrEqual(2, \count($delta['chg']), 'seuls les compartiments modifiés sont renvoyés');

        // L'appareil applique le différentiel et recalcule le condensat racine.
        $store = [];
        foreach ($full['lines'] as $line) {
            $store[json_decode($line, true)['k']] = $line;
        }
        foreach ($delta['chg'] as $bucket => $lines) {
            foreach (array_keys($store) as $key) {
                if (DatasetCodec::bucketOf($key) === (int) $bucket) {
                    unset($store[$key]);
                }
            }
            foreach ($lines as $line) {
                $store[json_decode($line, true)['k']] = $line;
            }
        }
        self::assertSame($delta['root'], DatasetCodec::root(DatasetCodec::sorted($store)), 'le condensat racine reconstruit correspond : synchronisation intègre');
    }

    public function testMalformedClientDigestsFallBackToAFullSnapshot(): void
    {
        $this->makePermit(['org']);
        [$device] = $this->activeDevice();

        foreach ([null, [], ['x'], array_fill(0, 256, 'ZZZZZZZZ'), array_fill(0, 255, '00000000')] as $bad) {
            self::assertNull(DatasetCodec::parseClientBuckets($bad));
        }
        self::assertSame('full', $this->sync($device, null)['mode']);
        // plus de 128 compartiments différents : instantané complet
        self::assertSame('full', $this->sync($device, array_fill(0, 256, '00000000'))['mode']);
    }

    public function testASuccessfulSyncIsRecordedOnTheDevice(): void
    {
        $this->makePermit(['org']);
        [$device] = $this->activeDevice();
        $response = $this->sync($device);
        $this->em()->clear();

        self::assertSame($response['root'], $this->scalar('SELECT last_snapshot_hash FROM control_device WHERE id = ?', [$device->id()->toBinary()]));
        self::assertSame(1, (int) $this->scalar('SELECT sync_count FROM control_device WHERE id = ?', [$device->id()->toBinary()]));
        self::assertNotNull($this->scalar('SELECT last_sync_at FROM control_device WHERE id = ?', [$device->id()->toBinary()]));
    }
}
