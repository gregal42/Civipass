<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Énumération de comptes par le temps de réponse (LOT 10, O-6) : pour un compte EXISTANT, un mot de passe erroné coûte une vérification de hachage
 * (≈ 350 ms avec l'algorithme de production) ; pour un identifiant INCONNU, l'échec était immédiat (≈ 7 ms) alors que le message est identique.
 *
 * Correctif : quand l'échec vient d'un identifiant sans compte, on exécute UN hachage factice du mot de passe saisi avec le MÊME algorithme et le MÊME
 * coût que les comptes réels (fabrique de hacheurs de l'application), puis on laisse la réponse inchangée. Ne change ni les messages, ni les règles
 * d'authentification, ni le limiteur de connexion : l'écouteur ne s'exécute qu'APRÈS un échec, et le limiteur refuse AVANT toute recherche de compte
 * (une connexion limitée n'effectue donc aucun hachage). Il ne vise pas une égalité parfaite des temps, seulement l'absence d'écart systématique.
 * Aucun mot de passe n'est journalisé ni conservé ; toute erreur du hachage est absorbée (la réponse ne change jamais).
 */
final readonly class UnknownAccountTimingListener
{
    /** Longueur maximale acceptée par les hacheurs : au-delà, la vérification d'un vrai compte échoue aussi sans hachage. */
    private const MAX_PASSWORD_LENGTH = 4096;

    public function __construct(private PasswordHasherFactoryInterface $hashers)
    {
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (!self::causedByUnknownAccount($event->getException())) {
            return;
        }
        $password = $this->password($event);
        if (null === $password || '' === $password || \strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return;
        }
        try {
            $this->hashers->getPasswordHasher(User::class)->hash($password);
        } catch (\Throwable) {
            // Jamais de changement de réponse ni de trace du mot de passe.
        }
    }

    private static function causedByUnknownAccount(\Throwable $exception): bool
    {
        for ($e = $exception; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof UserNotFoundException) {
                return true;
            }
        }

        return false;
    }

    private function password(LoginFailureEvent $event): ?string
    {
        try {
            $badge = $event->getPassport()?->getBadge(PasswordCredentials::class);
            if ($badge instanceof PasswordCredentials) {
                return $badge->getPassword();
            }
        } catch (\LogicException) {
            // Identifiants déjà effacés : repli sur la requête.
        }
        $value = $event->getRequest()->request->all()['password'] ?? null;

        return \is_string($value) ? $value : null;
    }
}
