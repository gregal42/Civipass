<?php

declare(strict_types=1);

namespace App\Admin\UI\Http;

use App\Admin\Application\AdministrativeHistoryReader;
use App\Admin\Application\MunicipalitySituationReader;
use App\Admin\Application\OpenableCommuneSearch;
use App\Identity\Application\CwsAccountRecovery;
use App\Identity\Application\UserManager;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Municipality\Application\CommercialAccessManager;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Application\MunicipalityManager;
use App\Municipality\Application\MunicipalityOpening;
use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityCommercialAccessRepository;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Municipality\Domain\NationalCommuneRepository;
use App\Onboarding\Application\MunicipalityAdminInvitationService;
use App\Onboarding\Domain\MunicipalityAdminInvitation;
use App\Onboarding\Domain\MunicipalityAdminInvitationRepository;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Actions d'écriture NOMMÉES du back-office CWS (LOT 07.1, amendement de DT-018) : ouvrir une commune, statut et contact, accès
 * commerciaux, invitations d'administrateur communal, comptes. Chaque action : POST + jeton CSRF propre à l'action et à la cible,
 * UN service applicatif audité, jamais de formulaire générique Sonata. Les routes vivent sous `/admin/cws/` : le pare-feu exige un
 * super-administrateur en authentification COMPLÈTE (security.yaml) ET chaque service revérifie l'acteur.
 *
 * Aucun identifiant fourni par le client n'est cru : chaque cible (accès, invitation, rattachement, compte) est relue et son type
 * est vérifié (404 uniforme). Aucune donnée opérationnelle (événement, demande, laissez-passer) n'est lue.
 */
#[Route('/admin/cws')]
final class CwsAdministrationController extends AbstractController
{
    /** Actions à confirmer : libellé et effet annoncé avant tout POST. */
    private const CONFIRMATIONS = [
        'commune_suspend' => ['Suspendre la commune', 'La commune passe en lecture seule : plus de nouvel événement ni de nouvelle participation, ses autorisations en cours deviennent inutilisables (DM-034, DM-035). Aucune donnée n’est supprimée ; la réactivation rétablit la situation.'],
        'commune_reactivate' => ['Réactiver la commune', 'La commune redevient active : ses droits et ses données sont retrouvés tels quels.'],
        'commune_deactivate' => ['Désactiver la commune', 'La commune devient inactive : plus aucun accès pour ses agents. Aucune donnée n’est supprimée. Vérifiez qu’aucun événement de crise n’est en cours.'],
        'access_revoke' => ['Révoquer l’accès commercial', 'La commune ne pourra plus créer de NOUVEAUX événements. Les événements déjà ouverts, leurs demandes et leurs laissez-passer ne sont pas interrompus. La révocation est définitive (un nouvel accès peut être attribué ensuite).'],
        'invitation_revoke' => ['Révoquer l’invitation', 'Le lien envoyé ne fonctionnera plus. Aucun droit n’avait été donné.'],
        'invitation_resend' => ['Renvoyer l’invitation', 'Un nouveau lien est envoyé à la même adresse ; l’ancien lien cesse de fonctionner.'],
        'admin_remove' => ['Retirer cet administrateur', 'Cette personne perd le rôle d’administrateur communal (ses autres rôles éventuels sont conservés). Le dernier administrateur actif d’une commune ne peut pas être retiré : invitez d’abord son remplaçant.'],
        'user_deactivate' => ['Désactiver le compte', 'La personne ne peut plus se connecter (effet à sa prochaine requête). Refusé si elle est le dernier administrateur actif d’une commune.'],
        'user_reactivate' => ['Réactiver le compte', 'La personne peut de nouveau se connecter avec son mot de passe actuel.'],
        'user_reset' => ['Envoyer un lien de réinitialisation', 'Un e-mail est envoyé à l’adresse du compte avec un lien valable 30 minutes. CWS ne voit jamais ce lien.'],
    ];

