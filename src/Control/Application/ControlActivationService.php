<?php

declare(strict_types=1);

namespace App\Control\Application;

use App\Control\Application\Result\IssuedActivationCode;
use App\Control\Domain\ActivationCode;
use App\Control\Domain\AssuranceMode;
use App\Control\Domain\ControlActivation;
use App\Control\Domain\ControlActivationRepository;
use App\Identity\Domain\User;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\MunicipalityRole;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Codes d'activation d'appareils de contrôle (LOT 09, DM-131) : créés par un ADMINISTRATEUR COMMUNAL effectif de la commune, pour un événement dont la commune
 * participe activement. Le droit est relu SOUS LE VERROU de la commune (un administrateur rétrogradé à l'instant ne crée plus rien). Les seuils de confiance ne
 * peuvent être que RACCOURCIS par rapport au plafond de configuration (DM-130 §4) ; refus explicite plutôt qu'écrêtage silencieux. Plafonds : 3 codes utilisables
 * à la fois par commune et événement, 10 créations par heure par commune. Le code en clair n'est jamais conservé ni audité.
 */
final readonly class ControlActivationService
{
    public function __construct(
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private ControlActivationRepository $activations,
        private MembershipRepository $memberships,
        private MembershipAccessPolicy $policy,
        private ControlScope $scope,
        private ControlSettings $settings,
        private ControlThrottle $throttle,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OperationNotAllowed|DomainRuleViolation|ControlStorageUnavailable
     */
    public function issue(Municipality $municipality, Uuid $eventId, User $actor, AssuranceMode $mode, int $maxDevices, ?int $warnSeconds, ?int $hardSeconds, ?int $codeMinutes = null): IssuedActivationCode
    {
        $this->assertAdministrator($actor, $municipality);
        $event = $this->scope->eventOf($municipality, $eventId);
        [$warn, $hard] = $this->thresholds($warnSeconds, $hardSeconds);
        $minutes = $codeMinutes ?? $this->settings->codeMinutes;
        if ($minutes < ControlSettings::MIN_CODE_MINUTES || $minutes > ControlSettings::MAX_CODE_MINUTES) {
            throw new DomainRuleViolation(sprintf('La durée de validité du code doit être de %d à %d minutes.', ControlSettings::MIN_CODE_MINUTES, ControlSettings::MAX_CODE_MINUTES));
        }
        if ($maxDevices < 1 || $maxDevices > ControlSettings::MAX_DEVICES_PER_CODE) {
            throw new DomainRuleViolation(sprintf('Le nombre d’appareils doit être de 1 à %d.', ControlSettings::MAX_DEVICES_PER_CODE));
        }
        if (null !== $this->throttle->activationCreation($municipality->id()->toRfc4122())) {
            throw new DomainRuleViolation('Trop de codes créés récemment. Réessayez dans quelques minutes.');
        }
        $code = ActivationCode::generate();
        $municipalityId = $municipality->id();

        $activation = $this->transaction->run(function () use ($municipalityId, $actor, $event, $code, $mode, $maxDevices, $warn, $hard, $minutes): ControlActivation {
            $locked = $this->municipalities->lock($municipalityId) ?? throw new OperationNotAllowed('Commune introuvable.');
            if (!$this->memberships->isEffectiveAdmin($actor, $locked)) {
                throw new OperationNotAllowed('Droit insuffisant pour préparer le contrôle de cette commune.');
            }
            $this->scope->assertOperational($locked, $event);
            $now = $this->clock->now();
            if ($this->activations->countUsable($municipalityId, $event->id(), $now) >= ControlSettings::MAX_ACTIVE_CODES) {
                throw new DomainRuleViolation(sprintf('%d codes sont déjà utilisables : annulez-en un ou attendez son expiration.', ControlSettings::MAX_ACTIVE_CODES));
            }
            $activation = new ControlActivation($locked, $event, $code->hash(), $now->add(new \DateInterval(sprintf('PT%dM', $minutes))), $maxDevices, $warn, $hard, $mode, $actor, $now);
            $this->activations->add($activation);
            $this->audit->record(new AuditEvent($now, AuditAction::ControlActivationCreated, AuditSubjectType::ControlActivation, $activation->id(), $municipalityId, AuditActor::user($actor->id()), null, [
                'eventId' => $event->id()->toRfc4122(),
                'expiresAt' => $activation->expiresAt()->format(\DATE_ATOM),
                'maxDevices' => $maxDevices,
                'trustWarnSeconds' => $warn,
                'trustHardSeconds' => $hard,
                'assuranceMode' => $mode->value,
            ]));

            return $activation;
        });

        return new IssuedActivationCode($activation, $code);
    }

    /**
     * @throws OperationNotAllowed|DomainRuleViolation
     */
    public function revoke(Municipality $municipality, Uuid $activationId, User $actor): void
    {
        $this->assertAdministrator($actor, $municipality);
        $activation = $this->activations->find($activationId);
        if (null === $activation || !$activation->municipality()->id()->equals($municipality->id())) {
            throw new OperationNotAllowed('Ressource inaccessible.'); // identifiant forgé ou d'une autre commune
        }
        $municipalityId = $municipality->id();
        $this->transaction->run(function () use ($municipalityId, $activationId, $actor): void {
            $locked = $this->municipalities->lock($municipalityId) ?? throw new OperationNotAllowed('Commune introuvable.');
            if (!$this->memberships->isEffectiveAdmin($actor, $locked)) {
                throw new OperationNotAllowed('Droit insuffisant pour préparer le contrôle de cette commune.');
            }
            $now = $this->clock->now();
            if (!$this->activations->revokeIfOpen($activationId, $actor->id(), $now)) {
                throw new DomainRuleViolation('Ce code n’est plus utilisable : il est déjà expiré ou annulé.');
            }
            $this->audit->record(new AuditEvent($now, AuditAction::ControlActivationRevoked, AuditSubjectType::ControlActivation, $activationId, $municipalityId, AuditActor::user($actor->id()), null, ['state' => 'REVOKED']));
        });
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function thresholds(?int $warn, ?int $hard): array
    {
        $hardValue = $hard ?? $this->settings->defaultHardSeconds();
        if ($hardValue < ControlSettings::MIN_HARD_SECONDS) {
            throw new DomainRuleViolation('Le seuil dur ne peut pas être inférieur à 2 heures.');
        }
        if ($hardValue > $this->settings->hardMaxSeconds) {
            throw new DomainRuleViolation(sprintf('Le seuil dur ne peut pas dépasser %d heures (limite fixée pour ce déploiement : toute augmentation exige une décision explicite).', intdiv($this->settings->hardMaxSeconds, 3600)));
        }
        $warnValue = $warn ?? min($this->settings->defaultWarnSeconds, $hardValue);
        if ($warnValue < 1_800 || $warnValue > $hardValue) {
            throw new DomainRuleViolation('Le seuil d’avertissement doit être d’au moins 30 minutes et ne pas dépasser le seuil dur.');
        }

        return [$warnValue, $hardValue];
    }

    private function assertAdministrator(User $actor, Municipality $municipality): void
    {
        if (!$this->policy->hasRole($actor, $municipality, MunicipalityRole::MunicipalityAdmin)) {
            throw new OperationNotAllowed('Droit insuffisant pour préparer le contrôle de cette commune.');
        }
    }
}
