<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Permit\Application\Read\NotificationFacts;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\RightState;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture, SANS verrou, de l'état courant d'un laissez-passer pour composer une notification (LOT 05.3.3, DM-117). Appelée par le distributeur
 * juste avant l'envoi : le message décrit les droits d'AUJOURD'HUI, jamais ceux d'un instantané ancien. Aucune écriture.
 */
final readonly class NotificationFactsReader
{
    /** Zones sans droit pour le bénéficiaire : elles n'apparaissent pas dans un message qui annonce des droits. */
    private const HIDDEN = [RightState::Revoked, RightState::ZoneNotAuthorized];

    public function __construct(
        private PermitRepository $permits,
        private PermitRightsEvaluator $evaluator,
        private SystemAccessLinks $links,
    ) {
    }

    /**
     * @param Uuid|null $authorizationId autorisation concernée par un message d'extension : `authorizationActive` dit si elle donne encore des droits
     */
    public function read(Uuid $permitId, \DateTimeImmutable $now, ?Uuid $authorizationId = null): ?NotificationFacts
    {
        $permit = $this->permits->find($permitId);
        if (null === $permit) {
            return null;
        }
        $communes = [];
        $listed = [];
        foreach ($this->evaluator->evaluate($permit, $now)->authorizations as $authorization) {
            $zones = [];
            foreach ($authorization->zones as $zone) {
                if (!\in_array($zone->state, self::HIDDEN, true)) {
                    $zones[] = $zone->label;
                }
            }
            if ([] !== $zones) {
                $communes[] = ['municipality' => $authorization->municipalityName, 'zones' => $zones];
                $listed[] = $authorization->authorizationId->toRfc4122();
            }
        }
        $request = $permit->request();

        return new NotificationFacts(
            $this->links->isUsable($permitId, $now),
            $request->firstName(),
            $permit->reference()->value,
            $permit->event()->name(),
            $communes,
            $listed,
            null === $authorizationId || \in_array($authorizationId->toRfc4122(), $listed, true),
            $request->email(),
        );
    }
}
