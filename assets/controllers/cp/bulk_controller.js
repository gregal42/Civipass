// Généré depuis src/controllers/cp/bulk_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  connect() {
    this.update();
  }
  toggleAll() {
    const on = this.hasAllTarget && this.allTarget.checked;
    this.itemTargets.forEach((i) => {
      if (!i.disabled) i.checked = on;
    });
    this.update();
  }
  selectAll() {
    this.itemTargets.forEach((i) => {
      if (!i.disabled) i.checked = true;
    });
    this.update();
  }
  update() {
    const items = this.itemTargets.filter((i) => !i.disabled);
    const n = items.filter((i) => i.checked).length;
    if (this.hasAllTarget) {
      this.allTarget.checked = n > 0 && n === items.length;
      this.allTarget.indeterminate = n > 0 && n < items.length;
    }
    if (this.hasCountTarget) {
      const noun = this.countTarget.dataset.noun ?? "\xE9l\xE9ment";
      this.countTarget.textContent = n === 0 ? `Aucune ${noun} s\xE9lectionn\xE9e` : `${n} ${noun}${n > 1 ? "s" : ""} s\xE9lectionn\xE9e${n > 1 ? "s" : ""}`;
    }
    this.actionTargets.forEach((b) => {
      b.disabled = n === 0;
    });
    const sel = this.selected();
    const configured = sel.filter((x) => x.configured).length;
    this.summaryTargets.forEach((t) => {
      t.textContent = n === 0 ? "Aucune s\xE9lection." : `${n} s\xE9lectionn\xE9e${n > 1 ? "s" : ""} : ${sel.map((x) => x.label).join(", ")}.${configured ? ` ${configured} ${configured > 1 ? "ont" : "a"} d\xE9j\xE0 une validit\xE9 : une confirmation sera demand\xE9e avant de la remplacer.` : ""}`;
    });
    this.element.dispatchEvent(new CustomEvent("cp:bulk-change", { bubbles: true, detail: { count: n } }));
  }
  selected() {
    return this.itemTargets.filter((i) => i.checked && !i.disabled).map((i) => ({ value: i.value, label: i.dataset.label ?? i.value, configured: i.dataset.configured === "true" }));
  }
}
stdin_default.targets = ["item", "all", "count", "action", "summary"];
export {
  stdin_default as default
};
