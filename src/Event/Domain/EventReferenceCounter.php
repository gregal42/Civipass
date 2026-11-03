<?php

declare(strict_types=1);

namespace App\Event\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compteur global permanent des références publiques : une seule ligne (id = 1), jamais remise à zéro.
 * La ligne est initialisée par la migration (last_number = 0). Table technique : l'incrément est atomique en SQL
 * (DoctrinePublicReferenceGenerator) ; l'entité n'existe que pour le mapping et la cohérence du schéma.
 */
#[ORM\Entity]
#[ORM\Table(name: 'event_reference_counter')]
class EventReferenceCounter
{
    public const SINGLE_ROW_ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $id = self::SINGLE_ROW_ID;

    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true, 'default' => 0])]
    private string $lastNumber = '0';

    public function id(): int
    {
        return $this->id;
    }

    public function lastNumber(): int
    {
        return (int) $this->lastNumber;
    }
}
