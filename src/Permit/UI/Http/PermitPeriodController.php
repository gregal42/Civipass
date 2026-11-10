<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use App\Event\Domain\Time\AmbiguousLocalTime;
use App\Identity\Domain\User;
use App\Permit\Application\AuthorizationPeriodService;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\Read\PermitRightsOverview;
use App\Permit\Domain\PeriodEnd;
use App\Permit\Domain\PeriodKind;
use App\Permit\Domain\PeriodPlan;
use App\Permit\Domain\PeriodReason;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\ShorteningReason;
use App\Request\Domain\DurationMode;
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
 * Prolonger, reprendre et raccourcir la période de l'autorisation d'une commune (LOT 05.5.2). Aucune règle ici : le planificateur et le service décident,
 * relisent l'état sous verrou et écrivent ; la commune et la demande de l'URL ne sont jamais crues. Deux temps : APERÇU (aucune écriture : la période
 * qui résulterait de l'opération, en heure de l'événement), puis CONFIRMATION (POST protégé par CSRF). Saisies conservées en cas de refus.
 *
 * Réponses : 403 rôle absent ; 404 uniforme hors périmètre ; 409 action impossible (participation, événement, période) ou conflit technique ; 422 saisie
 * refusée. `no-store` partout. Le motif n'est jamais affiché hors de l'historique de la commune.
 */
final class PermitPeriodController extends AbstractController
{
    private const MODES_EXTEND = [DurationMode::H24, DurationMode::H48, DurationMode::H72, DurationMode::Custom, DurationMode::UntilClosure];

    public function __construct(
        private readonly PermitRightsOverviewReader $overviews,
        private readonly AuthorizationPeriodService $periods,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/droits/periode/prolonger', name: 'app_municipal_permit_period_extend', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function extend(string $municipalityId, string $requestId, Request $request): Response
    {
        return $this->handle($municipalityId, $requestId, $request, PeriodKind::Extended);
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/droits/periode/raccourcir', name: 'app_municipal_permit_period_shorten', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function shorten(string $municipalityId, string $requestId, Request $request): Response
    {
        return $this->handle($municipalityId, $requestId, $request, PeriodKind::Shortened);
    }

    private function handle(string $municipalityId, string $requestId, Request $request, PeriodKind $kind): Response
    {
        $user = $this->user();
        $overview = $this->overview($user, $municipalityId, $requestId);
        $this->remember($request, $municipalityId);
        $shortening = PeriodKind::Shortened === $kind;
        $blocked = $shortening ? $overview->shortenBlockedReason : $overview->extendBlockedReason;
        $form = ['mode' => $shortening ? DurationMode::Custom->value : DurationMode::H24->value, 'end_date' => '', 'end_time' => '', 'occurrence' => '', 'reason' => '', 'comment' => '', 'confirmed' => false];
        $errors = [];
        $plan = null;
        $ambiguous = false;
        $status = '' !== $blocked ? Response::HTTP_CONFLICT : Response::HTTP_OK;
        if ('' === $blocked && $request->isMethod('POST')) {
            $form = $this->read($request, $form);
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid($this->csrfId($kind, $requestId), \is_string($token) ? $token : '')) {
                $errors['form'] = 'Le formulaire a expiré : rien n’a été enregistré. Recommencez.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                try {
                    $end = PeriodEnd::fromInput($form['mode'], $form['end_date'], $form['end_time'], $form['occurrence']);
                    if ($shortening && DurationMode::Custom !== $end->mode) {
                        throw new DomainRuleViolation('Indiquez la date et l’heure de la nouvelle fin.');
                    }
                    if ('apply' === $request->request->get('step')) {
                        $change = $shortening
                            ? $this->periods->shorten($user, $municipalityId, $requestId, $end, $form['reason'], $form['comment'], $form['confirmed'])
                            : $this->periods->extend($user, $municipalityId, $requestId, $end, $form['reason'], $form['comment']);
                        $this->addFlash('success', self::done($change->plan));

                        return $this->redirectToRoute('app_municipal_permit_rights', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
                    }
                    $plan = $this->periods->preview($user, $municipalityId, $requestId, $kind, $end);
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
                } catch (AmbiguousLocalTime $e) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    $ambiguous = true;
                    $errors['end'] = $e->getMessage();
                } catch (OperationNotAllowed $forged) {
                    throw $forged; // ressource inaccessible : refus uniforme (404), jamais un message de formulaire
                } catch (DomainRuleViolation $violation) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    $errors[self::field($violation->getMessage())] = $violation->getMessage();
                }
            }
        }

