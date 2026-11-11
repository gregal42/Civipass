<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/**
 * Détecte une PLAQUE d'immatriculation dans un commentaire libre (LOT 05.5.4, affiné au 05.5.6 : décision D1 du 11 novembre 2026). Pure, sans état. Une plaque ne doit jamais
 * être conservée dans un commentaire (elle l'est à part, anonymisable) ; la détection est un garde-fou serveur, le contrôle des plaques CONNUES du laissez-passer et de l'opération
 * (quelle que soit la casse ou la présentation) se fait en plus dans `PermitVehicleService`.
 *
 *  - Forme SIV (« AB-123-CD ») : caractéristique (deux lettres, trois chiffres, deux lettres) : détectée, SAUF la phrase « article ou préposition + nombre + unité » en minuscules avec des
 *    espaces simples (« de 200 kg », « en 150 km », « du 300 cm »), qui a la même forme sans être une plaque.
 *  - Forme FNI ancienne (« 1234 AB 56 » : 3 ou 4 chiffres, 1 à 3 lettres, département à deux chiffres, 2A ou 2B) : ressemble à de la prose chiffrée (« 1200 kg 45 passagers », « 1234 du 12 juin »).
 *    Elle est détectée SAUF si les lettres sont un mot courant (unité, article, préposition) écrit en MINUSCULES et séparé des chiffres par UNE SEULE espace ordinaire de chaque côté.
 *    Toute autre présentation reste détectée : majuscules, tirets, points, soulignés, barres obliques, espaces insécables ou doublées, forme collée (« 1200kg45 »), lettres qui ne sont pas
 *    dans la liste. Il n'existe donc pas de contournement par une simple mise en forme ; la seule plaque non détectée est une plaque FNI dont les lettres sont exactement un de ces mots,
 *    saisie en minuscules avec des espaces simples — et la plaque du laissez-passer reste, elle, toujours refusée (contrôle des plaques connues).
 *  - Séparateurs reconnus d'une plaque : espaces (y compris insécables), tirets de toute nature (U+2010 à U+2015), point, souligné, barre oblique — jusqu'à deux à la suite.
 */
final class PlateInCommentDetector
{
    private const SEPARATOR = '[\s\p{Z}\-\x{2010}-\x{2015}._\/]{0,2}';

    /**
     * Mots courants qui, en minuscules entre deux espaces simples, signalent une quantité ou une phrase et non une plaque : unités, articles, prépositions.
     *
     * @var list<string>
     */
    private const PROSE = ['g', 'h', 'l', 'm', 't', 'kg', 'km', 'cm', 'mm', 'ml', 'cc', 'cv', 'du', 'de', 'des', 'en', 'au', 'aux', 'le', 'la', 'les', 'un', 'une', 'et', 'ou', 'par', 'sur'];

    /** @var list<string> articles et prépositions de deux lettres (partie gauche d'une phrase « de 200 kg ») */
    private const PROSE_WORDS = ['de', 'du', 'en', 'au', 'le', 'la', 'un', 'et', 'ou'];

    /** @var list<string> unités de deux lettres (partie droite) */
    private const PROSE_UNITS = ['kg', 'km', 'cm', 'mm', 'ml', 'cc', 'cv'];

    public static function containsPlate(string $comment): bool
    {
        if (false === preg_match_all('/(?<![\p{L}\d])([A-Z]{2})('.self::SEPARATOR.')\d{3}('.self::SEPARATOR.')([A-Z]{2})(?![\p{L}\d])/iu', $comment, $sivMatches, \PREG_SET_ORDER)) {
            return true;
        }
        foreach ($sivMatches as $match) {
            if (!self::isProseSiv($match[1], $match[2], $match[3], $match[4])) {
                return true;
            }
        }
        if (false === preg_match_all('/(?<![\p{L}\d])\d{3,4}('.self::SEPARATOR.')([A-Z]{1,3})('.self::SEPARATOR.')(?:\d{2}|2[AB])(?![\p{L}\d])/iu', $comment, $matches, \PREG_SET_ORDER)) {
            return true; // en cas d'erreur d'analyse : refuser (jamais laisser passer une plaque)
        }
        foreach ($matches as $match) {
            if (!self::isProse($match[1], $match[2], $match[3])) {
                return true;
            }
        }

        return false;
    }

    private static function isProseSiv(string $words, string $left, string $right, string $unit): bool
    {
        return ' ' === $left && ' ' === $right && $words === mb_strtolower($words) && $unit === mb_strtolower($unit) && \in_array($words, self::PROSE_WORDS, true) && \in_array($unit, self::PROSE_UNITS, true);
    }

    private static function isProse(string $left, string $letters, string $right): bool
    {
        return ' ' === $left && ' ' === $right && $letters === mb_strtolower($letters) && \in_array($letters, self::PROSE, true);
    }
}
