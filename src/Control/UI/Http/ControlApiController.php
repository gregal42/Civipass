<?php

declare(strict_types=1);

namespace App\Control\UI\Http;

use App\Control\Application\ControlSettings;
use App\Control\Application\ControlStorageUnavailable;
use App\Control\Application\ControlThrottle;
use App\Control\Application\Dataset\DatasetCodec;
use App\Control\Application\DeviceNotOperational;
use App\Control\Application\DeviceRegistrationService;
use App\Control\Application\DeviceSyncService;
use App\Control\Application\RegistrationRefused;
use App\Control\Application\RegistrationThrottled;
use App\Control\Domain\ControlDevice;
use App\Control\Domain\ControlDeviceRepository;
use App\Control\Domain\DeviceSecret;
use App\Control\Domain\DeviceStatus;
use App\Shared\Domain\DomainRuleViolation;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API d'appareil de contrôle (LOT 09.2, DM-131, DT-056) : enregistrement avec un code d'activation et interrogation de l'état. Interrupteur `OFFLINE_CONTROL_ENABLED` à 0 :
 * 404 uniforme. AUCUNE session ni cookie (pare-feu `control_pwa`) ; l'appareil s'authentifie par son secret dans l'en-tête `Authorization: Bearer`, jamais dans une
 * URL. Aucune donnée personnelle n'est servie ici : seulement l'état de l'appareil. Refus d'enregistrement UNIFORMES (403), 429 avec `Retry-After`, 503 si les compteurs de
 * limitation sont indisponibles (jamais de limitation silencieusement perdue). Les journaux ne contiennent ni code, ni secret, ni adresse.
 */
final class ControlApiController
{
    private const MAX_BODY_BYTES = 8_192;

