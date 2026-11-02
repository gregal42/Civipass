// Généré depuis src/controllers/cp/dialog_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.pending = null;
    this.returnTo = null;
    this.onConfirmEvent = (e) => {
      e.preventDefault();
      this.ask(e.detail);
    };
  }
  connect() {
    if (this.globalValue) window.addEventListener("cp:confirm", this.onConfirmEvent);
  }
  disconnect() {
    window.removeEventListener("cp:confirm", this.onConfirmEvent);
  }
  ask(d) {
    this.titleTarget.textContent = d.title;
    this.messageTarget.textContent = d.message;
    this.confirmTarget.textContent = d.confirmLabel ?? "Confirmer";
    this.cancelTarget.textContent = d.cancelLabel ?? "Annuler";
    this.pending = d.onConfirm;
    this.returnTo = document.activeElement;
    this.element.showModal();
    this.cancelTarget.focus();
  }
  confirm() {
    const cb = this.pending;
    this.done();
    cb?.();
  }
  cancel() {
    this.done();
    this.returnTo?.focus();
  }
  done() {
    this.pending = null;
    if (this.element.open) this.element.close();
  }
}
stdin_default.targets = ["title", "message", "confirm", "cancel"];
stdin_default.values = { global: Boolean };
export {
  stdin_default as default
};
