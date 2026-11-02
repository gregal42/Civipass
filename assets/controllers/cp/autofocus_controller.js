// Généré depuis src/controllers/cp/autofocus_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  connect() {
    if (!this.element.hasAttribute("tabindex")) this.element.tabIndex = -1;
    requestAnimationFrame(() => {
      this.element.focus({ preventScroll: true });
      this.element.scrollIntoView({ block: "center" });
    });
  }
}
export {
  stdin_default as default
};
