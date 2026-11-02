<?php

declare(strict_types=1);

namespace App\Admin\Security;

use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Security\Handler\SecurityHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gestionnaire de sécurité du back-office CWS : gestionnaire PAR RÔLES de Sonata (ROLE_SUPER_ADMIN passe, les autres
 * rôles ne passent que s'ils portent explicitement le rôle de l'admin), limité au MOINDRE PRIVILÈGE : seules la
 * consultation (LIST, VIEW) est jamais accordée.
 *
 * Toute autre permission (CREATE, EDIT, DELETE, UNDELETE, EXPORT, HISTORY, OPERATOR, MASTER, ALL…) est refusée au niveau
 * des droits, MÊME pour un superadmin : c'est une seconde barrière, indépendante de la suppression des routes d'écriture
 * (ReadOnlyAdmin) et de la règle d'accès Symfony (security.yaml).
 */
final readonly class ReadOnlySecurityHandler implements SecurityHandlerInterface
{
    /** Permissions de lecture, seules accordées. */
    private const READ_PERMISSIONS = ['LIST', 'VIEW'];

    public function __construct(
        #[Autowire(service: 'sonata.admin.security.handler.role')]
        private SecurityHandlerInterface $roleHandler,
    ) {
    }

    public function isGranted(AdminInterface $admin, $attributes, ?object $object = null): bool
    {
        foreach ((array) $attributes as $attribute) {
            $name = (string) $attribute;
            // Rôles Symfony explicites (ex. ROLE_SONATA_ADMIN : droit d'accès au back-office) : délégués au gestionnaire par rôles.
            if (str_starts_with($name, 'ROLE_')) {
                continue;
            }
            if (!\in_array($name, self::READ_PERMISSIONS, true)) {
                return false;
            }
        }

        return $this->roleHandler->isGranted($admin, $attributes, $object);
    }

    public function getBaseRole(AdminInterface $admin): string
    {
        return $this->roleHandler->getBaseRole($admin);
    }

    public function buildSecurityInformation(AdminInterface $admin): array
    {
        return array_intersect_key($this->roleHandler->buildSecurityInformation($admin), array_flip(self::READ_PERMISSIONS));
    }

    public function createObjectSecurity(AdminInterface $admin, object $object): void
    {
        // Pas d'ACL par objet : rien à créer.
    }

    public function deleteObjectSecurity(AdminInterface $admin, object $object): void
    {
        // Pas d'ACL par objet : rien à supprimer.
    }
}
