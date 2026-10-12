// Entscheidungshilfe unter Prognose → Strompreis: rechnet beim Tippen wie PriceView::decision(), aus den Mitteln der
// letzten Monate (data-flat, data-window), dem statischen Preis und den Grundpreisen im Jahr.
import { $, $$ } from '../core/dom.js';
import { fmt, NNBSP } from '../core/format.js';

const euro = (value) => `${fmt(value, 2)}${NNBSP}€`;
const kwh = (value) => `${fmt(value, 0)}${NNBSP}kWh`;
const read = (input) => {
  const value = Number(String(input?.value ?? '').trim().replace(',', '.'));
  return Number.isFinite(value) && value >= 0 ? Math.min(value, 100000) : null;
};

export function initDecide(root = document) {
  for (const box of $$('[data-decide]', root)) {
    const form = $('[data-decide-form]', box);
    const verdict = $('[data-decide-verdict]', box);
    const detail = $('[data-decide-detail]', box);
    if (!form || !verdict || !detail) continue;
    const flat = Number(box.dataset.flat);
    const win = Number(box.dataset.window);
    const fixedCt = Number(box.dataset.static);
    const base = Number(box.dataset.base) || 0;
    const dynBase = Number(box.dataset.dynBase) || 0;
    const run = () => {
      const w = read(form.elements.fenster);
      const r = read(form.elements.rest);
      if (w === null || r === null) return;
      const dynamic = (w * win + r * flat) / 100 + dynBase;
      const fixed = ((w + r) * fixedCt) / 100 + base;
      const diff = fixed - dynamic;
      const saved = fixedCt - win;
      verdict.textContent = diff >= 0
        ? `Dynamisch wäre im Jahr etwa ${euro(diff)} günstiger.`
        : `Statisch wäre im Jahr etwa ${euro(-diff)} günstiger.`;
      const even = saved > 0.01 ? Math.max(0, Math.round(((r * (flat - fixedCt)) / 100 + dynBase - base) / (saved / 100))) : null;
      let tail = `Dynamisch lohnt sich ab etwa ${kwh(even)} im Ladefenster.`;
      if (even === null) tail = 'Das Ladefenster liegt nicht unter deinem statischen Preis, Verschieben bringt dynamisch nichts.';
      else if (even <= 0) tail = 'Dynamisch ist hier schon ohne Verschieben günstiger.';
      detail.textContent = `Dynamisch ${euro(dynamic)}, statisch ${euro(fixed)} im Jahr, mit Grundpreisen. ${tail}`;
    };
    form.addEventListener('input', run);
    // Mit Skript rechnet die Karte beim Tippen; der Knopf bleibt nur ohne Skript.
    const submit = $('[data-decide-submit]', form);
    if (submit) submit.closest('.form-actions').hidden = true;
  }
}
