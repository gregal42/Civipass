<?php

declare(strict_types=1);

namespace App\Tests\Functional\Control;

use App\Control\UI\Http\ControlHeadersListener;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Tests\Support\ControlEnv;
use App\Tests\Support\ControlTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * API d'appareil et écrans d'administration du contrôle hors connexion (LOT 09.2) : enregistrement, état, refus uniformes, limiteurs, aucune session ; droits par
 * rôle et par commune, identifiants forgés, CSRF, code affiché une seule fois, aucune fuite de secret.
 */
final class ControlHttpTest extends ControlTestCase
{
    use ControlEnv;

    private string $previousErrorLog = '';
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '1');
        $this->previousErrorLog = (string) ini_get('error_log');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'civipass-control-log-');
        ini_set('error_log', $this->logFile);
        parent::setUp();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->logFile);
        $this->restoreControlEnv();
        parent::tearDown();
    }

    // ---- API ------------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|string $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $uri, array|string $body = [], ?string $secret = null, string $address = '198.51.100.10'): array
    {
        $server = ['REMOTE_ADDR' => $address, 'CONTENT_TYPE' => 'application/json'] + (null === $secret ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        $this->client->request($method, $uri, server: $server, content: \is_string($body) ? $body : ([] === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @phpstan-impure : la réponse change à chaque requête */
    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function registered(string $label = 'Barrage Nord'): array
    {
        $issued = $this->issue(maxDevices: 5);
        $body = $this->api('POST', '/controle/api/activation', ['code' => $issued->code->display(), 'label' => $label, 'platform' => 'IOS']);
        self::assertSame(201, $this->httpStatus());

        return [$issued->code->value(), $body];
    }

    public function testRegistrationReturnsTheSecretOnceAndAPendingStatus(): void
    {
        [, $body] = $this->registered();

        self::assertSame(43, \strlen($body['secret']));
        self::assertMatchesRegularExpression('/^[0-9]{6}$/', $body['pairingCode']);
        self::assertSame('PENDING', $body['status']);
        self::assertGreaterThan(time(), $body['approvalDeadline']);
        $response = $this->client->getResponse();
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame(ControlHeadersListener::CSP, $response->headers->get('Content-Security-Policy'));
        self::assertEmpty($response->headers->getCookies(), 'aucune session ni cookie');
        self::assertSame(hash('sha256', $body['secret']), $this->scalar('SELECT secret_hash FROM control_device'));
    }

    public function testThePendingDeviceCanOnlyReadItsOwnStateAndGetsNoPersonalData(): void
    {
        [, $registered] = $this->registered();

        $state = $this->api('GET', '/controle/api/device', secret: $registered['secret']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('PENDING', $state['status']);
        self::assertSame($registered['pairingCode'], $state['pairingCode']);
        self::assertEqualsCanonicalizing(['status', 'pairingCode', 'approvalDeadline', 'label', 't'], array_keys($state), 'aucune autre donnée n’est servie');
        $refused = $this->api('POST', '/controle/api/sync', secret: $registered['secret']);
        self::assertSame(403, $this->httpStatus(), 'un appareil en attente ne reçoit jamais de jeu de données');
        self::assertSame(['status' => 'PENDING'], $refused, 'aucune donnée, l’état seulement');
    }

    public function testTheStateFollowsApprovalAndDeactivation(): void
    {
        [, $registered] = $this->registered();
        $deviceId = Uuid::fromBinary((string) $this->scalar('SELECT id FROM control_device'));
        $device = $this->em()->getRepository(\App\Control\Domain\ControlDevice::class)->find($deviceId) ?? self::fail();
        $this->approvals->approve($this->org, $deviceId, (string) $device->pairingCode(), $this->user($this->adminO));

        $active = $this->api('GET', '/controle/api/device', secret: $registered['secret']);
        self::assertSame('ACTIVE', $active['status']);
        self::assertGreaterThan(time(), $active['expiresAt']);

        $this->approvals->deactivateDevice($this->org, $deviceId, \App\Control\Domain\DeactivationReason::Lost, $this->user($this->adminO));
        self::assertSame('DEACTIVATED', $this->api('GET', '/controle/api/device', secret: $registered['secret'])['status']);
    }

    public function testEveryRefusalOfARegistrationIsUniform(): void
    {
        $issued = $this->issue();
        $expired = $this->issue();
        $this->clock->modify('+31 minutes');
        $this->sql("UPDATE control_activation SET expires_at = '2000-01-01 00:00:00' WHERE id = :id", ['id' => $expired->activation->id()->toBinary()]);
        $bodies = [];
        foreach (['ZZZZ-ZZZZ-ZZZZ', $expired->code->display(), 'pas un code', ''] as $code) {
            $bodies[] = $this->api('POST', '/controle/api/activation', ['code' => $code, 'label' => 'X', 'platform' => 'ANDROID']);
            self::assertSame(403, $this->httpStatus(), $code);
        }
        self::assertCount(1, array_unique(array_map('serialize', $bodies)), 'même corps pour code inconnu, expiré, mal formé ou vide');
        self::assertSame($issued->activation->id()->toRfc4122(), $this->em()->getRepository(\App\Control\Domain\ControlActivation::class)->find($issued->activation->id())?->id()->toRfc4122());
    }

    public function testMalformedRequestsAreRejected(): void
    {
        $this->api('POST', '/controle/api/activation', '{pas du json');
        self::assertSame(400, $this->httpStatus());
        $this->api('POST', '/controle/api/activation', '[1,2,3]');
        self::assertSame(400, $this->httpStatus());
        $this->api('POST', '/controle/api/activation', str_repeat('a', 5000));
        self::assertSame(400, $this->httpStatus());
        $this->api('GET', '/controle/api/activation');
        self::assertSame(405, $this->httpStatus());
        $issued = $this->issue();
        $this->api('POST', '/controle/api/activation', ['code' => $issued->code->display(), 'label' => '', 'platform' => 'ANDROID']);
        self::assertSame(422, $this->httpStatus(), 'libellé vide');
        self::assertSame(0, $this->rows('control_device'));
    }

    public function testAnUnknownOrMalformedSecretGivesTheSameUnauthorizedAnswer(): void
    {
        $answers = [];
        foreach ([null, 'court', str_repeat('a', 43), str_repeat('!', 43)] as $secret) {
            $this->client->request('GET', '/controle/api/device', server: ['REMOTE_ADDR' => '198.51.100.20'] + (null === $secret ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]));
            $answers[] = [$this->httpStatus(), (string) $this->client->getResponse()->getContent()];
        }
        self::assertCount(1, array_unique(array_map('serialize', $answers)));
        self::assertSame(401, $answers[0][0]);
    }

    public function testFailedRegistrationsAreThrottledPerAddressWithRetryAfter(): void
    {
        $this->issue();
        $last = 0;
        for ($i = 0; $i < 12; ++$i) {
            $this->api('POST', '/controle/api/activation', ['code' => 'ZZZZ-ZZZZ-ZZZZ', 'label' => 'X', 'platform' => 'ANDROID'], address: '203.0.113.50');
            $last = $this->httpStatus();
            if (429 === $last) {
                break;
            }
        }
        self::assertSame(429, $last);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->api('POST', '/controle/api/activation', ['code' => 'ZZZZ-ZZZZ-ZZZZ', 'label' => 'X', 'platform' => 'ANDROID'], address: '203.0.113.51');
        self::assertSame(403, $this->httpStatus(), 'une autre adresse n’est pas touchée');
    }

    public function testNeitherTheCodeNorTheSecretReachTheLogs(): void
    {
        [$code, $registered] = $this->registered();
        $this->api('POST', '/controle/api/activation', ['code' => 'ZZZZ-ZZZZ-ZZZZ', 'label' => 'X', 'platform' => 'ANDROID'], address: '203.0.113.60');
        $this->api('GET', '/controle/api/device', secret: $registered['secret']);

        $logs = (string) file_get_contents($this->logFile);
        self::assertStringNotContainsString($code, $logs);
        self::assertStringNotContainsString($registered['secret'], $logs);
    }

    public function testTheApiIsA404WhileTheSwitchIsOff(): void
    {
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '0');
        $this->client->getKernel()->shutdown();
        $this->api('POST', '/controle/api/activation', ['code' => 'X', 'label' => 'X', 'platform' => 'ANDROID']);
        self::assertSame(404, $this->httpStatus());
        $this->api('GET', '/controle/api/device');
        self::assertSame(404, $this->httpStatus());
    }

    // ---- Synchronisation ---------------------------------------------------------------------------------------------------------

    /** @return array{0: string, 1: string} secret et identifiant d'un appareil ACTIF de la commune organisatrice */
    private function approvedDevice(): array
    {
        [, $registered] = $this->registered('Barrage Nord');
        $deviceId = Uuid::fromBinary((string) $this->scalar('SELECT id FROM control_device'));
        $device = $this->em()->getRepository(\App\Control\Domain\ControlDevice::class)->find($deviceId) ?? self::fail();
        $this->approvals->approve($this->org, $deviceId, (string) $device->pairingCode(), $this->user($this->adminO));

        return [$registered['secret'], $deviceId->toRfc4122()];
    }

    public function testAnApprovedDeviceSynchronizesAndThenGetsADifferentialAnswer(): void
    {
        [$secret] = $this->approvedDevice();

        $full = $this->api('POST', '/controle/api/sync', secret: $secret);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('full', $full['mode']);
        self::assertSame(1, $full['v']);
        self::assertSame(256, \count($full['bk']));
        self::assertSame(64, \strlen($full['root']));
        self::assertSame(ControlHeadersListener::CSP, $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertEmpty($this->client->getResponse()->headers->getCookies());

        $again = $this->api('POST', '/controle/api/sync', ['buckets' => $full['bk']], $secret);
        self::assertSame('unchanged', $again['mode']);
        self::assertSame($full['root'], $again['root']);
    }

    public function testASynchronizationNeverAnswersAnUnknownMalformedPendingOrDeactivatedDevice(): void
    {
        $this->api('POST', '/controle/api/sync', secret: str_repeat('a', 43));
        self::assertSame(401, $this->httpStatus());
        $this->api('POST', '/controle/api/sync');
        self::assertSame(401, $this->httpStatus());

        [$secret, $deviceId] = $this->approvedDevice();
        $this->approvals->deactivateDevice($this->org, Uuid::fromString($deviceId), \App\Control\Domain\DeactivationReason::Lost, $this->user($this->adminO));
        $answer = $this->api('POST', '/controle/api/sync', secret: $secret);
        self::assertSame(403, $this->httpStatus());
        self::assertSame(['status' => 'DEACTIVATED'], $answer);
    }

    public function testASynchronizationRejectsMalformedBodiesAndWrongMethods(): void
    {
        [$secret] = $this->approvedDevice();
        $this->api('POST', '/controle/api/sync', '{pas du json', $secret);
        self::assertSame(400, $this->httpStatus());
        $this->api('GET', '/controle/api/sync', secret: $secret);
        self::assertSame(405, $this->httpStatus());
        // un condensat mal formé retombe sur l'instantané complet, jamais une erreur
        $answer = $this->api('POST', '/controle/api/sync', ['buckets' => ['x']], $secret);
        self::assertSame('full', $answer['mode']);
    }

    public function testSynchronizationsAreRateLimitedPerDevice(): void
    {
        [$secret] = $this->approvedDevice();
        $last = 0;
        for ($i = 0; $i < 62; ++$i) {
            $this->api('POST', '/controle/api/sync', secret: $secret);
            $last = $this->httpStatus();
            if (429 === $last) {
                break;
            }
        }
        self::assertSame(429, $last);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertStringNotContainsString($secret, (string) file_get_contents($this->logFile));
    }

    // ---- Écrans d'administration -----------------------------------------------------------------------------------------------------

    private function login(User $user): void
    {
        $this->client->loginUser($this->user($user));
    }

    private function page(Municipality $commune, string $suffix = ''): string
    {
        return '/communes/'.$commune->id()->toRfc4122().'/evenements/'.$this->event->id()->toRfc4122().'/controle'.$suffix;
    }

    private function csrf(string $uri): string
    {
        return (string) $this->client->request('GET', $uri)->filter('input[name=_token]')->first()->attr('value');
    }

    public function testTheAdministratorSeesTheScreenButAnInstructorDoesNot(): void
    {
        $this->login($this->adminO);
        $this->client->request('GET', $this->page($this->org));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('Contrôle hors connexion', (string) $this->client->getResponse()->getContent());

        $this->login($this->instructorO);
        $this->client->request('GET', $this->page($this->org));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnotherCommuneAndForgedIdentifiersAreRefusedUniformly(): void
    {
        $this->login($this->adminX); // administrateur d'une commune étrangère
        $this->client->request('GET', $this->page($this->org));
        self::assertResponseStatusCodeSame(404);

        $this->login($this->adminP); // administrateur de la commune partenaire sur l'URL de l'organisatrice
        $this->client->request('GET', $this->page($this->org));
        self::assertResponseStatusCodeSame(404);

        $this->login($this->adminO); // événement inconnu
        $this->client->request('GET', '/communes/'.$this->org->id()->toRfc4122().'/evenements/'.\Symfony\Component\Uid\Uuid::v7()->toRfc4122().'/controle');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheCodeIsShownExactlyOnceAndNeverStored(): void
    {
        $this->login($this->adminO);
        $token = $this->csrf($this->page($this->org, '/code'));
        $this->client->request('POST', $this->page($this->org, '/code'), ['_token' => $token, 'mode' => 'STRICT', 'devices' => '4', 'hard' => '480', 'warn' => '120', 'minutes' => '20']);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/data-testid="activation-code">([0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4})</', $html);
        preg_match('/data-testid="activation-code">([0-9A-Z-]+)</', $html, $m);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('<svg', $html, 'QR code d’activation');
        self::assertSame('STRICT', $this->scalar('SELECT assurance_mode FROM control_activation'));
        self::assertSame(28_800, (int) $this->scalar('SELECT trust_hard_seconds FROM control_activation'));

        $this->client->request('GET', $this->page($this->org));
        self::assertStringNotContainsString($m[1], (string) $this->client->getResponse()->getContent(), 'le code ne s’affiche plus jamais');
        self::assertStringNotContainsString(str_replace('-', '', $m[1]), (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($m[1], (string) file_get_contents($this->logFile));
    }

    public function testAnOutOfBoundsCodeRequestIsRefusedWithAMessage(): void
    {
        $this->login($this->adminO);
        $token = $this->csrf($this->page($this->org, '/code'));
        $this->client->request('POST', $this->page($this->org, '/code'), ['_token' => $token, 'mode' => 'STANDARD', 'devices' => '4', 'hard' => '900', 'warn' => '120', 'minutes' => '30']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('ne peut pas dépasser', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->rows('control_activation'));
    }

    public function testAnInvalidCsrfTokenCreatesNothing(): void
    {
        $this->login($this->adminO);
        $this->client->request('POST', $this->page($this->org, '/code'), ['_token' => 'forgé', 'mode' => 'STANDARD', 'devices' => '4', 'hard' => '720', 'warn' => '360', 'minutes' => '30']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rows('control_activation'));
    }

    public function testTheWholeApprovalJourneyThroughTheScreens(): void
    {
        [, $registered] = $this->registered('Barrage Nord');
        $deviceId = Uuid::fromBinary((string) $this->scalar('SELECT id FROM control_device'))->toRfc4122();
        $this->login($this->adminO);

        $this->client->request('GET', $this->page($this->org));
        self::assertStringContainsString('Barrage Nord', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($registered['pairingCode'], (string) $this->client->getResponse()->getContent(), 'le code d’appariement ne s’affiche que sur le téléphone');

        $url = $this->page($this->org, '/appareils/'.$deviceId.'/approuver');
        $token = $this->csrf($url);
        $wrong = '000000' === $registered['pairingCode'] ? '111111' : '000000';
        $this->client->request('POST', $url, ['_token' => $token, 'pairing' => $wrong]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('PENDING', $this->scalar('SELECT status FROM control_device'));

        $token = $this->csrf($url);
        $this->client->request('POST', $url, ['_token' => $token, 'pairing' => $registered['pairingCode']]);
        self::assertResponseRedirects($this->page($this->org));
        self::assertSame('ACTIVE', $this->scalar('SELECT status FROM control_device'));
        self::assertSame('ACTIVE', $this->api('GET', '/controle/api/device', secret: $registered['secret'])['status']);
    }

    public function testAForgedDeviceOfAnotherCommuneIsNotFound(): void
    {
        $this->registered();
        $deviceId = Uuid::fromBinary((string) $this->scalar('SELECT id FROM control_device'))->toRfc4122();
        $this->login($this->adminP);
        foreach (['/approuver', '/desactiver', '/renouveler'] as $action) {
            $this->client->request('GET', $this->page($this->partner, '/appareils/'.$deviceId.$action));
            self::assertResponseStatusCodeSame(404, $action);
        }
        $this->client->request('POST', $this->page($this->partner, '/appareils/'.$deviceId.'/desactiver'), ['reason' => 'LOST']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('PENDING', $this->scalar('SELECT status FROM control_device'));
    }

    public function testDeactivationAndRenewalThroughTheScreens(): void
    {
        [, $registered] = $this->registered();
        $deviceId = Uuid::fromBinary((string) $this->scalar('SELECT id FROM control_device'))->toRfc4122();
        $this->login($this->adminO);
        $approve = $this->page($this->org, '/appareils/'.$deviceId.'/approuver');
        $this->client->request('POST', $approve, ['_token' => $this->csrf($approve), 'pairing' => $registered['pairingCode']]);

        $renew = $this->page($this->org, '/appareils/'.$deviceId.'/renouveler');
        $this->client->request('POST', $renew, ['_token' => $this->csrf($renew)]);
        self::assertResponseRedirects();

        $off = $this->page($this->org, '/appareils/'.$deviceId.'/desactiver');
        $this->client->request('POST', $off, ['_token' => $this->csrf($off), 'reason' => 'REJECTED']);
        self::assertResponseStatusCodeSame(422); // « refusé » n'existe que pour un appareil en attente
        $this->client->request('POST', $off, ['_token' => $this->csrf($off), 'reason' => 'LOST']);
        self::assertResponseRedirects();
        self::assertSame('DEACTIVATED', $this->scalar('SELECT status FROM control_device'));
        self::assertSame('DEACTIVATED', $this->api('GET', '/controle/api/device', secret: $registered['secret'])['status']);
    }

    public function testASuspendedCommuneIsReadOnly(): void
    {
        $this->sql("UPDATE municipality SET status = 'SUSPENDED' WHERE id = :id", ['id' => $this->org->id()->toBinary()]);
        $this->login($this->adminO);
        $this->client->request('GET', $this->page($this->org));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->page($this->org, '/code'));
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAdministrationScreensAreA404WhileTheSwitchIsOff(): void
    {
        $this->login($this->adminO);
        $this->controlEnv('OFFLINE_CONTROL_ENABLED', '0');
        $this->client->getKernel()->shutdown();
        $this->client->request('GET', $this->page($this->org));
        self::assertResponseStatusCodeSame(404);
    }
}
