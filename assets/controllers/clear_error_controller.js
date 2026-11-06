import { Controller } from '@hotwired/stimulus';

// Efface un message d'erreur de validation (affiché par le serveur) dès que la saisie correspondante devient valide, sans attendre
// un nouvel envoi : `watch` est le sélecteur des entrées qui, une fois l'une d'elles cochée, rendent l'erreur caduque ;
// `error` est le sélecteur du message. Le serveur revalide toujours à l'envoi : ce contrôleur n'est qu'un confort d'affichage.
export default class extends Controller {
  static values = { watch: String, error: String };

  clear() {
    if (!this.element.querySelector(`${this.watchValue}:checked`)) return;
    const error = this.element.querySelector(this.errorValue);
    if (!error) return;
    const id = error.id;
    error.remove();
    if (id) {
      this.element.querySelectorAll('[aria-describedby]').forEach((el) => {
        const ids = el.getAttribute('aria-describedby').split(' ').filter((x) => x && x !== id);
        ids.length ? el.setAttribute('aria-describedby', ids.join(' ')) : el.removeAttribute('aria-describedby');
      });
    }
  }
}
