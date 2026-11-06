> **Composant UI-002, retenu par LOT UI-02.**

Navigation secondaire entre les rubriques d’un même objet : vue d’ensemble, demandes, communes participantes et paramètres d’un événement.

**Gabarit :** `components/subnav.html.twig`

```twig
{% include 'components/subnav.html.twig' with { label: 'Rubriques de l’événement', items: [
  { label: 'Vue d’ensemble', href: '…', current: true, icon: 'home' },
  { label: 'Communes participantes', href: '…', icon: 'users', count: 4 } ] } only %}
```

## Utilisation

- Ce sont des liens de page (`aria-current="page"`), pas des onglets : chaque rubrique a sa propre URL, compatible avec Turbo Drive et le bouton Retour.
- N’y passer que les rubriques permises au rôle (ex. « Paramètres » seulement pour un gestionnaire de l’organisatrice).
- En compact, la liste défile horizontalement dans son propre conteneur ; la page, elle, ne défile jamais latéralement.

## Différence avec Tabs

`Tabs` change un panneau dans la page sans changer d’URL. `SubNav` change de page.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.


## LOT 03 — compact
Sous 640 px, les rubriques se partagent la largeur avant de défiler : les trois onglets de la liste des demandes et leurs compteurs restent entiers à 375 px.
