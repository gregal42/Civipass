<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\TestDatabase;
use App\Tests\Support\UserBuilder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminAccessTest extends WebTestCase
{
    public function testAnonymousIsSentToTheLoginPageOnEveryAdminRoute(): void
    {
        $client = static::createClient();
        foreach (['/admin/', '/admin/dashboard', '/admin/search', '/admin/core/get-autocomplete-items'] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects('/login', 302, $path);
        }
    }

    public function testAuthenticatedNonSuperAdminIsForbidden(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->build();
        $this->persist($user);

        $client->loginUser($user);
        $client->request('GET', '/admin/dashboard');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminPassesAccessControl(): void
    {
        $client = static::createClient();
        $root = UserBuilder::aUser()->superAdmin()->build();
        $this->persist($root);

        $client->loginUser($root);
        $client->request('GET', '/admin/dashboard');

        self::assertResponseIsSuccessful();
    }

    public function testPublicHomeRemainsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    private function persist(object $entity): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        TestDatabase::reset($em);
        $em->persist($entity);
        $em->flush();
    }
}
