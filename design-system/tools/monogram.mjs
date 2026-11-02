// Monogramme Civipass « Cp » en SVG vectoriel, reproduit à partir du design system (composant .cp-logo__mark) :
// carré 30 px, rayon 8 px, fond `brand`, « Cp » en Atkinson Hyperlegible Mono Bold, 0,875 rem (14 px), couleur `on-brand`,
// centré comme le fait le CSS (place-items: center). Les glyphes sont convertis en TRACÉS : le SVG ne dépend d'aucune police
// ni d'aucune feuille de style (il sert de logo au back-office Sonata, qui ne charge pas le design system).
//
// Aucune forme n'est dessinée ici : les contours viennent de la police du design system (paquet @fontsource déclaré dans
// son package.json), lus directement dans le fichier WOFF.
import zlib from 'node:zlib';
import fs from 'node:fs';

function readWoff(buf) {
  if (buf.toString('latin1', 0, 4) !== 'wOFF') throw new Error('Fichier WOFF attendu.');
  const tables = {};
  for (let i = 0, n = buf.readUInt16BE(12); i < n; i++) {
    const o = 44 + i * 20;
    const [off, comp, orig] = [buf.readUInt32BE(o + 4), buf.readUInt32BE(o + 8), buf.readUInt32BE(o + 12)];
    const data = buf.subarray(off, off + comp);
    tables[buf.toString('latin1', o, o + 4)] = comp < orig ? zlib.inflateSync(data) : data;
  }
  return tables;
}

function glyphIndex(cmap, code) {
  const n = cmap.readUInt16BE(2);
  for (let i = 0; i < n; i++) {
    const [platform, encoding, offset] = [cmap.readUInt16BE(4 + i * 8), cmap.readUInt16BE(6 + i * 8), cmap.readUInt32BE(8 + i * 8)];
    if (!((platform === 3 && encoding === 1) || platform === 0) || cmap.readUInt16BE(offset) !== 4) continue;
    const segX2 = cmap.readUInt16BE(offset + 6); const seg = segX2 / 2;
    const end = offset + 14; const start = end + segX2 + 2; const delta = start + segX2; const range = delta + segX2;
    for (let s = 0; s < seg; s++) {
      if (code > cmap.readUInt16BE(end + s * 2) || code < cmap.readUInt16BE(start + s * 2)) continue;
      const ro = cmap.readUInt16BE(range + s * 2); const d = cmap.readInt16BE(delta + s * 2);
      if (ro === 0) return (code + d) & 0xffff;
      const g = cmap.readUInt16BE(range + s * 2 + ro + (code - cmap.readUInt16BE(start + s * 2)) * 2);
      return g === 0 ? 0 : (g + d) & 0xffff;
    }
  }
  throw new Error(`Glyphe introuvable pour U+${code.toString(16)}`);
}

function glyphContours(t, gid) {
  const head = t.head; const short = head.readInt16BE(50) === 0;
  const loca = (g) => (short ? t.loca.readUInt16BE(g * 2) * 2 : t.loca.readUInt32BE(g * 4));
  let o = loca(gid); const g = t.glyf;
  const nContours = g.readInt16BE(o);
  if (nContours < 0) throw new Error('Glyphe composite non pris en charge.');
  const ends = Array.from({ length: nContours }, (_, i) => g.readUInt16BE(o + 10 + i * 2));
  const nPoints = ends[ends.length - 1] + 1;
  o += 10 + nContours * 2; o += 2 + g.readUInt16BE(o);
  const flags = [];
  while (flags.length < nPoints) { const f = g[o++]; flags.push(f); if (f & 8) { for (let r = g[o++]; r > 0; r--) flags.push(f); } }
  const read = (short, same) => { const v = []; let cur = 0; for (const f of flags) {
    if (f & short) { const b = g[o++]; cur += f & same ? b : -b; } else if (!(f & same)) { cur += g.readInt16BE(o); o += 2; }
    v.push(cur); } return v; };
  const xs = read(2, 16); const ys = read(4, 32);
  const contours = []; let s = 0;
  for (const e of ends) { contours.push(Array.from({ length: e - s + 1 }, (_, i) => ({ x: xs[s + i], y: ys[s + i], on: !!(flags[s + i] & 1) }))); s = e + 1; }
  return contours;
}

// Contour TrueType (courbes quadratiques) → commandes de tracé SVG.
function toPath(contours, tx, ty) {
  const f = (v) => String(Math.round(v * 1000) / 1000);
  let d = '';
  for (const pts of contours) {
    const n = pts.length; let start;
    if (pts[0].on) start = pts[0]; else if (pts[n - 1].on) start = pts[n - 1]; else start = { x: (pts[0].x + pts[n - 1].x) / 2, y: (pts[0].y + pts[n - 1].y) / 2 };
    d += `M${f(tx(start.x))} ${f(ty(start.y))}`;
    let ctrl = null;
    const emit = (p) => { if (p.on) { d += ctrl ? `Q${f(tx(ctrl.x))} ${f(ty(ctrl.y))} ${f(tx(p.x))} ${f(ty(p.y))}` : `L${f(tx(p.x))} ${f(ty(p.y))}`; ctrl = null; }
      else { if (ctrl) { const m = { x: (ctrl.x + p.x) / 2, y: (ctrl.y + p.y) / 2 }; d += `Q${f(tx(ctrl.x))} ${f(ty(ctrl.y))} ${f(tx(m.x))} ${f(ty(m.y))}`; } ctrl = p; } };
    const body = pts[0].on ? pts.slice(1) : (pts[n - 1].on ? pts.slice(0, n - 1) : pts);
    for (const p of body) emit(p);
    // fermeture : retour au point de départ (courbe en attente comprise)
    if (ctrl) d += `Q${f(tx(ctrl.x))} ${f(ty(ctrl.y))} ${f(tx(start.x))} ${f(ty(start.y))}`;
    d += 'Z';
  }
  return d;
}

export function buildMonogramSvg(woffPath, { brand, onBrand }) {
  const t = readWoff(fs.readFileSync(woffPath));
  const upm = t.head.readUInt16BE(18);
  const asc = t.hhea.readInt16BE(4); const desc = t.hhea.readInt16BE(6); const nMetrics = t.hhea.readUInt16BE(34);
  const advance = (g) => t.hmtx.readUInt16BE(Math.min(g, nMetrics - 1) * 4);
  const fontSize = 14; const scale = fontSize / upm; const size = 30;
  const gids = [...'Cp'].map((c) => glyphIndex(t.cmap, c.charCodeAt(0)));
  const total = gids.reduce((s, g) => s + advance(g), 0) * scale;
  const baseline = size / 2 + ((asc + desc) * scale) / 2;
  let x0 = (size - total) / 2; let d = '';
  for (const g of gids) {
    const left = x0;
    d += toPath(glyphContours(t, g), (x) => left + x * scale, (y) => baseline - y * scale);
    x0 += advance(g) * scale;
  }
  return `<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="${size}" height="${size}" role="img" aria-label="Civipass">\n<title>Civipass</title>\n<rect width="${size}" height="${size}" rx="8" fill="${brand}"/>\n<path fill="${onBrand}" d="${d}"/>\n</svg>\n`;
}
