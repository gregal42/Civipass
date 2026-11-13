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
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Ouverture d'une commune par CWS (LOT 07.1) : toujours depuis le référentiel national (nom et code INSEE jamais saisis).
 * Idempotente : une commune déjà inscrite (par exemple par une invitation) est reprise telle quelle, sans doublon (unicité du
 * code INSEE, y compris en cas d'ouverture simultanée) ; seule une commune réellement créée est auditée.
 */
final readonly class MunicipalityOpening
{
    public function __construct(
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{0: Municipality, 1: bool} la commune et « créée à l'instant »
     *
     * @throws DomainRuleViolation commune retirée du référentiel
     * @throws OperationNotAllowed acteur non super-administrateur actif
     */
    public function open(NationalCommune $commune, User $actor): array
    {
        if (!$actor->isActive() || !$actor->isSuperAdmin()) {
            throw new OperationNotAllowed('Réservé à un super-administrateur CWS actif.');
        }
        if (!$commune->isActive()) {
            throw new DomainRuleViolation('Cette commune n\'est plus au référentiel : elle ne peut pas être ouverte.');
        }
        $code = $commune->inseeCode();
        $name = $commune->name();
        $actorId = $actor->id();

        try {
            return $this->transaction->run(function () use ($code, $name, $actorId): array {
                $existing = $this->municipalities->findByInseeCode($code);
                if (null !== $existing) {
                    return [$existing, false];
                }
                $now = $this->clock->now();
                $municipality = new Municipality($name, $code, $now);
                $this->municipalities->add($municipality);
                $this->audit->record(new AuditEvent($now, AuditAction::MunicipalityOpenedByCws, AuditSubjectType::Municipality, $municipality->id(), $municipality->id(), AuditActor::user($actorId), null, ['inseeCode' => $code->value, 'name' => $name, 'status' => $municipality->status()->value, 'origin' => 'CWS_BACKOFFICE']));

                return [$municipality, true];
            });
        } catch (UniqueConstraintViolationException) {
            $existing = $this->municipalities->findByInseeCode($code) ?? throw new DomainRuleViolation('Commune introuvable ou indisponible.');

            return [$existing, false];
        }
    }
}
