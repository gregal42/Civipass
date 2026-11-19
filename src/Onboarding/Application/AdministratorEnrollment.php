<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Application\UserManager;
use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Municipality\Application\MembershipManager;
use App\Onboarding\Domain\AdministratorStep;
use App\Onboarding\Domain\AdministratorValidation;
use App\Onboarding\Domain\AdministratorValidationRepository;
use App\Onboarding\Domain\EnrollmentResult;
use App\Onboarding\Domain\InvitationLinkKind;
use App\Onboarding\Domain\InvitationLinkRepository;
use App\Onboarding\Domain\LinkResolution;
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
 * Inscription de l'administrateur communal désigné par un lien PERSONNEL. Réutilise les mécanismes existants : compte
 * (UserManager::createUser, politique de mot de passe), appartenance (MembershipManager), participation (l'acceptation de
 * l'invitation reste l'action explicite et distincte, avec ses propres contrôles).
 *
 * Ce que la vérification prouve et ne prouve pas — à ne jamais présenter autrement :
 *   - la possession du lien et de la boîte e-mail est prouvée (le lien n'a été envoyé qu'à cette adresse) ;
 *   - l'HABILITATION OFFICIELLE à représenter la mairie n'est PAS vérifiée : aucune règle validée ne le permet. L'utilisateur
 *     l'atteste (case à cocher) et l'attestation est conservée dans l'audit.
 * SEC-016 : la demande n'attribue AUCUN droit ; un super-administrateur CWS la valide (AdministratorValidationService).
 * Garde-fous : rôle administrateur seul ; refusé si la commune a déjà un administrateur (relu sous verrou) ; un compte existant
 * doit se connecter (jamais rattaché sur la seule foi du lien) avec l'adresse invitée ; lien à usage unique consommé dans la même
 * transaction que l'appartenance ; ni abonnement, ni droit commercial, ni droit de créer des événements.
 */
final readonly class AdministratorEnrollment
{
    public function __construct(
        private UserRepository $users,
        private UserManager $userManager,
        private MembershipManager $memberships,
        private InvitationLinkRepository $links,
        private AdministratorValidationRepository $validations,
        private AdministratorValidationNotifier $notifier,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function stepFor(LinkResolution $resolution, ?User $current): AdministratorStep
    {
        $link = $resolution->link;
        if (!$resolution->isUsable() || null === $link || InvitationLinkKind::Administrator !== $link->kind()) {
            throw new OperationNotAllowed('Ce lien ne permet pas cette action.');
        }
        if ($resolution->communeAdministered) {
            return AdministratorStep::CommuneAdministered;
        }
        $invited = EmailAddress::fromString($link->recipientEmail());
        if (null !== $current) {
            return EmailAddress::fromString($current->email())->value === $invited->value ? AdministratorStep::Confirm : AdministratorStep::WrongAccount;
        }

        return null !== $this->users->findByEmail($invited) ? AdministratorStep::LoginRequired : AdministratorStep::Signup;
    }

    /**
     * @param User|null       $current compte connecté (obligatoire si un compte existe pour l'adresse invitée)
     * @param SignupData|null $signup  création du compte (seulement si aucun compte n'existe pour l'adresse invitée)
     *
     * @throws OperationNotAllowed lien inutilisable, commune déjà administrée, compte inadapté
     * @throws DomainRuleViolation attestation absente, saisies de compte invalides (mot de passe refusé…)
     */
    public function enroll(LinkResolution $resolution, ?User $current, ?SignupData $signup, bool $attestsRepresentation): EnrollmentResult
    {
        $step = $this->stepFor($resolution, $current);
        $link = $resolution->link ?? throw new OperationNotAllowed('Ce lien ne permet pas cette action.');
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
                $user = $this->userManager->createUser($link->recipientEmail(), $signup->password, trim($signup->firstName), trim($signup->lastName));
                $accountCreated = true;
                break;
            default:
                throw new OperationNotAllowed('Ce lien ne permet pas cette action.');
        }

        $municipality = $link->participation()->municipality();
        $linkId = $link->id();
        $participationId = $link->participation()->id();
        // SEC-016 : AUCUN droit n'est attribué ici. Le lien est consommé et une demande est enregistrée ; un super-administrateur CWS la valide (ou la refuse)
        // après vérification de la légitimité hors Civipass (AdministratorValidationService).
        $requested = null;
        $this->memberships->requestFirstAdministrator($user, $municipality, function () use ($linkId, $user, $municipality, $participationId, $link, &$requested): bool {
            $now = $this->clock->now();
            if (!$this->links->consume($linkId, $now)) {
                return false; // consommé, remplacé ou expiré entre-temps : une seule demande par lien
            }
            $validation = new AdministratorValidation($municipality, $user, $link, $now);
            $this->validations->add($validation);
            $requested = [$validation->id(), $now];
            $this->audit->record(new AuditEvent($now, AuditAction::AdministratorValidationRequested, AuditSubjectType::AdministratorValidation, $validation->id(), $municipality->id(), AuditActor::user($user->id()), null, [
                'linkId' => $linkId->toRfc4122(),
                'participationId' => $participationId->toRfc4122(),
                'attestedRepresentation' => true,
                'emailControlProven' => true,
                'officialHabilitationVerified' => false,
                'status' => 'PENDING',
            ]));

            return true;
        });

        // Après validation de la transaction : avertir CWS (un échec d'envoi ne change rien à la demande enregistrée ni aux droits).
        if (null !== $requested) {
            $this->notifier->notifyPending($requested[0], $municipality->name(), $requested[1]);
        }

        return new EnrollmentResult($user, $resolution->municipalityId ?? '', $resolution->eventId ?? '', $accountCreated);
    }
}
