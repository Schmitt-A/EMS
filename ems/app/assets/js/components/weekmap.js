// „Lohnt sich dynamisch?“: Heatmap nach Kalenderwochen, je Quartal eine Gruppe. Der Filter wählt die Kennzahl; je Feld
// stehen Farbstärke (data-v-*), kurze Zahl (data-n-*) und Text für Screenreader (data-t-*) schon im HTML, Farbe,
// Erklärung und kleinster und größter Wert für die Legende in data-metrics. Farbe und Schrift wie in der
// Statistik-Heatmap: --v über CSSOM, schwarz oder weiß nach der Helligkeit des Felds. Die Radios gehören zum Formular
// „Neu rechnen“ (form-Attribut), so bleibt die Kennzahl nach dem Rechnen stehen.
import { $, $$ } from '../core/dom.js';
import { ink } from './heatmap.js';

function paint(map, metric) {
  const meta = JSON.parse(map.dataset.metrics || '{}')[metric] || {};
  if (meta.color) map.dataset.color = meta.color;
  const cells = $$('.wk-cell', map);
  for (const cell of cells) {
    const v = cell.getAttribute(`data-v-${metric}`);
    const empty = v === null || v === '';
    cell.toggleAttribute('data-empty', empty);
    if (empty) cell.style.removeProperty('--v');
    else cell.style.setProperty('--v', v);
    const n = $('[data-wk-n]', cell);
    if (n) n.textContent = cell.getAttribute(`data-n-${metric}`) || '';
    const t = $('[data-wk-t]', cell);
    if (t) t.textContent = cell.getAttribute(`data-t-${metric}`) || '';
  }
  const caption = $('[data-wk-caption]', map);
  if (caption && meta.caption) caption.textContent = meta.caption;
  const lo = $('[data-wk-lo]', map);
  if (lo && meta.lo) lo.textContent = meta.lo;
  const hi = $('[data-wk-hi]', map);
  if (hi && meta.hi) hi.textContent = meta.hi;
  ink(cells);
}

export function initWeekMaps(root = document) {
  for (const map of $$('[data-weekmap]', root)) {
    const card = map.closest('section') || root;
    const filter = $('[data-week-metric]', card);
    const current = () => $('input:checked', filter)?.value || 'save';
    paint(map, current());
    filter?.addEventListener('change', () => paint(map, current()));
  }
}
