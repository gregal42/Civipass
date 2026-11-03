// Généré depuis src/controllers/cp/validity_controller.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
import { Controller } from "@hotwired/stimulus";
const PARIS = new Intl.DateTimeFormat("en-GB", { timeZone: "Europe/Paris", timeZoneName: "shortOffset" });
class stdin_default extends Controller {
  constructor() {
    super(...arguments);
    this.onChange = () => this.refresh();
  }
  connect() {
    this.element.addEventListener("input", this.onChange);
    this.element.addEventListener("change", this.onChange);
    this.refresh();
  }
  disconnect() {
    this.element.removeEventListener("input", this.onChange);
    this.element.removeEventListener("change", this.onChange);
  }
  /** Recalcule l’affichage (appelable après un pré-remplissage par script). */
  refresh() {
    const scope = this.value('[name$="[scope]"]:checked') || "periods";
    if (this.hasPeriodsTarget) {
      this.periodsTarget.hidden = scope === "event";
      this.periodsTarget.querySelectorAll("input, select").forEach((i) => {
        i.disabled = scope === "event";
      });
    }
    if (scope === "event") {
      this.summaryTarget.textContent = `Pendant toute la dur\xE9e de l\u2019\xE9v\xE9nement${this.eventValue ? `, ${this.eventValue}` : ""}.`;
      this.show(this.dstTarget, "");
      if (this.hasClockTarget) this.show(this.clockTarget, "");
      return;
    }
    const mode = this.value('[name$="[mode]"]:checked') || "continuous";
    for (const g of this.groupTargets) {
      const on = g.dataset.mode === mode;
      g.hidden = !on;
      g.querySelectorAll("input, select").forEach((i) => {
        i.disabled = !on;
      });
    }
    if (this.hasDaysTarget) this.daysTarget.hidden = this.value('[name$="[every]"]:checked') !== "days";
    const d = this.describe(mode);
    this.summaryTarget.textContent = d.text;
    this.show(this.dstTarget, d.from && d.to ? this.dstChange(d.from, d.to) : "");
    if (this.hasClockTarget) this.show(this.clockTarget, d.from && d.to ? this.clockIssues(d.from, d.to, d.times, d.days) : "");
  }
  show(el, text) {
    el.textContent = text;
    el.hidden = !text;
  }
  value(selector) {
    return (this.element.querySelector(selector)?.value ?? "").trim();
  }
  field(suffix) {
    return (this.element.querySelector(`[id$="${suffix}"]`)?.value ?? "").trim();
  }
  fr(date) {
    const [y, m, d] = date.split("-");
    return y && m && d ? `${d}/${m}/${y}` : "";
  }
  list(items) {
    return items.length < 2 ? items.join("") : `${items.slice(0, -1).join(", ")} et ${items[items.length - 1]}`;
  }
  describe(mode) {
    const none = { text: "Compl\xE9tez le cr\xE9neau pour voir son r\xE9sum\xE9.", from: "", to: "", times: [], days: [] };
    if (mode === "continuous") {
      const sd = this.field("_c_start_date"), st = this.field("_c_start_time"), ed = this.field("_c_end_date"), et = this.field("_c_end_time");
      if (!sd || !st || !ed || !et) return none;
      return { text: `Du ${this.fr(sd)} \xE0 ${st} au ${this.fr(ed)} \xE0 ${et}, sans interruption.`, from: sd, to: ed, times: [st, et], days: [] };
    }
    if (mode === "single") {
      const d = this.field("_s_date"), f2 = this.field("_s_from"), t2 = this.field("_s_to");
      if (!d || !f2 || !t2) return none;
      return { text: `Le ${this.fr(d)}, de ${f2} \xE0 ${t2}.`, from: d, to: d, times: [f2, t2], days: [] };
    }
    const f = this.field("_r_from"), t = this.field("_r_to"), d1 = this.field("_r_start"), d2 = this.field("_r_end");
    const every = this.value('[name$="[every]"]:checked') || "day";
    const boxes = Array.from(this.element.querySelectorAll('input[name$="[days][]"]:checked'));
    if (!f || !t || !d1 || !d2 || every === "days" && !boxes.length) return none;
    if (f === t) return { text: "L\u2019heure de fin doit \xEAtre diff\xE9rente de l\u2019heure de d\xE9but.", from: "", to: "", times: [], days: [] };
    const who = every === "day" ? "Tous les jours" : `Les ${this.list(boxes.map((b) => `${b.dataset.day ?? ""}s`))}`;
    const overnight = t < f ? " (le lendemain)" : "";
    return { text: `${who}, de ${f} \xE0 ${t}${overnight}, du ${this.fr(d1)} au ${this.fr(d2)}.`, from: d1, to: d2, times: [f, t], days: every === "day" ? [1, 2, 3, 4, 5, 6, 7] : boxes.map((b) => Number(b.value)) };
  }
  offset(d) {
    return PARIS.formatToParts(d).find((p) => p.type === "timeZoneName")?.value ?? "";
  }
  /** Jours de changement d’heure (Europe/Paris) compris entre deux dates incluses. */
  transitions(from, to) {
    const start = /* @__PURE__ */ new Date(`${from}T12:00:00Z`), end = /* @__PURE__ */ new Date(`${to}T12:00:00Z`);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end < start) return [];
    const out = [];
    let prev = this.offset(start);
    for (let d = new Date(start), i = 0; i < 800; i++) {
      d.setUTCDate(d.getUTCDate() + 1);
      if (d > end) break;
      const cur = this.offset(d);
      if (cur !== prev) {
        out.push({ day: d.toISOString().slice(0, 10), summer: cur > prev });
        prev = cur;
      }
    }
    return out;
  }
  dstChange(from, to) {
    const t = this.transitions(from, to);
    return t.length ? `Changement d\u2019heure ${t.map((x) => `le ${this.fr(x.day)} (${x.summer ? "passage \xE0 l\u2019heure d\u2019\xE9t\xE9" : "retour \xE0 l\u2019heure d\u2019hiver"})`).join(" et ")}. Les horaires restent ceux de l\u2019heure de Paris.` : "";
  }
  /** Heures saisies entre 02:00 et 02:59 un jour de changement d’heure : inexistantes (été) ou ambiguës (hiver). */
  clockIssues(from, to, times, days) {
    const risky = times.filter((t) => t >= "02:00" && t < "03:00");
    if (!risky.length) return "";
    const msgs = [];
    for (const tr of this.transitions(from, to)) {
      const dow = ((/* @__PURE__ */ new Date(`${tr.day}T12:00:00Z`)).getUTCDay() + 6) % 7 + 1;
      if (days.length && !days.includes(dow)) continue;
      msgs.push(tr.summer ? `Le ${this.fr(tr.day)}, ${risky.join(" et ")} n\u2019existe pas (passage de 02:00 \xE0 03:00) : l\u2019heure retenue sera 03:00.` : `Le ${this.fr(tr.day)}, ${risky.join(" et ")} existe deux fois (retour de 03:00 \xE0 02:00) : la premi\xE8re occurrence, en heure d\u2019\xE9t\xE9, sera retenue.`);
    }
    return msgs.length ? `${msgs.join(" ")} R\xE8gle propos\xE9e, \xE0 confirmer dans les STD.` : "";
  }
}
stdin_default.targets = ["group", "summary", "dst", "clock", "days", "periods"];
stdin_default.values = { event: String };
export {
  stdin_default as default
};
