// Généré depuis src/controllers/cp/plate_list_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
import { toast } from "./events.js";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.vehicle = null;
    this.onVehicle = () => this.syncVehicle();
    this.onToggle = (e) => {
      const i = e.target;
      if (i.type !== "checkbox" || !this.listTarget.contains(i)) return;
      i.closest("label")?.querySelector(".cp-plate")?.classList.toggle("cp-plate--off", !i.checked);
    };
  }
  connect() {
    this.vehicle = this.vehicleValue ? document.getElementById(this.vehicleValue) : null;
    this.vehicle?.addEventListener("change", this.onVehicle);
    this.element.addEventListener("change", this.onToggle);
    this.syncVehicle();
  }
  disconnect() {
    this.vehicle?.removeEventListener("change", this.onVehicle);
    this.element.removeEventListener("change", this.onToggle);
  }
  keydown(e) {
    if (e.key === "Enter") {
      e.preventDefault();
      this.add();
    }
  }
  add() {
    const v = this.inputTarget.value.trim().toUpperCase().replace(/\s+/g, "-");
    if (!v) {
      toast("Saisissez une immatriculation.");
      this.inputTarget.focus();
      return;
    }
    const exists = Array.from(this.listTarget.querySelectorAll("input[type=checkbox]")).some((i) => i.value === v);
    if (exists) {
      toast(`${v} figure d\xE9j\xE0 dans la liste.`);
      return;
    }
    const html = this.templateTarget.innerHTML.replaceAll("__VALUE__", v.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`));
    this.listTarget.insertAdjacentHTML("beforeend", html);
    this.inputTarget.value = "";
    this.inputTarget.focus();
    this.element.closest("form")?.dispatchEvent(new Event("change", { bubbles: true }));
  }
  syncVehicle() {
    const on = this.vehicle ? this.vehicle.checked : true;
    this.fieldsetTarget.disabled = !on;
    this.fieldsetTarget.hidden = !on;
    if (this.hasEmptyTarget) this.emptyTarget.hidden = on;
  }
}
stdin_default.targets = ["list", "input", "template", "fieldset", "empty"];
stdin_default.values = { vehicle: String };
export {
  stdin_default as default
};
