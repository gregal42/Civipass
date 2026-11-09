<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Event\Domain\Time\AmbiguousLocalTime;
use App\Event\Domain\Time\Occurrence;
use App\Request\Application\AgentRequestDenied;
use App\Request\Application\Command\AttachmentReference;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\Result\ProposableCommune;
use App\Request\Application\Result\SubmittedRequest;
use App\Request\Application\Result\ApprovalPreview;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\PermitRequestAttachment;
use App\Request\Domain\TravelMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Moteur unique du formulaire de demande en six étapes, partagé par le dépôt public et la saisie par un agent : une page serveur
 * par étape, brouillon en session (jamais en base), validation, justificatifs temporaires, récapitulatif, soumission. Seul le
 * contexte (RequestFormContext) change : URL, brouillon, jeton CSRF, gabarit et service de dépôt appelé. Aucune règle métier
 * ici : le service de dépôt les porte toutes, transaction et idempotence comprises.
 */
abstract class AbstractRequestFormController extends AbstractController
{
    protected const STEPS_REQUIREMENT = 'identite|situation|zones|deplacement|justificatifs|duree|recapitulatif';
    /** Version de l'attestation d'information de l'agent (distincte de la prise de connaissance du bénéficiaire public). */
    protected const AGENT_NOTICE_VERSION = 'agent-information-1';

    public function __construct(protected readonly RequestFormServices $s)
    {
    }

    /**
     * Appelle le service de dépôt du contexte : dépôt public, ou délivrance directe par un agent (`$duration` obligatoire, nulle en public).
     *
     * @throws OperationNotAllowed
     * @throws DomainRuleViolation
     * @throws OperationConflict
     */
    abstract protected function deposit(RequestFormContext $c, SubmitRequestCommand $command, ?ApprovalDuration $duration): SubmittedRequest;

