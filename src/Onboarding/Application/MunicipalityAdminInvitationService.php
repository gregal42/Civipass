<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\MunicipalityStatus;
use App\Onboarding\Domain\InvitationDeliveryStatus;
use App\Onboarding\Domain\InvitationToken;
use App\Onboarding\Domain\MunicipalityAdminInvitation;
use App\Onboarding\Domain\MunicipalityAdminInvitationRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Invitations d'administrateur communal émises par CWS (LOT 07.1) : émettre, renvoyer, révoquer.
 *
 * Garde-fous : super-administrateur actif uniquement ; commune ACTIVE ou SUSPENDED ; jamais d'invitation d'un compte CWS (A3) ni
 * d'un compte désactivé ; au plus UN lien utilisable par (commune, adresse) : l'ancien est révoqué dans la même transaction, sous
 * le verrou de la commune (renvoi sécurisé) ; aucun droit n'est donné avant l'acceptation. Le jeton n'est stocké que haché, ne
 * sort que dans l'e-mail et n'est écrit nulle part (audit, journaux, écrans) ; l'adresse n'entre jamais dans l'audit. Un échec
 * d'envoi est enregistré (FAILED) sans rien exposer : il se traite par « Renvoyer ».
 */
final readonly class MunicipalityAdminInvitationService
{
    private string $lifetime;

    public function __construct(
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private MunicipalityAdminInvitationRepository $invitations,
        private MembershipRepository $memberships,
        private UserRepository $users,
        private AdminInvitationNotifier $notifier,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire('%env(int:ADMIN_INVITATION_DAYS)%')]
        int $days = 7,
    ) {
        $this->lifetime = sprintf('P%dD', max(1, min(30, $days)));
    }

    /**
     * @throws OperationNotAllowed|DomainRuleViolation
     */
    public function issue(Municipality $municipality, string $email, User $actor): AdminInvitationIssued
    {
        $this->assertSuperAdmin($actor);
        $address = EmailAddress::fromString($email);
        $this->assertInvitable($municipality, $address);

        return $this->createAndSend($municipality, $address, $actor, null, AuditAction::AdminInvitationIssued);
    }

    /**
     * Nouveau lien pour la même adresse ; l'ancien (même expiré) est invalidé. Une invitation déjà utilisée ne se renvoie pas.
     *
     * @throws OperationNotAllowed|DomainRuleViolation
     */
    public function resend(MunicipalityAdminInvitation $invitation, User $actor): AdminInvitationIssued
    {
        $this->assertSuperAdmin($actor);
        $fresh = $this->invitations->find($invitation->id()) ?? throw new OperationNotAllowed('Invitation introuvable.');
        if (null !== $fresh->consumedAt()) {
            throw new DomainRuleViolation('Cette invitation a déjà été acceptée.');
        }
        $address = EmailAddress::fromString($fresh->recipientEmail());
        $this->assertInvitable($fresh->municipality(), $address);

        return $this->createAndSend($fresh->municipality(), $address, $actor, $fresh, AuditAction::AdminInvitationResent);
    }

    public function revoke(MunicipalityAdminInvitation $invitation, User $actor): void
    {
        $this->assertSuperAdmin($actor);
        $id = $invitation->id();
        $municipalityId = $invitation->municipality()->id();
        $this->transaction->run(function () use ($id, $municipalityId, $actor): void {
            $this->municipalities->lock($municipalityId);
            $now = $this->clock->now();
            // Révocation atomique et exclusive avec l'acceptation (consume) : jamais acceptée ET révoquée.
            if (!$this->invitations->revokeIfOpen($id, $actor->id(), $now)) {
                throw new DomainRuleViolation('Cette invitation n\'est plus en attente.');
            }
            $this->audit->record(new AuditEvent($now, AuditAction::AdminInvitationRevoked, AuditSubjectType::MunicipalityAdminInvitation, $id, $municipalityId, AuditActor::user($actor->id()), null, ['state' => 'REVOKED']));
        });
    }

    private function createAndSend(Municipality $municipality, EmailAddress $address, User $actor, ?MunicipalityAdminInvitation $parent, AuditAction $action): AdminInvitationIssued
    {
        $token = InvitationToken::generate();
        $invitation = $this->transaction->run(function () use ($municipality, $address, $actor, $parent, $action, $token): MunicipalityAdminInvitation {
            $locked = $this->municipalities->lock($municipality->id()) ?? throw new OperationNotAllowed('Commune introuvable.');
            if (!\in_array($locked->status(), [MunicipalityStatus::Active, MunicipalityStatus::Suspended], true)) {
                throw new OperationNotAllowed('Cette commune n\'est pas disponible.');
            }
            $now = $this->clock->now();
            $this->invitations->revokeOpen($locked->id(), $address->value, $actor->id(), $now);
            $invitation = new MunicipalityAdminInvitation($locked, $address->value, $token->hash(), $actor, $now, $this->lifetime, $parent);
            $this->invitations->add($invitation);
            $this->audit->record(new AuditEvent($now, $action, AuditSubjectType::MunicipalityAdminInvitation, $invitation->id(), $locked->id(), AuditActor::user($actor->id()), null, ['expiresAt' => $invitation->expiresAt()->format(\DATE_ATOM)]));

            return $invitation;
        });

        return new AdminInvitationIssued($invitation, $this->deliver($invitation, $token, $actor));
    }

    /** Envoi hors de la transaction d'émission (le lien est déjà enregistré) ; le résultat est noté sans jamais perdre le lien. */
    private function deliver(MunicipalityAdminInvitation $invitation, InvitationToken $token, User $actor): bool
    {
        $sent = false;
        try {
            $this->notifier->send($invitation, $token->plain);
            $sent = true;
        } catch (\Throwable $e) {
            $this->logger->error('Échec d\'envoi d\'une invitation d\'administrateur communal.', ['exception' => $e::class, 'invitationId' => $invitation->id()->toRfc4122()]);
        }
        $id = $invitation->id();
        $this->transaction->run(function () use ($id, $sent, $actor): void {
            $fresh = $this->invitations->find($id) ?? throw new OperationNotAllowed('Invitation introuvable.');
            $now = $this->clock->now();
            $fresh->markDelivery($sent ? InvitationDeliveryStatus::Sent : InvitationDeliveryStatus::Failed, $now);
            $this->audit->record(new AuditEvent($now, $sent ? AuditAction::AdminInvitationEmailSent : AuditAction::AdminInvitationEmailFailed, AuditSubjectType::MunicipalityAdminInvitation, $fresh->id(), $fresh->municipality()->id(), AuditActor::user($actor->id()), null, ['delivery' => $sent ? 'SENT' : 'FAILED']));
        });

        return $sent;
    }

    private function assertInvitable(Municipality $municipality, EmailAddress $address): void
    {
        if (!\in_array($municipality->status(), [MunicipalityStatus::Active, MunicipalityStatus::Suspended], true)) {
            throw new OperationNotAllowed('Cette commune n\'est pas disponible.');
        }
        $existing = $this->users->findByEmail($address);
        if (null === $existing) {
            return;
        }
        if ($existing->isSuperAdmin()) {
            throw new DomainRuleViolation('Cette adresse correspond à un compte CWS : il ne peut pas administrer une commune.');
        }
        if (!$existing->isActive()) {
            throw new DomainRuleViolation('Le compte de cette adresse est désactivé : réactivez-le avant d\'inviter.');
        }
        $membership = $this->memberships->findOneByUserAndMunicipality($existing, $municipality);
        if (null !== $membership && $membership->isActive()) {
            throw new DomainRuleViolation('Cette personne est déjà rattachée à la commune.');
        }
    }

    private function assertSuperAdmin(User $actor): void
    {
        if (!$actor->isActive() || !$actor->isSuperAdmin()) {
            throw new OperationNotAllowed('Réservé à un super-administrateur CWS actif.');
        }
    }
}
