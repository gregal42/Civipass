// Généré depuis src/controllers/cp/tabs_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  select(e) {
    this.activate(e.currentTarget);
  }
  keydown(e) {
    const tabs = this.tabTargets;
    const i = tabs.indexOf(e.currentTarget);
    const next = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
    if (next === void 0) return;
    e.preventDefault();
    const t = tabs[(next + tabs.length) % tabs.length];
    this.activate(t);
    t.focus();
  }
  activate(tab) {
    for (const t of this.tabTargets) {
      const on = t === tab;
      t.setAttribute("aria-selected", String(on));
      t.tabIndex = on ? 0 : -1;
      const panel = document.getElementById(t.getAttribute("aria-controls") ?? "");
      if (panel) panel.hidden = !on;
    }
  }
}
stdin_default.targets = ["tab"];
export {
  stdin_default as default
};
