// Diagramme laden und zeichnen. Fenster, Einheit und Serien schalten die Werkzeuge über dem Diagramm.
import { $, $$, getJson } from '../core/dom.js';
import { renderBars } from './bars.js';
import { showEmpty } from './frame.js';
import { renderTime } from './time.js';

const renderers = { time: renderTime, bars: renderBars };

const parse = (text) => {
  try {
    return JSON.parse(text || '{}') || {};
  } catch {
    return {};
  }
};

const hasData = (payload) =>
  (payload.series || []).some((series) => (series.data || []).some((point) => {
    const v = point !== null && typeof point === 'object' ? point.y : point;
    return v !== null && v !== undefined && !Number.isNaN(Number(v));
  }));

function goodness(payload, state) {
  const score = payload.goodness?.[state.window || '3'];
  if (!score) return;
  for (const node of $$('[data-goodness-ratio]')) node.textContent = score.text;
  for (const node of $$('[data-goodness-days]')) node.textContent = String(score.days);
}

async function load(figure) {
  const render = renderers[figure.dataset.chart];
  if (!render) return;
  const state = {
    hidden: new Set(),
    style: parse(figure.dataset.style),
    // Wer vor dem Laden schon ein Fenster oder eine Einheit gewählt hat, bekommt genau das.
    window: $('[data-chart-window] input:checked', figure)?.value || figure.dataset.window || null,
    anchor: figure.dataset.anchor || 'today',
    unit: $('[data-chart-unit] input:checked', figure)?.value || null,
    first: true,
    jump: true,
  };
  let payload;
  try {
    payload = await getJson(figure.dataset.src);
  } catch {
    showEmpty(figure, 'Die Werte lassen sich gerade nicht laden.');
    return;
  }
  if (!hasData(payload)) {
    showEmpty(figure, payload.error || figure.dataset.empty || 'Noch keine Werte für dieses Diagramm.');
    return;
  }
  for (const series of payload.series || []) if (series.hidden && !payload.axes) state.hidden.add(series.key);
  const draw = (jump) => {
    state.jump = jump;
    render(figure, payload, state);
    state.first = false;
    goodness(payload, state);
  };
  draw(true);

  for (const fieldset of $$('[data-chart-window]', figure)) {
    fieldset.addEventListener('change', (event) => {
      state.window = event.target.value;
      draw(true);
    });
    // Ein erneuter Tipp auf das gewählte Fenster springt zurück zum heutigen Tag.
    fieldset.addEventListener('click', (event) => {
      if (event.target.matches('input') && event.target.value === state.window) draw(true);
    });
  }
  // Ein per Tippen gezeigter Wert verschwindet, sobald man außerhalb des Diagramms tippt.
  document.addEventListener('pointerdown', (event) => {
    if (!figure.contains(event.target)) figure._clearTip?.();
  }, { passive: true });
  for (const fieldset of $$('[data-chart-unit]', figure)) {
    fieldset.addEventListener('change', (event) => {
      state.unit = event.target.value;
      draw(false);
    });
  }
  for (const button of $$('[data-chart-series]', figure)) {
    button.addEventListener('click', () => {
      const key = button.dataset.chartSeries;
      const on = state.hidden.has(key);
      if (on) state.hidden.delete(key);
      else state.hidden.add(key);
      button.setAttribute('aria-pressed', String(on));
      draw(false);
    });
  }
  let width = $('.chart-frame', figure).clientWidth;
  let timer = 0;
  new ResizeObserver(() => {
    const next = $('.chart-frame', figure).clientWidth;
    if (Math.abs(next - width) < 2) return;
    width = next;
    clearTimeout(timer);
    timer = setTimeout(() => draw(true), 120);
  }).observe($('.chart-frame', figure));
}

export function initCharts(root = document) {
  const figures = $$('figure[data-chart]', root);
  if (!figures.length) return;
  const visible = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (!entry.isIntersecting) continue;
      visible.unobserve(entry.target);
      load(entry.target);
    }
  }, { rootMargin: '200px' });
  for (const figure of figures) {
    if (figure.closest('dialog')) {
      const dialog = figure.closest('dialog');
      dialog.addEventListener('ems:open', () => load(figure), { once: true });
    } else {
      visible.observe(figure);
    }
  }
}
