// Ladeplan des Speichers: rechnet beim Tippen das nächste Mal (Fenster des Tarifs oder Uhrzeit, an den gewählten Tagen
// oder einmalig), wann das Laden etwa startet (spät oder sofort ab Beginn) und was es kostet. Die Fenster mit ihrem
// Wochentag, Preis, Leistung und Speicherwerte kommen als data-* vom Server (partials/battery-plan-form.php).
import { $, $$ } from '../core/dom.js';
import { clock, dayLabel, fmt, NNBSP, timeLabel } from '../core/format.js';

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
const when = (ms) => `${dayLabel(ms)} ${timeLabel(ms)}`;

function bind(form) {
  const d = form.dataset;
  const windows = parse(d.windows, []);
  const days = parse(d.days, {});
  const now = Number(d.now) * 1000;
  const power = num(d.power);
  const price = num(d.price);
  const eta = num(d.eta) ?? 0.9;
  const soc = num(d.soc);
  const capacity = num(d.capacity);

  // Das nächste Mal: {from, at} in ms, from null bei einer Uhrzeit.
  const next = () => {
    const once = $('input[name="once"][type="checkbox"]', form)?.checked;
    const chosen = once ? [1, 2, 3, 4, 5, 6, 7] : $$('input[name="days[]"]:checked', form).map((box) => Number(box.value));
    if (!chosen.length) return { error: 'Bitte Wochentage wählen oder „Einmalig“.' };
    if ($('input[name="when"]:checked', form)?.value === 'window') {
      const hit = windows.find(([start, end, day]) => end * 1000 > now && chosen.includes(day));
      return hit ? { from: hit[0] * 1000, at: hit[1] * 1000 } : { error: d.noWindow };
    }
    const [h, m] = ($('input[name="time"]', form)?.value || '').split(':').map(Number);
    if (!Number.isFinite(h) || !Number.isFinite(m)) return { error: 'Bitte eine Uhrzeit angeben.' };
    const ats = chosen.map((day) => {
      let at = Number(days[day]) * 1000 + (h * 3600 + m * 60) * 1000;
      if (at <= now) at += 7 * 86400000;
      return at;
    });
    return { from: null, at: Math.min(...ats) };
  };

  const update = () => {
    const start = $('[data-plan-start]', form);
    const cost = $('[data-plan-cost]', form);
    const n = next();
    const target = num($('input[name="target_soc"]', form)?.value);
    const need = $('input[name="amount"]:checked', form)?.value === 'need';
    const early = $('input[name="start"]:checked', form)?.value === 'early';
    if (n.error) {
      if (start) start.textContent = n.error;
      if (cost) cost.textContent = '';
      return;
    }
    const label = n.from === null ? `bis ${when(n.at)}` : `im Fenster ${when(n.from)}–${timeLabel(n.at)}`;
    if (need) {
      if (start) start.textContent = `Als Nächstes ${label}. Wie viel, rechnet EMS jedes Mal neu: so viel, wie das Haus bis einen Tag danach mit Sonne und Speicher braucht, wenn es sich nach Verlust und Verschleiß lohnt, höchstens ${fmt(target, 0)}${NNBSP}%.`;
      if (cost) cost.textContent = '';
      return;
    }
    if (soc === null || capacity === null || !power || target === null) {
      if (start) start.textContent = `Als Nächstes ${label}. Wann das Laden startet, rechnet EMS, sobald Ladestand und Größe des Speichers bekannt sind.`;
      if (cost) cost.textContent = '';
      return;
    }
    const kwh = (Math.max(0, target - soc) / 100) * capacity / eta;
    if (kwh < 0.05) {
      if (start) start.textContent = `Als Nächstes ${label}. Der Speicher hat ${fmt(target, 0)}${NNBSP}% schon.`;
      if (cost) cost.textContent = '';
      return;
    }
    const seconds = (kwh / power) * 3600;
    const begin = n.from !== null && early ? Math.max(n.from, now) : Math.max(n.from ?? now, n.at - seconds * 1000);
    if (start) start.textContent = `Als Nächstes ${label}: lädt etwa ab ${when(begin)} ${fmt(kwh, 1)}${NNBSP}kWh in ${clock(seconds)} mit ${fmt(power, 1)}${NNBSP}kW, dazu was das Haus bis dahin nimmt.`;
    if (cost) {
      cost.textContent = price === null
        ? ''
        : `Kosten ≈ ${fmt((kwh * price) / 100, 2)}${NNBSP}€ zu ${d.dynamic ? 'Ø ' : ''}${fmt(price, 1)}${NNBSP}ct/kWh${d.window ? ' im Zeitfenster' : ''}.`;
    }
  };
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
}

export function initBatteryPlans(root = document) {
  for (const form of $$('[data-battery-plan]', root)) bind(form);
}
