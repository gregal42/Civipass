<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\MunicipalityStatus;
use App\Municipality\Domain\PhoneNumberNormalizer;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Shared\Application\TransactionRunner;
use Psr\Clock\ClockInterface;

/**
 * Création et statut : superadmin ou console. Contact : administrateur de la commune ou superadmin.
 */
final readonly class MunicipalityManager
{
    public function __construct(
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private MembershipAccessPolicy $policy,
        private PhoneNumberNormalizer $phoneNormalizer,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function create(string $name, string $inseeCode, ?User $actor): Municipality
    {
        $this->assertSuperAdminOrConsole($actor);
        $code = InseeCode::fromString($inseeCode);

        return $this->transaction->run(function () use ($name, $code): Municipality {
            if (null !== $this->municipalities->findByInseeCode($code)) {
                throw new DomainRuleViolation('Une commune existe déjà avec ce code INSEE.');
            }
            $municipality = new Municipality($name, $code, $this->clock->now());
            $this->municipalities->add($municipality);

            return $municipality;
        });
    }

    public function changeStatus(Municipality $municipality, MunicipalityStatus $status, ?User $actor): void
    {
        $this->assertSuperAdminOrConsole($actor);
        $this->transaction->run(function () use ($municipality, $status, $actor): void {
            $municipality = $this->lockedMunicipality($municipality);
            $previous = $municipality->status();
            if ($previous === $status) {
                return;
            }
            $now = $this->clock->now();
            $municipality->changeStatus($status, $now);
            $this->audit->record(new AuditEvent(
                $now,
                AuditAction::MunicipalityStatusChanged,
                AuditSubjectType::Municipality,
                $municipality->id(),
                $municipality->id(),
                $this->auditActor($actor),
                ['status' => $previous->value],
                ['status' => $status->value],
            ));
        });
    }

    /** Changement de statut depuis le back-office CWS (LOT 07.1) : acteur super-administrateur actif obligatoire (jamais la console). */
    public function setStatusByCws(Municipality $municipality, MunicipalityStatus $status, User $actor): void
    {
        if (!$actor->isActive() || !$actor->isSuperAdmin()) {
            throw new OperationNotAllowed('Réservé à un super-administrateur CWS actif.');
        }
        $this->changeStatus($municipality, $status, $actor);
    }

    /**
     * Contact modifiable par un administrateur de la commune (ou le superadmin) : transactionnel, verrouillé
     * et audité (auteur, anciennes et nouvelles valeurs). Les valeurs sont normalisées avant toute écriture.
     */
    public function updateContact(
        Municipality $municipality,
        ?string $contactName,
        ?string $contactEmail,
        ?string $contactPhone,
        ?string $addressLine,
        ?string $postalCode,
        ?string $city,
        User $actor,
    ): void {
        $email = null === $contactEmail || '' === trim($contactEmail) ? null : EmailAddress::fromString($contactEmail);
        $phone = null === $contactPhone || '' === trim($contactPhone) ? null : $this->phoneNormalizer->normalize($contactPhone);

        $this->transaction->run(function () use ($municipality, $contactName, $email, $phone, $addressLine, $postalCode, $city, $actor): void {
            $municipality = $this->lockedMunicipality($municipality);
            if (!$this->policy->canEditContact($actor, $municipality)) {
                throw new OperationNotAllowed('Droit insuffisant pour modifier le contact de cette commune.');
            }

            $previous = $municipality->contactSnapshot();
            $now = $this->clock->now();
            $municipality->updateContact($contactName, $email, $phone, $addressLine, $postalCode, $city, $now);
            $new = $municipality->contactSnapshot();
            if ($previous === $new) {
                return;
            }
            $this->audit->record(new AuditEvent(
                $now,
                AuditAction::MunicipalityContactChanged,
                AuditSubjectType::Municipality,
                $municipality->id(),
                $municipality->id(),
                AuditActor::user($actor->id()),
                $previous,
                $new,
            ));
        });
    }

    private function lockedMunicipality(Municipality $municipality): Municipality
    {
        return $this->municipalities->lock($municipality->id())
            ?? throw new OperationNotAllowed('Commune introuvable.');
    }

    private function auditActor(?User $actor): AuditActor
    {
        return null === $actor ? AuditActor::console() : AuditActor::user($actor->id());
    }

    private function assertSuperAdminOrConsole(?User $actor): void
    {
        if (null !== $actor && !$this->policy->isActiveSuperAdmin($actor)) {
            throw new OperationNotAllowed('Action réservée à un superadmin actif.');
        }
    }
}
