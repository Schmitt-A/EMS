// Formulare: Regler-Ausgaben, Vorschläge übernehmen, Tooltips, Theme, Segmente als Filter, Entitätssuche.
import { $, $$, base, getJson, postJson } from '../core/dom.js';
import { fmt, NNBSP } from '../core/format.js';

function bindRanges(root) {
  for (const input of $$('input[type="range"]', root)) {
    const output = input.closest('.range')?.querySelector('output[data-unit]');
    if (!output || input.hasAttribute('data-zone-input')) continue;
    input.addEventListener('input', () => {
      output.textContent = `${fmt(input.value, Number(output.dataset.decimals || 0))}${NNBSP}${output.dataset.unit}`;
    });
  }
}

function bindTips() {
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-tip]');
    for (const tip of $$('.tip.open')) {
      if (!button || !tip.contains(button)) {
        tip.classList.remove('open');
        $('[data-tip]', tip)?.setAttribute('aria-expanded', 'false');
      }
    }
    if (!button) return;
    const tip = button.closest('.tip');
    const open = !tip.classList.contains('open');
    tip.classList.toggle('open', open);
    button.setAttribute('aria-expanded', String(open));
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    for (const tip of $$('.tip.open')) tip.classList.remove('open');
  });
}

function bindFill() {
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-fill]');
    if (!button) return;
    const input = document.getElementById(button.dataset.fill);
    if (input) {
      input.value = button.dataset.value || '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      button.hidden = true;
      input.focus();
    }
  });
}

// Segmente und Selects in GET-Formularen schicken das Formular beim Wechsel ab.
function bindAutosubmit(root) {
  for (const form of $$('form[data-autosubmit]', root)) {
    form.addEventListener('change', (event) => {
      if (event.target.matches('input[type="radio"], select')) form.requestSubmit();
    });
  }
}

function bindTheme(root) {
  for (const fieldset of $$('[data-theme-switch]', root)) {
    fieldset.addEventListener('change', async (event) => {
      const theme = event.target.value;
      const html = document.documentElement;
      html.dataset.theme = theme;
      const dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
      for (const meta of $$('meta[name="theme-color"]')) {
        if (theme === 'system') meta.content = meta.media.includes('dark') ? '#12100E' : '#FAF8F5';
        else {
          meta.removeAttribute('media');
          meta.content = dark ? '#12100E' : '#FAF8F5';
        }
      }
      try {
        await postJson('/api/darstellung', { theme });
      } catch {
        // Die Anzeige wechselt trotzdem; gespeichert wird beim nächsten Versuch.
      }
    });
  }
}

// Entitätssuche als Combobox mit Pfeiltasten.
function bindEntities(root) {
  for (const box of $$('[data-entity]', root)) {
    const input = $('[data-entity-search]', box);
    const list = $('.entity-results', box);
    if (!input || !list) continue;
    let timer = 0;
    let index = -1;
    const close = () => {
      list.hidden = true;
      list.replaceChildren();
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      index = -1;
    };
    const choose = (id) => {
      input.value = id;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      close();
    };
    const search = async () => {
      const query = input.value.trim();
      if (query.length < 2) return close();
      let payload = { results: [] };
      try {
        payload = await getJson(`${base()}/api/entities?q=${encodeURIComponent(query)}`);
      } catch {
        payload = { results: [], error: 'Die Suche antwortet gerade nicht.' };
      }
      // Eine ältere Antwort, die nach einer neueren eintrifft, zeigt nichts mehr an.
      if (input.value.trim() !== query) return;
      list.replaceChildren();
      const rows = payload.results || [];
      if (!rows.length) {
        // Ein Listbox-Kind muss eine Option sein; der Hinweis ist eine gesperrte.
        const empty = document.createElement('li');
        empty.className = 'entity-empty';
        empty.setAttribute('role', 'option');
        empty.setAttribute('aria-disabled', 'true');
        empty.setAttribute('aria-selected', 'false');
        empty.textContent = payload.error || 'Keine passende Entität.';
        list.append(empty);
      }
      rows.forEach((row, i) => {
        const item = document.createElement('li');
        item.setAttribute('role', 'none');
        const button = document.createElement('button');
        button.type = 'button';
        button.id = `${list.id}-${i}`;
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', 'false');
        const name = document.createElement('span');
        name.textContent = row.name || row.id;
        const meta = document.createElement('span');
        meta.className = 'entity-meta';
        meta.textContent = `${row.id} · ${row.state}${row.unit ? `${NNBSP}${row.unit}` : ''}`;
        button.append(name, meta);
        button.addEventListener('click', () => choose(row.id));
        item.append(button);
        list.append(item);
      });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    };
    input.addEventListener('input', (event) => {
      if (!event.isTrusted) return;
      clearTimeout(timer);
      timer = setTimeout(search, 180);
    });
    input.addEventListener('keydown', (event) => {
      const options = $$('[role="option"]:not([aria-disabled="true"])', list);
      if (event.key === 'Escape') return close();
      if (!options.length || !['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return;
      event.preventDefault();
      if (event.key === 'Enter') {
        if (options[index]) options[index].click();
        return;
      }
      index = event.key === 'ArrowDown' ? Math.min(options.length - 1, index + 1) : Math.max(0, index - 1);
      options.forEach((option, i) => option.setAttribute('aria-selected', String(i === index)));
      input.setAttribute('aria-activedescendant', options[index].id);
      options[index].scrollIntoView({ block: 'nearest' });
    });
    document.addEventListener('click', (event) => {
      if (!box.contains(event.target)) close();
    });
  }
}

// Assistent: die quer scrollbare Schrittleiste zeigt den aktuellen Schritt, ohne die Seite zu bewegen.
function bindSteps(root) {
  const current = $('.steps [aria-current="step"]', root);
  const list = current?.closest('.steps');
  if (!list) return;
  const offset = current.getBoundingClientRect().left - list.getBoundingClientRect().left + list.scrollLeft;
  list.scrollLeft = Math.max(0, offset - (list.clientWidth - current.offsetWidth) / 2);
}

// Fehler aus Komponenten als kurze Meldung im Kopf zeigen.
function bindErrors() {
  document.addEventListener('ems:error', (event) => {
    const main = $('#inhalt');
    if (!main) return;
    let flash = $('[data-flash-error]', main);
    if (!flash) {
      flash = document.createElement('p');
      flash.className = 'flash notice-error';
      flash.setAttribute('role', 'alert');
      flash.dataset.flashError = '';
      main.prepend(flash);
    }
    flash.textContent = event.detail;
  });
}

export function initForms(root = document) {
  bindRanges(root);
  bindAutosubmit(root);
  bindTheme(root);
  bindEntities(root);
  bindSteps(root);
}

export function initGlobal() {
  bindTips();
  bindFill();
  bindErrors();
}
