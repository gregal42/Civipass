import { Controller } from '@hotwired/stimulus';

/**
 * Copie un texte (ex. lien bénéficiaire) dans le presse-papiers. Sans API disponible, le champ est sélectionné pour une copie manuelle.
 * Targets : source (champ en lecture seule), button.
 */
export default class extends Controller {
    static targets = ['source', 'button'];

    async copy() {
        const field = this.sourceTarget;
        field.select();
        try {
            await navigator.clipboard.writeText(field.value);
            this.buttonTarget.querySelector('span').textContent = 'Lien copié';
        } catch {
            this.buttonTarget.querySelector('span').textContent = 'Sélectionné : copiez avec Ctrl+C';
        }
    }
}
