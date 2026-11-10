<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use App\Identity\Domain\User;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\PermitVehicleService;
use App\Permit\Application\PlateFormatter;
use App\Permit\Application\Read\PermitRightsOverview;
use App\Permit\Application\Result\VehicleChangeRequest;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\VehicleAddReason;
use App\Permit\Domain\VehicleChangeKind;
use App\Permit\Domain\VehiclePlan;
use App\Permit\Domain\VehicleReinstatementReason;
use App\Permit\Domain\VehicleRemovalReason;
use App\Permit\Domain\VehicleReplacementReason;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Shell\CommuneSelectionController;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Modifier les plaques d'un laissez-passer (LOT 05.5.4). Les plaques sont COMMUNES à toutes les communes du laissez-passer : l'écran l'annonce, nomme les autres communes
 * concernées et exige la confirmation de cette portée. Aucune règle ici : le planificateur et le service décident, relisent l'état sous verrou et écrivent ; la commune,
 * la demande et les identifiants de plaques du formulaire ne sont jamais crus. Deux temps : APERÇU (aucune écriture), puis CONFIRMATION (POST protégé par CSRF). Saisies
 * conservées en cas de refus.
 *
 * Réponses : 403 rôle absent ; 404 uniforme hors périmètre (commune, demande, plaque étrangère ou inexistante, laissez-passer à pied/vélo) ; 409 action impossible ou
 * conflit technique ; 422 saisie refusée. `no-store` partout. Le motif n'est jamais affiché hors de l'historique de la commune.
 */
final class PermitVehiclesController extends AbstractController
{
    public function __construct(
        private readonly PermitRightsOverviewReader $overviews,
        private readonly PermitVehicleService $vehicles,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/droits/vehicules', name: 'app_municipal_permit_vehicles', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function change(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $overview = $this->overview($user, $municipalityId, $requestId);
        $panel = $overview->vehicles ?? throw new OperationNotAllowed('Ressource inaccessible.');
        if (!$panel->vehicleMode) {
            throw new OperationNotAllowed('Ressource inaccessible.'); // laissez-passer à pied ou à vélo : aucune plaque, refus uniforme
        }
        $this->remember($request, $municipalityId);
        $blocked = !$panel->canChange ? $panel->blockedReason : '';
        $form = ['add' => [], 'remove' => [], 'reinstate' => [], 'replace' => [], 'add_reason' => '', 'remove_reason' => '', 'replace_reason' => '', 'reinstate_reason' => '', 'comment' => '', 'confirm' => false];
        $errors = [];
        $plan = null;
        $status = '' !== $blocked ? Response::HTTP_CONFLICT : Response::HTTP_OK;
        if ('' === $blocked && $request->isMethod('POST')) {
            $form = [
                'add' => self::list($request, 'add'),
                'remove' => self::list($request, 'remove'),
                'reinstate' => self::list($request, 'reinstate'),
                'replace' => self::pairs($request),
                'add_reason' => self::text($request, 'add_reason'),
                'remove_reason' => self::text($request, 'remove_reason'),
                'replace_reason' => self::text($request, 'replace_reason'),
                'reinstate_reason' => self::text($request, 'reinstate_reason'),
                'comment' => self::text($request, 'comment'),
                'confirm' => '1' === $request->request->get('confirm_global'),
            ];
            $replacements = [];
            foreach ($form['replace'] as $from => $to) {
                $replacements[] = ['from' => (string) $from, 'to' => $to];
            }
            $command = new VehicleChangeRequest($form['add'], $form['remove'], $form['reinstate'], $replacements, $form['add_reason'], $form['remove_reason'], $form['replace_reason'], $form['reinstate_reason'], $form['comment'], $form['confirm']);
            if (!$this->isCsrfTokenValid($this->csrfId($requestId), self::text($request, '_token'))) {
                $errors['form'] = 'Le formulaire a expiré : rien n’a été enregistré. Recommencez.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                try {
                    if ('apply' === $request->request->get('step')) {
                        $result = $this->vehicles->change($user, $municipalityId, $requestId, $command);
                        $this->addFlash('success', self::done($result->plan));

                        return $this->redirectToRoute('app_municipal_permit_rights', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
                    }
                    $plan = $this->vehicles->preview($user, $municipalityId, $requestId, $command);
                } catch (PermitAccessDenied) {
                    throw new AccessDeniedHttpException('Action non autorisée.');
                } catch (PermitAccessUnavailable) {
                    $status = Response::HTTP_CONFLICT;
                    $errors['form'] = 'La participation de votre commune à cet événement n’est plus active : rien n’a été enregistré.';
                } catch (OperationConflict $conflict) {
                    $status = Response::HTTP_CONFLICT;
                    $errors['form'] = ConflictCause::Deadlock === $conflict->cause || ConflictCause::LockWaitTimeout === $conflict->cause
                        ? 'Un autre traitement est en cours : rien n’a été enregistré. Réessayez dans un instant.'
                        : 'Les plaques ont changé entre-temps : rien n’a été enregistré. Relisez la page.';
                } catch (OperationNotAllowed $forged) {
                    throw $forged; // plaque forgée, étrangère ou inexistante : refus uniforme (404), jamais un message de formulaire
                } catch (DomainRuleViolation $violation) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    $errors[self::field($violation->getMessage())] = $violation->getMessage();
                }
            }
        }

