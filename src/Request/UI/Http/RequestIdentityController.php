<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Identity\Domain\User;
use App\Request\Application\Command\CorrectIdentityCommand;
use App\Request\Application\IdentityCorrectionDenied;
use App\Request\Application\Read\IdentityCorrectionPage;
use App\Request\Application\RequestIdentityCorrectionService;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shell\CommuneSelectionController;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Correction de l'identité du bénéficiaire (LOT 04.4, DM-111). Ni règle métier ni droit dans le contrôleur : il valide la FORME de la saisie avec
 * le validateur du formulaire de dépôt (mêmes règles, mêmes messages) et délègue à RequestIdentityCorrectionService, qui revérifie tout sous
 * verrou. Commune et demande sont des identifiants non fiables ; la saisie ne transite jamais par l'URL ; l'écriture est un POST protégé par un
 * jeton CSRF propre à la demande ; la page n'est jamais mise en cache.
 *
 * Réponses : 403 rôle INSTRUCTOR/MUNICIPALITY_ADMIN absent ; 404 uniforme hors périmètre ; 422 saisie refusée (saisie conservée) ; 409 identité
 * corrigée entre-temps (saisie conservée, valeurs enregistrées affichées) ; 303 après succès ou absence de changement.
 */
final class RequestIdentityController extends AbstractController
{
    /** Bornes de LECTURE, volontairement au-dessus des longueurs métier : un dépassement doit être signalé par la validation, jamais tronqué en silence. */
    private const FIELDS = ['last' => 300, 'first' => 300, 'address' => 700, 'email' => 400, 'phone' => 60];
    private const LABELS = ['lastName' => 'nom', 'firstName' => 'prénom', 'address' => 'adresse', 'email' => 'adresse e-mail', 'phone' => 'téléphone'];

    public function __construct(
        private readonly RequestIdentityCorrectionService $identity,
        private readonly PublicRequestStepValidator $validator,
        private readonly ShellContextFactory $shell,
    ) {
    }

