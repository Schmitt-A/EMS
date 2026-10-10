// Live-Werte: fragt /api/live alle 5 s ab, solange die Seite sichtbar ist und Live-Elemente hat.
// Texte, Zahlen mit Einheit und Phasen werden direkt geschrieben; Komponenten hören per onLive().
import { $, $$, base, getJson, pick } from './dom.js';
import { writeMetric } from './format.js';

const listeners = new Set();
const INTERVAL = 5000;
const SAY_EVERY = 30000;
let timer = 0;
let lastSaid = 0;
let lastSay = '';
let lastOk = null;

export const onLive = (listener) => listeners.add(listener);

const changed = (node) => {
  node.removeAttribute('data-changed');
  void node.offsetWidth;
  node.setAttribute('data-changed', '');
};

export function applyLive(data) {
  for (const node of $$('[data-live]')) {
    const value = pick(data, node.dataset.live);
    if (value === undefined || value === null || typeof value === 'object') continue;
    const text = String(value);
    if (node.textContent !== text) {
      node.textContent = text;
      changed(node);
    }
  }
  for (const node of $$('[data-live-num]')) {
    const value = pick(data, node.dataset.liveNum);
    if (value === undefined) continue;
    const before = node.textContent;
    writeMetric(node, value, node.dataset.unit || '', Number(node.dataset.decimals ?? 1));
    if (node.textContent !== before) changed(node);
  }
  for (const node of $$('[data-live-phases]')) {
    const value = pick(data, node.dataset.livePhases);
    if (value === undefined) continue;
    node.dataset.active = String(value);
    node.setAttribute('aria-label', value > 0 ? `${value}-phasig` : 'keine Phase aktiv');
  }
  for (const node of $$('[data-live-hide]')) {
    const value = pick(data, node.dataset.liveHide);
    node.hidden = value === undefined || value === null || value === '' || value === false;
  }
  connection(data);
  say(data);
  for (const listener of listeners) listener(data);
}

function connection(data) {
  const banner = $('[data-banner]');
  const offline = !data || data.connected === false;
  document.documentElement.toggleAttribute('data-offline', offline);
  if (!banner) return;
  banner.hidden = !offline;
  const text = $('[data-banner-text]', banner);
  if (text && offline) {
    const stamp = lastOk ? ` Letzter Wert ${lastOk}.` : '';
    text.textContent = (data && data.error ? data.error : 'Keine Verbindung zu Home Assistant.') + stamp;
  }
  if (!offline && data?.fetched_at) lastOk = data.fetched_at;
}

function say(data) {
  const region = $('[data-live-say]');
  if (!region || !data?.say) return;
  const now = Date.now();
  if (data.say === lastSay || now - lastSaid < SAY_EVERY) return;
  lastSay = data.say;
  lastSaid = now;
  region.textContent = data.say;
}

async function tick() {
  clearTimeout(timer);
  try {
    applyLive(await getJson(`${base()}/api/live`));
  } catch {
    connection(null);
  }
  if (!document.hidden) timer = setTimeout(tick, INTERVAL);
}

export function startLive() {
  if (!$('[data-live], [data-live-num], [data-flow], [data-energy-flow], [data-chargepoint], [data-battery-col]')) return;
  lastSaid = Date.now();
  timer = setTimeout(tick, INTERVAL);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) tick();
    else clearTimeout(timer);
  });
}
