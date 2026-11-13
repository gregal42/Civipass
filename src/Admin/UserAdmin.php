<?php

declare(strict_types=1);

namespace App\Admin;

use App\Identity\Domain\User;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\Type\Operator\StringOperatorType;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\DoctrineORMAdminBundle\Filter\BooleanFilter;
use Sonata\DoctrineORMAdminBundle\Filter\StringFilter;
use Knp\Menu\ItemInterface;
use Sonata\AdminBundle\Admin\AdminInterface;
use App\Municipality\Domain\MunicipalityMembership;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Utilisateurs : consultation seule. Champs EXPLICITEMENT autorisés : le hash du mot de passe n'est jamais lu ni affiché,
 * pas plus que les jetons (réinitialisation, remember-me) ; aucune découverte automatique de champs.
 *
 * @extends ReadOnlyAdmin<User>
 */
#[AutoconfigureTag('sonata.admin', ['model_class' => User::class, 'manager_type' => 'orm', 'label' => 'Utilisateurs', 'group' => 'Identité', 'icon' => '<i class="fa fa-user"></i>'])]
final class UserAdmin extends ReadOnlyAdmin
{
    protected function routeSlug(): string
    {
        return 'identity/user';
    }

    public function toString(object $object): string
    {
        return $object->email();
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        parent::configureDefaultSortValues($sortValues);
        $sortValues['_sort_by'] = 'createdAt';
        $sortValues['_sort_order'] = 'DESC';
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('email', StringFilter::class, ['label' => 'Adresse email'])
            ->add('lastName', StringFilter::class, ['label' => 'Nom'])
            ->add('firstName', StringFilter::class, ['label' => 'Prénom'])
            ->add('active', BooleanFilter::class, ['label' => 'Compte actif'])
            ->add('superAdmin', BooleanFilter::class, ['label' => 'Superadmin CWS']);
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('email', null, ['label' => 'Adresse email', 'route' => ['name' => 'show']])
            ->add('lastName', null, ['label' => 'Nom'])
            ->add('firstName', null, ['label' => 'Prénom'])
            ->add('active', 'boolean', ['label' => 'Actif'])
            ->add('superAdmin', 'boolean', ['label' => 'Superadmin'])
            ->add('lastLoginAt', 'datetime', ['label' => 'Dernière connexion'] + $this->dateOptions())
            ->add('createdAt', 'datetime', ['label' => 'Créé le'] + $this->dateOptions());
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->with('Compte', ['class' => 'col-md-6'])
                ->add('id', null, ['label' => 'Identifiant', 'accessor' => static fn (User $u): string => $u->id()->toRfc4122()])
                ->add('email', null, ['label' => 'Adresse email'])
                ->add('lastName', null, ['label' => 'Nom'])
                ->add('firstName', null, ['label' => 'Prénom'])
            ->end()
            ->with('État', ['class' => 'col-md-6'])
                ->add('active', 'boolean', ['label' => 'Compte actif'])
                ->add('superAdmin', 'boolean', ['label' => 'Superadmin CWS'])
                ->add('lastLoginAt', 'datetime', ['label' => 'Dernière connexion'] + $this->dateOptions())
                ->add('createdAt', 'datetime', ['label' => 'Créé le'] + $this->dateOptions())
                ->add('updatedAt', 'datetime', ['label' => 'Modifié le'] + $this->dateOptions())
            ->end();
    }

    protected function configureTabMenu(ItemInterface $menu, string $action, ?AdminInterface $childAdmin = null): void
    {
        $user = $this->hasSubject() ? $this->getSubject() : null;
        if ('show' !== $action || !$user instanceof User) {
            return;
        }
        if (!$user->isSuperAdmin()) {
            $menu->addChild('Gérer le compte (CWS)', ['uri' => '/admin/cws/comptes/'.$user->id()->toRfc4122()]);
        }
        $memberships = $this->getConfigurationPool()->getAdminByClass(MunicipalityMembership::class);
        $menu->addChild('Appartenances communales', ['uri' => $memberships->generateUrl('list', ['filter' => ['user__email' => ['value' => $user->email(), 'type' => StringOperatorType::TYPE_EQUAL]]])]);
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        parent::configureRoutes($collection);
    }
}
