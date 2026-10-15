// Ladeplan-Sheet: rechnet beim Tippen, wann das Laden etwa startet (aus dem, was zum Ziel fehlt, und der Ladeleistung),
// was es kostet und wie viel Sonne an jedem gewählten Tag bis zur Uhrzeit erwartet wird. Die Werte kommen als data-*
// vom Server (partials/plan-form.php); gespeichert wird per Formular.
import { $, $$ } from '../core/dom.js';
import { clock, dayLabel, fmt, NNBSP, timeLabel } from '../core/format.js';

const EFFICIENCY = 0.92;
const num = (value) => {
  const n = parseFloat(String(value ?? '').replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};
const parse = (text, fallback) => {
  try {
    return JSON.parse(text || '') ?? fallback;
  } catch {
    return fallback;
  }
};

function bind(form) {
  const d = form.dataset;
  const days = parse(d.days, {});
  const sun = parse(d.sun, []);
  const now = Number(d.now) * 1000;
  const power = num(d.power);
  const price = num(d.price);
  const soc = num(d.soc);
  const capacity = num(d.capacity);
  const range = num(d.range);
  const use = num(d.consumption);

  // Was zum Ziel fehlt, in kWh an der Wallbox, mit Ladeverlust; null, wenn Werte des Autos fehlen.
  const need = () => {
    const type = $('input[name="type"]:checked', form)?.value;
    if (type === 'soc') {
      const target = num($('input[name="soc"]', form)?.value);
      return target === null || soc === null || capacity === null ? null : (Math.max(0, target - soc) / 100) * capacity / EFFICIENCY;
    }
    if (type === 'range') {
      const km = num($('input[name="km"]', form)?.value);
      return km === null || range === null || use === null ? null : (Math.max(0, km - range) * use) / 100 / EFFICIENCY;
    }
    return num($('input[name="kwh"]', form)?.value);
  };
  // Je gewähltem Tag die nächste Uhrzeit; ist sie heute schon vorbei, die in einer Woche.
  const deadlines = () => {
    const [h, m] = ($('input[name="time"]', form)?.value || '').split(':').map(Number);
    if (!Number.isFinite(h) || !Number.isFinite(m)) return [];
    return $$('input[name="days[]"]:checked', form)
      .map((box) => {
        const start = Number(days[box.value]) * 1000;
        let at = start + (h * 3600 + m * 60) * 1000;
        let from = start;
        if (at <= now) {
          at += 7 * 86400000;
          from += 7 * 86400000;
        }
        return { day: Number(box.value), at, from };
      })
      .sort((a, b) => a.at - b.at);
  };
  const sunUntil = (from, at) => {
    let sum = 0;
    let known = false;
    for (const [t, kw] of sun) {
      const a = Math.max(t * 1000, from, now);
      const b = Math.min(t * 1000 + 3600000, at);
      if (t * 1000 + 3600000 > from && t * 1000 < at) known = true;
      if (b > a) sum += (kw * (b - a)) / 3600000;
    }
    return known ? sum : null;
  };

  const update = () => {
    const kwh = need();
    const list = deadlines();
    const start = $('[data-plan-start]', form);
    const cost = $('[data-plan-cost]', form);
    if (start) {
      if (!list.length) start.textContent = 'Bitte mindestens einen Wochentag wählen.';
      else if (kwh === null || !power) start.textContent = 'Wann das Laden startet, rechnet EMS, sobald Ladestand, Akku oder Reichweite des Autos bekannt sind.';
      else {
        const seconds = (kwh / power) * 3600;
        const next = list[0];
        start.textContent = kwh < 0.05
          ? 'Das Ziel ist schon erreicht.'
          : `Startet etwa ${dayLabel(next.at - seconds * 1000)} ${timeLabel(next.at - seconds * 1000)}: ${fmt(kwh, 1)}${NNBSP}kWh in ${clock(seconds)} mit ${fmt(power, 1)}${NNBSP}kW, wenn die Sonne vorher nichts beiträgt.`;
      }
    }
    if (cost) {
      cost.textContent = kwh === null || price === null
        ? ''
        : `Kosten ≈ ${fmt((kwh * price) / 100, 2)}${NNBSP}€ für ${fmt(kwh, 1)}${NNBSP}kWh aus dem Netz zu ${d.dynamic ? 'Ø ' : ''}${fmt(price, 1)}${NNBSP}ct/kWh${d.dynamic ? ' der nächsten 24 Stunden' : ''}.`;
    }
    const box = $('[data-plan-sun]', form);
    if (box) {
      box.replaceChildren(...list.map(({ at, from }) => {
        const item = document.createElement('li');
        const kwhSun = sunUntil(from, at);
        item.textContent = `${dayLabel(at)} bis ${timeLabel(at)}: ${kwhSun === null ? 'noch keine Prognose' : `≈ ${fmt(kwhSun, 1)}${NNBSP}kWh Sonne`}`;
        return item;
      }));
    }
  };
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
}

export function initPlans(root = document) {
  for (const form of $$('[data-plan-form]', root)) bind(form);
}
