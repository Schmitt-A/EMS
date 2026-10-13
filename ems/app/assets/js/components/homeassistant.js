// EMS als App in Home Assistant. Home Assistant zeigt EMS in einem Rahmen unter derselben Adresse, EMS darf deshalb
// dessen Fenster lesen und nutzt die Nachrichten, die Home Assistant Apps anbietet (ha-panel-app, seit 2026.2):
// - Die Adresse oben folgt der Seite in EMS (…/<slug>/speicher, per home-assistant/navigate), und so eine Adresse
//   öffnet die Seite, aus Lesezeichen, Links und Mitteilungen. Hat EMS den Rahmen gerade erst bekommen (window.name
//   noch nicht gesetzt), gilt die Adresse oben, sonst die Seite in EMS; ändert Home Assistant die Adresse später selbst,
//   folgt EMS ihr. Die Adresse liest EMS aus dem Fenster, weil Home Assistant vor 2026.10 Apps aus der Seitenleiste in
//   home-assistant/properties einen falschen Pfad meldete.
// - ?vollbild in der Adresse schaltet den Kiosk-Modus für Apps ein (subscribe-properties mit kioskMode): ohne
//   Seitenleiste und Kopfzeile; das Menü öffnet der Knopf links vom Seitentitel (home-assistant/toggle-menu).
// - Unter Einstellungen → Darstellung wird EMS zur Startseite: default_panel in den Benutzer- oder Systemdaten des
//   Frontends, dieselbe Einstellung wie Profil → Dashboard und Einstellungen → Dashboards → Als Standard festlegen.
// Läuft EMS nicht in Home Assistant (Demo, eigener Server, ältere Versionen), tut das Modul nichts.
import { $, $$, base } from '../core/dom.js';

const KIOSK = 'vollbild';
const FRAME = 'ems';
const WAIT_MS = 2000;

const hassOf = (h) => h.win.document.querySelector('home-assistant')?.hass;

/** Home Assistant um EMS herum, sonst null: Fenster, Slug, Anfang der Adresse und ob EMS in der Seitenleiste steht. */
function host() {
  try {
    if (window.parent === window) return null;
    const win = window.parent;
    const hass = win.document.querySelector('home-assistant')?.hass;
    if (!hass?.panels) return null;
    const [, first, second] = win.location.pathname.split('/');
    const generic = first === 'app';
    const slug = generic ? second : first;
    if (!slug || !/^[a-z0-9_]+$/.test(slug)) return null;
    const sidebar = hass.panels[slug]?.config?.addon === slug;
    if (!generic && !sidebar) return null;
    return { win, slug, sidebar, prefix: generic ? `/app/${slug}` : `/${slug}` };
  } catch {
    return null;
  }
}

/** Seite mit Abfrage, ohne ?vollbild; / ist die Startseite von EMS. */
function normal(path, search) {
  const params = new URLSearchParams(search);
  params.delete(KIOSK);
  const query = params.toString();
  return `/${path.replace(/^\/+|\/+$/g, '')}${query ? `?${query}` : ''}`;
}

const here = () => normal(location.pathname.slice(base().length), location.search);
// Seite in der Adresse oben; null, wenn die Adresse nicht mehr zu EMS gehört.
function there(h) {
  const { pathname, search } = h.win.location;
  const rest = pathname.slice(h.prefix.length);
  if (!pathname.startsWith(h.prefix) || (rest !== '' && !rest.startsWith('/'))) return null;
  return normal(rest, search);
}

// Nur Seiten von EMS folgen, keine Schnittstellen.
const isPage = (path) => /^\/(?!api(?:[/?]|$))[a-z0-9_\-/]*(?:\?.*)?$/.test(path);

/** Adresse in Home Assistant für eine Seite von EMS, mit ?vollbild am Ende. */
function address(h, path, kiosk) {
  const [page, query = ''] = path.split('?');
  const search = [query, kiosk ? KIOSK : ''].filter(Boolean).join('&');
  return `${h.prefix}${page === '/' ? '' : page}${search ? `?${search}` : ''}`;
}

const send = (h, message) => h.win.postMessage(message, location.origin);

const state = { kiosk: false, pending: null };

/** Schreibt die Seite von EMS in die Adresse oben, ohne neuen Eintrag im Verlauf. */
function push(h) {
  const path = here();
  const target = address(h, path, state.kiosk);
  if (h.win.location.pathname + h.win.location.search === target) return;
  state.pending = { path, until: Date.now() + WAIT_MS };
  send(h, { type: 'home-assistant/navigate', path: target, options: { replace: true } });
}

/** Meldet EMS bei Home Assistant an, mit oder ohne Kiosk-Modus; die Abmeldung davor schaltet einen früheren aus. */
function subscribe(h) {
  if (!state.kiosk) send(h, { type: 'home-assistant/unsubscribe-properties' });
  send(h, { type: 'home-assistant/subscribe-properties', kioskMode: state.kiosk });
}

function showMenus() {
  for (const button of $$('[data-ha-menu]')) button.hidden = !state.kiosk;
}

