<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Aucun secret dans les journaux applicatifs (DT-048) : décore le journal du framework et masque, dans le message et le contexte, les secrets de
 * lien bénéficiaire (`/laissez-passer/…`), les jetons de vérification (`/v/…`), d'invitation (`/invitation/…`) et de réinitialisation de mot de passe
 * (`/reset-password/…`) que le routeur ou une exception pourraient citer (par
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
        // Le segment qui suit un préfixe sensible est TOUJOURS masqué, quelle que soit sa longueur (un secret tronqué reste un secret), sauf les routes fixes :
        // /invitation/delegation, /invitation/rejoindre et /reset-password/done (même liste blanche que ops/nginx, contrôlée par les tests). Préfixes insensibles à la casse
        // (un lecteur de QR peut mettre l'URL en majuscules), tolérants aux slashs répétés (`///laissez-passer//…`) et, pour /v/, à l'écriture encodée (`/%76/…`) : le
        // routeur cite le chemin BRUT dans « No route found for … ».
        $value = (string) preg_replace(
            [
                '#(/laissez-passer/+)[A-Za-z0-9_-]+#i',
                '#(/(?:v|%76)/+)[A-Za-z0-9_-]+#i',
                '#(/invitation/+)(?!(?:delegation|rejoindre)(?![A-Za-z0-9_-]))[A-Za-z0-9_-]+#i',
                '#(/reset-password/+)(?!done(?![A-Za-z0-9_-]))[A-Za-z0-9_-]+#i',
            ],
            '$1'.self::MASK,
            $value,
        );

        // Message d'erreur du routeur : il cite l'URL telle que reçue, y compris des variantes qui ne correspondent à aucun préfixe connu (préfixe encodé autrement, segment
        // supplémentaire…). Dans CE message seulement, tout jeton opaque de 28 caractères ou plus est masqué (un UUID, identifiant interne, est conservé).
        if (str_contains($value, 'No route found for')) {
            $value = (string) preg_replace_callback(
                '#(?<![0-9A-Za-z_-])[0-9A-Za-z_-]{28,}(?![0-9A-Za-z_-])#',
                static fn (array $m): string => 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $m[0]) ? $m[0] : self::MASK,
                $value,
            );
        }

        return $value;
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
