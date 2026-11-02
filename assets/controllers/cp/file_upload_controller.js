// Généré depuis src/controllers/cp/file_upload_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.files = [];
  }
  dragover(e) {
    e.preventDefault();
    this.zoneTarget.classList.add("is-over");
  }
  dragleave() {
    this.zoneTarget.classList.remove("is-over");
  }
  drop(e) {
    e.preventDefault();
    this.zoneTarget.classList.remove("is-over");
    this.add(Array.from(e.dataTransfer?.files ?? []));
  }
  change() {
    this.add(Array.from(this.inputTarget.files ?? []), true);
  }
  remove(e) {
    const i = Number(e.currentTarget.dataset.index);
    const [f] = this.files.splice(i, 1);
    this.sync();
    this.render();
    this.statusTarget.textContent = f ? `${f.name} retir\xE9.` : "";
  }
  add(list, replaceInput = false) {
    const merged = replaceInput ? [...this.files, ...list] : [...this.files, ...list];
    this.files = merged.filter((f, i, a) => a.findIndex((x) => x.name === f.name && x.size === f.size) === i);
    this.sync();
    this.render();
  }
  problem(f, i) {
    const ext = f.name.split(".").pop()?.toLowerCase() ?? "";
    if (!this.acceptValue.split(",").includes(ext)) return `Format non accept\xE9 (${this.acceptValue.toUpperCase().replaceAll(",", ", ")})`;
    if (f.size > this.maxSizeValue) return `Fichier trop lourd : ${this.size(this.maxSizeValue)} maximum`;
    if (i >= this.maxValue) return `${this.maxValue} fichiers maximum : ce fichier ne sera pas envoy\xE9`;
    return null;
  }
  sync() {
    const dt = new DataTransfer();
    this.files.forEach((f, i) => {
      if (!this.problem(f, i)) dt.items.add(f);
    });
    this.inputTarget.files = dt.files;
  }
  render() {
    this.listTarget.innerHTML = this.files.map((f, i) => {
      const err = this.problem(f, i);
      const esc = (s) => s.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
      return this.templateTarget.innerHTML.replaceAll("__NAME__", esc(f.name)).replaceAll("__INDEX__", String(i)).replaceAll("__META__", esc(err ?? `${f.name.split(".").pop()?.toUpperCase()} \xB7 ${this.size(f.size)}`)).replace('cp-file"', err ? 'cp-file cp-file--error"' : 'cp-file"');
    }).join("");
    const ok = this.files.filter((f, i) => !this.problem(f, i)).length;
    this.statusTarget.textContent = `${ok} fichier${ok > 1 ? "s" : ""} pr\xEAt${ok > 1 ? "s" : ""} \xE0 l\u2019envoi.`;
  }
  size(b) {
    return b >= 1048576 ? `${(b / 1048576).toFixed(1).replace(".", ",")} Mo` : `${Math.max(1, Math.round(b / 1024))} Ko`;
  }
}
stdin_default.targets = ["input", "zone", "list", "template", "status"];
stdin_default.values = { max: { type: Number, default: 5 }, maxSize: { type: Number, default: 10485760 }, accept: { type: String, default: "pdf,jpg,jpeg,png,doc" } };
export {
  stdin_default as default
};
