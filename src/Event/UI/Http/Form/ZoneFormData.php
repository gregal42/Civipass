<?php

declare(strict_types=1);

namespace App\Event\UI\Http\Form;

/** Saisies brutes du formulaire de zone (chaînes), conservées pour réafficher le formulaire en cas d'erreur. */
final readonly class ZoneFormData
{
    public function __construct(
        public string $label = '',
        public string $type = '',
        public string $description = '',
        public bool $wholeConfirmed = false,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function fromPost(array $post): self
    {
        $text = static fn (string $key): string => \is_string($post[$key] ?? null) ? $post[$key] : '';

        return new self($text('label'), $text('type'), $text('description'), '1' === $text('whole_confirmed'));
    }
}
