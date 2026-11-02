// Généré depuis src/controllers/cp/dropdown_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.onDoc = (e) => {
      if (!this.element.contains(e.target)) this.close();
    };
    this.onKey = (e) => this.keydown(e);
  }
  connect() {
    this.element.addEventListener("keydown", this.onKey);
  }
  disconnect() {
    this.element.removeEventListener("keydown", this.onKey);
    document.removeEventListener("click", this.onDoc);
  }
  get items() {
    return Array.from(this.menuTarget.querySelectorAll('[role^="menuitem"]'));
  }
  get isOpen() {
    return !this.menuTarget.hidden;
  }
  toggle() {
    this.isOpen ? this.close(true) : this.open();
  }
  open() {
    this.menuTarget.hidden = false;
    this.buttonTarget.setAttribute("aria-expanded", "true");
    document.addEventListener("click", this.onDoc);
    (this.items.find((i) => i.getAttribute("aria-checked") === "true") ?? this.items[0])?.focus();
  }
  close(returnFocus = false) {
    if (!this.isOpen) return;
    this.menuTarget.hidden = true;
    this.buttonTarget.setAttribute("aria-expanded", "false");
    document.removeEventListener("click", this.onDoc);
    if (returnFocus) this.buttonTarget.focus();
  }
  keydown(e) {
    if (!this.isOpen) return;
    const items = this.items;
    const i = items.indexOf(document.activeElement);
    if (e.key === "Escape") {
      e.preventDefault();
      this.close(true);
    } else if (e.key === "ArrowDown") {
      e.preventDefault();
      items[(i + 1) % items.length]?.focus();
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      items[(i - 1 + items.length) % items.length]?.focus();
    } else if (e.key === "Home") {
      e.preventDefault();
      items[0]?.focus();
    } else if (e.key === "End") {
      e.preventDefault();
      items[items.length - 1]?.focus();
    } else if (e.key === "Tab") this.close();
  }
}
stdin_default.targets = ["button", "menu"];
export {
  stdin_default as default
};
