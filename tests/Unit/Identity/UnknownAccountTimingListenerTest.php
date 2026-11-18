<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Infrastructure\Security\UnknownAccountTimingListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * Correctif du temps de réponse de la connexion (LOT 10, O-6) : un échec dû à un identifiant sans compte exécute UN hachage factice ; aucun autre échec n'en
 * exécute ; la réponse n'est jamais modifiée ; aucune erreur du hachage ne remonte.
 */
final class UnknownAccountTimingListenerTest extends TestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $hashed;

    protected function setUp(): void
    {
        $this->hashed = new \ArrayObject();
    }

    private function listener(bool $failing = false): UnknownAccountTimingListener
    {
        $hasher = new class ($this->hashed, $failing) implements PasswordHasherInterface {
            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly \ArrayObject $log, private readonly bool $failing)
            {
            }

            public function hash(#[\SensitiveParameter] string $plainPassword): string
            {
                if ($this->failing) {
                    throw new \RuntimeException('hachage indisponible');
                }
                $this->log[] = $plainPassword;

                return 'hash';
            }

            public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
            {
                return false;
            }

            public function needsRehash(string $hashedPassword): bool
            {
                return false;
            }
        };
        $factory = new class ($hasher) implements PasswordHasherFactoryInterface {
            public function __construct(private readonly PasswordHasherInterface $hasher)
            {
            }

            public function getPasswordHasher(string|\Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface|\Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface $user): PasswordHasherInterface
            {
                return $this->hasher;
            }
        };

        return new UnknownAccountTimingListener($factory);
    }

    private function event(AuthenticationException $exception, ?string $password = 'mot de passe saisi', bool $withPassport = true, ?Response $response = null): LoginFailureEvent
    {
        $passport = $withPassport && null !== $password ? new Passport(new UserBadge('a@b.test', static fn () => throw new UserNotFoundException()), new PasswordCredentials($password)) : null;
        $request = Request::create('/login', 'POST', null === $password ? [] : ['email' => 'a@b.test', 'password' => $password]);

        return new LoginFailureEvent($exception, $this->createStub(AuthenticatorInterface::class), $request, $response, 'main', $passport);
    }

    private static function unknown(): BadCredentialsException
    {
        return new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException());
    }

    public function testAnUnknownIdentifierPerformsOneDummyHashOfTheTypedPassword(): void
    {
        $this->listener()->onLoginFailure($this->event(self::unknown()));

        self::assertSame(['mot de passe saisi'], $this->hashed->getArrayCopy());
    }

    public function testAUserNotFoundExceptionNotWrappedIsAlsoCovered(): void
    {
        $this->listener()->onLoginFailure($this->event(new UserNotFoundException()));

        self::assertCount(1, $this->hashed);
    }

    public function testAnExistingAccountWithAWrongPasswordPerformsNoExtraHash(): void
    {
        // Le compte existe : la vérification réelle a déjà eu lieu, aucun hachage supplémentaire.
        $this->listener()->onLoginFailure($this->event(new BadCredentialsException('Bad credentials.')));

        self::assertSame([], $this->hashed->getArrayCopy());
    }

    public function testAThrottledAttemptPerformsNoHashSoTheLimiterStaysTheOnlyGate(): void
    {
        $this->listener()->onLoginFailure($this->event(new TooManyLoginAttemptsAuthenticationException(15)));

        self::assertSame([], $this->hashed->getArrayCopy(), 'aucun coût de hachage pour une tentative refusée par le limiteur : pas de levier de déni de service');
    }

    public function testEmptyAndOversizedPasswordsPerformNoHashLikeARealAccountWouldFailEarly(): void
    {
        $this->listener()->onLoginFailure($this->event(self::unknown(), ''));
        $this->listener()->onLoginFailure($this->event(self::unknown(), str_repeat('x', 4097)));

        self::assertSame([], $this->hashed->getArrayCopy());
    }

    public function testTheTypedPasswordIsReadFromTheRequestWhenThereIsNoPassport(): void
    {
        $this->listener()->onLoginFailure($this->event(self::unknown(), 'depuis la requête', withPassport: false));

        self::assertSame(['depuis la requête'], $this->hashed->getArrayCopy());
    }

    public function testAFailingHasherNeverChangesTheOutcome(): void
    {
        $response = new Response('inchangée', 302);
        $event = $this->event(self::unknown(), response: $response);

        $this->listener(failing: true)->onLoginFailure($event);

        self::assertSame($response, $event->getResponse(), 'la réponse de l’échec n’est jamais remplacée');
        self::assertSame('inchangée', (string) $response->getContent());
    }

    public function testTheListenerNeverTouchesTheResponseNorTheException(): void
    {
        $response = new Response('x', 302);
        $exception = self::unknown();
        $event = $this->event($exception, response: $response);

        $this->listener()->onLoginFailure($event);

        self::assertSame($response, $event->getResponse());
        self::assertSame($exception, $event->getException());
    }
}
