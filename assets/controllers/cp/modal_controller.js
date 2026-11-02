// Généré depuis src/controllers/cp/modal_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.returnTo = null;
    this.onDocClick = (e) => {
      const t = e.target.closest("[data-modal-open]");
      if (t && t.dataset.modalOpen === this.element.id) {
        e.preventDefault();
        this.open(t);
      }
    };
    this.onBackdrop = (e) => {
      if (e.target === this.element) this.close();
    };
    this.onClose = () => {
      this.returnTo?.focus();
      this.returnTo = null;
    };
  }
  connect() {
    document.addEventListener("click", this.onDocClick);
    this.element.addEventListener("click", this.onBackdrop);
    this.element.addEventListener("close", this.onClose);
  }
  disconnect() {
    document.removeEventListener("click", this.onDocClick);
    this.element.removeEventListener("click", this.onBackdrop);
    this.element.removeEventListener("close", this.onClose);
  }
  open(trigger) {
    this.returnTo = trigger ?? document.activeElement;
    this.element.showModal();
    this.element.querySelector("[autofocus], .cp-modal__body a, .cp-modal__body button, .cp-modal__body input")?.focus();
  }
  close() {
    if (this.element.open) this.element.close();
  }
}
export {
  stdin_default as default
};
