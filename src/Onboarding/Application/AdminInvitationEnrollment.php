<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Application\UserManager;
use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Domain\MunicipalityStatus;
use App\Onboarding\Domain\AdministratorStep;
use App\Onboarding\Domain\InvitationToken;
use App\Onboarding\Domain\LinkStatus;
use App\Onboarding\Domain\MunicipalityAdminInvitationRepository;
use App\Onboarding\Domain\SignupData;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;

/**
 * Lecture et acceptation d'une invitation d'administrateur communal émise par CWS (LOT 07.1).
 *
 * Le lien ne donne AUCUN droit par lui-même. Il prouve le contrôle de la boîte e-mail, pas l'habilitation à représenter la
 * mairie : l'utilisateur l'atteste (conservé dans l'audit). Un compte existant pour l'adresse doit se connecter (jamais de
 * doublon, jamais de rattachement sur la seule foi du lien) ; sinon le compte est créé avec l'adresse invitée. L'invitation est
 * consommée dans la MÊME transaction que le rattachement (une seule acceptation gagnante).
 */
final readonly class AdminInvitationEnrollment
{
    public function __construct(
        private MunicipalityAdminInvitationRepository $invitations,
        private UserRepository $users,
        private UserManager $userManager,
        private MembershipManager $memberships,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(#[\SensitiveParameter] string $plainToken): AdminInvitationResolution
    {
        if (!InvitationToken::looksValid($plainToken)) {
            return new AdminInvitationResolution(LinkStatus::Unknown);
        }
        $invitation = $this->invitations->findByTokenHash(InvitationToken::fromPlain($plainToken)->hash());
        if (null === $invitation) {
            return new AdminInvitationResolution(LinkStatus::Unknown);
        }
        if (null !== $invitation->consumedAt()) {
            return new AdminInvitationResolution(LinkStatus::Used);
        }
        if (null !== $invitation->revokedAt()) {
            return new AdminInvitationResolution(LinkStatus::Replaced);
        }
        $now = $this->clock->now();
        if ($invitation->isExpiredAt($now)) {
            return new AdminInvitationResolution(LinkStatus::Expired);
        }
        if (!\in_array($invitation->municipality()->status(), [MunicipalityStatus::Active, MunicipalityStatus::Suspended], true)) {
            return new AdminInvitationResolution(LinkStatus::Inactive);
        }

        return new AdminInvitationResolution(LinkStatus::Usable, $invitation);
    }

    public function stepFor(AdminInvitationResolution $resolution, ?User $current): AdministratorStep
    {
        $invitation = $resolution->invitation;
        if (!$resolution->isUsable() || null === $invitation) {
            throw new OperationNotAllowed('Ce lien ne permet pas cette action.');
        }
        $invited = EmailAddress::fromString($invitation->recipientEmail());
        if (null !== $current) {
            return EmailAddress::fromString($current->email())->value === $invited->value ? AdministratorStep::Confirm : AdministratorStep::WrongAccount;
        }

        return null !== $this->users->findByEmail($invited) ? AdministratorStep::LoginRequired : AdministratorStep::Signup;
    }

    /**
     * @return array{0: User, 1: bool, 2: string} le compte, « compte créé à l'instant », l'identifiant de la commune
     *
     * @throws OperationNotAllowed lien inutilisable, compte inadapté
     * @throws DomainRuleViolation attestation absente, saisies invalides
     */
    public function accept(AdminInvitationResolution $resolution, ?User $current, ?SignupData $signup, bool $attestsRepresentation): array
    {
        $step = $this->stepFor($resolution, $current);
        $invitation = $resolution->invitation ?? throw new OperationNotAllowed('Ce lien ne permet pas cette action.');
        if (!$attestsRepresentation) {
            throw new DomainRuleViolation('Confirmez que vous êtes habilité(e) à représenter cette commune.');
        }

        $accountCreated = false;
        switch ($step) {
            case AdministratorStep::Confirm:
                $user = $current ?? throw new OperationNotAllowed('Ce lien ne permet pas cette action.');
                break;
            case AdministratorStep::Signup:
                if (null === $signup) {
                    throw new DomainRuleViolation('Renseignez votre nom, votre prénom et un mot de passe.');
                }
                $user = $this->userManager->createUser($invitation->recipientEmail(), $signup->password, trim($signup->firstName), trim($signup->lastName));
                $accountCreated = true;
                break;
            default:
                throw new OperationNotAllowed('Ce lien ne permet pas cette action.');
        }

        $municipality = $invitation->municipality();
        $invitationId = $invitation->id();
        $this->memberships->enrollInvitedAdministrator($user, $municipality, function () use ($invitationId, $user, $municipality): bool {
            $now = $this->clock->now();
            if (!$this->invitations->consume($invitationId, $user->id(), $now)) {
                return false; // consommée, révoquée ou expirée entre-temps : une seule acceptation par invitation
            }
            $this->audit->record(new AuditEvent($now, AuditAction::AdminInvitationAccepted, AuditSubjectType::MunicipalityAdminInvitation, $invitationId, $municipality->id(), AuditActor::user($user->id()), null, [
                'attestedRepresentation' => true,
                'emailControlProven' => true,
                'officialHabilitationVerified' => false,
            ]));

            return true;
        });

        return [$user, $accountCreated, $municipality->id()->toRfc4122()];
    }
}
