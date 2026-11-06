<?php

declare(strict_types=1);

namespace App\Request\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compteur des références de demande : une ligne par année civile, créée à la demande par l'incrément SQL atomique de
 * DoctrineRequestReferenceGenerator. Table technique : l'entité n'existe que pour le mapping et la cohérence du schéma.
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_reference_counter')]
class RequestReferenceCounter
{
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $year = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $lastNumber = 0;

    public function year(): int
    {
        return $this->year;
    }

    public function lastNumber(): int
    {
        return $this->lastNumber;
    }
}
