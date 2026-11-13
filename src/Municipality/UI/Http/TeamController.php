<?php

declare(strict_types=1);

namespace App\Municipality\UI\Http;

use App\Identity\Domain\User;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Application\RoleLabels;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Onboarding\Application\TeamInvitationService;
use App\Onboarding\Application\TeamReader;
use App\Onboarding\Domain\MunicipalityAdminInvitation;
use App\Onboarding\Domain\MunicipalityAdminInvitationRepository;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Écran « Équipe » d'une commune (LOT 07.2) : un administrateur communal invite, habilite et retire ses collaborateurs.
 *
 * Accès : membre de la commune de l'URL (relu à chaque requête ; identifiant jamais cru) ET administrateur ; un autre rôle voit 403, une
 * commune inconnue ou étrangère 404 uniforme. Écritures : commune ACTIVE (SUSPENDED = lecture seule, 403), authentification complète
 * (`security.yaml`), POST + jeton CSRF propre à l'action et à la cible, page de confirmation en GET pour les actions sensibles. Toute
 * cible (rattachement, invitation) est relue et DOIT appartenir à la commune de l'URL, sinon 404 uniforme. Les services revérifient
 * le droit sous le verrou de la commune. Aucune adresse ni jeton n'est écrit dans l'audit ni les journaux ; pages `no-store`.
 */
#[Route('/communes/{municipalityId}/equipe', requirements: ['municipalityId' => Requirement::UUID])]
final class TeamController extends AbstractController
{
    public function __construct(
        private readonly MunicipalityContext $context,
        private readonly ShellContextFactory $shell,
        private readonly TeamReader $reader,
        private readonly TeamInvitationService $invitations,
        private readonly MembershipManager $manager,
        private readonly MembershipRepository $memberships,
        private readonly MunicipalityAdminInvitationRepository $invitationRepository,
    ) {
    }

    #[Route('', name: 'app_team_index', methods: ['GET'])]
    public function index(string $municipalityId): Response
    {
        [$user, $municipality] = $this->administrator($municipalityId, write: false);
        $team = $this->reader->read($municipality);

        return $this->page('municipal/team/index.html.twig', $user, $municipalityId, 'Équipe', $team + [
            'self_id' => $user->id()->toRfc4122(),
            'writable' => MunicipalityStatus::Active === $municipality->status(),
        ]);
    }