    public function __construct(
        private readonly MunicipalityRepository $municipalities,
        private readonly MembershipRepository $memberships,
        private readonly MunicipalityCommercialAccessRepository $accesses,
        private readonly MunicipalityAdminInvitationRepository $invitations,
        private readonly UserRepository $users,
        private readonly NationalCommuneRepository $referential,
        private readonly OpenableCommuneSearch $search,
        private readonly MunicipalitySituationReader $situations,
        private readonly AdministrativeHistoryReader $history,
        private readonly MunicipalityOpening $opening,
        private readonly MunicipalityManager $municipalityManager,
        private readonly CommercialAccessManager $accessManager,
        private readonly MunicipalityAdminInvitationService $invitationService,
        private readonly MembershipManager $membershipManager,
        private readonly UserManager $userManager,
        private readonly CwsAccountRecovery $recovery,
        private readonly ClockInterface $clock,
        #[Autowire('%env(MAILER_DSN)%')]
        #[\SensitiveParameter]
        private readonly string $mailerDsn = 'null://null',
    ) {
    }

    #[Route('/communes/ouvrir', name: 'admin_cws_open', methods: ['GET'])]
    public function openForm(Request $request): Response
    {
        $query = $this->text($request->query->get('q'));

        return $this->render('admin/cws/open.html.twig', ['query' => $query, 'results' => $this->search->search($query), 'min' => OpenableCommuneSearch::MIN_LENGTH]);
    }

