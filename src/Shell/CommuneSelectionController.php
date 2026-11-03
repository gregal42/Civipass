<?php

declare(strict_types=1);

namespace App\Shell;

use App\Identity\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d'entrée de l'espace municipal : retrouve ou choisit la commune active. L'URL fait foi pour la suite (la
 * session ne mémorise que la dernière commune consultée, par confort) ; seules les communes pour lesquelles
 * l'utilisateur a un droit de lecture effectif sont proposées. Un compte sans commune (dont le super-administrateur
 * CWS, qui n'a aucun droit communal implicite) voit un état vide, jamais une commune.
 */
final class CommuneSelectionController extends AbstractController
{
    public const SESSION_KEY = 'civipass.last_municipality';

    public function __construct(private readonly ShellContextFactory $shell)
    {
    }

    #[Route('/communes', name: 'app_municipal_select', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $memberships = $this->shell->readableMemberships($user);

        $remembered = $request->hasSession() ? $request->getSession()->get(self::SESSION_KEY) : null;
        $target = null;
        foreach ($memberships as $membership) {
            $id = $membership->municipality()->id()->toRfc4122();
            if (1 === \count($memberships) || $id === $remembered) {
                $target = $id;
                break;
            }
        }
        if (null !== $target && !$request->query->has('changer')) {
            return $this->redirectToRoute('app_municipal_events', ['municipalityId' => $target]);
        }

        return $this->render('municipal/select.html.twig', [
            'communes' => array_map(fn ($m): array => [
                'id' => $m->municipality()->id()->toRfc4122(),
                'name' => $m->municipality()->name(),
                'role' => $this->shell->roleLabel($m),
            ], $memberships),
        ]);
    }
}
