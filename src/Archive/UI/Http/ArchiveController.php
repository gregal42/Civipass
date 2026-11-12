<?php

declare(strict_types=1);

namespace App\Archive\UI\Http;

use App\Archive\Application\ArchiveAccessPolicy;
use App\Archive\Application\ArchiveConsultationExpired;
use App\Archive\Application\ArchiveConsultationService;
use App\Archive\Application\ArchiveDesignationService;
use App\Archive\Application\ArchiveDossierReader;
use App\Archive\Application\LegalHoldService;
use App\Archive\Domain\ConsultationReason;
use App\Archive\Domain\LegalHoldReason;
use App\Identity\Domain\User;
use App\Shared\Application\FileStorageInterface;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Espace d'archives (LOT 05.6.3) : LECTURE SEULE, distinct des écrans opérationnels (préfixe `/communes/{m}/archives/…`, réservé et hors barrière de clôture). CHAQUE
 * route passe par `ArchiveAccessPolicy` via un service applicatif ; tout refus est un 404 uniforme (jamais « clos », « archive » ni « échue »). Les seules écritures sont
 * des actes de GESTION de l'administrateur (désignation, gel) et le journal de consultation ; aucune n'agit sur le dossier lui-même. `no-store` partout.
 */
final class ArchiveController extends AbstractController
{
    private const BASE = '/communes/{municipalityId}/archives';
    private const IDS = ['municipalityId' => Requirement::UUID];

    public function __construct(
        private readonly ArchiveDossierReader $reader,
        private readonly ArchiveConsultationService $consultations,
        private readonly ArchiveDesignationService $designations,
        private readonly LegalHoldService $holds,
        private readonly FileStorageInterface $storage,
        private readonly ArchiveAccessPolicy $policy,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route(self::BASE, name: 'app_archive_index', requirements: self::IDS, methods: ['GET'])]
    public function index(string $municipalityId): Response
    {
        $user = $this->user();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'archives', 'Archives');

