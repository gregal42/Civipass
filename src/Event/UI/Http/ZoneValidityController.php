<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\Command\ValiditySlotInput;
use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Application\EventZoneReader;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Read\ZoneBoard;
use App\Event\Application\Read\ZoneView;
use App\Event\Application\Service\EventZoneService;
use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Validity\InvalidValiditySlot;
use App\Event\Domain\Validity\ValidityMode;
use App\Event\UI\Http\Form\ValidityChoiceFactory;
use App\Event\UI\Http\Form\ValiditySlotFormTranslator;
use App\Identity\Domain\User;
use App\Shared\Domain\ConflictCause;
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

/**
 * Validité d'une zone : consultation des créneaux (fuseau de l'événement), choix du mode, ajout, modification et suppression
 * d'un créneau. Aucune règle métier ici : EventZoneService::configureValidity décide sous transaction.
 *
 * Le moteur REMPLACE la configuration entière (DM-075 : modifier = supprimer logiquement puis créer). Chaque opération part donc de
 * la configuration relue côté serveur, la modifie (ajout, remplacement, retrait d'un créneau) et la rend au moteur. Une empreinte
 * de la configuration affichée accompagne chaque formulaire : si elle a changé entre-temps (autre administrateur), rien n'est
 * écrit (409). Cette comparaison précède l'écriture sans en être atomique : un écart de quelques millisecondes reste possible
 * (limite connue, documentée).
 *
 * Seule la commune propriétaire de la zone agit : la zone doit figurer, avec le drapeau « sienne », dans le tableau des zones lu
 * côté serveur ; un créneau n'est jamais désigné que parmi ceux de cette zone. Statuts : 404 uniforme (zone ou créneau inconnu),
 * 403 (action refusée), 422 (saisie ou règle, saisies conservées), 409 (conflit ou configuration modifiée), 303 après succès.
 * Tout ce qui sert à réafficher la page est lu AVANT l'écriture (l'EntityManager peut être fermé après un échec).
 */
final class ZoneValidityController extends AbstractController
{
    /** Cible du lien du récapitulatif d'erreurs, par champ du composant `validity_form` (suffixe de l'identifiant du formulaire). */
    private const FIELD_TARGETS = ['mode' => '_mode', 'end' => '_c_end_date', 'date' => '_s_date', 'days' => '_d1', 'rec_range' => '_r_start'];

