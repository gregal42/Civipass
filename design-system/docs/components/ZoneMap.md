> **Obsolète — LOT UI-02.** Décision définitive : aucune cartographie en V1 (brief LOT 02 §3.2, SFD §1.3). Ne pas utiliser ce composant ; il est conservé pour mémoire et sera retiré de l’archive à la prochaine version majeure.

Aperçu cartographique d’une zone, en lecture seule, dans la limite de la commune.

**Gabarit :** `components/zone_map.html.twig`

```twig
{% include 'components/zone_map.html.twig' with { id: 'zone-sfn', title: 'Secteur forestier nord, dans Le Temple',
  shapes: [ { kind: 'commune', d: 'M30 40 …Z', label: 'Le Temple', lx: 286, ly: 74 },
            { kind: 'zone', d: 'M58 52 …Z', label: 'Secteur forestier nord', lx: 92, ly: 90 } ],
  caption: 'Tracé du 31/10/2026' } only %}
```

## Utilisation

- Affiché seulement si un tracé existe. Sans tracé (`shapes` vide), le composant affiche un état vide qui le dit : jamais de carte inventée.
- Le serveur fournit des chemins SVG déjà projetés dans le `viewBox` (aucun fond de carte, aucune bibliothèque cartographique). Le format source des tracés (GeoJSON, import, dessin) n’est pas tranché : voir les points à arbitrer.
- Zone : contour plein + hachures. Limite communale : tirets. Autres zones : contour fin. La forme ne dépend jamais de la couleur seule, et le nom de la zone est toujours écrit dans la page à côté de la carte.
- `title` donne le nom accessible de l’image (`role="img"`).

## À éviter

- Une carte comme seul moyen de comprendre un périmètre : la liste des zones et leur description restent la référence.
- Une saisie ou une modification de tracé : hors périmètre V1.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
