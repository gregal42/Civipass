// Généré depuis src/controllers/cp/events.ts du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :
// voir design-system/PROVENANCE.md.
function toast(message) {
  window.dispatchEvent(new CustomEvent("cp:toast", { detail: { message } }));
}
function confirmAction(detail) {
  const ev = new CustomEvent("cp:confirm", { detail, cancelable: true });
  if (window.dispatchEvent(ev)) console.warn("[civipass] aucune bo\xEEte de dialogue cp--dialog globale : action annul\xE9e.");
}
export {
  confirmAction,
  toast
};
