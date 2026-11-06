// Généré depuis src/controllers/cp/reveal_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  connect() {
    this.update();
  }
  update() {
    for (const s of this.sectionTargets) {
      const on = this.matches(s.dataset.when ?? "");
      s.hidden = !on;
      s.querySelectorAll("input, select, textarea, button").forEach((i) => {
        i.disabled = !on;
      });
    }
    for (const f of this.requiredTargets) {
      const on = this.matches(f.dataset.when ?? "");
      f.required = on;
      f.setAttribute("aria-required", String(on));
    }
  }
  matches(rule) {
    const [name, value] = rule.split("=");
    return Array.from(this.element.querySelectorAll(`input[name="${CSS.escape(name)}"]:checked`)).some((i) => i.value === value);
  }
}
stdin_default.targets = ["section", "required"];
export {
  stdin_default as default
};
