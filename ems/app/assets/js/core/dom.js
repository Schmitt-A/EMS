// Kleine DOM-Helfer, Basis-Pfad unter HA-Ingress und JSON-Anfragen mit CSRF-Token.
export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

export const base = () => document.documentElement.dataset.base || '';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

export async function postJson(path, data) {
  const response = await fetch(base() + path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ ...data, _csrf: csrf() }),
  });
  if (!response.ok) throw new Error(`Status ${response.status}`);
  return response.json();
}

export async function getJson(url) {
  const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error(`Status ${response.status}`);
  return response.json();
}

export const pick = (object, path) => path.split('.').reduce((value, key) => (value == null ? undefined : value[key]), object);

export const reducedMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

export function svg(tag, attrs = {}, parent = null) {
  const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value !== null && value !== undefined && value !== false) node.setAttribute(key, String(value));
  }
  if (parent) parent.append(node);
  return node;
}

export function html(tag, attrs = {}, text = null) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value !== null && value !== undefined && value !== false) node.setAttribute(key, String(value));
  }
  if (text !== null) node.textContent = text;
  return node;
}

export function icon(name, className = 'icon-16') {
  const sprite = document.documentElement.dataset.icons || '';
  const node = svg('svg', { class: `icon ${className}`, 'aria-hidden': 'true', focusable: 'false' });
  svg('use', { href: `${sprite}#${name}` }, node);
  return node;
}
