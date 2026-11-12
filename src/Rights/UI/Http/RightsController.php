<?php

declare(strict_types=1);

namespace App\Rights\UI\Http;

use App\Identity\Domain\User;
use App\Rights\Application\RectificationService;
use App\Rights\Application\RightsErasureService;
use App\Rights\Application\RightsExportService;
use App\Rights\Application\RightsRequestReader;
use App\Rights\Application\RightsRequestService;
use App\Rights\Application\RightsSettings;
use App\Rights\Domain\RectificationField;
use App\Rights\Domain\RightsDecision;
use App\Rights\Domain\RightsExtensionReason;
use App\Rights\Domain\RightsIdentityMethod;
use App\Rights\Domain\RightsRefusalReason;
use App\Rights\Domain\RightsRequestKind;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Droits des personnes (LOT 05.6.6) : préfixe `/communes/{m}/droits/…` RÉSERVÉ (hors barrière de clôture), `MUNICIPALITY_ADMIN` de la commune seulement ; le droit est relu AVANT
 * le jeton CSRF et tout refus est le 404 uniforme (jamais « droits », « clos » ni l'existence d'un dossier ou d'une demande). `no-store` partout. CWS n'y a aucun accès.
 */
final class RightsController extends AbstractController
{
    private const BASE = '/communes/{municipalityId}/droits';
    private const IDS = ['municipalityId' => Requirement::UUID];

    public function __construct(
        private readonly RightsRequestService $requests,
        private readonly RightsRequestReader $reader,
        private readonly RectificationService $rectifications,
        private readonly RightsExportService $exports,
        private readonly RightsErasureService $erasures,
        private readonly RightsSettings $settings,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route(self::BASE, name: 'app_rights_index', requirements: self::IDS, methods: ['GET'])]
    public function index(string $municipalityId): Response
    {
        $user = $this->user();
        $rows = $this->reader->list($user, $municipalityId);

        return $this->page('rights/index.html.twig', $user, $municipalityId, ['requests' => $rows, 'overdue' => \count(array_filter($rows, static fn (array $r): bool => $r['overdue']))]);
    }

    #[Route(self::BASE.'/nouvelle', name: 'app_rights_new', requirements: self::IDS, methods: ['GET', 'POST'])]
    public function new(string $municipalityId, Request $request): Response
    {
        $user = $this->user();
        $this->reader->list($user, $municipalityId); // droit AVANT tout
        $problem = null;
        $values = ['kind' => '', 'references' => '', 'received' => ''];
        if ($request->isMethod('POST')) {
            $post = $request->request->all();
            $values = ['kind' => $this->text($post['kind'] ?? ''), 'references' => $this->text($post['references'] ?? ''), 'received' => $this->text($post['received'] ?? '')];
            if (!$this->isCsrfTokenValid('rights_new', $this->text($post['_token'] ?? ''))) {
                $problem = 'Le formulaire a expiré. Rien n’a été enregistré : validez à nouveau.';
            } else {
                try {
                    $received = '' === $values['received'] ? null : new \DateTimeImmutable($values['received'], new \DateTimeZone('UTC'));
                    $created = $this->requests->register($user, $municipalityId, $values['kind'], preg_split('/[\s,;]+/', $values['references']) ?: [], $received);
                    $this->addFlash('success', 'Demande enregistrée : '.$created->publicReference().'.');

                    return $this->redirectToRoute('app_rights_show', ['municipalityId' => $municipalityId, 'requestId' => $created->id()->toRfc4122()], Response::HTTP_SEE_OTHER);
                } catch (DomainRuleViolation $e) {
                    $problem = $e->getMessage();
                } catch (\Exception) {
                    $problem = 'La date de réception n’est pas valide.';
                }
            }
        }

        return $this->page('rights/new.html.twig', $user, $municipalityId, ['kinds' => RightsRequestKind::cases(), 'values' => $values, 'problem' => $problem], null === $problem ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route(self::BASE.'/{requestId}', name: 'app_rights_show', requirements: self::IDS + ['requestId' => Requirement::UUID], methods: ['GET'])]
    public function show(string $municipalityId, string $requestId): Response
    {
        $user = $this->user();
        $detail = $this->reader->detail($user, $municipalityId, $requestId);

        return $this->page('rights/show.html.twig', $user, $municipalityId, [
            'r' => $detail,
            'methods' => RightsIdentityMethod::cases(),
            'decisions' => RightsDecision::cases(),
            'refusals' => RightsRefusalReason::cases(),
            'extensions' => RightsExtensionReason::cases(),
            'fields' => RectificationField::cases(),
            'maxDownloads' => $this->settings->exportMaxDownloads(),
        ]);
    }

    #[Route(self::BASE.'/{requestId}/identite', name: 'app_rights_attest', requirements: self::IDS + ['requestId' => Requirement::UUID], methods: ['POST'])]
    public function attest(string $municipalityId, string $requestId, Request $request): Response
    {
        return $this->act($municipalityId, $requestId, $request, 'rights_attest_', 'L’identité du demandeur est attestée.', fn (User $u, array $p) => $this->requests->attestIdentity($u, $municipalityId, $requestId, $this->text($p['method'] ?? '')));
    }

    #[Route(self::BASE.'/{requestId}/proroger', name: 'app_rights_extend', requirements: self::IDS + ['requestId' => Requirement::UUID], methods: ['POST'])]
    public function extend(string $municipalityId, string $requestId, Request $request): Response
    {
        return $this->act($municipalityId, $requestId, $request, 'rights_extend_', 'La demande est prorogée.', fn (User $u, array $p) => $this->requests->extend($u, $municipalityId, $requestId, $this->text($p['reason'] ?? '')));
    }

    #[Route(self::BASE.'/{requestId}/decision', name: 'app_rights_decide', requirements: self::IDS + ['requestId' => Requirement::UUID], methods: ['POST'])]
    public function decide(string $municipalityId, string $requestId, Request $request): Response
    {
        return $this->act($municipalityId, $requestId, $request, 'rights_decide_', 'La décision est enregistrée.', fn (User $u, array $p) => $this->requests->decide($u, $municipalityId, $requestId, $this->text($p['decision'] ?? ''), '' === $this->text($p['refusal'] ?? '') ? null : $this->text($p['refusal']), '1' === $this->text($p['conservation'] ?? '')));
    }

    #[Route(self::BASE.'/{requestId}/cloture', name: 'app_rights_close', requirements: self::IDS + ['requestId' => Requirement::UUID], methods: ['POST'])]
    public function close(string $municipalityId, string $requestId, Request $request): Response
    {
        return $this->act($municipalityId, $requestId, $request, 'rights_close_', 'La demande est clôturée.', fn (User $u, array $p) => $this->requests->close($u, $municipalityId, $requestId));
    }

    #[Route(self::BASE.'/{requestId}/mention', name: 'app_rights_mention', requirements: self::IDS + ['requestId' => Requirement::UUID], methods: ['POST'])]
    public function mention(string $municipalityId, string $requestId, Request $request): Response
    {
        return $this->act($municipalityId, $requestId, $request, 'rights_mention_', 'La mention est ajoutée au dossier.', function (User $u, array $p) use ($municipalityId, $requestId): void {
            $fields = \is_array($p['fields'] ?? null) ? array_map($this->text(...), $p['fields']) : [];
            $this->rectifications->addMention($u, $municipalityId, $requestId, $this->text($p['dossier'] ?? ''), $fields, $this->text($p['mention'] ?? ''));
        });
    }

    #[Route(self::BASE.'/{requestId}/effacement', name: 'app_rights_erase', requirements: self::IDS + ['requestId' => Requirement::UUID], methods: ['POST'])]
    public function erase(string $municipalityId, string $requestId, Request $request): Response
    {
        return $this->act($municipalityId, $requestId, $request, 'rights_erase_', 'L’effacement est exécuté.', fn (User $u, array $p) => $this->erasures->execute($u, $municipalityId, $requestId));
    }

    /** Téléchargement par POST (il consomme l'un des trois téléchargements) : droit, puis jeton CSRF, puis génération à la volée dans un fichier temporaire supprimé après l'envoi. */
    #[Route(self::BASE.'/{requestId}/export', name: 'app_rights_export', requirements: self::IDS + ['requestId' => Requirement::UUID], methods: ['POST'])]
    public function export(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $this->reader->detail($user, $municipalityId, $requestId); // droit AVANT le jeton CSRF
        $back = $this->redirectToRoute('app_rights_show', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
        if (!$this->isCsrfTokenValid('rights_export_'.$requestId, $this->text($request->request->all()['_token'] ?? ''))) {
            $this->addFlash('error', 'Le formulaire a expiré. Aucun téléchargement n’a été décompté : validez à nouveau.');

            return $back;
        }
        try {
            $file = $this->exports->download($user, $municipalityId, $requestId);
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $back;
        }
        $response = new BinaryFileResponse($file['path']);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file['filename']);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function act(string $municipalityId, string $requestId, Request $request, string $tokenPrefix, string $success, callable $operation): Response
    {
        $user = $this->user();
        $this->reader->detail($user, $municipalityId, $requestId); // droit AVANT le jeton CSRF : un utilisateur non habilité reçoit toujours le 404 uniforme
        $back = $this->redirectToRoute('app_rights_show', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
        $post = $request->request->all();
        if (!$this->isCsrfTokenValid($tokenPrefix.$requestId, $this->text($post['_token'] ?? ''))) {
            $this->addFlash('error', 'Le formulaire a expiré. Rien n’a été enregistré : validez à nouveau.');

            return $back;
        }
        try {
            $operation($user, $post);
            $this->addFlash('success', $success);
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (OperationConflict) {
            $this->addFlash('error', 'Un autre traitement est en cours : rien n’a été enregistré. Vous pouvez réessayer.');
        }

        return $back;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(string $template, User $user, string $municipalityId, array $context, int $status = Response::HTTP_OK): Response
    {
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'rights', 'Droits des personnes');
        $response = $this->render($template, array_merge($shell, ['page_title' => 'Droits des personnes', 'municipalityId' => $municipalityId], $context), new Response('', $status));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
