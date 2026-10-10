// Ladeziel-Sheet: unter Energie, Uhrzeit, Ladestand und Reichweite die Kilometer und die Dauer, beim Tippen neu
// gerechnet aus den data-Werten des Formulars (Verbrauch kWh/100 km, bisher geladen, Leistung, Ladestand, Akku,
// Reichweite). Wie Energy::kmFromKwh(): 8 % der Energie ab Wallbox gehen beim Laden verloren.
import { $, $$ } from '../core/dom.js';
import { clock, withUnit } from '../core/format.js';

const EFFICIENCY = 0.92;
const MISSING = 'Für Kilometer fehlt der Verbrauch: unter Einstellungen → Fahrzeug eintragen oder Reichweite und Ladestand des Autos zuordnen.';

const num = (value) => {
  if (value === null || value === undefined || String(value).trim() === '') return null;
  const n = Number(String(value).replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};
const km = (kwh, use) => (kwh === null || !use ? null : Math.round((Math.max(0, kwh) * EFFICIENCY) / use * 100));
const kmText = (value) => withUnit(value, 'km', 0);

// Sekunden bis HH:MM; eine vergangene Uhrzeit meint morgen, wie Target::build().
function secondsUntil(text) {
  const match = /^(\d{1,2}):(\d{2})/.exec(text || '');
  if (!match) return null;
  const now = new Date();
  const at = new Date(now);
  at.setHours(Number(match[1]), Number(match[2]), 0, 0);
  if (at <= now) at.setDate(at.getDate() + 1);
  return (at - now) / 1000;
}

function update(form) {
  const d = form.dataset;
  const use = num(d.consumption);
  const now = num(d.kwhNow) ?? 0;
  const rate = num(d.rate);
  const soc = num(d.soc);
  const capacity = num(d.capacity);
  const range = num(d.range);
  const set = (panel, text) => {
    const node = $(`[data-target-km="${panel}"]`, form);
    if (node && node.textContent !== text) node.textContent = text;
  };

  const energy = num($('[name="kwh"]', form)?.value);
  if (!use) set('energy', MISSING);
  else if (energy !== null) {
    const more = now >= 0.05 ? `, ab jetzt ≈ ${kmText(km(Math.max(0, energy - now), use))} dazu` : '';
    set('energy', `Das sind ≈ ${kmText(km(energy, use))}${more}.`);
  }

  const seconds = secondsUntil($('[name="until"]', form)?.value);
  if (seconds !== null && rate) {
    const kwh = now + (rate * seconds) / 3600;
    set('time', `Bis dahin bei ${withUnit(rate, 'kW')} ≈ ${withUnit(kwh, 'kWh')}${use ? ` ≈ ${kmText(km(kwh, use))}` : ''} seit dem Anstecken.`);
  } else {
    set('time', 'Wie viel bis dahin zusammenkommt, zeigt sich, sobald die Wallbox lädt.');
  }

  const goal = num($('[name="soc"]', form)?.value);
  if (goal !== null && soc !== null) {
    if (!use || !capacity) set('soc', MISSING);
    else {
      const add = Math.round((Math.max(0, goal - soc) / 100) * capacity / use * 100);
      const at = range !== null && soc >= 1 ? Math.round((range / soc) * goal) : Math.round((goal / 100) * capacity / use * 100);
      set('soc', `Reichweite dann ≈ ${kmText(at)}, ab jetzt ≈ ${kmText(add)} dazu.`);
    }
  }

  const target = num($('[name="km"]', form)?.value);
  if (target !== null && range !== null) {
    const left = Math.max(0, target - range);
    if (left === 0) set('range', `Das Auto zeigt schon ${kmText(range)}.`);
    else {
      const need = use ? (left * use) / 100 / EFFICIENCY : null;
      const time = need !== null && rate ? (need / rate) * 3600 : null;
      set('range', `Noch ${kmText(left)}${need !== null ? `, ≈ ${withUnit(need, 'kWh')}` : ''}${time !== null ? `, ca. ${clock(time)}` : ''}.`);
    }
  }
}

export function initTargets(root = document) {
  for (const form of $$('[data-target-form]', root)) {
    update(form);
    form.addEventListener('input', () => update(form));
    form.addEventListener('change', () => update(form));
  }
}
