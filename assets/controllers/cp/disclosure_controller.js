// Généré depuis src/controllers/cp/disclosure_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
import { confirmAction } from "./events.js";
class stdin_default extends Controller {
  open(e) {
    const name = e.params?.panel ?? "";
    const current = this.openPanel;
    if (current && current.dataset.panel !== name && this.isDirty(current)) {
      confirmAction({ title: "Changer de d\xE9cision ?", message: "Les choix saisis dans le formulaire ouvert seront perdus.", confirmLabel: "Changer de d\xE9cision", onConfirm: () => this.show(name) });
      return;
    }
    this.show(name);
  }
  close() {
    const current = this.openPanel;
    if (current && this.isDirty(current)) {
      confirmAction({ title: "Abandonner la saisie ?", message: "Votre d\xE9cision n\u2019a pas \xE9t\xE9 confirm\xE9e. Les choix saisis seront perdus.", confirmLabel: "Abandonner", onConfirm: () => this.show(null) });
      return;
    }
    this.show(null);
  }
  get openPanel() {
    return this.panelTargets.find((p) => !p.hidden) ?? null;
  }
  isDirty(panel) {
    return panel.querySelector('form[data-dirty="true"]') !== null;
  }
  show(name) {
    for (const p of this.panelTargets) {
      const on = p.dataset.panel === name;
      if (!on && !p.hidden) p.querySelector("form")?.reset();
      p.querySelectorAll("form").forEach((f) => {
        if (!on) delete f.dataset.dirty;
      });
      p.hidden = !on;
    }
    for (const t of this.triggerTargets) t.setAttribute("aria-expanded", String(t.getAttribute("data-cp--disclosure-panel-param") === name));
    for (const h of this.hideWhenOpenTargets) h.hidden = name !== null;
    const panel = this.openPanel;
    if (panel) {
      panel.scrollIntoView({ behavior: matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "start" });
      window.setTimeout(() => panel.querySelector("input:not([type=hidden]):not(:disabled), textarea, select")?.focus({ preventScroll: true }), 250);
    }
  }
}
stdin_default.targets = ["panel", "trigger", "hideWhenOpen"];
export {
  stdin_default as default
};
