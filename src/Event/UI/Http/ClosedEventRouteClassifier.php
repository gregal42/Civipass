<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

/**
 * Classe CHAQUE route de l'application vis-à-vis de la clôture d'un événement (LOT 05.6.2, P1). La barrière n'est pas une liste de motifs à tenir
 * à jour : tout chemin de l'espace municipal est déduit de sa FORME, les exemptions forment une liste FERMÉE dont chaque ligne est justifiée, et
 * un test parcourt tout le routeur : une route nouvelle non classée fait échouer la suite (`Unclassified`).
 */
final class ClosedEventRouteClassifier
{
    /** Paramètres qui désignent une ressource d'exploitation : sur une route inconnue, leur présence est une alerte. */
    public const RESOURCE_PLACEHOLDERS = ['{requestId}', '{attachmentId}', '{permitId}', '{linkId}', '{secret}', '{token}'];

    /**
     * Routes hors de l'espace municipal : justification obligatoire. Clé = préfixe de chemin (ou chemin exact avec `=`).
     *
     * @var array<string, string>
     */
    private const OUTSIDE = [
        '=/' => 'accueil',
        '=/login' => 'connexion',
        '=/logout' => 'déconnexion',
        '/forgot-password' => 'réinitialisation de mot de passe : lien lié à l\'utilisateur, aucun lien d\'événement',
        '/reset-password' => 'réinitialisation de mot de passe',
        '=/communes' => 'sélecteur de commune (aucune donnée de dossier)',
        '/v/' => 'contrôle public : « Événement terminé » seul, test dédié (lecteur du contrôle public)',
        '/laissez-passer/' => 'lien bénéficiaire : fermé par BeneficiaryPassReader, test dédié',
        '/demandes/' => 'dépôt public : page fermée sans donnée, dépôt refusé, test dédié',
        '/invitation' => 'invitation intercommunale : liens révoqués à la clôture, test dédié',
        '/admin' => 'Sonata : aucune entité de demande (test dédié)',
        '/dev/ui' => 'maquettes, environnement dev seulement',
        '/_' => 'routes internes de Symfony (profiler, erreurs)',
    ];

    /** Familles de commune qui portent des dossiers. */
    private const DOSSIER_FAMILIES = ['demandes', 'delivrances', 'corrections-identite'];

    public function classify(string $path): RouteClosure
    {
        $commune = '/communes/{municipalityId}';
        if (str_starts_with($path, $commune.'/')) {
            $rest = substr($path, \strlen($commune) + 1);
            $family = explode('/', $rest, 2)[0];
            if ('archives' === $family || 'retention' === $family) {
                return RouteClosure::Reserved;
            }
            if ('evenements' === $family) {
                return RouteClosure::Exempt;
            }
            if (\in_array($family, self::DOSSIER_FAMILIES, true)) {
                return str_contains($path, '{requestId}') ? RouteClosure::Guarded : RouteClosure::BusinessGuarded;
            }

            return RouteClosure::Unclassified;
        }

        return null === $this->justification($path) ? RouteClosure::Unclassified : RouteClosure::Public;
    }

    /** Justification d'une route hors espace municipal, pour le test de matrice. */
    public function justification(string $path): ?string
    {
        foreach (self::OUTSIDE as $prefix => $why) {
            if ('=' === $prefix[0] ? substr($prefix, 1) === $path : str_starts_with($path, $prefix)) {
                return $why;
            }
        }

        return null;
    }
}
