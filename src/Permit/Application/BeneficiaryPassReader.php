<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Permit\Application\Read\BeneficiaryPass;
use App\Permit\Domain\AccessSecret;
use App\Permit\Domain\PermitAccessTokenRepository;
use App\Permit\Domain\PermitVehicle;
use App\Request\Domain\TravelMode;

/**
 * Lecture du laissez-passer par son bénéficiaire (LOT 05.2, DM-114), sans compte : le secret du lien est la seule preuve. Lecture seule ;
 * les droits sont ceux de PermitRightsEvaluator, évalués à l'instant demandé (jamais un second moteur).
 *
 * Secret mal formé, inconnu, révoqué ou expiré : la MÊME réponse (`null`), qui ne dit pas lequel (aucune énumération). La révocation est
 * prioritaire sur l'expiration. Le secret n'est jamais journalisé ni cité dans une exception.
 */
final readonly class BeneficiaryPassReader
{
    public function __construct(
        private PermitAccessTokenRepository $tokens,
        private PermitRightsEvaluator $evaluator,
    ) {
    }

    public function find(#[\SensitiveParameter] string $secret, \DateTimeImmutable $now): ?BeneficiaryPass
    {
        $parsed = AccessSecret::tryFrom($secret);
        if (null === $parsed) {
            return null;
        }
        $token = $this->tokens->findByHash($parsed->hash());
        if (null === $token || !$token->matches($parsed) || !$token->isUsableAt($now)) {
            return null;
        }
        $permit = $token->permit();
        $request = $permit->request();

        return new BeneficiaryPass(
            $permit->reference()->value,
            $permit->event()->name(),
            $permit->event()->timezone()->identifier(),
            $request->lastName(),
            $request->firstName(),
            $request->category()->label(),
            TravelMode::Vehicle === $request->travelMode() ? 'vehicle' : 'foot_bike',
            array_map(static fn (PermitVehicle $v): string => PlateFormatter::format($v), $permit->activeVehicles()),
            $this->evaluator->evaluate($permit, $now),
            $permit->activeToken()->value(),
        );
    }
}
