<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Jeton de vérification porté par le QR (DM-113) : 160 bits aléatoires encodés en 32 caractères base32 minuscules, sans aucune
 * donnée personnelle, stocké EN CLAIR pour pouvoir régénérer le QR et le PDF. Un seul jeton ACTIF par laissez-passer :
 * `active_permit_id` vaut l'identifiant du laissez-passer tant que le jeton est actif et NULL une fois retiré (MySQL n'a pas
 * d'index unique partiel ; plusieurs NULL sont permis). Le retrait sert au remplacement exceptionnel du LOT 05.5.
 * Le jeton ne doit JAMAIS figurer dans un journal, une exception, un audit ni un message d'erreur.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permit_verification_token')]
#[ORM\UniqueConstraint(name: 'uniq_permit_token_value', columns: ['token'])]
#[ORM\UniqueConstraint(name: 'uniq_permit_token_active', columns: ['active_permit_id'])]
class VerificationToken
{
    public const LENGTH = 32;
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: self::LENGTH, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
    private string $token;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $activePermitId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    /**
     * @internal construit par Permit
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Permit::class, inversedBy: 'tokens', fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'permit_id', nullable: false, onDelete: 'RESTRICT')]
        private Permit $permit,
        string $token,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        if (1 !== preg_match('/^[a-z2-7]{'.self::LENGTH.'}$/', $token)) {
            throw new DomainRuleViolation('Jeton de vérification invalide.');
        }
        $this->id = Uuid::v7();
        $this->token = $token;
        $this->activePermitId = $permit->id();
    }

    /** Génère un jeton de 160 bits (générateur cryptographiquement sûr). */
    public static function generate(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $encoded;
    }

    /**
     * Extrait le jeton de ce que lit un lecteur de QR : le jeton seul ou une URL dont il est le dernier segment du chemin (`https://hôte/v/<jeton>`,
     * avec ou sans paramètres ni ancre). Fonction pure, SANS accès réseau ni base : c'est ce qui permettra à la future PWA de contrôle de retrouver
     * le laissez-passer dans ses données locales. Les lecteurs peuvent passer le texte en majuscules : la casse est normalisée. `null` si la valeur
     * n'est pas un jeton (jamais d'exception : une valeur lue est une entrée non fiable).
     */
    public static function extract(string $scanned): ?string
    {
        $scanned = trim($scanned);
        if (\strlen($scanned) > 2048) {
            return null;
        }
        $path = 1 === preg_match('#^[a-z][a-z0-9+.-]*://[^/?\#]*(/[^?\#]*)?#i', $scanned, $m) ? ($m[1] ?? '') : preg_replace('/[?#].*$/', '', $scanned);
        $segment = strtolower(basename(rtrim((string) $path, '/')));

        return 1 === preg_match('/^[a-z2-7]{'.self::LENGTH.'}$/', $segment) ? $segment : null;
    }

    /**
     * Jeton nu (celui du chemin `/v/{jeton}`) normalisé : espaces retirés, minuscules. `null` s'il n'a pas la forme d'un jeton ; contrairement à
     * `extract()`, une URL n'est PAS acceptée. Fonction pure, jamais d'exception.
     */
    public static function normalize(#[\SensitiveParameter] string $value): ?string
    {
        $value = strtolower(trim($value));

        return 1 === preg_match('/^[a-z2-7]{'.self::LENGTH.'}$/', $value) ? $value : null;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function permit(): Permit
    {
        return $this->permit;
    }

    public function value(): string
    {
        return $this->token;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return null === $this->retiredAt && null !== $this->activePermitId;
    }

    /**
     * Retrait DÉFINITIF (remplacement du QR, LOT 05.5.5) : le jeton ne redevient jamais actif ; la ligne reste (le retrait sert à répondre 410).
     *
     * @internal appelé par PermitTokenReplacementService
     */
    public function retire(\DateTimeImmutable $at): void
    {
        if (null === $this->activePermitId) {
            throw new DomainRuleViolation('Ce jeton est déjà retiré.');
        }
        $this->activePermitId = null;
        $this->retiredAt = $at;
    }

    public function retiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }
}
