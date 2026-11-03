// Généré depuis src/controllers/cp/unsaved_guard_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
import { confirmAction } from "./events.js";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.onVisit = (e) => this.beforeVisit(e);
    this.onUnload = (e) => {
      if (this.dirty) {
        e.preventDefault();
        e.returnValue = "";
      }
    };
    this.bypass = false;
    this.mark = () => {
      this.element.dataset.dirty = "true";
    };
    this.clear = () => {
      delete this.element.dataset.dirty;
    };
  }
  connect() {
    this.element.addEventListener("input", this.mark);
    this.element.addEventListener("change", this.mark);
    this.element.addEventListener("submit", this.clear);
    this.element.addEventListener("reset", this.clear);
    document.addEventListener("turbo:before-visit", this.onVisit);
    window.addEventListener("beforeunload", this.onUnload);
  }
  disconnect() {
    document.removeEventListener("turbo:before-visit", this.onVisit);
    window.removeEventListener("beforeunload", this.onUnload);
  }
  get dirty() {
    return this.element.dataset.dirty === "true";
  }
  beforeVisit(e) {
    if (!this.dirty || this.bypass || !this.element.isConnected || this.element.closest("[hidden]")) return;
    e.preventDefault();
    const url = e.detail.url;
    confirmAction({
      title: this.titleValue,
      message: this.messageValue,
      confirmLabel: this.confirmValue,
      cancelLabel: this.cancelValue,
      onConfirm: () => {
        this.bypass = true;
        this.clear();
        window.Turbo?.visit(url) ?? (window.location.href = url);
      }
    });
  }
}
stdin_default.values = {
  title: { type: String, default: "Quitter sans enregistrer ?" },
  message: { type: String, default: "Votre d\xE9cision n\u2019a pas \xE9t\xE9 confirm\xE9e. Les choix saisis dans le formulaire seront perdus." },
  confirm: { type: String, default: "Quitter sans enregistrer" },
  cancel: { type: String, default: "Rester sur le dossier" }
};
export {
  stdin_default as default
};
