// Généré depuis src/controllers/cp/choice_trace_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.sync = (e) => {
      const i = e.target;
      if (i.hasAttribute("data-trace-on") || i.hasAttribute("data-trace-off")) this.apply(i);
    };
  }
  connect() {
    this.element.addEventListener("change", this.sync);
    this.element.querySelectorAll("input[data-trace-on], input[data-trace-off]").forEach((i) => this.apply(i));
  }
  disconnect() {
    this.element.removeEventListener("change", this.sync);
  }
  apply(i) {
    const note = i.closest("label")?.querySelector("[data-trace-note]");
    if (!note) return;
    note.textContent = (i.checked ? i.dataset.traceOn : i.dataset.traceOff) ?? "";
    note.classList.toggle("is-trace", i.checked === (i.dataset.traceWhen !== "unchecked"));
  }
}
export {
  stdin_default as default
};
