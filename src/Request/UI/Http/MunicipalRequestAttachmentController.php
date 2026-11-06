<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Identity\Domain\User;
use App\Request\Application\InstructorRoleRequired;
use App\Request\Application\MunicipalRequestReader;
use App\Shared\Application\FileStorageInterface;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Téléchargement d'un justificatif depuis la fiche. Le lecteur revérifie à CHAQUE appel le membership, le rôle INSTRUCTOR, la
 * portion communale de la demande et l'appartenance de la pièce à cette demande ; le fichier est lu dans le stockage privé
 * (jamais depuis public/) et servi en pièce jointe, sans interprétation par le navigateur et sans mise en cache.
 */
final class MunicipalRequestAttachmentController extends AbstractController
{
    public function __construct(
        private readonly MunicipalRequestReader $requests,
        private readonly FileStorageInterface $storage,
    ) {
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/justificatifs/{attachmentId}', name: 'app_municipal_request_attachment', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID, 'attachmentId' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $municipalityId, string $requestId, string $attachmentId): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        try {
            $file = $this->requests->attachment($user, $municipalityId, $requestId, $attachmentId);
        } catch (InstructorRoleRequired) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
        if (!$this->storage->exists($file->storageKey)) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $stream = $this->storage->readStream($file->storageKey);
        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', $file->mimeType);
        $response->headers->set('Content-Length', (string) $file->sizeBytes);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->displayName, $this->asciiFallback($file->displayName)));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function asciiFallback(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]+/', '_', $name) ?? '';
        $ascii = str_replace('%', '_', $ascii);

        return '' === trim($ascii, ' _.') ? 'justificatif' : $ascii;
    }
}
