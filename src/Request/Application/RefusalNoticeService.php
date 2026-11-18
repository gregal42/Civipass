<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityRole;
use App\Request\Application\Read\RefusalToCommunicate;
use App\Request\Domain\ManualChannel;
use App\Request\Domain\RefusalNoticeRepository;
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
 * « Refus à communiquer » (LOT 08.5, DM-136) : liste des refus dont le demandeur n'a pas pu être informé par e-mail (aucune adresse ou échec définitif) et marquage
 * « informé par un autre moyen ». Réservé aux INSTRUCTEURS et ADMINISTRATEURS de la commune de l'URL (relue par MunicipalityContext : refus uniforme pour une commune
 * étrangère) ; l'écriture exige en plus une commune active. Toute ligne est relue dans le périmètre de la commune : un identifiant forgé donne le refus d'une ressource
 * inexistante. L'audit ne contient ni adresse ni coordonnée.
 */
final readonly class RefusalNoticeService
{
    public const LIST_LIMIT = 200;

    public function __construct(
        private MunicipalityContext $context,
        private RefusalNoticeRepository $notices,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** L'agent a-t-il accès à cette fonction ? (instructeur ou administrateur de la commune) */
    public function mayAccess(User $user, string $municipalityId): bool
    {
        try {
            $membership = $this->context->requireMembership($user, $municipalityId, write: false);
        } catch (OperationNotAllowed) {
            return false;
        }

        return $membership->hasRole(MunicipalityRole::Instructor) || $membership->hasRole(MunicipalityRole::MunicipalityAdmin);
    }

    /** @throws OperationNotAllowed commune inaccessible ou rôle insuffisant */
    public function count(User $user, string $municipalityId): int
    {
        return $this->notices->countToCommunicate($this->scope($user, $municipalityId, write: false));
    }

    /**
     * @return list<RefusalToCommunicate>
     *
     * @throws OperationNotAllowed
     */
    public function list(User $user, string $municipalityId): array
    {
        return $this->notices->toCommunicate($this->scope($user, $municipalityId, write: false), self::LIST_LIMIT);
    }

    /**
     * @throws OperationNotAllowed commune inaccessible, rôle insuffisant, commune suspendue (écriture), ligne introuvable dans cette commune
     * @throws DomainRuleViolation l'information n'attend plus d'action d'un agent
     */
    public function markInformed(User $user, string $municipalityId, string $noticeId, ManualChannel $channel): void
    {
        $municipality = $this->scope($user, $municipalityId, write: true);
        $this->transactions->run(function () use ($user, $municipality, $noticeId, $channel): void {
            $notice = Uuid::isValid($noticeId) ? $this->notices->find(Uuid::fromString($noticeId)) : null;
            // Événement clos : plus aucun dossier ne se sert (LOT 05.6.2) ; même refus qu'une ligne inexistante.
            if (null === $notice || !$notice->municipality()->id()->equals($municipality) || $notice->decision()->requestMunicipality()->request()->event()->status()->isClosed()) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $now = $this->clock->now();
            $notice->markInformed($channel, $user, $now);
            $this->audit->record(new AuditEvent($now, AuditAction::RefusalNoticeMarkedInformed, AuditSubjectType::RefusalNotice, $notice->id(), $municipality, AuditActor::user($user->id()), ['status' => 'TO_COMMUNICATE'], ['status' => 'MANUAL', 'channel' => $channel->value]));
        });
    }

    private function scope(User $user, string $municipalityId, bool $write): Uuid
    {
        $membership = $this->context->requireMembership($user, $municipalityId, write: $write);
        if (!$membership->hasRole(MunicipalityRole::Instructor) && !$membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return $membership->municipality()->id();
    }
}
