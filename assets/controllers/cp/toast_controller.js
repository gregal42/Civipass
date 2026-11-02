// Généré depuis src/controllers/cp/toast_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.onToast = (e) => this.show(e.detail.message);
  }
  connect() {
    window.addEventListener("cp:toast", this.onToast);
  }
  disconnect() {
    window.removeEventListener("cp:toast", this.onToast);
  }
  show(message) {
    const el = document.createElement("div");
    el.className = "cp-toast";
    el.textContent = message;
    this.element.appendChild(el);
    window.setTimeout(() => el.remove(), this.durationValue);
  }
}
stdin_default.values = { duration: { type: Number, default: 4e3 } };
export {
  stdin_default as default
};
