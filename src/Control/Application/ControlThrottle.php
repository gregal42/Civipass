<?php

declare(strict_types=1);

namespace App\Control\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limiteurs du contrôle hors connexion (LOT 09, DM-131). Stockage STRICT : toute panne du stockage devient une ControlStorageUnavailable (503), jamais une limitation
 * silencieusement perdue. Retourne le délai d'attente en secondes lorsque le plafond est dépassé, nul sinon.
 */
final readonly class ControlThrottle
{
    private const MAX_WAIT = 900;

    public function __construct(
        #[Autowire(service: 'limiter.control_registration_access')] private RateLimiterFactoryInterface $access,
        #[Autowire(service: 'limiter.control_registration_failure')] private RateLimiterFactoryInterface $failures,
        #[Autowire(service: 'limiter.control_pairing_admin')] private RateLimiterFactoryInterface $pairing,
        #[Autowire(service: 'limiter.control_device_status')] private RateLimiterFactoryInterface $status,
        #[Autowire(service: 'limiter.control_activation_commune')] private RateLimiterFactoryInterface $activations,
        #[Autowire(service: 'limiter.control_sync_device')] private RateLimiterFactoryInterface $sync,
        #[Autowire(service: 'limiter.control_sync_refused_audit')] private RateLimiterFactoryInterface $refusedAudit,
    ) {
    }

    public function registrationAccess(string $clientKey): ?int
    {
        return $this->consume($this->access, $clientKey);
    }

    /** À appeler APRÈS un enregistrement refusé (code inconnu, expiré, épuisé ou mal formé). */
    public function registrationFailure(string $clientKey): ?int
    {
        return $this->consume($this->failures, $clientKey);
    }

    public function pairingAttempt(string $adminId, string $deviceId): ?int
    {
        return $this->consume($this->pairing, $adminId.'|'.$deviceId);
    }

    public function deviceStatus(string $deviceId): ?int
    {
        return $this->consume($this->status, $deviceId);
    }

    public function activationCreation(string $municipalityId): ?int
    {
        return $this->consume($this->activations, $municipalityId);
    }

    public function syncAttempt(string $deviceId): ?int
    {
        return $this->consume($this->sync, $deviceId);
    }

    /** Vrai si une trace d'audit du refus peut être écrite maintenant (une par heure et par appareil). */
    public function mayAuditRefusal(string $deviceId): bool
    {
        return null === $this->consume($this->refusedAudit, $deviceId);
    }

    private function consume(RateLimiterFactoryInterface $factory, string $key): ?int
    {
        try {
            $limit = $factory->create($key)->consume();
        } catch (\Throwable $e) {
            throw new ControlStorageUnavailable('Compteurs de limitation indisponibles.', 0, $e);
        }

        return $limit->isAccepted() ? null : max(1, min(self::MAX_WAIT, $limit->getRetryAfter()->getTimestamp() - time()));
    }
}
