// Généré depuis src/controllers/cp/repeat_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.next = 0;
  }
  connect() {
    this.next = this.rows().length;
    this.refresh();
  }
  add() {
    if (this.maxValue && this.rows().length >= this.maxValue) return;
    const html = this.templateTarget.innerHTML.replaceAll("__INDEX__", String(this.next++));
    this.listTarget.insertAdjacentHTML("beforeend", html);
    const all = this.rows();
    const row = all[all.length - 1];
    row?.querySelector("input")?.focus();
    this.statusTarget.textContent = `${this.cap(this.nounValue)} ${this.rows().length} ajout\xE9e.`;
    this.refresh();
  }
  remove(e) {
    const row = e.currentTarget.closest("[data-repeat-row]");
    if (!row) return;
    if (this.rows().length === 1) {
      row.querySelectorAll("input").forEach((i) => {
        i.value = "";
      });
      row.querySelector("input")?.focus();
      this.statusTarget.textContent = `${this.cap(this.nounValue)} effac\xE9e.`;
    } else {
      row.remove();
      this.addTarget.focus();
      this.statusTarget.textContent = `${this.cap(this.nounValue)} retir\xE9e. ${this.rows().length} restante${this.rows().length > 1 ? "s" : ""}.`;
    }
    this.refresh();
  }
  rows() {
    return Array.from(this.listTarget.querySelectorAll("[data-repeat-row]"));
  }
  refresh() {
    this.rows().forEach((r, i) => r.querySelectorAll("[data-repeat-num]").forEach((n) => {
      n.textContent = String(i + 1);
    }));
    if (this.maxValue) this.addTarget.hidden = this.rows().length >= this.maxValue;
  }
  cap(s) {
    return s.charAt(0).toUpperCase() + s.slice(1);
  }
}
stdin_default.targets = ["list", "template", "add", "status"];
stdin_default.values = { noun: { type: String, default: "ligne" }, max: { type: Number, default: 0 } };
export {
  stdin_default as default
};