        return $this->page('archive/index.html.twig', $shell, ['events' => $this->reader->events($user, $municipalityId), 'municipalityId' => $municipalityId]);
    }

    #[Route(self::BASE.'/evenements/{eventId}', name: 'app_archive_event', requirements: self::IDS + ['eventId' => Requirement::UUID], methods: ['GET'])]
    public function event(string $municipalityId, string $eventId): Response
    {
        $user = $this->user();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'archives', 'Archives');
        $data = $this->reader->dossiers($user, $municipalityId, $eventId);
        $management = $data['canManage'] ? $this->designations->forEvent($user, $municipalityId, $eventId) : null;

        return $this->page('archive/event.html.twig', $shell, $data + [
            'municipalityId' => $municipalityId,
            'management' => $management,
            'holdReasons' => LegalHoldReason::cases(),
        ]);
    }

    #[Route(self::BASE.'/dossiers/{dossierId}/ouvrir', name: 'app_archive_open', requirements: self::IDS + ['dossierId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function open(string $municipalityId, string $dossierId, Request $request): Response
    {
        $user = $this->user();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'archives', 'Archives');
        $this->consultations->preflight($user, $municipalityId, $dossierId); // droit AVANT tout (formulaire, jeton CSRF) : refus uniforme 404
        if (!$request->isMethod('POST')) {
            return $this->page('archive/open.html.twig', $shell, ['municipalityId' => $municipalityId, 'dossierId' => $dossierId, 'reasons' => ConsultationReason::cases(), 'problem' => null, 'values' => ['reason' => '', 'comment' => '']]);
        }
        $post = $request->request->all();
        $reason = \is_string($post['reason'] ?? null) ? $post['reason'] : '';
        $comment = \is_string($post['comment'] ?? null) ? $post['comment'] : '';
        $render = fn (string $problem, int $status): Response => $this->page('archive/open.html.twig', $shell, ['municipalityId' => $municipalityId, 'dossierId' => $dossierId, 'reasons' => ConsultationReason::cases(), 'problem' => $problem, 'values' => ['reason' => $reason, 'comment' => $comment]], $status);
        if (!$this->isCsrfTokenValid('archive_open_'.$dossierId, \is_string($post['_token'] ?? null) ? $post['_token'] : '')) {
            return $render('Le formulaire a expiré. Rien n’a été enregistré : validez à nouveau.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $consultation = $this->consultations->open($user, $municipalityId, $dossierId, $reason, $comment);
        } catch (DomainRuleViolation $e) {
            return $render($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('app_archive_consultation', ['municipalityId' => $municipalityId, 'consultationId' => $consultation->id()->toRfc4122()], Response::HTTP_SEE_OTHER);
    }

    #[Route(self::BASE.'/consultations/{consultationId}', name: 'app_archive_consultation', requirements: self::IDS + ['consultationId' => Requirement::UUID], methods: ['GET'])]
    public function consultation(string $municipalityId, string $consultationId): Response
    {
        $user = $this->user();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'archives', 'Archives');
        try {
            $view = $this->consultations->view($user, $municipalityId, $consultationId);
        } catch (ArchiveConsultationExpired $expired) {
            $this->addFlash('error', 'Votre consultation a expiré : indiquez à nouveau le motif pour rouvrir le dossier.');

            return $this->redirectToRoute('app_archive_open', ['municipalityId' => $municipalityId, 'dossierId' => $expired->dossierId], Response::HTTP_SEE_OTHER);
        }

        return $this->page('archive/dossier.html.twig', $shell, ['view' => $view, 'municipalityId' => $municipalityId, 'dossierIdForHold' => $view->dossierId, 'holdReasons' => LegalHoldReason::cases()]);
    }

    #[Route(self::BASE.'/consultations/{consultationId}/justificatifs/{attachmentId}', name: 'app_archive_attachment', requirements: self::IDS + ['consultationId' => Requirement::UUID, 'attachmentId' => Requirement::UUID], methods: ['GET'])]
    public function attachment(string $municipalityId, string $consultationId, string $attachmentId): Response
    {
        $user = $this->user();
        try {
            $file = $this->consultations->download($user, $municipalityId, $consultationId, $attachmentId);
        } catch (ArchiveConsultationExpired $expired) {
            return $this->redirectToRoute('app_archive_open', ['municipalityId' => $municipalityId, 'dossierId' => $expired->dossierId], Response::HTTP_SEE_OTHER);
        }
        if (!$this->storage->exists($file['storageKey'])) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $stream = $this->storage->readStream($file['storageKey']);
        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        });
        $ascii = preg_replace('/[^\x20-\x7E]+/', '_', $file['name']) ?? '';
        $ascii = str_replace('%', '_', $ascii);
        $response->headers->set('Content-Type', $file['mime']);
        $response->headers->set('Content-Length', (string) $file['size']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file['name'], '' === trim($ascii, ' _.') ? 'justificatif' : $ascii));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route(self::BASE.'/evenements/{eventId}/designations', name: 'app_archive_designate', requirements: self::IDS + ['eventId' => Requirement::UUID], methods: ['POST'])]
    public function designate(string $municipalityId, string $eventId, Request $request): Response
    {
        return $this->act($municipalityId, $request, 'archive_designate_'.$eventId, $this->redirectEvent($municipalityId, $eventId), 'La personne est désignée pour cet événement.', fn (User $user, array $post) => $this->designations->designate($user, $municipalityId, $eventId, $this->text($post['user'] ?? '')));
    }

    #[Route(self::BASE.'/designations/{designationId}/revoquer', name: 'app_archive_designation_revoke', requirements: self::IDS + ['designationId' => Requirement::UUID], methods: ['POST'])]
    public function revokeDesignation(string $municipalityId, string $designationId, Request $request): Response
    {
        $eventId = $this->text($request->request->all()['event'] ?? '');

        return $this->act($municipalityId, $request, 'archive_revoke_'.$designationId, Uuid::isValid($eventId) ? $this->redirectEvent($municipalityId, $eventId) : $this->redirectToRoute('app_archive_index', ['municipalityId' => $municipalityId], Response::HTTP_SEE_OTHER), 'La désignation est révoquée.', fn (User $user, array $post) => $this->designations->revoke($user, $municipalityId, $designationId));
    }

    #[Route(self::BASE.'/dossiers/{dossierId}/gel/{action}', name: 'app_archive_hold', requirements: self::IDS + ['dossierId' => Requirement::UUID, 'action' => 'placer|revue|lever'], methods: ['POST'])]
    public function hold(string $municipalityId, string $dossierId, string $action, Request $request): Response
    {
        $back = $this->text($request->request->all()['back'] ?? '');
        $target = Uuid::isValid($back) ? $this->redirectToRoute('app_archive_consultation', ['municipalityId' => $municipalityId, 'consultationId' => $back], Response::HTTP_SEE_OTHER) : $this->redirectToRoute('app_archive_index', ['municipalityId' => $municipalityId], Response::HTTP_SEE_OTHER);
        $success = ['placer' => 'Le dossier est gelé.', 'revue' => 'Le maintien du gel est confirmé.', 'lever' => 'Le gel est levé.'][$action];

        return $this->act($municipalityId, $request, 'archive_hold_'.$action.'_'.$dossierId, $target, $success, fn (User $user, array $post) => match ($action) {
            'placer' => $this->holds->place($user, $municipalityId, $dossierId, $this->text($post['reason'] ?? ''), $this->text($post['comment'] ?? '')),
            'revue' => $this->holds->review($user, $municipalityId, $dossierId),
            default => $this->holds->lift($user, $municipalityId, $dossierId, $this->text($post['comment'] ?? '')),
        });
    }

    #[Route(self::BASE.'/journal', name: 'app_archive_journal', requirements: self::IDS, methods: ['GET'])]
    public function journal(string $municipalityId): Response
    {
        $user = $this->user();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'archives', 'Archives');

        return $this->page('archive/journal.html.twig', $shell, ['entries' => $this->reader->journal($user, $municipalityId), 'municipalityId' => $municipalityId]);
    }

    /**
     * Acte de gestion : CSRF, puis service applicatif (qui refait TOUS les contrôles) ; refus de droit = 404 uniforme ; règle métier = message ; retour 303.
     *
     * @param callable(User, array<string, mixed>): mixed $operation
     */
    private function act(string $municipalityId, Request $request, string $tokenId, Response $back, string $success, callable $operation): Response
    {
        $user = $this->user();
        $this->policy->administrator($user, $municipalityId, write: false); // droit AVANT le jeton CSRF : un utilisateur non habilité reçoit toujours le 404 uniforme
        $post = $request->request->all();
        if (!$this->isCsrfTokenValid($tokenId, $this->text($post['_token'] ?? ''))) {
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

    private function redirectEvent(string $municipalityId, string $eventId): Response
    {
        return $this->redirectToRoute('app_archive_event', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, mixed> $shell
     * @param array<string, mixed> $context
     */
    private function page(string $template, array $shell, array $context, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($template, array_merge($shell, ['page_title' => 'Archives'], $context), new Response('', $status));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
