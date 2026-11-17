<?php

declare(strict_types=1);

namespace App\Control\Application\Dataset;

use App\Control\Domain\ControlDevice;
use App\Event\Domain\EventStatus;
use App\Permit\Application\PermitRightsEvaluator;
use App\Permit\Application\PlateFormatter;
use App\Permit\Domain\AuthorizationRights;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitVehicle;
use App\Permit\Domain\RightState;
use App\Permit\Domain\ZoneRight;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Construit le jeu de données d'un appareil de contrôle (LOT 09.3, DM-130, DM-132, DT-056). SEUL point qui lit des laissez-passer pour l'appareil, appelé uniquement pour un
 * appareil APPROUVÉ, non désactivé et non expiré (le service de synchronisation l'impose ; aucun jeu n'est jamais construit pour un appareil en attente).
 *
 * Principes :
 *  - le MOTEUR DE DROITS reste unique (`PermitRightsEvaluator`, mode vérification) : le serveur matérialise des fenêtres UTC explicites ; l'appareil ne recalcule rien ;
 *  - ISOLATION : la commune et l'événement viennent de l'ACTIVATION de l'appareil (jamais d'un identifiant fourni par le client) ; seules les autorisations de CETTE commune
 *    sont lues, seuls les laissez-passer qui en ont une entrent dans le jeu ;
 *  - MINIMISATION : nom, prénom, plaques, zones, fenêtres ; jamais adresse, e-mail, téléphone, pièces ni motif ; le jeton n'est jamais transmis (empreinte seulement) ;
 *  - HORIZON : les fenêtres sont bornées à [début de grille, fin de grille], la grille étant de 1 h ; aucun droit n'est affirmé au-delà de l'horizon, et les lignes ne
 *    changent qu'à chaque heure (synchronisation différentielle stable) ;
 *  - EMPREINTES RÉVOQUÉES : un QR révoqué (autorisation révoquée) ou remplacé n'apparaît que tant que le laissez-passer pourrait encore être présenté (jusqu'à la fin de sa
 *    dernière période), sans stockage supplémentaire ;
 *  - événement CLOS : jeu vide marqué clos (l'appareil purge).
 */
final readonly class DatasetBuilder
{
    public const GRID_SECONDS = 3_600;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PermitRepository $permits,
        private PermitRightsEvaluator $evaluator,
    ) {
    }

    public function build(ControlDevice $device, \DateTimeImmutable $now): ControlDataset
    {
        $activation = $device->activation();
        $municipality = $activation->municipality();
        $event = $activation->event();
        $hard = $activation->trustHardSeconds();
        $lower = intdiv($now->getTimestamp(), self::GRID_SECONDS) * self::GRID_SECONDS;
        $horizon = intdiv($now->getTimestamp() + $hard, self::GRID_SECONDS) * self::GRID_SECONDS + self::GRID_SECONDS;
        $closed = EventStatus::Closed === $event->status();

        $envelope = [
            'v' => DatasetCodec::SCHEMA_VERSION,
            'gen' => $now->getTimestamp(),
            'hor' => $horizon,
            'ev' => ['ref' => $event->publicReference()->value, 'name' => $event->name(), 'end' => $event->endsAt()?->getTimestamp(), 'closed' => $closed],
            'mu' => $municipality->name(),
            'tz' => $event->timezone()->identifier(),
            'tr' => ['w' => $activation->trustWarnSeconds(), 'h' => $hard, 'm' => $activation->assuranceMode()->value],
            'dev' => ['exp' => $device->expiresAt()->getTimestamp()],
        ];
        if ($closed) {
            return new ControlDataset($envelope, []);
        }

        $lines = [];
        $permitIds = $this->permitIds($municipality->id(), $event->id());
        $tokens = $this->tokens($permitIds);
        $count = 0;
        foreach ($permitIds as $permitId) {
            $permit = $this->permits->find($permitId);
            if (null === $permit) {
                continue;
            }
            $rights = $this->evaluator->evaluate($permit, $now, true);
            $mine = array_values(array_filter($rights->authorizations, static fn (AuthorizationRights $a): bool => $a->municipalityId->equals($municipality->id())));
            if ([] === $mine) {
                continue;
            }
            $authorization = $mine[0];
            $revoked = null !== $authorization->revokedAt;
            $untilTs = $this->lastEnd($authorization);
            $alive = null === $untilTs || $untilTs >= $lower; // le laissez-passer pourrait encore être présenté

            foreach ($tokens[$permitId->toRfc4122()] ?? [] as [$token, $active]) {
                $key = DatasetCodec::tokenKey($token);
                if ($revoked || !$active) {
                    if ($alive) {
                        $lines[$key] = DatasetCodec::line(['k' => $key, 't' => $revoked ? 'R' : 'X']);
                    }
                    continue;
                }
                $request = $permit->request();
                $lines[$key] = DatasetCodec::line([
                    'k' => $key,
                    't' => 'P',
                    'r' => $permit->reference()->value,
                    'f' => $request->firstName(),
                    'l' => $request->lastName(),
                    'p' => array_map(static fn (PermitVehicle $v): string => PlateFormatter::format($v), $permit->activeVehicles()),
                    'z' => $this->zones($authorization->zones, $lower, $horizon),
                ]);
            }
            if (0 === ++$count % 200) {
                $this->entityManager->clear(); // mémoire bornée sur de gros événements (les entités lues ne servent plus)
            }
        }
        $envelope['n'] = \count($lines);

        return new ControlDataset($envelope, $lines);
    }

    /**
     * @return list<Uuid>
     */
    private function permitIds(Uuid $municipalityId, Uuid $eventId): array
    {
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT a.permit_id FROM permit_authorization a JOIN event_participation p ON p.id = a.participation_id WHERE a.municipality_id = :m AND p.event_id = :e ORDER BY a.permit_id',
            ['m' => $municipalityId->toBinary(), 'e' => $eventId->toBinary()],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromBinary((string) $id), $rows);
    }

    /**
     * Jetons (actif ou retiré) des laissez-passer de la commune, par laissez-passer.
     *
     * @param list<Uuid> $permitIds
     *
     * @return array<string, list<array{0: string, 1: bool}>>
     */
    private function tokens(array $permitIds): array
    {
        $result = [];
        foreach (array_chunk($permitIds, 500) as $chunk) {
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT permit_id, token, retired_at FROM permit_verification_token WHERE permit_id IN ('.implode(',', array_fill(0, \count($chunk), '?')).') ORDER BY created_at',
                array_map(static fn (Uuid $id): string => $id->toBinary(), $chunk),
            );
            foreach ($rows as $row) {
                $result[Uuid::fromBinary((string) $row['permit_id'])->toRfc4122()][] = [(string) $row['token'], null === $row['retired_at']];
            }
        }

        return $result;
    }

    /** Fin de la dernière période de validité de l'autorisation (instant UTC), nulle si elle court jusqu'à la clôture. */
    private function lastEnd(AuthorizationRights $authorization): ?int
    {
        return $authorization->validUntil?->getTimestamp();
    }

    /**
     * @param list<ZoneRight> $zones
     *
     * @return list<array{l: string, s: string, w: list<array{0: int, 1: int}>}>
     */
    private function zones(array $zones, int $lower, int $horizon): array
    {
        $out = [];
        foreach ($zones as $zone) {
            if (RightState::Removed === $zone->state) {
                continue;
            }
            $windows = [];
            $future = false;
            foreach ($zone->periods as $period) {
                $start = $period->start->getTimestamp();
                $end = null === $period->end ? $horizon : min($period->end->getTimestamp(), $horizon);
                if ($start >= $horizon) {
                    $future = true;
                    continue;
                }
                if ($end > $lower && $end > $start) {
                    $windows[] = [max($start, $lower), $end];
                }
            }
            $code = match ($zone->state) {
                RightState::Valid, RightState::OutsidePeriod, RightState::NotYetValid => [] !== $windows ? 'V' : ($future ? 'F' : 'E'),
                RightState::ZoneSuspended, RightState::ParticipationInactive, RightState::CommuneInactive, RightState::EventNotActive => 'S',
                default => 'E',
            };
            $out[] = ['l' => $zone->label, 's' => $code, 'w' => 'V' === $code ? $windows : []];
        }

        return $out;
    }
}
