<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use App\Identity\Domain\User;
use App\Permit\Application\AuthorizationZoneService;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\Read\PermitRightsOverview;
use App\Permit\Application\Result\ZoneChangeRequest;
use App\Permit\Domain\LabelCorrectionReason;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\ZoneAddReason;
use App\Permit\Domain\ZoneChangeKind;
use App\Permit\Domain\ZonePlan;
use App\Permit\Domain\ZoneReinstatementReason;
use App\Permit\Domain\ZoneRemovalReason;
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
 * Modifier les zones de l'autorisation d'une commune et corriger leurs libellés (LOT 05.5.3). Aucune règle ici : le planificateur et le service décident, relisent
 * l'état sous verrou et écrivent ; la commune, la demande et les identifiants de zones du formulaire ne sont jamais crus. Deux temps : APERÇU (aucune écriture :
 * les zones rattachées après l'opération), puis CONFIRMATION (POST protégé par CSRF). Saisies conservées en cas de refus.
 *
 * Réponses : 403 rôle absent ; 404 uniforme hors périmètre (commune, demande, zone étrangère ou inexistante) ; 409 action impossible ou conflit technique ; 422 saisie
 * refusée. `no-store` partout. Le motif n'est jamais affiché hors de l'historique de la commune.
 */
final class PermitZonesController extends AbstractController
{
    public function __construct(
        private readonly PermitRightsOverviewReader $overviews,
        private readonly AuthorizationZoneService $zones,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/droits/zones', name: 'app_municipal_permit_zones', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function change(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $overview = $this->overview($user, $municipalityId, $requestId);
        $this->remember($request, $municipalityId);
        $blocked = !$overview->canChangeZones ? $overview->changeZonesBlockedReason : '';
        $form = ['add' => [], 'remove' => [], 'reinstate' => [], 'add_reason' => '', 'remove_reason' => '', 'reinstate_reason' => '', 'comment' => ''];
        $errors = [];
        $plan = null;
        $status = '' !== $blocked ? Response::HTTP_CONFLICT : Response::HTTP_OK;
        if ('' === $blocked && $request->isMethod('POST')) {
            $form = [
                'add' => self::list($request, 'add'),
                'remove' => self::list($request, 'remove'),
                'reinstate' => self::list($request, 'reinstate'),
                'add_reason' => self::text($request, 'add_reason'),
                'remove_reason' => self::text($request, 'remove_reason'),
                'reinstate_reason' => self::text($request, 'reinstate_reason'),
                'comment' => self::text($request, 'comment'),
            ];
            $command = new ZoneChangeRequest($form['add'], $form['remove'], $form['reinstate'], $form['add_reason'], $form['remove_reason'], $form['reinstate_reason'], $form['comment']);
            if (!$this->isCsrfTokenValid($this->csrfId('change', $requestId), self::text($request, '_token'))) {
                $errors['form'] = 'Le formulaire a expiré : rien n’a été enregistré. Recommencez.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                try {
                    if ('apply' === $request->request->get('step')) {
                        $result = $this->zones->change($user, $municipalityId, $requestId, $command);
                        $this->addFlash('success', self::done($result->plan));

                        return $this->redirectToRoute('app_municipal_permit_rights', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
                    }
                    $plan = $this->zones->preview($user, $municipalityId, $requestId, $command);
                } catch (PermitAccessDenied) {
                    throw new AccessDeniedHttpException('Action non autorisée.');
                } catch (PermitAccessUnavailable) {
                    $status = Response::HTTP_CONFLICT;
                    $errors['form'] = 'La participation de votre commune à cet événement n’est plus active : rien n’a été enregistré.';
                } catch (OperationConflict $conflict) {
                    $status = Response::HTTP_CONFLICT;
                    $errors['form'] = ConflictCause::Deadlock === $conflict->cause || ConflictCause::LockWaitTimeout === $conflict->cause
                        ? 'Un autre traitement est en cours : rien n’a été enregistré. Réessayez dans un instant.'
                        : 'Les droits ont changé entre-temps : rien n’a été enregistré. Relisez la page.';
                } catch (OperationNotAllowed $forged) {
                    throw $forged; // zone forgée, étrangère ou inexistante : refus uniforme (404), jamais un message de formulaire
                } catch (DomainRuleViolation $violation) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    $errors[self::field($violation->getMessage())] = $violation->getMessage();
                }
            }
        }

