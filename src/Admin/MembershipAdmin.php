<?php

declare(strict_types=1);

namespace App\Admin;

use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\DoctrineORMAdminBundle\Filter\BooleanFilter;
use Sonata\DoctrineORMAdminBundle\Filter\StringFilter;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Appartenances communales : consultation seule. Les rôles et l'activation ne se modifient que par MembershipManager
 * (transaction, verrou, audit, protection du dernier administrateur). L'utilisateur et la commune sont des liens vers
 * leurs propres fiches.
 *
 * @extends ReadOnlyAdmin<MunicipalityMembership>
 */
#[AutoconfigureTag('sonata.admin', ['model_class' => MunicipalityMembership::class, 'manager_type' => 'orm', 'label' => 'Appartenances communales', 'group' => 'Communes', 'icon' => '<i class="fa fa-link"></i>'])]
final class MembershipAdmin extends ReadOnlyAdmin
{
    public const ROLE_LABELS = [
        MunicipalityRole::MunicipalityAdmin->value => 'Administrateur communal',
        MunicipalityRole::EventManager->value => 'Responsable d’événement',
        MunicipalityRole::Instructor->value => 'Instructeur',
    ];

    protected function routeSlug(): string
    {
        return 'communes/appartenance';
    }

    public function toString(object $object): string
    {
        return sprintf('%s — %s', $object->user()->email(), $object->municipality()->name());
    }

    /**
     * @return callable(MunicipalityMembership): string
     */
    private static function rolesAccessor(): callable
    {
        return static fn (MunicipalityMembership $m): string => implode(', ', array_map(static fn (MunicipalityRole $r): string => self::ROLE_LABELS[$r->value], $m->roles()));
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
            ->add('user.email', StringFilter::class, ['label' => 'Email de l’utilisateur'])
            ->add('municipality.name', StringFilter::class, ['label' => 'Nom de la commune'])
            ->add('municipality.inseeCode', StringFilter::class, ['label' => 'Code INSEE'])
            ->add('active', BooleanFilter::class, ['label' => 'Appartenance active']);
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->add('user', null, ['label' => 'Utilisateur', 'route' => ['name' => 'show'], 'associated_property' => 'email', 'sortable' => 'user.email'])
            ->add('municipality', null, ['label' => 'Commune', 'route' => ['name' => 'show'], 'associated_property' => 'name', 'sortable' => 'municipality.name'])
            ->add('roles', 'string', ['label' => 'Rôles communaux', 'accessor' => self::rolesAccessor(), 'sortable' => false])
            ->add('active', 'boolean', ['label' => 'Active'])
            ->add('createdAt', 'datetime', ['label' => 'Créée le'] + $this->dateOptions())
            ->add(ListMapper::NAME_ACTIONS, null, ['label' => 'Actions', 'actions' => ['show' => []]]);
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->with('Appartenance', ['class' => 'col-md-6'])
                ->add('id', null, ['label' => 'Identifiant', 'accessor' => static fn (MunicipalityMembership $m): string => $m->id()->toRfc4122()])
                ->add('user', null, ['label' => 'Utilisateur', 'route' => ['name' => 'show'], 'associated_property' => 'email'])
                ->add('municipality', null, ['label' => 'Commune', 'route' => ['name' => 'show'], 'associated_property' => 'name'])
                ->add('roles', 'string', ['label' => 'Rôles communaux', 'accessor' => self::rolesAccessor()])
                ->add('active', 'boolean', ['label' => 'Appartenance active'])
            ->end()
            ->with('Historique', ['class' => 'col-md-6'])
                ->add('createdAt', 'datetime', ['label' => 'Créée le'] + $this->dateOptions())
                ->add('updatedAt', 'datetime', ['label' => 'Modifiée le'] + $this->dateOptions())
            ->end();
    }
}
