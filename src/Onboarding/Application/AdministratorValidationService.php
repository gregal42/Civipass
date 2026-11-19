<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Domain\User;
use App\Municipality\Application\MembershipManager;
use App\Onboarding\Domain\AdministratorValidation;
use App\Onboarding\Domain\AdministratorValidationRepository;
use App\Onboarding\Domain\AdministratorValidationStatus;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Décision d'un super-administrateur CWS sur une demande de premier administrateur communal (SEC-016). Seul un super-administrateur actif décide ; le motif
 * (comment la légitimité a été vérifiée HORS Civipass) est obligatoire ; valideur, date et motif sont conservés (demande + audit). L'approbation reprend les
 * gardes d'attribution existantes (commune active, aucun administrateur effectif, vérifié sous verrou) : si une autre voie a nommé un administrateur entre-temps,
 * l'approbation est refusée. Aucun délai de traitement n'est garanti par l'application (procédure opérationnelle à définir).
 */
final readonly class AdministratorValidationService
{
    public function __construct(
        private AdministratorValidationRepository $validations,
        private MembershipManager $memberships,
        private TransactionRunner $transaction,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<AdministratorValidation> */
    public function pending(User $operator): array
    {
        $this->assertOperator($operator);

        return $this->validations->pending();
    }

    /**
     * @throws OperationNotAllowed                    opérateur non autorisé, demande introuvable ou déjà traitée, commune déjà administrée
     * @throws \App\Shared\Domain\DomainRuleViolation motif absent ou hors bornes
     */
    public function approve(User $operator, Uuid $validationId, string $reason): void
    {
        $this->assertOperator($operator);
        $validation = $this->validations->find($validationId);
        if (null === $validation || !$validation->isPending()) {
            throw new OperationNotAllowed('Demande introuvable ou déjà traitée.');
        }
        $reason = AdministratorValidation::cleanReason($reason);
        $this->memberships->enrollFirstAdministrator($validation->user(), $validation->municipality(), function () use ($validation, $operator, $reason): bool {
            $now = $this->clock->now();
            $validation->decide(AdministratorValidationStatus::Approved, $operator, $reason, $now);
            $this->record(AuditAction::AdministratorValidationApproved, $validation, $operator, $reason, $now);

            return true;
        }, AuditActor::user($operator->id()), 'CWS_VALIDATION');
    }

    public function reject(User $operator, Uuid $validationId, string $reason): void
    {
        $this->assertOperator($operator);
        $validation = $this->validations->find($validationId);
        if (null === $validation || !$validation->isPending()) {
            throw new OperationNotAllowed('Demande introuvable ou déjà traitée.');
        }
        $reason = AdministratorValidation::cleanReason($reason);
        $this->transaction->run(function () use ($validation, $operator, $reason): void {
            $now = $this->clock->now();
            $validation->decide(AdministratorValidationStatus::Rejected, $operator, $reason, $now);
            $this->record(AuditAction::AdministratorValidationRejected, $validation, $operator, $reason, $now);
        });
    }

    private function assertOperator(User $operator): void
    {
        if (!$operator->isActive() || !$operator->isSuperAdmin()) {
            throw new OperationNotAllowed('Seul un super-administrateur CWS actif peut valider un administrateur communal.');
        }
    }

    private function record(AuditAction $action, AdministratorValidation $validation, User $operator, string $reason, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditEvent($now, $action, AuditSubjectType::AdministratorValidation, $validation->id(), $validation->municipality()->id(), AuditActor::user($operator->id()), null, [
            'validationId' => $validation->id()->toRfc4122(),
            'administratorUserId' => $validation->user()->id()->toRfc4122(),
            'validatorUserId' => $operator->id()->toRfc4122(),
            'reason' => $reason,
        ]));
    }
}
