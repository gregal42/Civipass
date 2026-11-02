<?php

declare(strict_types=1);

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Route\RouteCollectionInterface;

/**
 * Base des admins du back-office CWS : LECTURE SEULE STRICTE.
 *
 * Les écritures ne sont pas « masquées » : leurs routes n'existent pas (404), les actions groupées et exports sont
 * supprimés, et le gestionnaire de sécurité (ReadOnlySecurityHandler) refuse toute permission d'écriture. Les
 * modifications de rôles, de statuts et de privilèges passent exclusivement par les services applicatifs audités
 * (MembershipManager, MunicipalityManager, UserManager).
 *
 * @template T of object
 *
 * @extends AbstractAdmin<T>
 */
abstract class ReadOnlyAdmin extends AbstractAdmin
{
    /**
     * Segment d'URL et de nom de route, ex. « identity/user ».
     */
    abstract protected function routeSlug(): string;

    protected function generateBaseRoutePattern(bool $isChildAdmin = false): string
    {
        return '/'.$this->routeSlug();
    }

    protected function generateBaseRouteName(bool $isChildAdmin = false): string
    {
        return 'admin_'.str_replace('/', '_', $this->routeSlug());
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        // Seules la liste et la consultation existent : ni create, edit, delete, batch, export, history, acl.
        $collection->clearExcept(['list', 'show']);
        // Les identifiants sont des UUID : toute autre valeur est une route inexistante (404 propre), jamais une erreur
        // de conversion Doctrine (500).
        $collection->get('show')->setRequirement('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    }

    protected function configureBatchActions(array $actions): array
    {
        return [];
    }

    protected function configureDashboardActions(array $actions): array
    {
        return [];
    }

    public function getExportFormats(): array
    {
        return [];
    }

    protected function configureExportFields(): array
    {
        return [];
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_page'] = 1;
        $sortValues['_per_page'] = 25;
    }

    /**
     * @return array<string, mixed>
     */
    protected function dateOptions(): array
    {
        return ['format' => 'd/m/Y à H:i', 'timezone' => 'Europe/Paris'];
    }
}
