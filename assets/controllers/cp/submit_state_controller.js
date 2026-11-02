// Généré depuis src/controllers/cp/submit_state_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.btn = null;
    this.html = "";
    this.start = (e) => {
      if (e.defaultPrevented) return;
      const b = e.submitter ?? this.element.querySelector("button[type=submit]");
      if (!b) return;
      this.btn = b;
      this.html = b.innerHTML;
      b.disabled = true;
      b.setAttribute("aria-busy", "true");
      b.innerHTML = `<span class="cp-spinner" aria-hidden="true"></span> ${b.dataset.loadingLabel ?? "Envoi en cours\u2026"}`;
      this.element.querySelectorAll("button:not([type=submit])").forEach((x) => x.disabled = true);
    };
    this.end = () => {
      if (!this.btn) return;
      this.btn.disabled = false;
      this.btn.removeAttribute("aria-busy");
      this.btn.innerHTML = this.html;
      this.btn = null;
      this.element.querySelectorAll("button:not([type=submit])").forEach((x) => x.disabled = false);
    };
  }
  connect() {
    this.element.addEventListener("submit", this.start);
    this.element.addEventListener("turbo:submit-end", this.end);
  }
  disconnect() {
    this.element.removeEventListener("submit", this.start);
    this.element.removeEventListener("turbo:submit-end", this.end);
  }
}
export {
  stdin_default as default
};
