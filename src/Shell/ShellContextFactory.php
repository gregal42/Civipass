<?php

declare(strict_types=1);

namespace App\Shell;

use App\Identity\Domain\User;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Request\Application\AgentRequestAccess;
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

        $nav = [['label' => 'Événements', 'icon' => 'flag', 'href' => $eventsUrl, 'current' => 'events' === $section]];
        if ($membership->hasRole(MunicipalityRole::Instructor)) {
            $nav[] = ['label' => 'Demandes', 'icon' => 'file', 'href' => $this->urls->generate('app_municipal_requests', ['municipalityId' => $municipality->id()->toRfc4122()]), 'current' => 'requests' === $section];
        }
        if (AgentRequestAccess::mayEnter($membership)) {
            $nav[] = ['label' => 'Nouvelle demande', 'icon' => 'plus', 'href' => $this->urls->generate('app_municipal_request_new', ['municipalityId' => $municipality->id()->toRfc4122()]), 'current' => 'new_request' === $section];
        }
        if ($membership->hasRole(MunicipalityRole::Instructor) || $membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
            $nav[] = ['label' => 'Corrections d’identité', 'icon' => 'user', 'href' => $this->urls->generate('app_municipal_identity_corrections', ['municipalityId' => $municipality->id()->toRfc4122()]), 'current' => 'identity' === $section];
        }
        if ($membership->hasRole(MunicipalityRole::MunicipalityAdmin) || $membership->hasRole(MunicipalityRole::EventManager)) {
            // Lien seulement : l'accès réel (administrateur, ou gestionnaire désigné) est décidé par ArchiveAccessPolicy à chaque requête (LOT 05.6.3).
            $nav[] = ['label' => 'Archives', 'icon' => 'file', 'href' => $this->urls->generate('app_archive_index', ['municipalityId' => $municipality->id()->toRfc4122()]), 'current' => 'archives' === $section];
        }
        if ($membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
            // Lien seulement : l'accès réel au registre est décidé par ArchiveAccessPolicy::administrator à chaque requête (LOT 05.6.4).
            $nav[] = ['label' => 'Conservation des données', 'icon' => 'file', 'href' => $this->urls->generate('app_retention_index', ['municipalityId' => $municipality->id()->toRfc4122()]), 'current' => 'retention' === $section];
        }
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
            'nav_items' => $nav,
            'bottom_items' => [...$nav, ['label' => 'Menu', 'icon' => 'menu', 'href' => '#', 'modal' => 'cp-mobile-menu']],
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
            'agent_role' => AgentRequestAccess::mayEnter($membership),
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
