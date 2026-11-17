<?php

declare(strict_types=1);

namespace App\Control\Application\Result;

use App\Control\Domain\ControlDevice;
use App\Control\Domain\DeviceSecret;

/** Appareil enregistré en attente d'approbation : le secret n'est remis qu'ici, une fois. */
final readonly class RegisteredDevice
{
    public function __construct(public ControlDevice $device, public DeviceSecret $secret)
    {
    }
}
