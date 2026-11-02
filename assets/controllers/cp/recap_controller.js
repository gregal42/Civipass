// Généré depuis src/controllers/cp/recap_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  connect() {
    this.update();
  }
  update() {
    for (const line of this.lineTargets) {
      const out = line.querySelector("[data-recap-value]") ?? line;
      const when = line.dataset.when ? this.element.querySelector(`#${CSS.escape(line.dataset.when)}`) : null;
      if (when && !when.checked) {
        out.textContent = "\u2014";
        out.classList.remove("cp-recap__missing");
        continue;
      }
      let text = "";
      if (line.dataset.group) {
        text = Array.from(this.element.querySelectorAll(`input[name="${CSS.escape(line.dataset.group)}"]:checked:not(:disabled)`)).map((i) => i.dataset.recapLabel ?? i.closest("label")?.querySelector(".cp-choice__label")?.textContent?.trim() ?? i.value).join(line.dataset.sep ?? ", ");
      } else if (line.dataset.format) {
        text = line.dataset.format.replace(/\{([\w-]+)\}/g, (_, id) => this.fieldValue(id));
      }
      const missing = !text.trim();
      out.textContent = missing ? line.dataset.empty ?? "\u2014" : text;
      out.classList.toggle("cp-recap__missing", missing);
    }
  }
  fieldValue(id) {
    const el = this.element.querySelector(`#${CSS.escape(id)}`);
    if (!el || !el.value) return "";
    if (el.type === "date") {
      const [y, m, d] = el.value.split("-");
      return `${d}/${m}/${y}`;
    }
    return el.value;
  }
}
stdin_default.targets = ["line"];
export {
  stdin_default as default
};