        return $this->render('permit/period.html.twig', $this->context($user, $municipalityId, $requestId, $overview) + [
            'kind' => $kind->value,
            'shortening' => $shortening,
            'resuming' => 'resume' === $overview->extendMode,
            'blocked' => $blocked,
            'modes' => $shortening ? [] : array_map(static fn (DurationMode $m): array => ['value' => $m->value, 'label' => self::modeLabel($m)], self::MODES_EXTEND),
            'reasons' => $shortening
                ? array_map(static fn (ShorteningReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], ShorteningReason::cases())
                : array_map(static fn (PeriodReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], PeriodReason::cases()),
            'comment_max' => PermitRevision::COMMENT_MAX_LENGTH,
            'form' => $form,
            'errors' => $errors,
            'plan' => $plan,
            'ambiguous' => $ambiguous,
            'action_url' => $this->generateUrl($shortening ? 'app_municipal_permit_period_shorten' : 'app_municipal_permit_period_extend', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'csrf_id' => $this->csrfId($kind, $requestId),
        ], $this->protected($status));
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array{mode: string, end_date: string, end_time: string, occurrence: string, reason: string, comment: string, confirmed: bool}
     */
    private function read(Request $request, array $form): array
    {
        $text = static function (string $name) use ($request): string {
            $value = $request->request->get($name);

            return \is_string($value) ? $value : '';
        };

        return [
            'mode' => $text('mode') ?: (string) $form['mode'],
            'end_date' => $text('end_date'),
            'end_time' => $text('end_time'),
            'occurrence' => $text('occurrence'),
            'reason' => $text('reason'),
            'comment' => $text('comment'),
            'confirmed' => '1' === $request->request->get('confirmed'),
        ];
    }

    private static function modeLabel(DurationMode $mode): string
    {
        return match ($mode) {
            DurationMode::H24 => 'Ajouter 24 heures',
            DurationMode::H48 => 'Ajouter 48 heures',
            DurationMode::H72 => 'Ajouter 72 heures',
            DurationMode::Custom => 'Choisir la date et l’heure de fin',
            DurationMode::UntilClosure => 'Jusqu’à la clôture de l’événement',
        };
    }

    private static function done(PeriodPlan $plan): string
    {
        return match ($plan->kind) {
            PeriodKind::Extended => 'Période prolongée. Le bénéficiaire est prévenu par e-mail si une adresse est enregistrée.',
            PeriodKind::Resumed => 'Droits repris à partir de maintenant ; aucun droit n’est rétabli pour la période écoulée. Le bénéficiaire est prévenu par e-mail si une adresse est enregistrée.',
            PeriodKind::Shortened => $plan->immediate
                ? 'Fin immédiate enregistrée : les droits de votre commune prennent fin maintenant. Le bénéficiaire est prévenu sans attendre.'
                : 'Période raccourcie. Le bénéficiaire est prévenu sans attendre.',
        };
    }

    private static function field(string $message): string
    {
        return match (true) {
            str_contains($message, 'motif de raccourcissement'), str_contains($message, 'Un motif est obligatoire'), str_contains($message, 'Motif inconnu') => 'reason',
            str_contains($message, 'ommentaire') => 'comment',
            str_contains($message, 'Confirmez') => 'confirmed',
            str_contains($message, 'durée'), str_contains($message, 'date'), str_contains($message, 'heure'), str_contains($message, 'fin') => 'end',
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
        return $this->shell->forMunicipality($user, $municipalityId, 'requests', 'Période du laissez-passer '.$overview->reference) + [
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

    private function csrfId(PeriodKind $kind, string $requestId): string
    {
        return 'permit_period_'.strtolower($kind->value).'_'.$requestId;
    }
}
