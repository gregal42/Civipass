// Avertissement d'expiration de session (LOT 08.3). Le serveur décide ; ce contrôleur n'avertit que WARNING secondes avant, vérifie l'état réel
// (un autre onglet a pu prolonger la session) puis propose de rester connecté. Sans JavaScript : la session expire quand même, côté serveur.
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { remaining: Number, warning: Number, stateUrl: String, extendUrl: String, token: String, loginUrl: String };
    static targets = ['banner', 'count', 'extend', 'note'];

    connect() {
        this.deadline = Date.now() + this.remainingValue * 1000;
        this.tick = window.setInterval(() => this.update(), 1000);
        this.update();
    }

    disconnect() {
        window.clearInterval(this.tick);
    }

    update() {
        const left = Math.round((this.deadline - Date.now()) / 1000);
        if (left <= 0) {
            window.clearInterval(this.tick);
            window.location.assign(this.loginUrlValue);
            return;
        }
        if (left <= this.warningValue) {
            if (!this.confirming) {
                this.confirming = true;
                this.confirmState();
            }
            this.render(left);
        }
    }

    async confirmState() {
        try {
            const r = await fetch(this.stateUrlValue, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (r.status === 401) { window.location.assign(this.loginUrlValue); return; }
            const s = await r.json();
            this.deadline = Date.now() + s.remaining * 1000;
            this.extendable = s.extendable;
        } catch (e) { /* hors réseau : on garde l'échéance connue */ }
        this.confirming = false;
    }

    render(left) {
        this.bannerTarget.hidden = false;
        this.countTarget.textContent = `${Math.floor(left / 60)} min ${String(left % 60).padStart(2, '0')} s`;
        const can = this.extendable !== false;
        this.extendTarget.hidden = !can;
        this.noteTarget.textContent = can ? 'Votre session va expirer faute d’activité.' : 'Votre session atteint sa durée maximale : enregistrez votre travail, puis reconnectez-vous.';
    }

    async extend() {
        const body = new URLSearchParams({ _token: this.tokenValue });
        const r = await fetch(this.extendUrlValue, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (r.status === 401) { window.location.assign(this.loginUrlValue); return; }
        const s = await r.json();
        this.deadline = Date.now() + s.remaining * 1000;
        this.bannerTarget.hidden = true;
    }
}
