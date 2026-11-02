// Généré depuis src/controllers/cp/zone_select_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.sync = () => {
      this.element.querySelectorAll('[data-role="item"]').forEach((item) => {
        const commune = item.querySelector('[data-role="commune"]');
        const body = item.querySelector('[data-role="body"]');
        const zones = item.querySelector('[data-role="zones"]');
        const on = commune ? commune.checked : true;
        if (body) {
          body.hidden = !on;
          body.querySelectorAll("input").forEach((i) => i.disabled = !on || i.dataset.locked === "true");
        }
        const scope = item.querySelector('[data-role="scope"]:checked');
        if (zones) {
          const show = on && scope?.value === "zones";
          zones.hidden = !show;
          zones.querySelectorAll("input").forEach((i) => i.disabled = !show || i.dataset.locked === "true");
        }
      });
    };
  }
  connect() {
    this.element.addEventListener("change", this.sync);
    this.sync();
  }
  disconnect() {
    this.element.removeEventListener("change", this.sync);
  }
}
export {
  stdin_default as default
};
