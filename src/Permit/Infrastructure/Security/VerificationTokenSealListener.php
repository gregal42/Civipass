<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure\Security;

use App\Permit\Domain\TokenSealer;
use App\Permit\Domain\VerificationToken;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/** Scelle le jeton d'une nouvelle ligne juste avant son écriture (SEC-020) : le clair n'atteint jamais la base. */
#[AsDoctrineListener(event: Events::prePersist)]
final readonly class VerificationTokenSealListener
{
    public function __construct(private TokenSealer $sealer)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof VerificationToken) {
            $entity->seal($this->sealer);
        }
    }
}
