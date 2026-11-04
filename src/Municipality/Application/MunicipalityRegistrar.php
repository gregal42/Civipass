<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\NationalCommune;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Inscrit sur Civipass une commune du référentiel national, à l'occasion d'une invitation. La création « manuelle » reste
 * réservée au superadmin et à la console (MunicipalityManager) ; ici seule une commune OFFICIELLE du référentiel (nom et code
 * INSEE issus de la source, jamais saisis) peut être inscrite, sans droit commercial, sans membre, sans abonnement. L'appelant
 * a déjà vérifié le droit d'inviter. Idempotent : si la commune existe déjà (même concurrente), elle est renvoyée telle quelle,
 * jamais de doublon (unicité du code INSEE).
 */
final readonly class MunicipalityRegistrar
{
    public function __construct(
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DomainRuleViolation commune du référentiel désactivée
     */
    public function registerFromReferential(NationalCommune $commune, User $actor): Municipality
    {
        if (!$commune->isActive()) {
            throw new DomainRuleViolation('Cette commune n\'est plus au référentiel : elle ne peut pas être inscrite.');
        }
        $code = $commune->inseeCode();
        $name = $commune->name();
        $actorId = $actor->id();

        try {
            return $this->transaction->run(function () use ($code, $name, $actorId): Municipality {
                $existing = $this->municipalities->findByInseeCode($code);
                if (null !== $existing) {
                    return $existing;
                }
                $now = $this->clock->now();
                $municipality = new Municipality($name, $code, $now);
                $this->municipalities->add($municipality);
                $this->audit->record(new AuditEvent($now, AuditAction::MunicipalityRegisteredFromReferential, AuditSubjectType::Municipality, $municipality->id(), $municipality->id(), AuditActor::user($actorId), null, ['inseeCode' => $code->value, 'name' => $name, 'status' => $municipality->status()->value, 'origin' => 'NATIONAL_REFERENTIAL_INVITATION']));

                return $municipality;
            });
        } catch (UniqueConstraintViolationException) {
            // Inscription simultanée par une autre invitation : on retient la commune créée par l'autre.
            return $this->municipalities->findByInseeCode($code) ?? throw new DomainRuleViolation('Commune introuvable ou indisponible.');
        }
    }
}
