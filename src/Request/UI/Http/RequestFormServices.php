<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Request\Application\ProposableZones;
use App\Request\Application\ReceiptNotifier;
use App\Request\Application\RequestSubmissionService;
use App\Request\Application\TemporaryUploadService;
use App\Request\Domain\RequestReferentialRepository;
use App\Shared\Application\FileStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Collaborateurs communs aux deux parcours de dépôt (public et agent) : un seul moteur de formulaire, deux contextes.
 */
final readonly class RequestFormServices
{
    public function __construct(
        public ProposableZones $proposable,
        public RequestReferentialRepository $referentials,
        public PublicRequestStepValidator $validator,
        public TemporaryUploadService $uploads,
        public ClockInterface $clock,
        public RequestSubmissionService $submission,
        public ReceiptNotifier $receipts,
        public FileStorageInterface $files,
        public LoggerInterface $logger,
        #[Autowire(env: 'PRIVACY_POLICY_URL')]
        public string $privacyPolicyUrl,
        #[Autowire(service: 'limiter.public_request_upload_ip')]
        public RateLimiterFactory $uploadLimiter,
    ) {
    }
}
