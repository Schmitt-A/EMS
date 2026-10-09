// Zahlen nach de-DE, zwischen Zahl und Einheit ein schmales, nicht umbrechendes Leerzeichen.
export const NNBSP = ' ';
const formats = new Map();

export function fmt(value, decimals = 1) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';
  if (!formats.has(decimals)) {
    formats.set(decimals, new Intl.NumberFormat('de-DE', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }));
  }
  return formats.get(decimals).format(Number(value));
}

export const withUnit = (value, unit, decimals = 1) => (value === null || value === undefined ? '—' : `${fmt(value, decimals)}${NNBSP}${unit}`);

/** Schreibt Zahl und Einheit als zwei Spans in node, wie metric() in PHP. */
export function writeMetric(node, value, unit, decimals = 1) {
  const number = document.createElement('span');
  number.className = 'num';
  number.textContent = fmt(value, decimals);
  if (value === null || value === undefined) {
    node.replaceChildren(number);
    return;
  }
  const unitNode = document.createElement('span');
  unitNode.className = 'unit';
  unitNode.textContent = unit;
  node.replaceChildren(number, NNBSP, unitNode);
}

export function clock(seconds) {
  if (seconds === null || seconds === undefined) return '—';
  const s = Math.max(0, Math.round(seconds));
  return `${Math.floor(s / 3600)}:${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}${NNBSP}h`;
}

const timeFormat = new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Berlin' });
const dayFormat = new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', timeZone: 'Europe/Berlin' });
const weekdayFormat = new Intl.DateTimeFormat('de-DE', { weekday: 'short', timeZone: 'Europe/Berlin' });

export const timeLabel = (ms) => timeFormat.format(new Date(ms));
export const dayLabel = (ms) => dayFormat.format(new Date(ms)).replace(/\.,/, '');
export const weekday = (ms) => weekdayFormat.format(new Date(ms)).replace('.', '');
