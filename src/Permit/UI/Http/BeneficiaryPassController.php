<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use App\Permit\Application\BeneficiaryPassReader;
use App\Permit\Application\PassPdfRenderer;
use App\Permit\Application\QrCodeRenderer;
use App\Permit\Application\Read\BeneficiaryPass;
use App\Permit\Application\VerificationUrlFactory;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page et PDF du bénéficiaire (LOT 05.2, DM-114, DT-048) : accès par le SEUL secret du lien, sans compte ni session.
 *
 * Lien mal formé, inconnu, révoqué ou expiré : la même page, le même code 404, sans exception ni journal (le secret n'apparaît donc dans aucun
 * message). Protections : plafond de requêtes par IP, plafond d'échecs par IP (429 avant toute recherche), aucun cookie ni session, en-têtes
 * `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag`, politique de sécurité de contenu stricte (aucune ressource tierce,
 * aucun script), `X-Content-Type-Options`. Le jeton du QR (`/v/…`) ne donne JAMAIS accès à ces routes : ils sont indépendants.
 */
final class BeneficiaryPassController extends AbstractController
{
    private const CSP = "default-src 'none'; style-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    public function __construct(
        private readonly BeneficiaryPassReader $passes,
        private readonly QrCodeRenderer $qr,
        private readonly PassPdfRenderer $pdf,
        private readonly VerificationUrlFactory $urls,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.pass_access')] private readonly RateLimiterFactoryInterface $access,
        #[Autowire(service: 'limiter.pass_failure')] private readonly RateLimiterFactoryInterface $failures,
    ) {
    }

    #[Route('/laissez-passer/{secret}', name: 'app_beneficiary_pass', requirements: ['secret' => '[^/]{1,200}'], methods: ['GET'])]
    public function show(#[\SensitiveParameter] string $secret, Request $request): Response
    {
        return $this->guarded($secret, $request, function (BeneficiaryPass $pass) use ($secret): Response {
            $now = $this->clock->now();

            return $this->render('permit/pass.html.twig', [
                'page_title' => 'Mon laissez-passer',
                'pass' => $pass,
                'qr_svg' => $this->qr->svg($this->urls->forToken($pass->verificationToken)),
                'pdf_url' => $this->generateUrl('app_beneficiary_pass_pdf', ['secret' => $secret]),
                'generated_at' => $now,
            ], new Response());
        });
    }

    #[Route('/laissez-passer/{secret}/pdf', name: 'app_beneficiary_pass_pdf', requirements: ['secret' => '[^/]{1,200}'], methods: ['GET'])]
    public function download(#[\SensitiveParameter] string $secret, Request $request): Response
    {
        return $this->guarded($secret, $request, function (BeneficiaryPass $pass): Response {
            $content = $this->pdf->render($pass, $this->qr->png($this->urls->forToken($pass->verificationToken)), $this->clock->now());
            $response = new Response($content, Response::HTTP_OK, ['Content-Type' => 'application/pdf']);
            $response->headers->set('Content-Disposition', sprintf('attachment; filename="laissez-passer-%s.pdf"', $pass->reference));

            return $response;
        });
    }

    /**
     * @param callable(BeneficiaryPass): Response $found
     */
    private function guarded(#[\SensitiveParameter] string $secret, Request $request, callable $found): Response
    {
        $client = $request->getClientIp() ?? 'unknown';
        if (!$this->access->create($client)->consume()->isAccepted()) {
            return $this->protect($this->tooMany(), false);
        }
        $failures = $this->failures->create($client);
        // `consume(0)` consulte sans consommer (isAccepted est toujours vrai pour 0 jeton) : le plafond est atteint quand il ne reste aucun jeton.
        if ($failures->consume(0)->getRemainingTokens() < 1) {
            return $this->protect($this->tooMany(), false);
        }
        $pass = $this->passes->find($secret, $this->clock->now());
        if (null === $pass) {
            $failures->consume();

            return $this->protect($this->render('permit/pass_unavailable.html.twig', ['page_title' => 'Lien non valide'], new Response('', Response::HTTP_NOT_FOUND)), false);
        }

        return $this->protect($found($pass), true);
    }

    private function tooMany(): Response
    {
        $response = new Response('Trop de tentatives. Réessayez dans quelques minutes.', Response::HTTP_TOO_MANY_REQUESTS, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $response->headers->set('Retry-After', '900');

        return $response;
    }

    /** En-têtes communs à TOUTE réponse de ces routes (succès comme refus) : rien n'est mis en cache, indexé, ni transmis en Referer. */
    private function protect(Response $response, bool $document): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (!str_starts_with((string) $response->headers->get('Content-Type'), 'application/pdf')) {
            $response->headers->set('Content-Security-Policy', self::CSP);
        }
        $response->headers->set('X-Frame-Options', 'DENY');
        unset($document);

        return $response;
    }
}
