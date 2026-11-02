<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Application\UserManager;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;

/**
 * Tests HTTP sur la base de TEST (purge via TestDatabase, qui refuse toute base hors « _test »).
 */
abstract class WebTestCase extends BaseWebTestCase
{
    use EmailAccess;

    public const PASSWORD = 'correct horse battery';

    /**
     * @param array<string, mixed> $options options du noyau (ex. ['debug' => false] pour les vraies pages d'erreur)
     */
    protected function freshClient(array $options = []): KernelBrowser
    {
        // État statique de Request : aucun proxy de confiance, quoi qu'un test précédent ait démarré (noyau dev, par exemple).
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        $client = static::createClient($options);
        TestDatabase::reset($this->em());
        // Le client redémarre le noyau à chaque requête : l'état des limiteurs de connexion doit être persistant
        // (cache fichier) pendant un test, et vidé entre deux tests.
        static::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    protected function createAccount(string $email, bool $superAdmin = false, bool $active = true): User
    {
        $manager = static::getContainer()->get(UserManager::class);
        $user = $manager->createUser($email, self::PASSWORD, 'Prénom', 'Nom');
        if ($superAdmin) {
            $manager->grantSuperAdmin($user, null);
        }
        if (!$active) {
            // Désactivation directe : le garde-fou du dernier superadmin n'est pas l'objet de ces tests.
            $this->em()->getConnection()->executeStatement('UPDATE identity_user SET active = 0 WHERE id = ?', [$user->id()->toBinary()]);
            $this->em()->refresh($user);
        }

        return $user;
    }

    /**
     * Soumet le formulaire de connexion (le jeton CSRF vient de la page, comme pour un navigateur).
     */
    protected function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['email' => $email, 'password' => $password]);
    }

    /**
     * Demande un lien de réinitialisation par le formulaire et retourne le lien de l'email (null si aucun email).
     *
     * @param array<string, string> $server
     */
    protected function requestResetLink(KernelBrowser $client, string $email, array $server = []): ?string
    {
        $client->request('GET', '/forgot-password', [], [], $server);
        $client->submitForm('Envoyer le lien', ['email' => $email], 'POST', $server);

        return $this->linkOfLastEmail();
    }

    protected function linkOfLastEmail(): ?string
    {
        if (0 === \count(self::getMailerMessages())) {
            return null;
        }
        $body = (string) self::emailAt(0)->getTextBody();

        return 1 === preg_match('~https?://\S+/reset-password/[A-Za-z0-9_-]{43}~', $body, $m) ? $m[0] : null;
    }

    protected function pathOf(string $url): string
    {
        return (string) parse_url($url, \PHP_URL_PATH);
    }
}
