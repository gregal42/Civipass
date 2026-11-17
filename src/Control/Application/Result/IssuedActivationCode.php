<?php

declare(strict_types=1);

namespace App\Control\Application\Result;

use App\Control\Domain\ActivationCode;
use App\Control\Domain\ControlActivation;

/** Code d'activation créé : le code en clair n'existe que dans cet objet, affiché UNE fois à l'administrateur (jamais conservé). */
final readonly class IssuedActivationCode
{
    public function __construct(public ControlActivation $activation, public ActivationCode $code)
    {
    }
}
