<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\Domain\PlateInCommentDetector;
use App\Permit\Domain\TokenReplacementRules;
use App\Permit\Domain\VerificationToken;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * RECETTE (LOT 05.5.6) : mesure des faux positifs et des faux négatifs des détecteurs de commentaires sur un corpus de commentaires réalistes en français. Deux détecteurs : plaque
 * dans un commentaire de modification de plaques (LOT 05.5.4, affiné par la décision D1 du 11 novembre 2026) et jeton ou adresse de contrôle dans un commentaire de remplacement du QR
 * (LOT 05.5.5).
 *
 * Principe : AUCUN faux négatif sur les plaques et les présentations qui les dissimulent (une vraie plaque passée = donnée personnelle conservée) ; les phrases ordinaires et à quantités
 * ne sont pas refusées ; les faux positifs restants sont CONNUS, listés et figés ici (s'ils changent, le test échoue et la liste doit être relue). Aucune plaque ni jeton n'est réel.
 */
#[Group('recette')]
final class CommentDetectorCorpusTest extends TestCase
{
    /** @return list<string> commentaires légitimes (aucune plaque) */
    private static function ordinary(): array
    {
        return [
            'Véhicule vendu le mois dernier.',
            'Erreur de saisie à la création du dossier.',
            'Demande du bénéficiaire par téléphone le 12 juin.',
            'Changement de véhicule suite à une panne.',
            'Le bénéficiaire a un nouveau véhicule de société.',
            'Plaque erronée dans la demande initiale, corrigée sur justificatif.',
            'Voir pièce jointe n° 2026-0001.',
            'Référence du dossier LP-2026-0001.',
            'Rendez-vous à 12 h 30 en mairie.',
            'Rendez-vous à 12h30 en mairie.',
            'Intervention le 12/06/2026 de 8h à 18h.',
            'Appel au 06 11 22 33 44 sans réponse.',
            'Livraison de 12 palettes prévue.',
            'Camion de 3,5 t, hauteur 3 m 20.',
            'Véhicule utilitaire de 20 m3.',
            'Zone 12 secteur nord, accès par la rue 45.',
            'Convoi de 4 véhicules autorisés.',
            'Remplacement demandé par courriel du 11 juin.',
            'Ancienne remorque non utilisée cette année.',
            'Contrôle effectué par la police municipale.',
            'Arrêté municipal 2026-145 du 3 juin.',
            'Parking P2 niveau 3, place 118.',
            'Distance 120 km aller-retour.',
            'Pesée à 3 500 kg.',
            'Entreprise SARL Dupont, 2 véhicules.',
            'Véhicule de remplacement pendant la réparation.',
            'Retiré à la demande de l\'employeur.',
            'Doublon avec le dossier du 10 juin.',
            'Ajout suite à l\'embauche d\'un salarié.',
            'Bénéficiaire joint par SMS.',
        ];
    }

    /**
     * Phrases à QUANTITÉS (nombre, unité ou mot court en minuscules, nombre à deux chiffres) : elles ressemblent à une plaque FNI ancienne. Décision D1 : elles ne doivent PAS être refusées.
     *
     * @return list<string>
     */
    private static function quantities(): array
    {
        return [
            'Rendez-vous au point 100 km 35.',
            'Poids 1200 kg 45 passagers',
            'Livraison de 200 kg 12 palettes',
            'Remorque 750 kg 35 cv',
            'Convoi 300 m 20 véhicules',
            'Bus 2026 en 34 exemplaires',
            'Dossier 1234 du 12 juin',
            'Tronçon 120 km 50',
            'Vitesse 130 km 90',
            'Moteur 1600 cc 90',
            'Chargement de 450 kg 12 caisses et 800 kg 40 sacs',
            'Pas plus de 1500 kg 20 minutes',
        ];
    }

    /**
     * Faux positifs RESTANTS, connus et assumés : lettres en MAJUSCULES entre deux nombres (« place 118 BC 45 »), indiscernables d'une plaque FNI. L'agent reformule.
     *
     * @var list<string>
     */
    private const KNOWN_REMAINING_FALSE_POSITIVES = [
        'Parking niveau 3 place 118 BC 45',
        'Arrêté 2026-145 BC 12',
    ];

    /** @return list<string> commentaires qui contiennent une vraie plaque : DOIVENT être refusés */
    private static function withPlate(): array
    {
        return [
            'Ancienne plaque AB-123-CD.',
            'ancienne plaque ab 123 cd',
            'plaque AB123CD remplacée',
            'Véhicule gh-482-kt vendu.',
            'Remplace GH 482 KT par la nouvelle.',
            'Plaque : AB‑123‑CD (tiret insécable)',
            'véhicule 1234 AB 56 retiré',
            'immatriculation 1234-AB-56',
            'ancienne immatriculation 123 ABC 2A',
            'Le 4567ab75 est vendu',
            'plaques AB-123-CD et EF-456-GH',
            'XY-987-ZT',
            'AB.123.CD',
            'AB_123_CD',
            'AB/123/CD',
            "AB\u{00A0}123\u{00A0}CD",
            'AB–123–CD (tiret demi-cadratin)',
            'véhicule 1234 ab 56 retiré',
            'immatriculation 4567 xz 75',
            'plaque 123 abc 2b',
        ];
    }

    /**
     * Tentatives de DISSIMULATION d'une plaque FNI derrière l'exemption des phrases : toutes doivent rester refusées (aucun contournement évident par la mise en forme). Les lettres sont ici
     * celles d'un mot courant (« kg », « du », « en »), car seule cette famille est exemptée, et seulement en minuscules avec des espaces simples.
     *
     * @return list<string>
     */
    private static function disguised(): array
    {
        return [
            '1234 KG 56',          // majuscules
            '1234 Kg 56',          // casse mixte
            '1234-kg-56',          // tirets
            '1234.kg.56',          // points
            '1234_kg_56',          // soulignés
            '1234/kg/56',          // barres obliques
            '1234kg56',            // collée
            '1234  kg  56',        // espaces doublées
            "1234\u{00A0}kg\u{00A0}56", // espaces insécables
            '1234 kg-56',          // séparateurs mixtes
            '1234- kg 56',         // séparateur double à gauche
            'DE 200 KG',           // forme SIV en majuscules
            'de-200-kg',           // forme SIV avec tirets
            'de 200kg',            // forme SIV sans espace à droite
            'de  200  kg',         // forme SIV, espaces doublées
            'ab 123 cd',           // forme SIV dont les lettres ne sont pas des mots courants
            'en 123 ab',           // article puis lettres qui ne sont pas une unité
            '1234 du 56 et 4567 AB 12', // une phrase suivie d'une vraie plaque
            'poids 1200 kg 45 passagers puis 1234 ab 56', // quantité puis plaque
        ];
    }

    public function testNoRealPlateAndNoDisguisedPlateEverGoesThroughTheDetector(): void
    {
        $missed = array_values(array_filter([...self::withPlate(), ...self::disguised()], static fn (string $c): bool => !PlateInCommentDetector::containsPlate($c)));

        self::assertSame([], $missed, 'FAUX NÉGATIFS : un commentaire contenant (ou dissimulant) une plaque a été accepté');
    }

    public function testOrdinaryCommentsAreNeverRefusedByThePlateDetector(): void
    {
        $refused = array_values(array_filter(self::ordinary(), static fn (string $c): bool => PlateInCommentDetector::containsPlate($c)));

        self::assertSame([], $refused, sprintf('faux positifs sur %d commentaires ordinaires', \count(self::ordinary())));
    }

    public function testQuantityPhrasesLike1200Kg45PassengersAreNoLongerRefused(): void
    {
        $refused = array_values(array_filter(self::quantities(), static fn (string $c): bool => PlateInCommentDetector::containsPlate($c)));

        self::assertSame([], $refused, 'décision D1 : une quantité n\'est pas une plaque (« 1200 kg 45 passagers », « 1234 du 12 juin »)');
        self::assertFalse(PlateInCommentDetector::containsPlate('1200 kg 45 passagers'));
    }

    public function testTheRemainingFalsePositivesAreKnownAndPinned(): void
    {
        $refused = array_values(array_filter(self::KNOWN_REMAINING_FALSE_POSITIVES, static fn (string $c): bool => PlateInCommentDetector::containsPlate($c)));

        self::assertSame(self::KNOWN_REMAINING_FALSE_POSITIVES, $refused, 'ces phrases restent refusées (lettres en majuscules) : si cette liste change, la relire');
    }

    public function testASivPlateIsAlwaysDetectedWhateverItsSurroundings(): void
    {
        foreach (['AB-123-CD', 'plaque AB-123-CD', 'AB-123-CD vendue', '(AB-123-CD)', 'ab 123 cd', 'Remplace ab-123-cd par EF-456-GH'] as $comment) {
            self::assertTrue(PlateInCommentDetector::containsPlate($comment), $comment);
        }
    }

    public function testTheTokenDetectorHasNoFalseNegativeAndNoFalsePositiveOnOrdinaryComments(): void
    {
        $token = VerificationToken::generate();
        foreach ([$token, strtoupper($token), implode(' ', str_split($token, 8)), implode('-', str_split($token, 4)), implode('.', str_split($token, 6)), 'QR '.$token.' publié', 'https://exemple.fr/v/'.$token, '/v/abc'] as $withToken) {
            self::assertTrue(TokenReplacementRules::containsToken($withToken), 'FAUX NÉGATIF : '.$withToken);
        }
        $benign = [
            'QR code perdu par le bénéficiaire lors du déménagement.',
            'Photographié et publié sur un réseau social.',
            'Appareil volé le 12 juin, plainte déposée.',
            'Imprimé en trop grand nombre : ancien exemplaire jeté.',
            'Demande du bénéficiaire par téléphone.',
            'Référence LP-2026-0001, dossier 2026-0145.',
            'Le bénéficiaire a transmis le QR code à un tiers par erreur.',
            'Compromission suspectée : accès inhabituels signalés par la police municipale.',
            'Merci de contacter la mairie au 04 78 12 34 56 pour tout renseignement complémentaire sur ce dossier.',
            'abcdefghijklmnopqrstuvwxyz234567 est un exemple de 32 caractères',
        ];
        $falsePositives = array_values(array_filter($benign, static fn (string $c): bool => TokenReplacementRules::containsToken($c)));

        // le dernier commentaire EST un jeton de 32 caractères base32 (alphabet complet) : c'est un vrai positif, pas un faux
        self::assertSame(['abcdefghijklmnopqrstuvwxyz234567 est un exemple de 32 caractères'], $falsePositives, 'seul un véritable jeton (même cité en exemple) est refusé');
    }
}