        return $this->render('permit/vehicles.html.twig', $this->context($user, $municipalityId, $requestId, $overview) + [
            'blocked' => $blocked,
            'form' => $form,
            'errors' => $errors,
            'plan' => $plan,
            'plan_lines' => null === $plan ? [] : array_map(static fn ($c): array => ['kind' => $c->kind->value, 'plate' => PlateFormatter::formatRegistration($c->registration, $c->format), 'pair' => $c->pair], $plan->changes),
            'kinds' => [VehicleChangeKind::Removed->value => 'Retirée', VehicleChangeKind::ReplacedOut->value => 'Remplacée (ancienne plaque)', VehicleChangeKind::ReplacedIn->value => 'Remplacée (nouvelle plaque)', VehicleChangeKind::Reinstated->value => 'Réactivée', VehicleChangeKind::Added->value => 'Ajoutée'],
            'add_reasons' => array_map(static fn (VehicleAddReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], VehicleAddReason::cases()),
            'remove_reasons' => array_map(static fn (VehicleRemovalReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], VehicleRemovalReason::cases()),
            'replace_reasons' => array_map(static fn (VehicleReplacementReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], VehicleReplacementReason::cases()),
            'reinstate_reasons' => array_map(static fn (VehicleReinstatementReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], VehicleReinstatementReason::cases()),
            'comment_max' => PermitRevision::COMMENT_MAX_LENGTH,
            'action_url' => $this->generateUrl('app_municipal_permit_vehicles', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'csrf_id' => $this->csrfId($requestId),
        ], $this->protected($status));
    }

    /** @return list<string> */
    private static function list(Request $request, string $name): array
    {
        $values = $request->request->all($name);

        return array_values(array_filter($values, static fn (mixed $v): bool => \is_string($v) && '' !== trim($v)));
    }

    /**
     * Remplacements `replace[<identifiant de plaque>] = nouvelle plaque` : seules les entrées renseignées sont retenues.
     *
     * @return array<string, string>
     */
    private static function pairs(Request $request): array
    {
        $pairs = [];
        foreach ($request->request->all('replace') as $from => $to) {
            if (\is_string($to) && '' !== trim($to)) {
                $pairs[(string) $from] = $to;
            }
        }

        return $pairs;
    }

    private static function text(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return \is_string($value) ? $value : '';
    }

    private static function done(VehiclePlan $plan): string
    {
        $parts = [];
        foreach ([[VehicleChangeKind::Removed, 'retirée(s)'], [VehicleChangeKind::ReplacedIn, 'remplacée(s)'], [VehicleChangeKind::Reinstated, 'réactivée(s)'], [VehicleChangeKind::Added, 'ajoutée(s)']] as [$kind, $word]) {
            $n = \count(array_filter($plan->changes, static fn ($c): bool => $c->kind === $kind));
            if ($n > 0) {
                $parts[] = $n.' '.$word;
            }
        }

        return 'Plaques modifiées : '.implode(', ', $parts).'. '.($plan->hasRemoval() ? 'Le bénéficiaire est prévenu sans attendre.' : 'Le bénéficiaire est prévenu par e-mail si une adresse est enregistrée.');
    }

    private static function field(string $message): string
    {
        return match (true) {
            str_contains($message, 'Confirmez') => 'confirm',
            str_contains($message, 'motif du retrait') => 'remove_reason',
            str_contains($message, 'motif du remplacement') => 'replace_reason',
            str_contains($message, 'motif de la réactivation') => 'reinstate_reason',
            str_contains($message, 'Motif d\'ajout') => 'add_reason',
            str_contains($message, 'ommentaire') => 'comment',
            str_contains($message, 'Plaque d\'immatriculation invalide') => 'add',
            default => 'form',
        };
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        return $user;
    }

    private function overview(User $user, string $municipalityId, string $requestId): PermitRightsOverview
    {
        try {
            return $this->overviews->overview($user, $municipalityId, $requestId);
        } catch (PermitAccessDenied) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
    }

    /** @return array<string, mixed> */
    private function context(User $user, string $municipalityId, string $requestId, PermitRightsOverview $overview): array
    {
        return $this->shell->forMunicipality($user, $municipalityId, 'requests', 'Plaques du laissez-passer '.$overview->reference) + [
            'overview' => $overview,
            'rights_url' => $this->generateUrl('app_municipal_permit_rights', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
        ];
    }

    private function protected(int $status): Response
    {
        $response = new Response('', $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function remember(Request $request, string $municipalityId): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }
    }

    private function csrfId(string $requestId): string
    {
        return 'permit_vehicles_'.$requestId;
    }
}