    protected function step(Request $request, RequestFormContext $c, string $step): Response
    {
        $communes = $this->s->proposable->forEvent($c->event, $this->s->clock->now());
        if ($c->agent) {
            // Saisie municipale : seules les zones de la commune de l'agent sont proposées (le service de dépôt le revérifie).
            $communes = array_values(array_filter($communes, static fn (ProposableCommune $commune): bool => $commune->participation->municipality()->id()->toRfc4122() === $c->municipalityId));
        }
        if ([] === $communes) {
            return $this->render($c->agent ? 'municipal/request/new_closed.html.twig' : 'request/public/closed.html.twig', ['page_title' => 'Demande de laissez-passer', 'request_event' => ['name' => $c->event->name()]] + $c->view);
        }

        $current = PublicRequestStep::from($step);
        if (!\in_array($current, PublicRequestStep::sequence($c->agent), true)) {
            throw new NotFoundHttpException(); // « Durée » n'existe que pour la délivrance directe
        }
        $proposable = $this->zoneIds($communes);

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $c, $current, $communes, $proposable);
        }

        // Une étape n'est accessible que si les précédentes sont valides (le brouillon est revérifié : une zone a pu cesser d'être proposée).
        $values = $this->pruneZones($c->draft, $proposable);
        foreach (PublicRequestStep::sequence($c->agent) as $before) {
            if ($before === $current) {
                break;
            }
            $errors = $this->validateStep($c, $before, $values, $proposable);
            if ([] !== $errors) {
                if (PublicRequestStep::Duration === $before) {
                    // La durée a cessé d'être valable depuis sa saisie (zone suspendue, période écoulée) : on l'explique, jamais de redirection muette.
                    $this->addFlash('request_notice', $errors['duration'] ?? $errors['form'] ?? 'Vérifiez la durée de l\'autorisation.');
                }

                return $this->redirect(($c->stepUrl)($before));
            }
        }

        return $this->form($c, $current, $communes, [], Response::HTTP_OK);
    }

    /**
     * Confirmation du dépôt PUBLIC : lisible UNIQUEMENT par la session qui l'a réalisé, jamais mise en cache. Aucun QR code, aucun suivi en
     * ligne. (La confirmation d'une délivrance directe est une page serveur distincte, relue en base : DirectIssuanceController.).
     */
    protected function confirmationPage(RequestFormContext $c): Response
    {
        $confirmation = $c->draft->confirmation();
        if (null === $confirmation) {
            throw new NotFoundHttpException();
        }
        $response = $this->render('request/public/confirmation.html.twig', [
            'page_title' => 'Demande envoyée',
            'request_event' => ['name' => $confirmation['event']],
            'reference' => $confirmation['reference'],
            'email' => $confirmation['email'],
            'beneficiary' => $confirmation['beneficiary'],
        ] + $c->view);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @param list<ProposableCommune> $communes
     * @param list<string>            $proposable
     */
    private function handlePost(Request $request, RequestFormContext $c, PublicRequestStep $current, array $communes, array $proposable): Response
    {
        $draft = $c->draft;
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid($c->csrfId, \is_string($token) ? $token : '')) {
            return $this->form($c, $current, $communes, ['form' => 'Votre session a expiré : vérifiez vos informations puis réessayez.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // Double clic ou nouvelle soumission après un dépôt réussi : la confirmation existe déjà, rien n'est recréé.
        if (PublicRequestStep::Recap === $current && null !== $draft->confirmation()) {
            return $this->redirect(($c->confirmationUrl)(), Response::HTTP_SEE_OTHER);
        }
        if (PublicRequestStep::Identity === $current && null !== $draft->confirmation()) {
            $draft->reset(); // nouvelle demande : on repart d'un brouillon vide
        }
        $errors = [];
        $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        $removed = false;

        if (PublicRequestStep::Documents === $current) {
            [$removed, $errors, $status] = $this->handleDocuments($request, $draft);
        } else {
            $draft->merge($this->submittedValues($request, $current));
            $values = $this->pruneZones($draft, $proposable, PublicRequestStep::Zones === $current);
            $errors = $this->validateStep($c, $current, $values, $proposable);
        }

        if ([] === $errors) {
            if (PublicRequestStep::Recap === $current) {
                return $this->submit($c, $communes, $proposable);
            }
            if ($removed) {
                return $this->redirect(($c->stepUrl)($current), Response::HTTP_SEE_OTHER);
            }

            return $this->redirect(($c->stepUrl)($current->next($c->agent) ?? $current), Response::HTTP_SEE_OTHER);
        }

        return $this->form($c, $current, $communes, $errors, $status);
    }

    /**
     * Soumission définitive : le brouillon est REVALIDÉ en entier (zones et fichiers au moment du dépôt), puis transmis au service
     * de dépôt, qui porte toutes les règles métier, la transaction, la référence et l'idempotence. En cas d'échec rien n'est
     * créé, le brouillon est conservé et l'on peut corriger puis réessayer.
     *
     * @param list<ProposableCommune> $communes
     * @param list<string>            $proposable
     */
    private function submit(RequestFormContext $c, array $communes, array $proposable): Response
    {
        $draft = $c->draft;
        $values = $this->pruneZones($draft, $proposable);
        foreach (PublicRequestStep::sequence($c->agent) as $step) {
            $errors = $this->validateStep($c, $step, $values, $proposable);
            if ([] !== $errors) {
                return $this->form($c, $step, $communes, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        // Justificatifs expirés ou supprimés (purge de 24 h) : retirés du brouillon, à renvoyer.
        $missing = [];
        foreach ($draft->files() as $file) {
            if (!$this->s->files->exists($file['key'])) {
                $draft->removeFile($file['key']);
                $missing[] = sprintf('Le justificatif « %s » n\'est plus disponible : renvoyez-le.', $file['name']);
            }
        }
        if ([] !== $missing) {
            return $this->form($c, PublicRequestStep::Documents, $communes, ['files' => implode("\n", $missing)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $vehicle = 'vehicle' === $values['mode'];
        $command = new SubmitRequestCommand(
            $c->event->id()->toRfc4122(),
            $values['last'],
            $values['first'],
            $values['address'],
            $values['email'],
            $values['phone'],
            $values['category'],
            $values['motif'],
            $values['detail'],
            $vehicle ? TravelMode::Vehicle : TravelMode::FootBike,
            $vehicle ? $values['plates'] : [],
            $values['zones'],
            '1' === $values['privacy'],
            $c->agent ? self::AGENT_NOTICE_VERSION : null,
            array_map(static fn (array $f): AttachmentReference => new AttachmentReference($f['key'], $f['name']), $draft->files()),
        );
        try {
            $result = $this->deposit($c, $command, $c->agent ? $this->durationOf($values) : null);
        } catch (OperationNotAllowed) {
            return $this->failed($c, $c->agent ? 'Le dépôt n\'est plus possible : vos droits, l\'événement ou la participation de votre commune ont changé.' : 'Le dépôt n\'est plus possible pour cet événement.');
        } catch (DomainRuleViolation $e) {
            return $this->failed($c, $e->getMessage());
        } catch (OperationConflict) {
            return $this->failed($c, 'La demande est en cours de traitement ou le service est très sollicité : patientez un instant puis renvoyez-la.');
        } catch (\Throwable $e) {
            $this->s->logger->error('Échec technique du dépôt d\'une demande.', ['exception' => $e::class, 'agent' => $c->agent]);

            return $this->failed($c, 'Une erreur technique est survenue : la demande n\'a pas été enregistrée.');
        }

        // Aucun accusé de réception pour une délivrance directe : le message du dépôt public annonce une demande à instruire (hors périmètre : e-mails).
        if (!$c->agent && !$result->replayed && '' !== trim($values['email'])) {
            try {
                $this->s->receipts->sendReceipt(trim($values['email']), $result->reference, $c->event->name());
            } catch (\Throwable $e) {
                // L'accusé de réception ne doit jamais défaire un dépôt réussi.
                $this->s->logger->warning('Accusé de réception de demande non envoyé.', ['exception' => $e::class]);
            }
        }
        $draft->confirm(['reference' => $result->reference, 'event' => $c->event->name(), 'email' => trim($values['email']), 'beneficiary' => trim($values['first'].' '.$values['last']), 'request_id' => $result->requestId]);

        return $this->redirect(($c->confirmationUrl)(), Response::HTTP_SEE_OTHER);
    }

    /**
     * Échec du dépôt : message à l'écran (session flash) et retour au récapitulatif ; redirection plutôt que rendu direct, car
     * après un échec technique l'EntityManager est fermé (DT-007) et une nouvelle requête repart d'un état sain.
     */
    private function failed(RequestFormContext $c, string $message): Response
    {
        $this->addFlash('request_error', $message);

        return $this->redirect(($c->stepUrl)(PublicRequestStep::Recap), Response::HTTP_SEE_OTHER);
    }

    /**
     * Étape des justificatifs : retrait d'un fichier déjà reçu, puis réception des nouveaux (contrôle du contenu réel, stockage
     * temporaire privé). Un fichier refusé n'empêche pas les autres.
     *
     * @return array{bool, array<string, string>, int}
     */
    private function handleDocuments(Request $request, PublicRequestDraft $draft): array
    {
        $remove = $request->request->get('remove');
        if (\is_string($remove) && $draft->removeFile($remove)) {
            $this->s->uploads->discard($remove);

            return [true, [], Response::HTTP_OK];
        }
        $files = array_values(array_filter((array) $request->files->get('files', []), static fn (mixed $f): bool => $f instanceof UploadedFile));
        $messages = [];
        if ([] !== $files && !$this->s->uploadLimiter->create($request->getClientIp() ?? 'unknown')->consume(\count($files))->isAccepted()) {
            return [false, ['files' => 'Trop de fichiers envoyés en peu de temps : réessayez dans quelques minutes.'], Response::HTTP_TOO_MANY_REQUESTS];
        }
        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            if (\count($draft->files()) >= PermitRequestAttachment::MAX_PER_REQUEST) {
                $messages[] = sprintf('« %s » : 5 justificatifs au maximum.', $name);
                continue;
            }
            if (!$file->isValid()) {
                $messages[] = \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                    ? sprintf('« %s » : le fichier dépasse 10 Mo.', $name)
                    : sprintf('« %s » : le fichier n\'a pas pu être reçu, renvoyez-le.', $name);
                continue;
            }
            try {
                $stored = $this->s->uploads->store($file->getPathname(), $name);
                $draft->addFile($stored->temporaryKey, $stored->document->originalName, $stored->document->mimeType, $stored->document->sizeBytes);
            } catch (DomainRuleViolation $e) {
                $messages[] = $e->getMessage();
            }
        }

        return [false, [] === $messages ? [] : ['files' => implode("\n", $messages)], Response::HTTP_UNPROCESSABLE_ENTITY];
    }

    /**
     * Valeurs soumises pour l'étape (chaînes et listes de chaînes uniquement ; le reste est ignoré).
     *
     * @return array<string, string|list<string>>
     */
    private function submittedValues(Request $request, PublicRequestStep $step): array
    {
        $text = static fn (string $key): string => \is_string($v = $request->request->all()[$key] ?? '') ? trim($v) : '';

        return match ($step) {
            PublicRequestStep::Identity => ['last' => $text('last'), 'first' => $text('first'), 'address' => $text('address'), 'email' => $text('email'), 'phone' => $text('phone')],
            PublicRequestStep::Situation => ['category' => $text('category'), 'motif' => $text('motif'), 'detail' => $text('detail')],
            PublicRequestStep::Zones => ['zones' => $this->submittedZones($request)],
            PublicRequestStep::Travel => ['mode' => $text('mode'), 'plates' => $this->submittedPlates($request)],
            PublicRequestStep::Duration => ['duration_mode' => $text('duration_mode'), 'duration_date' => $text('duration_date'), 'duration_time' => $text('duration_time'), 'duration_occurrence' => $text('duration_occurrence')],
            PublicRequestStep::Recap => ['privacy' => '1' === $text('privacy') ? '1' : ''],
            PublicRequestStep::Documents => [],
        };
    }

    /**
     * Le composant de sélection poste `zones[<commune>][selected]` et `zones[<commune>][zones][]` : seules les zones des
     * communes cochées comptent. Les identifiants restent à revalider contre les zones proposées.
     *
     * @return list<string>
     */
    private function submittedZones(Request $request): array
    {
        $ids = [];
        foreach ((array) ($request->request->all()['zones'] ?? []) as $commune) {
            if (!\is_array($commune) || '1' !== ($commune['selected'] ?? null)) {
                continue;
            }
            foreach ((array) ($commune['zones'] ?? []) as $zoneId) {
                if (\is_string($zoneId)) {
                    $ids[] = $zoneId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function submittedPlates(Request $request): array
    {
        $plates = [];
        foreach ((array) ($request->request->all()['plates'] ?? []) as $plate) {
            if (\is_string($plate) && '' !== trim($plate)) {
                $plates[] = trim($plate);
            }
        }

        return array_slice($plates, 0, 25);
    }

    /**
     * Valeurs du brouillon, sans les zones qui ne sont plus proposées (elles sont retirées du brouillon).
     *
     * @param list<string> $proposable
     *
     * @return array<string, string|list<string>>
     */
    private function pruneZones(PublicRequestDraft $draft, array $proposable, bool $keepStale = false): array
    {
        $values = $draft->values();
        if (!$keepStale) {
            $kept = array_values(array_intersect($values['zones'], $proposable));
            if (\count($kept) !== \count($values['zones'])) {
                $draft->merge(['zones' => $kept]);
                $values['zones'] = $kept;
            }
        }

        return $values;
    }

    /**
     * @param list<ProposableCommune> $communes
     * @param array<string, string>   $errors
     */
    private function form(RequestFormContext $c, PublicRequestStep $current, array $communes, array $errors, int $status): Response
    {
        $values = $c->draft->values();
        $steps = [];
        foreach (PublicRequestStep::sequence($c->agent) as $step) {
            $before = $this->index($step, $c->agent) < $this->index($current, $c->agent);
            $steps[] = [
                'label' => $step->label(),
                'href' => $before ? ($c->stepUrl)($step) : '',
                'state' => $step === $current ? ([] !== $errors ? 'error' : 'current') : ($before ? 'done' : 'todo'),
            ];
        }
        $previous = $current->previous($c->agent);
        $summary = [];
        foreach ($errors as $field => $message) {
            if ('form' !== $field) {
                $summary[] = ['target' => $field, 'message' => explode("\n", $message)[0]];
            }
        }
        $chosen = array_flip($values['zones']);
        $zoneCommunes = array_map(static fn (ProposableCommune $commune): array => [
            'id' => $commune->participation->id()->toRfc4122(),
            'name' => $commune->name(),
            'checked' => [] !== array_filter($commune->zones, static fn ($z): bool => isset($chosen[$z->id()->toRfc4122()])),
            'zones' => array_map(static fn ($z): array => ['id' => $z->id()->toRfc4122(), 'name' => $z->label(), 'checked' => isset($chosen[$z->id()->toRfc4122()])], $commune->zones),
        ], $communes);

        return $this->render('request/public/page.html.twig', [
            'page_title' => 'Demande de laissez-passer',
            'layout' => $c->agent ? 'layouts/app.html.twig' : 'layouts/public.html.twig',
            'agent' => $c->agent,
            'request_event' => ['name' => $c->event->name(), 'communes' => array_map(static fn (ProposableCommune $commune): string => $commune->name(), $communes)],
            'step' => $current->value,
            'steps' => $steps,
            'action' => ($c->stepUrl)($current),
            'prev_href' => null === $previous ? '' : ($c->stepUrl)($previous),
            'csrf_id' => $c->csrfId,
            'f' => $values,
            'err' => $errors,
            'errors' => \count($summary) > 1 ? $summary : [],
            'categories' => array_map(static fn ($category): array => ['id' => $category->code(), 'label' => $category->label()], $this->s->referentials->activeCategories()),
            'motifs' => array_map(static fn ($m): array => ['id' => $m->code(), 'label' => $m->label()], $this->s->referentials->activeMotifs()),
            'zone_communes' => $zoneCommunes,
            'files' => $c->draft->files(),
            'privacy_url' => $this->s->privacyPolicyUrl,
            'notice' => PublicRequestStep::Recap === $current ? $this->consumeFlash() : [],
            'hints' => $this->consumeFlash('request_notice'),
            'recap' => PublicRequestStep::Recap === $current ? $this->recap($c, $values, $communes) : [],
            'issuance' => $c->agent && PublicRequestStep::Recap === $current ? $this->issuance($c, $values) : null,
            'event_tz' => $c->event->timezone()->identifier(),
            'event_ends_at' => $c->event->endsAt(),
        ] + $c->view, new Response(status: $status));
    }

    /**
     * Sections du récapitulatif (texte échappé par Twig ; rien n'est passé en HTML).
     *
     * @param array{last: string, first: string, address: string, email: string, phone: string, category: string, motif: string, detail: string, mode: string, privacy: string, duration_mode: string, duration_date: string, duration_time: string, duration_occurrence: string, plates: list<string>, zones: list<string>} $values
     * @param list<ProposableCommune>                                                                                                                                                                                                                                                                                        $communes
     *
     * @return list<array{title: string, href: string, items: list<array<string, mixed>>}>
     */
    private function recap(RequestFormContext $c, array $values, array $communes): array
    {
        $href = static fn (PublicRequestStep $s): string => ($c->stepUrl)($s);
        $categories = [];
        foreach ($this->s->referentials->activeCategories() as $category) {
            $categories[$category->code()] = $category->label();
        }
        $motifs = [];
        foreach ($this->s->referentials->activeMotifs() as $motif) {
            $motifs[$motif->code()] = $motif->label();
        }

        // Champs facultatifs absents : « Non renseigné(e) », comme le prototype.
        $identity = [
            ['label' => 'Bénéficiaire', 'value' => trim($values['last'].' '.$values['first'])],
            ['label' => 'Adresse', 'value' => '' !== $values['address'] ? $values['address'] : 'Non renseignée'],
            ['label' => 'E-mail', 'value' => '' !== $values['email'] ? $values['email'] : 'Non renseigné'],
            ['label' => 'Téléphone', 'value' => '' !== $values['phone'] ? $values['phone'] : 'Non renseigné'],
        ];
        $situation = [
            ['label' => 'Catégorie', 'value' => $categories[$values['category']] ?? ''],
            ['label' => 'Motif', 'value' => $motifs[$values['motif']] ?? '', 'note' => '' === $values['detail'] ? '· sans précision' : ''],
        ];
        if ('' !== $values['detail']) {
            $situation[] = ['label' => 'Précision', 'value' => $values['detail']];
        }
        $chosen = array_flip($values['zones']);
        $zones = [];
        foreach ($communes as $commune) {
            $labels = [];
            foreach ($commune->zones as $zone) {
                if (isset($chosen[$zone->id()->toRfc4122()])) {
                    $labels[] = $zone->label();
                }
            }
            if ([] !== $labels) {
                $zones[] = ['label' => $commune->name(), 'value' => implode(', ', $labels)];
            }
        }
        $travel = 'vehicle' === $values['mode']
            ? [['label' => 'Mode', 'value' => 'En véhicule'], ['label' => 'Immatriculations', 'plates' => array_map('strtoupper', $values['plates'])]]
            : [['label' => 'Mode', 'value' => 'À pied / à vélo']];
        $names = array_map(fn (array $f): string => sprintf('%s (%s)', $f['name'], $this->size($f['size'])), $c->draft->files());

        return [
            ['title' => 'Identité et contact', 'href' => $href(PublicRequestStep::Identity), 'items' => $identity],
            ['title' => 'Situation', 'href' => $href(PublicRequestStep::Situation), 'items' => $situation],
            ['title' => 'Zones', 'href' => $href(PublicRequestStep::Zones), 'items' => $zones],
            ['title' => 'Déplacement', 'href' => $href(PublicRequestStep::Travel), 'items' => $travel],
            ['title' => 'Justificatifs', 'href' => $href(PublicRequestStep::Documents), 'items' => [['label' => 'Fichiers', 'value' => [] === $names ? 'Aucun justificatif joint' : implode(', ', $names)]]],
        ];
    }

    /**
     * @return list<string>
     */
    private function consumeFlash(string $key = 'request_error'): array
    {
        return array_values(array_filter($this->container->get('request_stack')->getSession()->getFlashBag()->get($key), '\is_string'));
    }

    /** Taille lisible : Ko sous 1 Mo (1 Ko au minimum), Mo avec une décimale au-delà. */
    private function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / (1024 * 1024), 1, ',', ' ').' Mo'
            : max(1, (int) round($bytes / 1024)).' Ko';
    }

    private function index(PublicRequestStep $step, bool $agent): int
    {
        return (int) array_search($step, PublicRequestStep::sequence($agent), true);
    }

    /**
     * Validation d'une étape. « Durée » (délivrance directe) exige l'événement et la commune de l'agent : la durée est lue, sa fenêtre
     * calculée et CHAQUE zone choisie vérifiée par la prévisualisation du moteur de décision (lecture seule) ; les autres étapes passent
     * par le validateur commun.
     *
     * @param array<string, string|list<string>> $values
     * @param list<string>                       $proposable
     *
     * @return array<string, string>
     */
    private function validateStep(RequestFormContext $c, PublicRequestStep $step, array $values, array $proposable): array
    {
        if (PublicRequestStep::Duration !== $step) {
            return $this->s->validator->validate($step, $values, $proposable, $c->agent);
        }
        try {
            $preview = $this->preview($c, $values);
        } catch (AmbiguousLocalTime $e) {
            return ['duration' => $e->getMessage(), 'occurrence' => 'Choisissez la première ou la seconde occurrence de cette heure.'];
        } catch (AgentRequestDenied|OperationNotAllowed) {
            return ['form' => 'La délivrance n\'est plus possible : vos droits, l\'événement ou la participation de votre commune ont changé.'];
        } catch (DomainRuleViolation $e) {
            return ['duration' => $e->getMessage()];
        }
        if (!$preview->isApprovable()) {
            return ['duration' => 'Délivrance impossible avec cette durée : '.implode(' ', array_map(static fn ($p): string => sprintf('« %s » : %s', $p->label, $p->explanation()), $preview->problems))];
        }

        return [];
    }

    /**
     * @param array<string, string|list<string>> $values
     *
     * @throws OperationNotAllowed
     * @throws AgentRequestDenied
     * @throws DomainRuleViolation
     */
    private function preview(RequestFormContext $c, array $values): ApprovalPreview
    {
        \assert(null !== $c->user && null !== $c->municipalityId);

        return $this->s->issuance->preview($c->user, $c->municipalityId, $c->event->id()->toRfc4122(), \is_array($values['zones']) ? $values['zones'] : [], $this->durationOf($values));
    }

    /**
     * Durée du brouillon. Le mode est obligatoire ; la fin n'est lue que pour la durée personnalisée.
     *
     * @param array<string, string|list<string>> $values
     *
     * @throws DomainRuleViolation
     */
    private function durationOf(array $values): ApprovalDuration
    {
        $text = static fn (string $key): string => \is_string($values[$key] ?? null) ? $values[$key] : '';
        if ('' === $text('duration_mode')) {
            throw new DomainRuleViolation('Choisissez la durée de l\'autorisation.');
        }

        return ApprovalDuration::fromInput($text('duration_mode'), $text('duration_date'), $text('duration_time'), Occurrence::tryFrom($text('duration_occurrence')));
    }

    /**
     * Récapitulatif de la délivrance directe : fenêtre prévisionnelle et zones incompatibles (lecture seule), ou raison pour laquelle
     * elle n'est pas calculable. Sans zone bloquante et sans erreur, seulement, la confirmation est proposée.
     *
     * @param array<string, string|list<string>> $values
     *
     * @return array{preview: ?ApprovalPreview, error: string, edit_url: string}
     */
    private function issuance(RequestFormContext $c, array $values): array
    {
        $preview = null;
        $error = '';
        try {
            $preview = $this->preview($c, $values);
        } catch (AmbiguousLocalTime $e) {
            $error = $e->getMessage();
        } catch (AgentRequestDenied|OperationNotAllowed) {
            $error = 'La délivrance n\'est plus possible : vos droits, l\'événement ou la participation de votre commune ont changé.';
        } catch (DomainRuleViolation $e) {
            $error = $e->getMessage();
        }

        return ['preview' => $preview, 'error' => $error, 'edit_url' => ($c->stepUrl)(PublicRequestStep::Duration)];
    }

    /**
     * @param list<ProposableCommune> $communes
     *
     * @return list<string>
     */
    private function zoneIds(array $communes): array
    {
        $ids = [];
        foreach ($communes as $commune) {
            foreach ($commune->zones as $zone) {
                $ids[] = $zone->id()->toRfc4122();
            }
        }

        return $ids;
    }
}