    public function __construct(
        private readonly EventZoneReader $reader,
        private readonly EventZoneService $zones,
        private readonly ValiditySlotFormTranslator $translator,
        private readonly ValidityChoiceFactory $choices,
        private readonly ShellContextFactory $shell,
        private readonly EventViewFactory $views,
    ) {
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/zones/{zoneId}/validite', name: 'app_municipal_zone_validity', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'zoneId' => Requirement::UUID], methods: ['GET'])]
    public function show(string $municipalityId, string $eventId, string $zoneId): Response
    {
        [$shell, $board] = $this->load($municipalityId, $eventId);
        $zone = $board->zone($zoneId) ?? throw new OperationNotAllowed('Ressource inaccessible.');

        return $this->render('municipal/event/zone_validity.html.twig', $this->page($shell, $board, $municipalityId, $zone));
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/zones/{zoneId}/validite/configuration', name: 'app_municipal_zone_validity_scope', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'zoneId' => Requirement::UUID], methods: ['POST'])]
    public function scope(string $municipalityId, string $eventId, string $zoneId, Request $request): Response
    {
        return $this->handle($municipalityId, $eventId, $zoneId, $request, 'scope');
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/zones/{zoneId}/validite/creneaux', name: 'app_municipal_zone_validity_slot_add', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'zoneId' => Requirement::UUID], methods: ['POST'])]
    public function add(string $municipalityId, string $eventId, string $zoneId, Request $request): Response
    {
        return $this->handle($municipalityId, $eventId, $zoneId, $request, 'add');
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/zones/{zoneId}/validite/creneaux/{slotId}/modifier', name: 'app_municipal_zone_validity_slot_edit', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'zoneId' => Requirement::UUID, 'slotId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $municipalityId, string $eventId, string $zoneId, string $slotId, Request $request): Response
    {
        return $this->handle($municipalityId, $eventId, $zoneId, $request, 'edit', $slotId);
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/zones/{zoneId}/validite/creneaux/{slotId}/supprimer', name: 'app_municipal_zone_validity_slot_delete', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'zoneId' => Requirement::UUID, 'slotId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function delete(string $municipalityId, string $eventId, string $zoneId, string $slotId, Request $request): Response
    {
        return $this->handle($municipalityId, $eventId, $zoneId, $request, 'delete', $slotId);
    }

    /**
     * @param 'scope'|'add'|'edit'|'delete' $operation
     */
    private function handle(string $municipalityId, string $eventId, string $zoneId, Request $request, string $operation, ?string $slotId = null): Response
    {
        [$shell, $board] = $this->load($municipalityId, $eventId);
        $zone = $board->zone($zoneId) ?? throw new OperationNotAllowed('Ressource inaccessible.');
        $availability = $zone->visibleAction(EventAction::ConfigureValidity);
        if (!$zone->own || null === $availability || !$availability->allowed) {
            throw $this->denied();
        }
        $periods = ValidityMode::SpecificPeriods === $zone->validityMode;
        if ('scope' !== $operation && !$periods) {
            throw $this->denied(); // aucun créneau à ajouter, modifier ou supprimer avant le choix « périodes spécifiques »
        }
        $post = $request->request->all();
        $csrfId = 'zone_validity_'.$operation.'_'.($slotId ?? $zoneId);
        $template = \in_array($operation, ['edit', 'delete'], true) ? 'municipal/event/zone_validity_slot.html.twig' : 'municipal/event/zone_validity.html.twig';

        $index = null;
        if (null !== $slotId) {
            foreach ($zone->slots as $position => $slot) {
                if ($slot->slotId === $slotId) {
                    $index = $position;
                }
            }
            if (null === $index) {
                if ($request->isMethod('POST') && $this->text($post['revision'] ?? '') !== $zone->validityRevision()) {
                    return $this->stale($shell, $board, $municipalityId, $zone); // formulaire d'une configuration remplacée depuis
                }
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
        }
        $slot = null === $index ? null : $zone->slots[$index];
        $render = fn (int $status, array $extra = []): Response => $this->render($template, $this->page($shell, $board, $municipalityId, $zone, $extra + ['slot_mode' => $operation, 'slot' => $slot, 'slot_index' => null === $index ? null : $index + 1]), new Response('', $status));
        if ($request->isMethod('GET')) {
            return $render(Response::HTTP_OK);
        }
        if (!$this->isCsrfTokenValid($csrfId, $this->text($post['_token'] ?? ''))) {
            return $render(Response::HTTP_UNPROCESSABLE_ENTITY, ['alert' => 'Le formulaire a expiré. Rien n’a été enregistré : validez à nouveau.']);
        }
        $formId = match ($operation) {
            'scope' => 'cfg',
            'add' => 'new',
            'edit' => 'edit',
            'delete' => 'del',
        };
        $panel = 'add' === $operation ? 'add' : null;

        $existing = array_map($this->translator->inputOf(...), $zone->slots);
        $input = null;
        if ('add' === $operation || 'edit' === $operation || ('scope' === $operation && 'periods' === $this->scopeOf($post))) {
            if ('scope' === $operation && $periods) {
                throw $this->denied(); // le mode se change vers « durée de l'événement » seulement ; les créneaux se gèrent un à un
            }
            [$input, $errors, $values] = $this->translator->fromPost($formId, $post);
            if (null === $input) {
                return $render(Response::HTTP_UNPROCESSABLE_ENTITY, $this->formState($formId, $values, $errors, [], [], $panel));
            }
        }
        $expected = $this->text($post['revision'] ?? ''); // vérifiée par le moteur SOUS les verrous de la zone (jamais ici)
        $command = match ($operation) {
            'scope' => 'periods' === $this->scopeOf($post) ? new ZoneValidityCommand(ValidityMode::SpecificPeriods->value, [$input ?? throw $this->denied()], $expected) : $this->eventDuration($post, $expected),
            'add' => new ZoneValidityCommand(ValidityMode::SpecificPeriods->value, [...$existing, $input], $expected),
            'edit' => new ZoneValidityCommand(ValidityMode::SpecificPeriods->value, $this->replaced($existing, (int) $index, $input), $expected),
            'delete' => new ZoneValidityCommand(ValidityMode::SpecificPeriods->value, array_values(array_filter($existing, static fn (int $position): bool => $position !== $index, \ARRAY_FILTER_USE_KEY)), $expected),
        };

        try {
            $result = $this->zones->configureValidity($this->currentUser(), $zoneId, $command);
        } catch (OperationNotAllowed) {
            throw $this->denied();
        } catch (InvalidValiditySlot $e) {
            $values = $this->translator->fromPost($formId, $post)[2];
            $posted = [];
            foreach (null !== $input ? $input->dstChoices : [] as $choice) {
                $posted[$choice['date'].'|'.$choice['field']] = $choice['occurrence'];
            }
            $choices = null !== $input ? $this->choices->choices($e->problems, $input, EventTimezone::fromIdentifier($board->detail->timezone), $posted) : [];
            $messages = $this->choices->messages($e->problems);

            return $render(Response::HTTP_UNPROCESSABLE_ENTITY, $this->formState($formId, $values, [] === $messages ? [] : ['times' => implode(' ', $messages), 'end' => implode(' ', $messages)], $choices, $messages, $panel));
        } catch (DomainRuleViolation $e) {
            return $render(Response::HTTP_UNPROCESSABLE_ENTITY, $this->formState($formId, $this->translator->fromPost($formId, $post)[2], [], [], [$e->getMessage()], $panel));
        } catch (OperationConflict $e) {
            if (ConflictCause::StaleState === $e->cause) {
                return $this->stale($shell, $board, $municipalityId, $zone);
            }

            return $render(Response::HTTP_CONFLICT, ['alert' => 'Un autre traitement est en cours : rien n’a été enregistré. Vos saisies sont conservées, vous pouvez réessayer.']);
        }

        $this->addFlash('success', $result->changed ? match ($operation) {
            'scope' => 'periods' === $this->scopeOf($post) ? 'La validité est configurée : le premier créneau a été enregistré.' : 'La zone est valable pendant toute la durée de l’événement.',
            'add' => 'Le créneau a été ajouté.',
            'edit' => 'Le créneau a été modifié.',
            'delete' => 'Le créneau a été supprimé.',
        } : 'Aucune modification : la configuration est inchangée.');

        return $this->redirectToRoute('app_municipal_zone_validity', ['municipalityId' => $municipalityId, 'eventId' => $eventId, 'zoneId' => $zoneId], Response::HTTP_SEE_OTHER);
    }

    /**
     * 409 : la configuration affichée n'est plus la configuration en base (constaté par le moteur sous verrou). Rien n'a été écrit ;
     * aucune fusion, aucune nouvelle tentative : la page ne propose que de relire la configuration actuelle.
     *
     * @param array<string, mixed> $shell
     */
    private function stale(array $shell, ZoneBoard $board, string $municipalityId, ZoneView $zone): Response
    {
        return $this->render('municipal/event/zone_validity.html.twig', $this->page($shell, $board, $municipalityId, $zone, [
            'alert' => 'La validité de cette zone a été modifiée par un autre traitement depuis l’affichage de la page : rien n’a été enregistré. Relisez la configuration actuelle, puis recommencez votre modification.',
            'stale' => true,
            'can_edit' => false,
        ]), new Response('', Response::HTTP_CONFLICT));
    }

    /**
     * @param array<string, mixed> $post
     */
    private function eventDuration(array $post, string $expected): ZoneValidityCommand
    {
        if ('event' !== $this->scopeOf($post)) {
            throw $this->denied();
        }

        return new ZoneValidityCommand(ValidityMode::EventDuration->value, [], $expected);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function scopeOf(array $post): string
    {
        $cfg = $post['cfg'] ?? null;

        return \is_array($cfg) ? $this->text($cfg['scope'] ?? '') : '';
    }

    /**
     * @param list<ValiditySlotInput> $slots
     *
     * @return list<ValiditySlotInput>
     */
    private function replaced(array $slots, int $index, ValiditySlotInput $input): array
    {
        $slots[$index] = $input;

        return array_values($slots);
    }

    /**
     * @param array<string, mixed>       $values
     * @param array<string, string>      $errors
     * @param list<array<string, mixed>> $choices
     * @param list<string>               $messages
     *
     * @return array<string, mixed>
     */
    private function formState(string $formId, array $values, array $errors, array $choices, array $messages, ?string $panel): array
    {
        $summary = [];
        foreach ($errors as $key => $message) {
            $target = $formId.(self::FIELD_TARGETS[$key] ?? ('times' === $key ? ('single' === ($values['mode'] ?? '') ? '_s_from' : '_r_from') : ''));
            $summary[] = ['target' => $target, 'message' => $message];
        }
        foreach ($messages as $message) {
            if ([] === $errors) {
                $summary[] = ['target' => $formId, 'message' => $message];
            }
        }
        if ([] !== $choices) {
            $summary[] = ['target' => $formId.'_dst', 'message' => 'Choisissez l’occurrence de l’horaire qui se produit deux fois (changement d’heure).'];
        }

        return ['forms' => [$formId => ['values' => $values, 'errors' => 'times' === array_key_first($errors) && 'continuous' === ($values['mode'] ?? '') ? ['end' => $errors['times']] : $errors, 'choices' => $choices]], 'summary' => $summary, 'open_panel' => $panel];
    }

    /**
     * @return array{array<string, mixed>, ZoneBoard}
     */
    private function load(string $municipalityId, string $eventId): array
    {
        $user = $this->currentUser();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Validité de la zone');
        $board = $this->reader->forEvent($user, $municipalityId, $eventId); // 404 uniforme si inaccessible

        return [$shell, $board];
    }

    /**
     * @param array<string, mixed> $shell
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function page(array $shell, ZoneBoard $board, string $municipalityId, ZoneView $zone, array $extra = []): array
    {
        $route = ['municipalityId' => $municipalityId, 'eventId' => $board->detail->eventId, 'zoneId' => $zone->zoneId];
        $availability = $zone->visibleAction(EventAction::ConfigureValidity);
        $alert = isset($extra['alert']) && \is_string($extra['alert']) ? $extra['alert'] : null;
        unset($extra['alert']);

        return $this->views->page($shell, $board->detail, $municipalityId, 'Validité · '.$zone->label, $alert, array_merge([
            'board' => $board,
            'zone' => $zone,
            'group' => $board->groupOf($zone->zoneId),
            'can_edit' => $zone->own && null !== $availability && $availability->allowed,
            'revision' => $zone->validityRevision(),
            'edit_values' => array_map($this->translator->valuesOf(...), $zone->slots),
            'forms' => [],
            'summary' => [],
            'problem' => null,
            'validity_url' => $this->generateUrl('app_municipal_zone_validity', $route),
            'zone_url' => $this->generateUrl('app_municipal_event_zone', $route),
        ], $extra));
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function denied(): AccessDeniedHttpException
    {
        return new AccessDeniedHttpException('Action non autorisée.');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