        return $this->render('permit/zones.html.twig', $this->context($user, $municipalityId, $requestId, $overview) + [
            'blocked' => $blocked,
            'form' => $form,
            'errors' => $errors,
            'plan' => $plan,
            'kinds' => ['REMOVED' => 'Retirée', 'REINSTATED' => 'Réintégrée', 'ADDED' => 'Ajoutée'],
            'add_reasons' => array_map(static fn (ZoneAddReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], ZoneAddReason::cases()),
            'remove_reasons' => array_map(static fn (ZoneRemovalReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], ZoneRemovalReason::cases()),
            'reinstate_reasons' => array_map(static fn (ZoneReinstatementReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], ZoneReinstatementReason::cases()),
            'comment_max' => PermitRevision::COMMENT_MAX_LENGTH,
            'action_url' => $this->generateUrl('app_municipal_permit_zones', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'csrf_id' => $this->csrfId('change', $requestId),
        ], $this->protected($status));
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/droits/zones/libelles', name: 'app_municipal_permit_zone_labels', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function labels(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $overview = $this->overview($user, $municipalityId, $requestId);
        $this->remember($request, $municipalityId);
        $blocked = !$overview->canCorrectLabels ? $overview->correctLabelsBlockedReason : '';
        $form = ['zone' => [], 'reason' => '', 'confirmed' => false];
        $errors = [];
        $status = '' !== $blocked ? Response::HTTP_CONFLICT : Response::HTTP_OK;
        if ('' === $blocked && $request->isMethod('POST')) {
            $form = ['zone' => self::list($request, 'zone'), 'reason' => self::text($request, 'reason'), 'confirmed' => '1' === $request->request->get('confirmed')];
            if (!$this->isCsrfTokenValid($this->csrfId('labels', $requestId), self::text($request, '_token'))) {
                $errors['form'] = 'Le formulaire a expiré : rien n’a été enregistré. Recommencez.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                try {
                    $changes = $this->zones->correctLabels($user, $municipalityId, $requestId, $form['zone'], $form['reason'], $form['confirmed']);
                    $this->addFlash('success', sprintf('%d libellé(s) corrigé(s). Le périmètre n’est pas modifié : aucune notification n’est envoyée.', \count($changes)));

                    return $this->redirectToRoute('app_municipal_permit_rights', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
                } catch (PermitAccessDenied) {
                    throw new AccessDeniedHttpException('Action non autorisée.');
                } catch (PermitAccessUnavailable) {
                    $status = Response::HTTP_CONFLICT;
                    $errors['form'] = 'La participation de votre commune à cet événement n’est plus active : rien n’a été enregistré.';
                } catch (OperationConflict) {
                    $status = Response::HTTP_CONFLICT;
                    $errors['form'] = 'Un autre traitement est en cours ou les droits ont changé : rien n’a été enregistré. Réessayez.';
                } catch (OperationNotAllowed $forged) {
                    throw $forged;
                } catch (DomainRuleViolation $violation) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    $errors[str_contains($violation->getMessage(), 'Confirmez') ? 'confirmed' : (str_contains($violation->getMessage(), 'motif') ? 'reason' : 'form')] = $violation->getMessage();
                }
            }
        }

        return $this->render('permit/zone_labels.html.twig', $this->context($user, $municipalityId, $requestId, $overview) + [
            'blocked' => $blocked,
            'form' => $form,
            'errors' => $errors,
            'reasons' => array_map(static fn (LabelCorrectionReason $r): array => ['code' => $r->value, 'label' => $r->label()], LabelCorrectionReason::cases()),
            'action_url' => $this->generateUrl('app_municipal_permit_zone_labels', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'csrf_id' => $this->csrfId('labels', $requestId),
        ], $this->protected($status));
    }

    /** @return list<string> */
    private static function list(Request $request, string $name): array
    {
        $values = $request->request->all($name);

        return array_values(array_filter($values, static fn (mixed $v): bool => \is_string($v) && '' !== $v));
    }

    private static function text(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return \is_string($value) ? $value : '';
    }

    private static function done(ZonePlan $plan): string
    {
        $parts = [];
        foreach ([[ZoneChangeKind::Removed, 'retirée(s)'], [ZoneChangeKind::Reinstated, 'réintégrée(s)'], [ZoneChangeKind::Added, 'ajoutée(s)']] as [$kind, $word]) {
            $n = \count(array_filter($plan->changes, static fn ($c): bool => $c->kind === $kind));
            if ($n > 0) {
                $parts[] = $n.' '.$word;
            }
        }

        return 'Zones modifiées : '.implode(', ', $parts).'. '.($plan->hasRemoval() ? 'Le bénéficiaire est prévenu sans attendre.' : 'Le bénéficiaire est prévenu par e-mail si une adresse est enregistrée.');
    }

    private static function field(string $message): string
    {
        return match (true) {
            str_contains($message, 'motif du retrait') => 'remove_reason',
            str_contains($message, 'motif de la réintégration') => 'reinstate_reason',
            str_contains($message, 'Motif d\'ajout') => 'add_reason',
            str_contains($message, 'ommentaire') => 'comment',
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
        return $this->shell->forMunicipality($user, $municipalityId, 'requests', 'Zones du laissez-passer '.$overview->reference) + [
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

    private function csrfId(string $action, string $requestId): string
    {
        return 'permit_zones_'.$action.'_'.$requestId;
    }
}
