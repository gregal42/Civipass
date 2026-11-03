<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        // Utilisateur communal : l'accueil est l'espace municipal. Le super-administrateur CWS (aucun droit communal implicite)
        // garde la page d'accueil, d'où il rejoint le back-office.
        if (null !== $this->getUser() && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('app_municipal_select');
        }

        return $this->render('home/index.html.twig');
    }
}