// Ändert Home Assistant die Adresse selbst (Link, Mitteilung), öffnet EMS die Seite, ohne eigenen Eintrag im Verlauf;
// den hat Home Assistant schon angelegt. Solange die eigene Änderung unterwegs ist, kann noch eine ältere Meldung
// kommen; die zählt nicht.
function listen(h) {
  window.addEventListener('message', (event) => {
    if (event.source !== h.win || event.data?.type !== 'home-assistant/properties') return;
    const path = there(h);
    if (path === null) return;
    if (state.pending) {
      if (path !== state.pending.path && Date.now() < state.pending.until) return;
      state.pending = null;
    }
    if (path !== here() && isPage(path)) location.replace(base() + path);
  });
}

function panelTitle(hass, path) {
  const panel = hass?.panels?.[path];
  if (!panel) return path;
  return hass.localize?.(`panel.${panel.title}`) || panel.title || path;
}

const say = (node, text) => {
  if (node) node.textContent = text;
};

/** Startseite: Stand und Knöpfe für mich und, als Admin, für alle. */
function bindStart(h, root) {
  const status = $('[data-ha-start-status]', root);
  const mine = $('[data-ha-start="user"]', root);
  const all = $('[data-ha-start="system"]', root);
  const read = (scope) => {
    const hass = hassOf(h);
    return (scope === 'system' ? hass?.systemData : hass?.userData)?.default_panel || null;
  };
  const render = () => {
    const hass = hassOf(h);
    const user = read('user');
    const system = read('system');
    const shown = user || system || 'home';
    const whose = user ? 'deine Wahl im Profil' : system ? 'für alle festgelegt' : 'Standard von Home Assistant';
    if (!h.sidebar) {
      say(status, 'Dafür muss EMS in der Seitenleiste stehen: in Home Assistant unter Einstellungen → Apps → EMS → In Seitenleiste anzeigen.');
    } else {
      say(status, shown === h.slug ? `EMS ist deine Startseite (${whose}).` : `Deine Startseite ist ${panelTitle(hass, shown)} (${whose}).`);
    }
    if (mine) {
      mine.hidden = !h.sidebar;
      mine.textContent = user === h.slug ? 'Für mich zurücksetzen' : 'Für mich festlegen';
    }
    if (all) {
      all.hidden = !h.sidebar || !hass?.user?.is_admin;
      all.textContent = system === h.slug ? 'Für alle zurücksetzen' : 'Für alle festlegen';
    }
  };
  const change = async (button, scope) => {
    const hass = hassOf(h);
    if (!hass) return;
    const set = read(scope) !== h.slug;
    const next = { ...((scope === 'system' ? hass.systemData : hass.userData) || {}) };
    if (set) next.default_panel = h.slug;
    else delete next.default_panel;
    button.disabled = true;
    try {
      await hass.callWS({ type: scope === 'system' ? 'frontend/set_system_data' : 'frontend/set_user_data', key: 'core', value: next });
      // Home Assistant meldet die neuen Daten gleich nach; bis dahin kurz warten, damit der Stand stimmt.
      for (let i = 0; i < 10 && (read(scope) === h.slug) !== set; i += 1) await new Promise((resolve) => setTimeout(resolve, 100));
      render();
    } catch (error) {
      say(status, `Hat nicht geklappt: ${error?.message || error?.code || 'unbekannter Fehler'}.`);
    } finally {
      button.disabled = false;
    }
  };
  mine?.addEventListener('click', () => change(mine, 'user'));
  all?.addEventListener('click', () => change(all, 'system'));
  render();
}

/** Links mit Kopierknopf und der Schalter fürs Vollbild. */
function bindLinks(h, root) {
  const link = `${h.win.location.origin}${h.sidebar ? `/${h.slug}` : `/app/${h.slug}`}`;
  const fields = { plain: link, kiosk: `${link}?${KIOSK}` };
  const status = $('[data-ha-copy-status]', root);
  for (const [kind, value] of Object.entries(fields)) {
    const field = $(`[data-ha-link="${kind}"]`, root);
    if (field) field.value = value;
    $(`[data-ha-copy="${kind}"]`, root)?.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(value);
        say(status, 'Link kopiert.');
      } catch {
        field?.select();
        say(status, 'Der Link ist markiert, zum Kopieren Strg+C oder ⌘C.');
      }
    });
  }
  const toggle = $('[data-ha-kiosk]', root);
  const label = () => {
    if (toggle) toggle.textContent = state.kiosk ? 'Ausschalten' : 'Jetzt einschalten';
  };
  toggle?.addEventListener('click', () => {
    state.kiosk = !state.kiosk;
    push(h);
    subscribe(h);
    showMenus();
    label();
  });
  label();
}

/** Gibt true zurück, wenn EMS gleich eine andere Seite öffnet. */
export function initHomeAssistant() {
  const h = host();
  if (!h) return false;
  const first = window.name !== FRAME;
  window.name = FRAME;
  const target = there(h);
  if (first && target !== null && target !== here() && isPage(target)) {
    // Link auf eine Seite von EMS: die öffnen, die nächste Seite gleicht den Rest ab.
    location.replace(base() + target);
    return true;
  }
  state.kiosk = new URLSearchParams(h.win.location.search).has(KIOSK);
  push(h);
  subscribe(h);
  listen(h);
  showMenus();
  for (const button of $$('[data-ha-menu]')) button.addEventListener('click', () => send(h, { type: 'home-assistant/toggle-menu' }));
  const card = $('[data-ha-card]');
  if (card) {
    $('[data-ha-outside]', card)?.setAttribute('hidden', '');
    $('[data-ha-inside]', card)?.removeAttribute('hidden');
    bindStart(h, card);
    bindLinks(h, card);
  }
  return false;
}
