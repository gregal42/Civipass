<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Application\EventTypeInitializer;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

/**
 * Monde persisté (MySQL de test) pour les tests des services transactionnels :
 *  - `org` : commune organisatrice avec un accès commercial CWS valide ; administrateur, gestionnaire, instructeur ;
 *  - `guestA` : commune sans aucun membre ni droit ; `guestB` : commune avec un administrateur, sans aucun droit ;
 *  - `other` : commune étrangère avec un administrateur et AUCUN droit ; `superAdmin` : aucun membership ;
 *  - les neuf types d'événement initiaux.
 */
final class ServiceWorld
{
    public Municipality $org;
    public Municipality $guestA;
    public Municipality $guestB;
    public Municipality $other;
    public User $adminO;
    public User $managerO;
    public User $instructorO;
    public User $adminB;
    public User $adminX;
    public User $superAdmin;

    public static function persist(EntityManagerInterface $em, ContainerInterface $container, string $orgInsee = '75056'): self
    {
        $w = new self();
        $w->org = MunicipalityBuilder::aMunicipality()->withInseeCode($orgInsee)->build();
        $w->guestA = MunicipalityBuilder::aMunicipality()->build();
        $w->guestB = MunicipalityBuilder::aMunicipality()->build();
        $w->other = MunicipalityBuilder::aMunicipality()->build();
        $w->adminO = UserBuilder::aUser()->build();
        $w->managerO = UserBuilder::aUser()->build();
        $w->instructorO = UserBuilder::aUser()->build();
        $w->adminB = UserBuilder::aUser()->build();
        $w->adminX = UserBuilder::aUser()->build();
        $w->superAdmin = UserBuilder::aUser()->superAdmin()->build();

        $now = EventFixtures::now();
        $entities = [
            $w->org, $w->guestA, $w->guestB, $w->other, $w->adminO, $w->managerO, $w->instructorO, $w->adminB, $w->adminX, $w->superAdmin,
            MembershipBuilder::aMembership($w->adminO, $w->org)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($w->managerO, $w->org)->withRoles(MunicipalityRole::EventManager)->build(),
            MembershipBuilder::aMembership($w->instructorO, $w->org)->withRoles(MunicipalityRole::Instructor)->build(),
            MembershipBuilder::aMembership($w->adminB, $w->guestB)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($w->adminX, $w->other)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            new MunicipalityCommercialAccess($w->org, $w->superAdmin, $now->modify('-30 days'), null, 'Convention CWS', $now->modify('-30 days')),
        ];
        foreach ($entities as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $container->get(EventTypeInitializer::class)->initialize();
        $em->clear();

        return $w;
    }
}