    /**
     * Point d'entrée « Corrections d'identité » (LOT 04.5) : recherche par référence dans la commune, rien d'autre. Accessible aux INSTRUCTOR et
     * MUNICIPALITY_ADMIN ; n'ouvre ni la liste, ni la fiche, ni l'instruction (le lien mène à la seule route de correction). La référence n'est pas
     * une donnée personnelle : elle peut figurer dans l'URL (GET, sans effet).
     */
    #[Route('/communes/{municipalityId}/corrections-identite', name: 'app_municipal_identity_corrections', requirements: ['municipalityId' => Requirement::UUID], methods: ['GET'])]
    public function search(string $municipalityId, Request $request): Response
    {
        $user = $this->user();
        $raw = $request->query->get('reference');
        $reference = \is_string($raw) ? mb_substr(trim($raw), 0, 40) : '';
        try {
            // Mêmes droits exigés pour afficher la page que pour chercher (une référence vide ne trouve rien).
            $found = $this->identity->find($user, $municipalityId, '' === $reference ? '-' : $reference);
        } catch (IdentityCorrectionDenied) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }
        $response = $this->render('municipal/request/identity_search.html.twig', $this->shell->forMunicipality($user, $municipalityId, 'identity', 'Corrections d’identité') + [
            'reference' => $reference,
            'searched' => '' !== $reference,
            'found' => $found,
            'result_url' => null === $found ? null : $this->generateUrl('app_municipal_request_identity', ['municipalityId' => $municipalityId, 'requestId' => $found->requestId]),
            'search_url' => $this->generateUrl('app_municipal_identity_corrections', ['municipalityId' => $municipalityId]),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/identite', name: 'app_municipal_request_identity', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function correct(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $page = $this->page($user, $municipalityId, $requestId);
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }
        if (!$request->isMethod('POST')) {
            return $this->view($user, $municipalityId, $requestId, $page, $this->current($page), false, [], Response::HTTP_OK);
        }

        $values = $this->values($request->request->all(), $page->version);
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid($this->csrfId($requestId), \is_string($token) ? $token : '')) {
            return $this->view($user, $municipalityId, $requestId, $page, $values, false, ['form' => 'Le formulaire a expiré : rien n’a été enregistré. Vérifiez la saisie puis recommencez.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $errors = $this->validator->validate(PublicRequestStep::Identity, $values, []);
        if ('1' !== $values['same']) {
            $errors['same'] = 'Confirmez qu’il s’agit bien de la même personne : une correction ne permet pas de changer de bénéficiaire.';
        }
        if ([] !== $errors) {
            return $this->view($user, $municipalityId, $requestId, $page, $values, false, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->identity->correct($user, $municipalityId, $requestId, new CorrectIdentityCommand((int) $values['version'], $values['last'], $values['first'], $values['address'], $values['email'], $values['phone'], true));
        } catch (IdentityCorrectionDenied) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        } catch (OperationConflict $conflict) {
            if (ConflictCause::StaleState === $conflict->cause) {
                // Corrigée entre-temps : la saisie est conservée, les valeurs ENREGISTRÉES sont montrées, la version est celle d'aujourd'hui (renvoyer est un choix délibéré).
                $fresh = $this->page($user, $municipalityId, $requestId);

                return $this->view($user, $municipalityId, $requestId, $fresh, ['version' => (string) $fresh->version, 'same' => ''] + $values, true, ['form' => 'Les informations de ce bénéficiaire ont été corrigées par ailleurs pendant votre saisie : rien n’a été enregistré. Comparez avec les valeurs enregistrées ci-dessous, puis confirmez de nouveau si votre correction reste nécessaire.'], Response::HTTP_CONFLICT);
            }

            return $this->view($user, $municipalityId, $requestId, $page, $values, false, ['form' => 'Un autre traitement est en cours sur cette demande : rien n’a été enregistré. Réessayez dans un instant.'], Response::HTTP_CONFLICT);
        } catch (DomainRuleViolation $violation) {
            return $this->view($user, $municipalityId, $requestId, $page, $values, false, ['form' => $violation->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$result->changed()) {
            $this->addFlash('info', 'Aucune modification : les informations saisies sont identiques à celles enregistrées.');
        } else {
            $this->addFlash('success', sprintf('Informations corrigées (%s). La décision, les droits et la durée de la demande %s sont inchangés.%s', implode(', ', array_map(static fn (string $f): string => self::LABELS[$f], $result->changedFields)), $result->reference, $result->otherMunicipalities > 0 ? sprintf(' %d autre%s commune%s concernée%s voi%s la correction.', $result->otherMunicipalities, $result->otherMunicipalities > 1 ? 's' : '', $result->otherMunicipalities > 1 ? 's' : '', $result->otherMunicipalities > 1 ? 's' : '', $result->otherMunicipalities > 1 ? 'ent' : 't') : ''));
        }

        return $this->redirectToRoute('app_municipal_request_identity', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function view(User $user, string $municipalityId, string $requestId, IdentityCorrectionPage $page, array $values, bool $showStored, array $errors, int $status): Response
    {
        $route = ['municipalityId' => $municipalityId];
        $response = new Response('', $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $this->render('municipal/request/identity.html.twig', $this->shell->forMunicipality($user, $municipalityId, 'identity', 'Corriger les informations du bénéficiaire') + [
            'page' => $page,
            'form' => $values,
            'errors' => $errors,
            'summary' => array_map(static fn (string $target, string $message): array => ['target' => 'f-'.('form' === $target ? 'last' : ('contact' === $target ? 'email' : $target)), 'message' => $message], array_keys($errors), $errors),
            'stored' => $showStored ? $this->current($page) : null,
            'action_url' => $this->generateUrl('app_municipal_request_identity', $route + ['requestId' => $requestId]),
            'csrf_id' => $this->csrfId($requestId),
            'requests_url' => $this->generateUrl('app_municipal_requests', $route),
            'request_url' => $page->consultable ? $this->generateUrl('app_municipal_request', $route + ['requestId' => $requestId]) : null,
        ], $response);
    }

    private function page(User $user, string $municipalityId, string $requestId): IdentityCorrectionPage
    {
        try {
            return $this->identity->page($user, $municipalityId, $requestId);
        } catch (IdentityCorrectionDenied) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function current(IdentityCorrectionPage $page): array
    {
        return ['last' => $page->lastName, 'first' => $page->firstName, 'address' => $page->address, 'email' => $page->email, 'phone' => $page->phone, 'version' => (string) $page->version, 'same' => ''];
    }

    /**
     * Saisie réduite à des chaînes de longueur bornée ; tout champ hors périmètre (catégorie, zones, statut…) est ignoré.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, string>
     */
    private function values(array $raw, int $fallbackVersion): array
    {
        $values = ['version' => (string) $fallbackVersion, 'same' => '1' === ($raw['same'] ?? null) ? '1' : ''];
        foreach (self::FIELDS as $field => $max) {
            $values[$field] = \is_string($raw[$field] ?? null) ? mb_substr(trim($raw[$field]), 0, $max) : '';
        }
        $values['version'] = \is_string($raw['version'] ?? null) && ctype_digit($raw['version']) && \strlen($raw['version']) < 9 ? $raw['version'] : '-1';

        return $values;
    }

    private function csrfId(string $requestId): string
    {
        return 'municipal_request_identity_'.$requestId;
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
