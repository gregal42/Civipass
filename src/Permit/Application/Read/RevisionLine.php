<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/**
 * Ligne de l'historique des droits (LOT 05.5.1, DM-119 §7). Une révision de SA commune : détail (motif, commentaire, auteur). Une révision d'une
 * autre commune : ligne NEUTRE (type, date, commune concernée), `own` faux, aucun motif, commentaire ni auteur n'est lu ni transmis.
 */
final readonly class RevisionLine
{
    /**
     * @param array<string, mixed>|null $period  changement de période (révisions de SA commune seulement) : début et fin avant et après, fin immédiate, fin demandée
     * @param array<string, mixed>|null $zone    zone concernée (révisions de SA commune seulement) : libellé et libellé précédent
     * @param array<string, mixed>|null $token   remplacement du QR (révisions de SA commune seulement) : numéro d'ordre, rapproché, inhabituel ; jamais un jeton
     * @param array<string, mixed>|null $vehicle plaque concernée (révisions de SA commune seulement) : plaque affichée (nulle une fois anonymisée), action, numéro de remplacement
     */
    public function __construct(
        public bool $own,
        public string $label,
        public \DateTimeImmutable $occurredAt,
        public string $municipalityName,
        public ?string $reasonLabel,
        public ?string $comment,
        public ?string $actorName,
        public ?array $period = null,
        public ?array $zone = null,
        public ?array $vehicle = null,
        public ?array $token = null,
    ) {
    }
}
