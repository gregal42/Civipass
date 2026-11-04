import { Controller } from '@hotwired/stimulus';

// Recherche dynamique d'une commune à inviter (référentiel national) : dès que le champ « q » contient au moins `min`
// caractères, le fragment HTML des résultats est demandé au serveur (jamais de données côté client) puis remplace la zone
// des résultats. Sans JavaScript, le formulaire GET de recherche reste fonctionnel. La commune cochée est conservée si elle
// figure encore dans les nouveaux résultats.
export default class extends Controller {
  static targets = ['results'];
  static values = { url: String, min: { type: Number, default: 2 } };

  connect() {
    this.timer = null;
    this.sequence = 0;
    this.input = this.element.querySelector('input[name="q"]');
    this.onInput = () => this.schedule();
    this.input?.addEventListener('input', this.onInput);
  }

  disconnect() {
    this.input?.removeEventListener('input', this.onInput);
    clearTimeout(this.timer);
  }

  schedule() {
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.search(), 250);
  }

  async search() {
    const query = this.input.value.trim();
    if (query.length < this.minValue) {
      return;
    }
    const ticket = ++this.sequence;
    const checked = this.resultsTarget.querySelector('input[name="insee_code"]:checked')?.value;
    try {
      const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
      if (!response.ok || ticket !== this.sequence) {
        return; // réponse périmée ou erreur : on garde l'affichage actuel (le formulaire GET reste disponible)
      }
      this.resultsTarget.innerHTML = await response.text();
      if (checked) {
        const again = this.resultsTarget.querySelector(`input[name="insee_code"][value="${CSS.escape(checked)}"]:not([disabled])`);
        if (again) {
          again.checked = true;
        }
      }
      const hidden = this.element.querySelector('form#invite-form input[name="q"]');
      if (hidden) {
        hidden.value = query;
      }
    } catch (error) {
      // Réseau indisponible : rien n'est modifié.
    }
  }
}