    public function __construct(
        private readonly ControlSettings $settings,
        private readonly DeviceRegistrationService $registration,
        private readonly DeviceSyncService $sync,
        private readonly ControlDeviceRepository $devices,
        private readonly ControlThrottle $throttle,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/controle/api/activation', name: 'app_control_api_activation', methods: ['POST'])]
    public function activate(Request $request): Response
    {
        $this->guard();
        $body = $this->json($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }
        $code = \is_string($body['code'] ?? null) ? $body['code'] : '';
        $label = \is_string($body['label'] ?? null) ? $body['label'] : '';
        $platform = \is_string($body['platform'] ?? null) ? $body['platform'] : '';
        $previous = \is_string($body['previousSecret'] ?? null) ? $body['previousSecret'] : null;

        try {
            $registered = $this->registration->register($code, $label, $platform, $previous, ClientKey::of($request));
        } catch (RegistrationThrottled $e) {
            return $this->throttled($e->retryAfterSeconds);
        } catch (RegistrationRefused) {
            return new JsonResponse(['error' => 'refused', 'message' => 'Code d’activation invalide, expiré ou déjà utilisé.'], Response::HTTP_FORBIDDEN);
        } catch (DomainRuleViolation $e) {
            return new JsonResponse(['error' => 'invalid', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ControlStorageUnavailable $e) {
            $this->logger->error('Contrôle hors connexion : compteurs de limitation indisponibles.');

            return new JsonResponse(['error' => 'unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $device = $registered->device;

        return new JsonResponse([
            'secret' => $registered->secret->reveal(),
            'pairingCode' => $device->pairingCode(),
            'status' => $device->status()->value,
            'approvalDeadline' => $device->expiresAt()->getTimestamp(),
            't' => $this->clock->now()->getTimestamp(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/controle/api/device', name: 'app_control_api_device', methods: ['GET'])]
    public function state(Request $request): Response
    {
        $this->guard();
        try {
            $device = $this->authenticate($request);
            if ($device instanceof Response) {
                return $device;
            }
            if (null !== ($wait = $this->throttle->deviceStatus($device->id()->toRfc4122()))) {
                return $this->throttled($wait);
            }
        } catch (ControlStorageUnavailable) {
            $this->logger->error('Contrôle hors connexion : compteurs de limitation indisponibles.');

            return new JsonResponse(['error' => 'unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $now = $this->clock->now();

        return new JsonResponse(self::describe($device, $now) + ['t' => $now->getTimestamp()]);
    }

    /**
     * Synchronisation : jeu de données de la commune de l'appareil (jamais un identifiant fourni par le client). Refusée (403, état en clair, AUCUNE donnée) pour un
     * appareil en attente d'approbation, désactivé ou expiré. Corps JSON facultatif : `{"buckets":[256 condensats]}` pour une réponse différentielle.
     */
    #[Route('/controle/api/sync', name: 'app_control_api_sync', methods: ['POST'])]
    public function synchronize(Request $request): Response
    {
        $this->guard();
        try {
            $device = $this->authenticate($request);
            if ($device instanceof Response) {
                return $device;
            }
            if (null !== ($wait = $this->throttle->syncAttempt($device->id()->toRfc4122()))) {
                return $this->throttled($wait);
            }
            $raw = $request->getContent();
            $buckets = null;
            if ('' !== $raw) {
                $body = $this->json($request);
                if (null === $body) {
                    return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
                }
                $buckets = DatasetCodec::parseClientBuckets($body['buckets'] ?? null);
            }

            return new JsonResponse($this->sync->sync($device, $buckets));
        } catch (DeviceNotOperational $e) {
            return new JsonResponse(['status' => $e->state], Response::HTTP_FORBIDDEN);
        } catch (ControlStorageUnavailable) {
            $this->logger->error('Contrôle hors connexion : compteurs de limitation indisponibles.');

            return new JsonResponse(['error' => 'unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * Appareil authentifié par son secret, ou réponse 401 UNIFORME (secret absent, mal formé ou inconnu : même réponse). Un secret inconnu compte comme un échec pour
     * l'adresse (plafond partagé avec les enregistrements refusés). Aucune donnée n'est servie à un appareil non approuvé par cette méthode : l'appelant décide.
     *
     * @throws ControlStorageUnavailable
     */
    private function authenticate(Request $request): ControlDevice|Response
    {
        $secret = DeviceSecret::tryFromHeader($request->headers->get('Authorization'));
        $device = null === $secret ? null : $this->devices->findBySecretHash($secret->hash());
        if (null === $device) {
            $wait = $this->throttle->registrationFailure(ClientKey::of($request));

            return null === $wait ? new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']) : $this->throttled($wait);
        }

        return $device;
    }

    /**
     * État d'un appareil, SANS donnée personnelle : `PENDING` (code d'appariement à montrer à l'administrateur, échéance d'approbation), `ACTIVE` (échéance), `EXPIRED`
     * (échéance atteinte, approuvé ou non), `DEACTIVATED`.
     *
     * @return array<string, mixed>
     */
    public static function describe(ControlDevice $device, \DateTimeImmutable $now): array
    {
        if (DeviceStatus::Deactivated === $device->status()) {
            return ['status' => 'DEACTIVATED'];
        }
        if ($device->expiresAt() <= $now) {
            return ['status' => 'EXPIRED'];
        }
        if (DeviceStatus::Pending === $device->status()) {
            return ['status' => 'PENDING', 'pairingCode' => $device->pairingCode(), 'approvalDeadline' => $device->expiresAt()->getTimestamp(), 'label' => $device->label()];
        }

        return ['status' => 'ACTIVE', 'expiresAt' => $device->expiresAt()->getTimestamp(), 'label' => $device->label()];
    }

    private function guard(): void
    {
        if (!$this->settings->enabled) {
            throw new NotFoundHttpException();
        }
    }

    /** @return array<string, mixed>|null */
    private function json(Request $request): ?array
    {
        $raw = $request->getContent();
        if ('' === $raw || \strlen($raw) > self::MAX_BODY_BYTES) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) && !array_is_list($data) ? $data : null;
    }

    private function throttled(int $seconds): Response
    {
        return new JsonResponse(['error' => 'throttled'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $seconds]);
    }
}
