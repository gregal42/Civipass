<?php

declare(strict_types=1);

namespace App\Identity\UI;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Identity\Infrastructure\Security\SessionLifetimeListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, RequestStack $requests): Response
    {
        // Point d'arrivée après connexion : chaque profil est dirigé vers son espace.
        // Seule une authentification COMPLÈTE redirige : un utilisateur seulement « mémorisé » (cookie remember-me)
        // doit pouvoir saisir son mot de passe, sinon /login ↔ /admin boucleraient.
        if (null !== $this->getUser() && $this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute($this->isGranted('ROLE_SUPER_ADMIN') ? 'sonata_admin_dashboard' : 'app_home');
        }

        $session = $requests->getSession();
        $response = $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'expired' => $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(SessionLifetimeListener::FLASH) : [],
        ]);
        // Page contenant un jeton CSRF et un éventuel message d'erreur : jamais mise en cache.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Déconnexion : POST uniquement, avec jeton CSRF « logout ». Interceptée par le firewall avant ce code.
     */
    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode est interceptée par la clé « logout » du firewall.');
    }
}
