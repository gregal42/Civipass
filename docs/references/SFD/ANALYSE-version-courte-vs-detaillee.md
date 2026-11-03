# Analyse : SFD V1.0 courte vs SFD V1.0 détaillée

Statut : analyse documentaire. La version courte est **conservée** ([`SFD-V1.0-courte.docx`](SFD-V1.0-courte.docx)) ; la version détaillée ([`SFD-V1.0-detaille.docx`](SFD-V1.0-detaille.docx)) est la référence fonctionnelle principale.

## Méthode

Extraction du texte des deux `.docx` (`textutil`), normalisation des lignes, comparaison ordonnée par script.

## Résultats

| Constat | Détail |
|---|---|
| Lignes de la version courte retrouvées à l'identique et dans le même ordre dans la détaillée | 416 / 416 |
| Exigence présente **uniquement** dans la version courte | Aucune |
| Contradiction entre les deux versions | Aucune |
| Contenu propre à la version détaillée | Un bloc de 1 077 lignes : Annexes A (écrans SCR-001 à SCR-057), B (matrice des droits), C (recettes REC-001 à REC-015) et D (points ouverts) |

La version détaillée contient donc la version courte plus les annexes. Rien à arbitrer ni à intégrer depuis la version courte.

## Observations sur les identifiants

- La version courte numérote ses écrans « Écran NN » et ses recettes REC-01 à REC-12 (§10.1).
- La version détaillée conserve ces deux séries et ajoute SCR-001 à SCR-057 (même numéro que l'écran correspondant) et une **seconde série de recettes REC-001 à REC-015** (Annexe C). Deux séries de recettes cohabitent donc dans la même version (incohérence de numérotation à signaler, non corrigée ici).

## Limites

- Comparaison sur le texte extrait, pas sur la mise en forme.
- L'équivalence du PDF ([`SFD-V1.0-detaille.pdf`](SFD-V1.0-detaille.pdf)) avec le `.docx` n'est pas vérifiée (pas d'outil d'extraction PDF disponible) ; le PDF est daté d'une minute avant le `.docx`. Le `.docx` fait foi jusqu'à vérification.