    #[Route('/communes/ouvrir', name: 'admin_cws_open_do', methods: ['POST'])]
    public function open(Request $request): Response
    {
        if (!$this->valid($request, 'cws_open')) {
            return $this->failure('Le formulaire a expiré. Recommencez.', 'admin_cws_open');
        }
        try {
            $commune = $this->referential->findByInseeCode(InseeCode::fromString($this->text($request->request->get('insee')))) ?? throw new DomainRuleViolation('Commune introuvable au référentiel.');
            [$municipality, $created] = $this->opening->open($commune, $this->actor());
        } catch (DomainRuleViolation|OperationNotAllowed $e) {
            return $this->failure($e->getMessage(), 'admin_cws_open');
        }
        $this->addFlash('success', $created ? 'Commune ouverte. Attribuez-lui maintenant un accès commercial et invitez son administrateur.' : 'Cette commune existait déjà sur Civipass : voici sa fiche.');

        return $this->redirectToRoute('admin_cws_municipality', ['id' => $municipality->id()->toRfc4122()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/communes/{id}', name: 'admin_cws_municipality', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        $municipality = $this->municipality($id);
        $administrators = array_values(array_filter($this->memberships->findByMunicipality($municipality), static fn (MunicipalityMembership $m): bool => $m->hasRole(MunicipalityRole::MunicipalityAdmin)));

        return $this->render('admin/cws/municipality.html.twig', [
            'm' => $municipality,
            'situation' => $this->situations->read($municipality),
            'accesses' => $this->accesses->findByMunicipality($municipality->id()),
            'administrators' => $administrators,
            'invitations' => $this->invitations->findByMunicipality($municipality),
            'history' => $this->history->forMunicipality($municipality),
            'now' => $this->clock->now(),
            // Indicateur d'exploitation SANS valeur sensible : seul le fait que le transport ne soit pas « null » est exposé.
            'mailer_ready' => !str_starts_with($this->mailerDsn, 'null:'),
        ]);
    }

    #[Route('/communes/{id}/contact', name: 'admin_cws_contact', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function contact(string $id, Request $request): Response
    {
        $municipality = $this->municipality($id);
        if (!$this->valid($request, 'cws_contact_'.$id)) {
            return $this->failure('Le formulaire a expiré. Recommencez.', 'admin_cws_municipality', ['id' => $id]);
        }
        $p = $request->request;
        try {
            $this->municipalityManager->updateContact($municipality, $this->text($p->get('contact_name')), $this->text($p->get('contact_email')), $this->text($p->get('contact_phone')), $this->text($p->get('address_line')), $this->text($p->get('postal_code')), $this->text($p->get('city')), $this->actor());
        } catch (DomainRuleViolation|OperationNotAllowed $e) {
            return $this->failure($e->getMessage(), 'admin_cws_municipality', ['id' => $id]);
        }
        $this->addFlash('success', 'Contact enregistré.');

        return $this->redirectToRoute('admin_cws_municipality', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/communes/{id}/acces', name: 'admin_cws_access', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function access(string $id, Request $request): Response
    {
        $municipality = $this->municipality($id);
        if (!$this->valid($request, 'cws_access_'.$id)) {
            return $this->failure('Le formulaire a expiré. Recommencez.', 'admin_cws_municipality', ['id' => $id]);
        }
        $kind = $this->text($request->request->get('kind'));
        try {
            $expires = $this->endOfDay($this->text($request->request->get('expires_on')));
            $reason = $this->text($request->request->get('reason'));
            if ('renew' === $kind) {
                $this->accessManager->renew($municipality, $expires, $reason, $this->actor());
                $this->addFlash('success', 'Accès commercial renouvelé : il prend la suite de l’accès en cours.');
            } elseif ('grant' === $kind) {
                $this->accessManager->grant($municipality, $expires, $reason, $this->actor());
                $this->addFlash('success', 'Accès commercial attribué.');
            } else {
                throw new DomainRuleViolation('Action inconnue.');
            }
        } catch (DomainRuleViolation|OperationNotAllowed $e) {
            return $this->failure($e->getMessage(), 'admin_cws_municipality', ['id' => $id]);
        }

        return $this->redirectToRoute('admin_cws_municipality', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/communes/{id}/administrateurs/inviter', name: 'admin_cws_invite', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function invite(string $id, Request $request): Response
    {
        $municipality = $this->municipality($id);
        if (!$this->valid($request, 'cws_invite_'.$id)) {
            return $this->failure('Le formulaire a expiré. Recommencez.', 'admin_cws_municipality', ['id' => $id]);
        }
        try {
            $result = $this->invitationService->issue($municipality, $this->text($request->request->get('email')), $this->actor());
        } catch (DomainRuleViolation|OperationNotAllowed|OperationConflict $e) {
            return $this->failure($e->getMessage(), 'admin_cws_municipality', ['id' => $id]);
        }
        $this->flashDelivery($result->emailSent);

        return $this->redirectToRoute('admin_cws_municipality', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/comptes/{id}', name: 'admin_cws_user', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function user(string $id): Response
    {
        $user = $this->communalAccount($id);

        return $this->render('admin/cws/user.html.twig', ['u' => $user, 'memberships' => $this->memberships->findActiveByUser($user)]);
    }

    /** Page de confirmation : énonce l'effet de l'action avant tout POST (GET, aucune écriture). */
    #[Route('/confirmer/{action}/{id}', name: 'admin_cws_confirm', requirements: ['action' => '[a-z_]{3,30}', 'id' => Requirement::UUID], methods: ['GET'])]
    public function confirm(string $action, string $id): Response
    {
        [, $return, $returnParams] = $this->resolve($action, $id);
        [$title, $effect] = self::CONFIRMATIONS[$action];

        return $this->render('admin/cws/confirm.html.twig', ['title' => $title, 'effect' => $effect, 'cws_action' => $action, 'id' => $id, 'return' => $this->generateUrl($return, $returnParams)]);
    }

    #[Route('/executer/{action}/{id}', name: 'admin_cws_execute', requirements: ['action' => '[a-z_]{3,30}', 'id' => Requirement::UUID], methods: ['POST'])]
    public function execute(string $action, string $id, Request $request): Response
    {
        [$target, $return, $returnParams] = $this->resolve($action, $id);
        if (!$this->valid($request, 'cws_exec_'.$action.'_'.$id)) {
            return $this->failure('Le formulaire a expiré. Recommencez.', $return, $returnParams);
        }
        $actor = $this->actor();
        try {
            match ($action) {
                'commune_suspend' => $this->municipalityManager->setStatusByCws($this->as(Municipality::class, $target), MunicipalityStatus::Suspended, $actor),
                'commune_reactivate' => $this->municipalityManager->setStatusByCws($this->as(Municipality::class, $target), MunicipalityStatus::Active, $actor),
                'commune_deactivate' => $this->municipalityManager->setStatusByCws($this->as(Municipality::class, $target), MunicipalityStatus::Inactive, $actor),
                'access_revoke' => $this->accessManager->revoke($this->as(MunicipalityCommercialAccess::class, $target), $actor),
                'invitation_revoke' => $this->invitationService->revoke($this->as(MunicipalityAdminInvitation::class, $target), $actor),
                'invitation_resend' => $this->flashDelivery($this->invitationService->resend($this->as(MunicipalityAdminInvitation::class, $target), $actor)->emailSent),
                'admin_remove' => $this->membershipManager->removeAdministratorByCws($this->as(MunicipalityMembership::class, $target), $actor),
                'user_deactivate' => $this->userManager->deactivateByCws($this->as(User::class, $target), $actor),
                'user_reactivate' => $this->userManager->activateByCws($this->as(User::class, $target), $actor),
                'user_reset' => $this->recovery->sendResetLink($this->as(User::class, $target), $actor),
                default => throw $this->createNotFoundException(),
            };
        } catch (DomainRuleViolation|OperationNotAllowed|OperationConflict $e) {
            return $this->failure($e->getMessage(), $return, $returnParams);
        }
        if ('invitation_resend' !== $action) {
            $this->addFlash('success', self::CONFIRMATIONS[$action][0].' : fait.');
        }

        return $this->redirectToRoute($return, $returnParams, Response::HTTP_SEE_OTHER);
    }

    /**
     * Relit la cible et refuse (404 uniforme) toute action inconnue ou tout identifiant qui n'est pas du type attendu.
     *
     * @return array{0: object, 1: string, 2: array<string, string>} la cible, la route de retour et ses paramètres
     */
    private function resolve(string $action, string $id): array
    {
        if (!isset(self::CONFIRMATIONS[$action])) {
            throw $this->createNotFoundException();
        }
        $uuid = Uuid::fromString($id);
        $target = match (true) {
            str_starts_with($action, 'commune_') => $this->municipalities->find($uuid),
            str_starts_with($action, 'access_') => $this->accesses->find($uuid),
            str_starts_with($action, 'invitation_') => $this->invitations->find($uuid),
            str_starts_with($action, 'admin_') => $this->memberships->find($uuid),
            default => $this->communalAccount($id),
        };
        if (null === $target) {
            throw $this->createNotFoundException();
        }
        if ($target instanceof Municipality) {
            return [$target, 'admin_cws_municipality', ['id' => $id]];
        }
        if ($target instanceof User) {
            return [$target, 'admin_cws_user', ['id' => $id]];
        }
        if ($target instanceof MunicipalityMembership && !$target->hasRole(MunicipalityRole::MunicipalityAdmin)) {
            throw $this->createNotFoundException(); // CWS ne touche qu'aux administrateurs (A3)
        }

        return [$target, 'admin_cws_municipality', ['id' => $target->municipality()->id()->toRfc4122()]];
    }

    private function municipality(string $id): Municipality
    {
        return $this->municipalities->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException();
    }

    /** Compte atteignable depuis le back-office : jamais un compte CWS (la console reste la seule voie). */
    private function communalAccount(string $id): User
    {
        $user = $this->users->find(Uuid::fromString($id));
        if (null === $user || $user->isSuperAdmin()) {
            throw $this->createNotFoundException();
        }

        return $user;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function as(string $class, object $target): object
    {
        return $target instanceof $class ? $target : throw $this->createNotFoundException();
    }

    private function actor(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }

    private function valid(Request $request, string $tokenId): bool
    {
        return $this->isCsrfTokenValid($tokenId, $this->text($request->request->get('_token')));
    }

    /**
     * @param array<string, string> $params
     */
    private function failure(string $message, string $route, array $params = []): Response
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute($route, $params, Response::HTTP_SEE_OTHER);
    }

    private function flashDelivery(bool $sent): void
    {
        $this->addFlash($sent ? 'success' : 'warning', $sent ? 'Invitation envoyée. Elle ne donne aucun droit tant qu’elle n’est pas acceptée.' : 'L’invitation est enregistrée mais l’e-mail n’a pas pu partir. Utilisez « Renvoyer » une fois la messagerie rétablie.');
    }

    /** « AAAA-MM-JJ » → fin de journée à Paris (exclusive), en UTC ; vide = sans expiration. */
    private function endOfDay(string $date): ?\DateTimeImmutable
    {
        if ('' === $date) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Paris'));
        if (false === $day || $day->format('Y-m-d') !== $date) {
            throw new DomainRuleViolation('Date d\'expiration invalide.');
        }

        return $day->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
