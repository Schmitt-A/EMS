// Einstellungen → Tarif: rechnet je Zeitfenster des statischen Tarifs beim Tippen den Preis im Fenster aus, aus dem
// Arbeitspreis oben, der Art (Arbeitspreis minus oder fester Preis) und dem Betrag. Gespeichert wird per Formular.
import { $, $$ } from '../core/dom.js';
import { fmt, NNBSP } from '../core/format.js';

const num = (value) => {
  const n = parseFloat(String(value ?? '').replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};
const ct = (value) => `${fmt(value, 1)}${NNBSP}ct/kWh`;

function update(form) {
  const base = num($('[name="import_ct"]', form)?.value);
  for (const card of $$('[data-tw]', form)) {
    const out = $('[data-tw-result]', card);
    if (!out) continue;
    const amount = num($('[data-tw-ct]', card)?.value);
    const kind = $('[data-tw-kind]', card)?.value;
    const uses = $$('[data-tw-for]:checked', card).map((box) => box.closest('label').textContent.trim());
    if (amount === null || base === null) {
      out.textContent = '';
      continue;
    }
    const price = kind === 'price' ? amount : Math.max(0, base - amount);
    out.textContent = `Im Fenster ${ct(price)} statt ${ct(base)}${uses.length ? ` für ${uses.join(' und ')}` : ', bitte noch wählen, wofür es gilt'}.`;
  }
}

export function initTariff(root = document) {
  for (const form of $$('[data-tariff-form]', root)) {
    form.addEventListener('input', () => update(form));
    form.addEventListener('change', () => update(form));
    update(form);
  }
}
