<?php

declare(strict_types=1);

namespace App\Control\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adresse de la PWA de contrôle remise à l'administrateur (QR d'activation) : `CONTROL_PWA_BASE_URL` (cible : sous-domaine dédié, DT-056), à défaut le domaine
 * public configuré (`DEFAULT_URI`, développement local). Jamais l'en-tête Host de la requête. Le code d'activation est placé dans le FRAGMENT : un fragment n'est
 * jamais transmis au serveur ni écrit dans ses journaux d'accès.
 */
final readonly class ControlUrlFactory
{
    public function __construct(
        #[Autowire('%env(CONTROL_PWA_BASE_URL)%')] private string $pwaBase,
        #[Autowire('%env(DEFAULT_URI)%')] private string $defaultUri,
    ) {
    }

    public function app(): string
    {
        $base = '' !== trim($this->pwaBase) ? rtrim($this->pwaBase, '/') : rtrim($this->defaultUri, '/');

        return $base.'/controle/';
    }

    public function activation(string $code): string
    {
        return $this->app().'#a='.$code;
    }
}
