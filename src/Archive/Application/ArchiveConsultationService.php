<?php

declare(strict_types=1);

namespace App\Archive\Application;

use App\Archive\Application\Read\ArchiveDossierView;
use App\Archive\Domain\ArchiveConsultation;
use App\Archive\Domain\ArchiveConsultationRepository;
use App\Archive\Domain\ConsultationAction;
use App\Archive\Domain\ConsultationReason;
use App\Identity\Domain\User;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Consultation d'un dossier archivé (LOT 05.6.3) : MOTIF OBLIGATOIRE, journal écrit AVANT l'envoi du contenu (un échec d'écriture REFUSE l'accès : fail-closed), lien de
 * consultation LIÉ à l'utilisateur et au dossier, de durée LIMITÉE, et RECONTRÔLÉ à chaque affichage par `ArchiveAccessPolicy` (désignation révoquée, rôle perdu, gel levé
 * et archive échue : accès coupé aussitôt). Chaque téléchargement de pièce ajoute sa propre ligne de journal. Aucune écriture métier, aucune réouverture de droits.
 */
final readonly class ArchiveConsultationService
{
    public function __construct(
        private TransactionRunner $transaction,
        private ArchiveAccessPolicy $policy,
        private ArchiveConsultationRepository $consultations,
        private ArchiveDossierReader $reader,
        private ArchiveSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    /** Même contrôle que l'ouverture, SANS écriture : le formulaire de motif n'est pas servi à un utilisateur sans accès. @throws OperationNotAllowed */
    public function preflight(User $user, string $municipalityId, string $dossierId): void
    {
        $this->policy->dossier($user, $municipalityId, $dossierId);
    }

    /**
     * @throws OperationNotAllowed refus uniforme
     * @throws DomainRuleViolation motif manquant ou invalide
     */
    public function open(User $user, string $municipalityId, string $dossierId, string $reasonCode, ?string $comment): ArchiveConsultation
    {
        $reason = ConsultationReason::tryFrom($reasonCode) ?? throw new DomainRuleViolation('Choisissez un motif de consultation.');
        $comment = null === $comment ? null : trim($comment);
        $comment = '' === $comment ? null : $comment;
        if (ConsultationReason::Other === $reason && null === $comment) {
            throw new DomainRuleViolation('Précisez le motif de la consultation.');
        }
        $access = $this->policy->dossier($user, $municipalityId, $dossierId);

        return $this->transaction->run(function () use ($user, $access, $reason, $comment): ArchiveConsultation {
            $consultation = new ArchiveConsultation($access->dossier, $user, ConsultationAction::DossierOpened, $reason, $comment, null, $this->clock->now());
            $this->consultations->add($consultation);

            return $consultation;
        });
    }

    /**
     * @throws OperationNotAllowed        consultation inconnue, d'un autre utilisateur ou d'une autre commune (refus uniforme)
     * @throws ArchiveConsultationExpired lien périmé pour son propriétaire
     */
    public function view(User $user, string $municipalityId, string $consultationId): ArchiveDossierView
    {
        [$consultation, $access] = $this->resolve($user, $municipalityId, $consultationId);

        return $this->reader->view($user, $consultation, $access, $this->settings->consultationValidUntil($consultation->occurredAt()));
    }

    /**
     * Pièce d'un dossier consulté : appartient à la DEMANDE du dossier ; ligne de journal écrite avant l'envoi.
     *
     * @return array{storageKey: string, mime: string, size: int, name: string}
     *
     * @throws OperationNotAllowed
     * @throws ArchiveConsultationExpired
     */
    public function download(User $user, string $municipalityId, string $consultationId, string $attachmentId): array
    {
        [$consultation, $access] = $this->resolve($user, $municipalityId, $consultationId);
        $found = null;
        foreach ($access->dossier->request()->attachments() as $attachment) {
            if (Uuid::isValid($attachmentId) && $attachment->id()->equals(Uuid::fromString($attachmentId))) {
                $found = $attachment;
            }
        }
        $found ?? throw new OperationNotAllowed('Ressource inaccessible.');
        $this->transaction->run(function () use ($user, $access, $consultation, $found): void {
            $this->consultations->add(new ArchiveConsultation($access->dossier, $user, ConsultationAction::AttachmentDownloaded, $consultation->reason(), $consultation->reasonComment(), $found->id(), $this->clock->now()));
        });

        return ['storageKey' => $found->storageKey(), 'mime' => $found->mimeType(), 'size' => $found->sizeBytes(), 'name' => $found->originalName()];
    }

    /** @return array{ArchiveConsultation, ArchiveAccess} */
    private function resolve(User $user, string $municipalityId, string $consultationId): array
    {
        $consultation = Uuid::isValid($consultationId) ? $this->consultations->find(Uuid::fromString($consultationId)) : null;
        if (null === $consultation || !$consultation->user()->id()->equals($user->id()) || !$consultation->dossier()->municipality()->id()->equals(Uuid::isValid($municipalityId) ? Uuid::fromString($municipalityId) : Uuid::v7())) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        // Habilitation d'abord (même politique qu'à l'ouverture, recontrôlée), PUIS seulement l'expiration : un lecteur qui n'a plus le droit reçoit le refus uniforme.
        $access = $this->policy->dossier($user, $municipalityId, $consultation->dossier()->id()->toRfc4122());
        if ($this->settings->consultationValidUntil($consultation->occurredAt()) <= $this->clock->now()) {
            throw new ArchiveConsultationExpired($consultation->dossier()->id()->toRfc4122());
        }

        return [$consultation, $access];
    }
}
