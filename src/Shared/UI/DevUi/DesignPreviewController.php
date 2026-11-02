<?php

declare(strict_types=1);

namespace App\Shared\UI\DevUi;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prévisualisation du design system : composants et écrans du prototype, avec des données FICTIVES.
 *
 * ENVIRONNEMENT DEV UNIQUEMENT, trois verrous indépendants :
 *  1. #[Route(env: 'dev')] : les routes n'existent ni en test ni en prod ;
 *  2. #[When(env: 'dev')]  : le service n'est même pas enregistré hors dev ;
 *  3. assertDevelopment()  : garde d'exécution, au cas où l'un des deux serait contourné.
 * Aucune entité Doctrine, aucun service métier, aucune donnée réelle, aucune écriture.
 */
#[When(env: 'dev')]
#[Route('/dev/ui', name: 'dev_ui_', env: 'dev')]
final class DesignPreviewController extends AbstractController
{
    private const THEMES = ['light', 'dark'];
    private const WIDTHS = [375, 768, 1440];
    private const SLUG = 'le-temple|saumos';

    public function __construct(
        private readonly PrototypeData $prototype,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.project_dir%/src/Shared/UI/DevUi/Resources/catalog.json')]
        private readonly string $catalogFile,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertDevelopment();
        $groups = [];
        foreach ($this->catalog() as $component) {
            $groups[$component['group']][] = $component['name'];
        }

        return $this->render('dev/ui/index.html.twig', ['groups' => $groups, 'theme' => $this->theme($request)]);
    }

    #[Route('/components/{name}', name: 'component', requirements: ['name' => '[A-Za-z]+'], methods: ['GET'])]
    public function component(string $name, Request $request): Response
    {
        $this->assertDevelopment();
        if (!\in_array($name, array_column($this->catalog(), 'name'), true)) {
            throw new NotFoundHttpException('Composant inconnu.');
        }
        $width = (int) $request->query->get('width', 0);

        return $this->render('dev/ui/component.html.twig', [
            'name' => $name,
            'theme' => $this->theme($request),
            'width' => \in_array($width, self::WIDTHS, true) ? $width : 0,
            'widths' => self::WIDTHS,
            'qr_svg' => '<svg aria-hidden="true" focusable="false" viewBox="0 0 3 3" fill="currentColor"><rect width="1" height="1"/><rect x="2" y="2" width="1" height="1"/></svg>',
        ]);
    }

    #[Route('/{slug}/tableau-de-bord', name: 'dashboard', requirements: ['slug' => self::SLUG], methods: ['GET'])]
    public function dashboard(string $slug, Request $request): Response
    {
        $this->assertDevelopment();

        return $this->render('dev/ui/screens/dashboard.html.twig', $this->prototype->dashboard($slug, $request->query->getBoolean('bascule')));
    }

    #[Route('/{slug}/demandes', name: 'requests', requirements: ['slug' => self::SLUG], methods: ['GET'])]
    public function requests(string $slug, Request $request): Response
    {
        $this->assertDevelopment();
        /** @var array<string, string> $query */
        $query = array_map('strval', array_filter($request->query->all(), 'is_scalar'));

        return $this->render('dev/ui/screens/requests.html.twig', $this->prototype->requestList($slug, $query));
    }

    /**
     * POST : les formulaires de décision du prototype ne font rien ; retour à la fiche avec un message explicite.
     */
    #[Route('/{slug}/demandes/{ref}', name: 'request', requirements: ['slug' => self::SLUG, 'ref' => 'DEM-2026-\d{4}'], methods: ['GET', 'POST'])]
    public function request(string $slug, string $ref, Request $request): Response
    {
        $this->assertDevelopment();
        if ($request->isMethod('POST')) {
            return new RedirectResponse($this->generateUrl('dev_ui_request', ['slug' => $slug, 'ref' => $ref, 'demo' => 1]), Response::HTTP_SEE_OTHER);
        }
        $context = $this->prototype->request($slug, $ref, $request->query->getBoolean('demo'));
        if (null === $context) {
            throw new NotFoundHttpException('Demande inconnue pour cette commune.');
        }

        return $this->render('dev/ui/screens/request.html.twig', $context);
    }

    #[Route('/saumos/utilisateurs', name: 'forbidden', methods: ['GET'])]
    public function forbidden(): Response
    {
        $this->assertDevelopment();

        return $this->render('dev/ui/screens/forbidden.html.twig', $this->prototype->forbidden(), new Response('', Response::HTTP_FORBIDDEN));
    }

    #[Route('/deconnexion', name: 'logout', methods: ['POST'])]
    public function logout(): Response
    {
        $this->assertDevelopment();

        return $this->redirectToRoute('dev_ui_index');
    }

    private function assertDevelopment(): void
    {
        if ('dev' !== $this->environment) {
            throw new NotFoundHttpException();
        }
    }

    private function theme(Request $request): string
    {
        $theme = (string) $request->query->get('theme', '');

        return \in_array($theme, self::THEMES, true) ? $theme : '';
    }

    /**
     * @return list<array{name: string, group: string}>
     */
    private function catalog(): array
    {
        /** @var list<array{name: string, group: string}> $catalog */
        $catalog = json_decode((string) file_get_contents($this->catalogFile), true, 512, \JSON_THROW_ON_ERROR);

        return $catalog;
    }
}
