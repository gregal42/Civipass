<?php

declare(strict_types=1);

namespace App\Admin;

use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityStatus;
use Knp\Menu\ItemInterface;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\Type\Operator\StringOperatorType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\DoctrineORMAdminBundle\Filter\ChoiceFilter;
use Sonata\DoctrineORMAdminBundle\Filter\StringFilter;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * Communes : consultation seule. Le statut administratif, le contact et le code INSEE ne se modifient que par
 * MunicipalityManager (audité) ; l'abonnement n'a pas encore d'entité (aucun module Abonnements).
 *
 * @extends ReadOnlyAdmin<Municipality>
 */
#[AutoconfigureTag('sonata.admin', ['model_class' => Municipality::class, 'manager_type' => 'orm', 'label' => 'Communes', 'group' => 'Communes', 'icon' => '<i class="fa fa-building"></i>'])]
final class MunicipalityAdmin extends ReadOnlyAdmin
{
    public const STATUS_LABELS = [
        MunicipalityStatus::Active->value => 'Active',
        MunicipalityStatus::Suspended->value => 'Suspendue',
        MunicipalityStatus::Inactive->value => 'Inactive',
    ];

    protected function routeSlug(): string
    {
        return 'communes/commune';
    }

    public function toString(object $object): string
    {
        return sprintf('%s (%s)', $object->name(), $object->inseeCode()->value);
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        parent::configureDefaultSortValues($sortValues);
        $sortValues['_sort_by'] = 'name';
        $sortValues['_sort_order'] = 'ASC';
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('name', StringFilter::class, ['label' => 'Nom'])
            ->add('inseeCode', StringFilter::class, ['label' => 'Code INSEE'])
            ->add('city', StringFilter::class, ['label' => 'Ville'])
            ->add('status', ChoiceFilter::class, [
                'label' => 'Statut',
                'field_type' => ChoiceType::class,
                'field_options' => ['choices' => array_flip(self::STATUS_LABELS)],
            ]);
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('name', null, ['label' => 'Nom', 'route' => ['name' => 'show']])
            ->add('inseeCode', null, ['label' => 'Code INSEE', 'accessor' => static fn (Municipality $m): string => $m->inseeCode()->value])
            ->add('status', 'string', ['label' => 'Statut', 'accessor' => static fn (Municipality $m): string => self::STATUS_LABELS[$m->status()->value]])
            ->add('postalCode', null, ['label' => 'Code postal'])
            ->add('city', null, ['label' => 'Ville'])
            ->add('updatedAt', 'datetime', ['label' => 'Modifiée le'] + $this->dateOptions());
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->with('Commune', ['class' => 'col-md-6'])
                ->add('id', null, ['label' => 'Identifiant', 'accessor' => static fn (Municipality $m): string => $m->id()->toRfc4122()])
                ->add('name', null, ['label' => 'Nom'])
                ->add('inseeCode', null, ['label' => 'Code INSEE', 'accessor' => static fn (Municipality $m): string => $m->inseeCode()->value])
                ->add('status', 'string', ['label' => 'Statut administratif', 'accessor' => static fn (Municipality $m): string => self::STATUS_LABELS[$m->status()->value]])
                ->add('createdAt', 'datetime', ['label' => 'Créée le'] + $this->dateOptions())
                ->add('updatedAt', 'datetime', ['label' => 'Modifiée le'] + $this->dateOptions())
            ->end()
            ->with('Contact', ['class' => 'col-md-6'])
                ->add('contactName', null, ['label' => 'Nom du contact'])
                ->add('contactEmail', null, ['label' => 'Email'])
                ->add('contactPhone', null, ['label' => 'Téléphone'])
                ->add('addressLine', null, ['label' => 'Adresse'])
                ->add('postalCode', null, ['label' => 'Code postal'])
                ->add('city', null, ['label' => 'Ville'])
            ->end();
    }

    protected function configureTabMenu(ItemInterface $menu, string $action, ?AdminInterface $childAdmin = null): void
    {
        $municipality = $this->hasSubject() ? $this->getSubject() : null;
        if ('show' !== $action || !$municipality instanceof Municipality) {
            return;
        }
        $memberships = $this->getConfigurationPool()->getAdminByClass(MunicipalityMembership::class);
        $menu->addChild('Appartenances de la commune', ['uri' => $memberships->generateUrl('list', ['filter' => ['municipality__inseeCode' => ['value' => $municipality->inseeCode()->value, 'type' => StringOperatorType::TYPE_EQUAL]]])]);
    }
}
