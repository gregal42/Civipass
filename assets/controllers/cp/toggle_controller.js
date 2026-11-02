// Généré depuis src/controllers/cp/toggle_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  toggle() {
    const open = this.panelTarget.hidden;
    this.panelTarget.hidden = !open;
    this.buttonTarget.setAttribute("aria-expanded", String(open));
    if (open) this.panelTarget.querySelector("input, select, button")?.focus();
  }
}
stdin_default.targets = ["button", "panel"];
export {
  stdin_default as default
};