    #[Route('/inviter', name: 'app_team_invite', methods: ['GET', 'POST'])]
    public function invite(string $municipalityId, Request $request): Response
    {
        [$user, $municipality] = $this->administrator($municipalityId, write: true);
        $form = ['email' => '', 'roles' => [MunicipalityRole::EventManager->value]];
        if ($request->isMethod('POST')) {
            $post = $request->request->all();
            $form = ['email' => $this->text($post['email'] ?? ''), 'roles' => $this->roleValues($post['roles'] ?? [])];
            if (!$this->isCsrfTokenValid('team_invite', $this->text($post['_token'] ?? ''))) {
                return $this->inviteForm($user, $municipalityId, $form, 'Le formulaire a expiré. Validez à nouveau.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            try {
                $result = $this->invitations->issue($municipality, $form['email'], $this->toRoles($form['roles']), $user);
            } catch (DomainRuleViolation $e) {
                return $this->inviteForm($user, $municipalityId, $form, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            } catch (OperationConflict) {
                return $this->inviteForm($user, $municipalityId, $form, 'Un autre traitement est en cours : rien n’a été enregistré. Réessayez.', Response::HTTP_CONFLICT);
            }
            $this->addFlash($result->emailSent ? 'success' : 'warning', $result->emailSent
                ? 'Invitation envoyée. Elle ne donne aucun droit tant que la personne ne l’a pas acceptée.'
                : 'L’invitation est enregistrée mais l’e-mail n’a pas pu partir. Utilisez « Renvoyer » dans quelques instants.');

            return $this->back($municipalityId);
        }

        return $this->inviteForm($user, $municipalityId, $form);
    }

    #[Route('/{membershipId}/roles', name: 'app_team_roles', requirements: ['membershipId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function roles(string $municipalityId, string $membershipId, Request $request): Response
    {
        [$user, $municipality] = $this->administrator($municipalityId, write: true);
        $target = $this->member($municipality, $membershipId);
        $form = ['roles' => array_map(static fn (MunicipalityRole $r): string => $r->value, $target->roles())];
        if ($request->isMethod('POST')) {
            $post = $request->request->all();
            $form = ['roles' => $this->roleValues($post['roles'] ?? [])];
            if (!$this->isCsrfTokenValid('team_roles_'.$membershipId, $this->text($post['_token'] ?? ''))) {
                return $this->rolesForm($user, $municipalityId, $target, $form, 'Le formulaire a expiré. Validez à nouveau.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            try {
                $this->manager->changeTeamRoles($municipality, $target, $this->toRoles($form['roles']), $user);
            } catch (OperationNotAllowed $e) {
                if ($this->isRule($e)) {
                    return $this->rolesForm($user, $municipalityId, $target, $form, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                throw $e;
            } catch (DomainRuleViolation $e) {
                return $this->rolesForm($user, $municipalityId, $target, $form, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            } catch (OperationConflict) {
                return $this->rolesForm($user, $municipalityId, $target, $form, 'Un autre traitement est en cours : rien n’a été enregistré. Réessayez.', Response::HTTP_CONFLICT);
            }
            $this->addFlash('success', 'Les rôles ont été modifiés. Le changement est immédiat.');

            return $this->back($municipalityId);
        }

        return $this->rolesForm($user, $municipalityId, $target, $form);
    }

    #[Route('/{membershipId}/retirer', name: 'app_team_remove', requirements: ['membershipId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function remove(string $municipalityId, string $membershipId, Request $request): Response
    {
        [$user, $municipality] = $this->administrator($municipalityId, write: true);
        $target = $this->member($municipality, $membershipId);
        $name = trim($target->user()->firstName().' '.$target->user()->lastName());
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('team_remove_'.$membershipId, $this->text($request->request->get('_token')))) {
                $this->addFlash('error', 'Le formulaire a expiré. Rien n’a été modifié : validez à nouveau.');

                return $this->back($municipalityId);
            }
            try {
                $this->manager->removeFromTeam($municipality, $target, $user);
                $this->addFlash('success', sprintf('%s ne fait plus partie de l’équipe. L’accès est coupé immédiatement.', $name));
            } catch (OperationNotAllowed $e) {
                if (!$this->isRule($e)) {
                    throw $e;
                }
                $this->addFlash('error', $e->getMessage());
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->back($municipalityId);
        }

        return $this->confirmPage($user, $municipalityId, 'Retirer '.$name.' de l’équipe', [
            'Son accès à '.$municipality->name().' est coupé immédiatement, y compris s’il est connecté.',
            'Ses habilitations d’archive éventuelles dans cette commune sont révoquées.',
        ], [
            'Aucune donnée n’est supprimée ; ses actions passées restent tracées.',
            'Son compte et ses autres communes ne sont pas touchés.',
            'Vous pourrez l’inviter de nouveau plus tard.',
        ], 'Retirer de l’équipe', $this->generateUrl('app_team_remove', ['municipalityId' => $municipalityId, 'membershipId' => $membershipId]), 'team_remove_'.$membershipId);
    }

    #[Route('/invitations/{invitationId}/{action}', name: 'app_team_invitation', requirements: ['invitationId' => Requirement::UUID, 'action' => 'renvoyer|revoquer'], methods: ['GET', 'POST'])]
    public function invitation(string $municipalityId, string $invitationId, string $action, Request $request): Response
    {
        [$user, $municipality] = $this->administrator($municipalityId, write: true);
        $invitation = $this->invitation_($municipality, $invitationId);
        $resend = 'renvoyer' === $action;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('team_invitation_'.$action.'_'.$invitationId, $this->text($request->request->get('_token')))) {
                $this->addFlash('error', 'Le formulaire a expiré. Rien n’a été modifié : validez à nouveau.');

                return $this->back($municipalityId);
            }
            try {
                if ($resend) {
                    $sent = $this->invitations->resend($municipality, $invitation, $user)->emailSent;
                    $this->addFlash($sent ? 'success' : 'warning', $sent ? 'Nouvelle invitation envoyée ; l’ancien lien ne fonctionne plus.' : 'L’invitation est enregistrée mais l’e-mail n’a pas pu partir. Réessayez dans quelques instants.');
                } else {
                    $this->invitations->revoke($municipality, $invitation, $user);
                    $this->addFlash('success', 'Invitation révoquée : le lien envoyé ne fonctionne plus.');
                }
            } catch (OperationNotAllowed $e) {
                if (!$this->isRule($e)) {
                    throw $e;
                }
                $this->addFlash('error', $e->getMessage());
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            } catch (OperationConflict) {
                $this->addFlash('error', 'Un autre traitement est en cours : rien n’a été modifié. Réessayez.');
            }

            return $this->back($municipalityId);
        }

        return $this->confirmPage(
            $user,
            $municipalityId,
            $resend ? 'Renvoyer l’invitation' : 'Révoquer l’invitation',
            $resend
            ? ['Un nouveau lien est envoyé à la même adresse.', 'L’ancien lien cesse de fonctionner.']
            : ['Le lien envoyé ne fonctionnera plus.'],
            ['Aucun droit n’avait été donné par cette invitation.'],
            $resend ? 'Renvoyer' : 'Révoquer',
            $this->generateUrl('app_team_invitation', ['municipalityId' => $municipalityId, 'invitationId' => $invitationId, 'action' => $action]),
            'team_invitation_'.$action.'_'.$invitationId
        );
    }

    /**
     * @return array{0: User, 1: Municipality}
     */
    private function administrator(string $municipalityId, bool $write): array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $membership = $this->context->requireMembership($user, $municipalityId, write: false); // 404 uniforme : inconnue, étrangère ou non membre
        if (!$membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
            throw new AccessDeniedHttpException('Réservé aux administrateurs communaux.');
        }
        if ($write && MunicipalityStatus::Active !== $membership->municipality()->status()) {
            throw new AccessDeniedHttpException('Commune suspendue : gestion de l’équipe en lecture seule.');
        }

        return [$user, $membership->municipality()];
    }

    private function member(Municipality $municipality, string $membershipId): MunicipalityMembership
    {
        $target = $this->memberships->find(Uuid::fromString($membershipId));
        if (null === $target || !$target->isActive() || !$target->municipality()->id()->equals($municipality->id())) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return $target;
    }

    private function invitation_(Municipality $municipality, string $invitationId): MunicipalityAdminInvitation
    {
        $invitation = $this->invitationRepository->find(Uuid::fromString($invitationId));
        if (null === $invitation || !$invitation->municipality()->id()->equals($municipality->id())) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return $invitation;
    }

    /** Refus de RÈGLE métier (affichable) plutôt que refus de périmètre (404 uniforme). */
    private function isRule(OperationNotAllowed $e): bool
    {
        return str_contains($e->getMessage(), 'administrateur') || str_contains($e->getMessage(), 'propres') || str_contains($e->getMessage(), 'vous-même');
    }

    /**
     * @param array<string, mixed> $form
     */
    private function inviteForm(User $user, string $municipalityId, array $form, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->page('municipal/team/invite.html.twig', $user, $municipalityId, 'Inviter un collaborateur', [
            'form' => $form, 'error' => $error, 'role_options' => RoleLabels::options(), 'csrf' => 'team_invite',
            'action_url' => $this->generateUrl('app_team_invite', ['municipalityId' => $municipalityId]),
            'cancel_url' => $this->generateUrl('app_team_index', ['municipalityId' => $municipalityId]),
        ], $status);
    }

    /**
     * @param array<string, mixed> $form
     */
    private function rolesForm(User $user, string $municipalityId, MunicipalityMembership $target, array $form, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $id = $target->id()->toRfc4122();

        return $this->page('municipal/team/roles.html.twig', $user, $municipalityId, 'Modifier les rôles', [
            'form' => $form, 'error' => $error, 'role_options' => RoleLabels::options(), 'csrf' => 'team_roles_'.$id,
            'person' => trim($target->user()->firstName().' '.$target->user()->lastName()),
            'action_url' => $this->generateUrl('app_team_roles', ['municipalityId' => $municipalityId, 'membershipId' => $id]),
            'cancel_url' => $this->generateUrl('app_team_index', ['municipalityId' => $municipalityId]),
        ], $status);
    }

    /**
     * @param list<string> $consequences
     * @param list<string> $keeps
     */
    private function confirmPage(User $user, string $municipalityId, string $title, array $consequences, array $keeps, string $label, string $actionUrl, string $csrfId): Response
    {
        return $this->page('municipal/team/confirm.html.twig', $user, $municipalityId, $title, [
            'title' => $title, 'consequences' => $consequences, 'keeps' => $keeps, 'confirm_label' => $label, 'action_url' => $actionUrl, 'csrf' => $csrfId,
            'cancel_url' => $this->generateUrl('app_team_index', ['municipalityId' => $municipalityId]),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(string $template, User $user, string $municipalityId, string $title, array $data, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($template, $this->shell->forMunicipality($user, $municipalityId, 'team', $title) + $data, new Response('', $status));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function back(string $municipalityId): Response
    {
        return $this->redirectToRoute('app_team_index', ['municipalityId' => $municipalityId], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return list<string>
     */
    private function roleValues(mixed $raw): array
    {
        return array_values(array_filter(\is_array($raw) ? $raw : [], 'is_string'));
    }

    /**
     * @param list<string> $values
     *
     * @return list<MunicipalityRole>
     */
    private function toRoles(array $values): array
    {
        $roles = [];
        foreach ($values as $value) {
            $roles[] = MunicipalityRole::tryFrom($value) ?? throw new DomainRuleViolation('Rôle inconnu.');
        }

        return $roles;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
