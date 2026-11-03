<?php

declare(strict_types=1);

namespace App\Shell;

use App\Identity\Domain\User;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Compose le contexte du layout de l'espace municipal (utilisateur, commune active, rôle, navigation) à partir des
 * services existants : aucune permission n'est décidée ici. L'identifiant de commune vient de l'URL et n'est jamais pris
 * pour une preuve : MunicipalityContext exige un membership effectif, avec le même refus pour « inexistante » et
 * « non autorisée ». Les communes proposées au changement sont celles pour lesquelles l'utilisateur a un droit de lecture.
 *
 * Ce composant est transverse (hors modules métier) : il relie les contrôleurs des modules au layout du design system.
 */
final readonly class ShellContextFactory
{
    private const ROLE_LABELS = [
        MunicipalityRole::MunicipalityAdmin->value => 'Administrateur communal',
        MunicipalityRole::EventManager->value => 'Gestionnaire événement',
        MunicipalityRole::Instructor->value => 'Agent instructeur',
    ];

    public function __construct(
        private MunicipalityContext $context,
        private MembershipRepository $memberships,
        private MembershipAccessPolicy $access,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Variables du layout `layouts/app.html.twig` pour la commune active de l'URL.
     *
     * @param string $section rubrique courante de la navigation (`events`)
     *
     * @return array<string, mixed>
     *
     * @throws \App\Shared\Domain\OperationNotAllowed commune inaccessible (refus uniforme)
     */
    public function forMunicipality(User $user, string $municipalityId, string $section, string $pageTitle): array
    {
        $membership = $this->context->requireMembership($user, $municipalityId, write: false);
        $municipality = $membership->municipality();
        $role = $this->roleLabel($membership);
        $eventsUrl = $this->urls->generate('app_municipal_events', ['municipalityId' => $municipality->id()->toRfc4122()]);

        $options = [];
        foreach ($this->readableMemberships($user) as $candidate) {
            $options[] = [
                'label' => $candidate->municipality()->name(),
                'description' => $this->roleLabel($candidate),
                'href' => $this->urls->generate('app_municipal_events', ['municipalityId' => $candidate->municipality()->id()->toRfc4122()]),
                'current' => $candidate->municipality()->id()->equals($municipality->id()),
            ];
        }

        return [
            'page_title' => $pageTitle,
            'nav_items' => [['label' => 'Événements', 'icon' => 'flag', 'href' => $eventsUrl, 'current' => 'events' === $section]],
            'bottom_items' => [
                ['label' => 'Événements', 'icon' => 'flag', 'href' => $eventsUrl, 'current' => 'events' === $section],
                ['label' => 'Menu', 'icon' => 'menu', 'href' => '#', 'modal' => 'cp-mobile-menu'],
            ],
            'menu_items' => [],
            'commune' => [
                'id' => 'commune',
                'kicker' => 'Commune active',
                'value' => $municipality->name(),
                'options' => $options,
                'change_href' => $this->urls->generate('app_municipal_select'),
            ],
            'event' => null,
            'ctx_parts' => [['text' => $municipality->name(), 'strong' => true]],
            'role' => $role,
            'user' => ['name' => trim($user->firstName().' '.$user->lastName()), 'initials' => self::initials($user), 'role' => $role],
            'home' => $eventsUrl,
            'logout_href' => $this->urls->generate('app_logout'),
            'cstatus' => MunicipalityStatus::Suspended === $municipality->status() ? ['state' => 'suspended', 'commune' => $municipality->name()] : null,
            'active_municipality_id' => $municipality->id()->toRfc4122(),
            'active_municipality_name' => $municipality->name(),
        ];
    }

    /**
     * Communes de l'utilisateur pour lesquelles un droit de lecture existe (membership actif, commune non inactive).
     *
     * @return list<MunicipalityMembership>
     */
    public function readableMemberships(User $user): array
    {
        $readable = array_values(array_filter(
            $this->memberships->findActiveByUser($user),
            fn (MunicipalityMembership $m): bool => null !== $this->access->effectiveMembership($user, $m->municipality(), write: false),
        ));
        usort($readable, static fn (MunicipalityMembership $a, MunicipalityMembership $b): int => [$a->municipality()->name(), $a->municipality()->id()->toRfc4122()] <=> [$b->municipality()->name(), $b->municipality()->id()->toRfc4122()]);

        return $readable;
    }

    public function roleLabel(MunicipalityMembership $membership): string
    {
        $labels = [];
        foreach (MunicipalityRole::cases() as $role) {
            if ($membership->hasRole($role)) {
                $labels[] = self::ROLE_LABELS[$role->value];
            }
        }

        return implode(', ', $labels);
    }

    private static function initials(User $user): string
    {
        $initials = mb_strtoupper(mb_substr($user->firstName(), 0, 1).mb_substr($user->lastName(), 0, 1));

        return '' === $initials ? '?' : $initials;
    }
}
