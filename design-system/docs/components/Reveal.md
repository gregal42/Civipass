# Reveal (`cp--reveal`) — PROPOSITION LOT 03

Affichage conditionnel dans un formulaire, sans logique métier : le serveur applique les mêmes règles.

```twig
<form data-controller="cp--reveal" data-action="change->cp--reveal#update">
  … radios name="mode" (foot | vehicle) …
  <fieldset data-cp--reveal-target="section" data-when="mode=vehicle" hidden>…</fieldset>   {# masqué et désactivé sinon #}
  <textarea data-cp--reveal-target="required" data-when="motif=autre"></textarea>          {# required + aria-required #}
</form>
```
Les champs d’une section masquée sont désactivés : ils ne sont pas envoyés.
