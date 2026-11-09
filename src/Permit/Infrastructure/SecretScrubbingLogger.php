<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Aucun secret dans les journaux applicatifs (DT-048) : décore le journal du framework et masque, dans le message et le contexte, les secrets de
 * lien bénéficiaire (`/laissez-passer/…`) et les jetons de vérification (`/v/…`) que le routeur ou une exception pourraient citer (par
 * exemple « Matched route » avec ses paramètres). Les journaux du serveur web relèvent de l'infrastructure (docs/lot-05-schema.md § 10).
 */
#[AsDecorator('logger', onInvalid: \Symfony\Component\DependencyInjection\ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final class SecretScrubbingLogger implements LoggerInterface
{
    use LoggerTrait;

    private const MASK = '[masqué]';
    private const SENSITIVE_KEYS = ['secret', 'token', 'verificationtoken', 'verification_token'];

    public function __construct(#[AutowireDecorated] private readonly LoggerInterface $inner)
    {
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        /** @var array<array-key, mixed> $clean */
        $clean = self::scrubValue($context);
        $this->inner->log($level, self::scrubString((string) $message), $clean);
    }

    public static function scrubString(string $value): string
    {
        return (string) preg_replace(
            ['#(/laissez-passer/)[A-Za-z0-9_-]{16,}#', '#(/v/)[A-Za-z2-7]{20,}#'],
            ['$1'.self::MASK, '$1'.self::MASK],
            $value,
        );
    }

    private static function scrubValue(mixed $value, ?string $key = null): mixed
    {
        if (\is_string($key) && \in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
            return self::MASK;
        }
        if (\is_string($value)) {
            return self::scrubString($value);
        }
        if (\is_array($value)) {
            $clean = [];
            foreach ($value as $k => $v) {
                $clean[$k] = self::scrubValue($v, \is_string($k) ? $k : null);
            }

            return $clean;
        }

        return $value;
    }
}
