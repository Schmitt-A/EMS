(function () {
  const base = window.EMS_BASE || '';
  const colorVar = {
    pv: '--c-pv', battery: '--c-battery', house: '--c-house', import: '--c-import',
    export: '--c-export', wallbox: '--c-wallbox', muted: '--c-muted',
  };
  const flowColor = { pv: '--c-pv', battery: '--c-battery', house: '--c-house', grid: '--c-import', wallbox: '--c-wallbox' };

  function color(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(colorVar[name] || '--c-fg').trim() || '#115e59';
  }

  function applyTheme(theme) {
    const dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
  }

  document.querySelectorAll('input[name="theme"]').forEach((input) => {
    input.addEventListener('change', () => {
      localStorage.setItem('ems-theme', input.value);
      applyTheme(input.value);
    });
  });

  document.querySelectorAll('input[type="range"]').forEach((input) => {
    const out = input.parentElement && input.parentElement.querySelector('[data-range-out]');
    if (!out) return;
    input.addEventListener('input', () => {
      const unit = (out.textContent.match(/[^\d,.\s-]+$/) || [''])[0];
      out.textContent = input.value + (unit ? ' ' + unit : '');
    });
  });

  document.addEventListener('click', (event) => {
    const tip = event.target.closest('.tip-btn');
    document.querySelectorAll('.tip.open').forEach((node) => {
      if (!tip || !node.contains(tip)) node.classList.remove('open');
    });
    if (tip) {
      event.preventDefault();
      tip.parentElement.classList.toggle('open');
    }
    const fill = event.target.closest('[data-fill]');
    if (!fill) return;
    const input = document.querySelector('[name="' + fill.dataset.fill + '"]');
    if (input) input.value = fill.dataset.value || '';
  });

  let searchTimer = 0;
  let searchIndex = -1;

  function closeSearch(except) {
    document.querySelectorAll('[data-results]').forEach((list) => {
      if (except && list === except) return;
      list.remove();
    });
    searchIndex = -1;
  }

  async function searchEntities(input) {
    let list = input.parentElement.querySelector('[data-results]');
    if (!list) {
      list = document.createElement('ul');
      list.setAttribute('data-results', '');
      list.setAttribute('role', 'listbox');
      input.insertAdjacentElement('afterend', list);
    }
    const query = input.value.trim();
    if (query.length < 2) {
      list.innerHTML = '';
      return;
    }
    const response = await fetch(base + '/api/entities?q=' + encodeURIComponent(query));
    const payload = await response.json();
    list.innerHTML = '';
    searchIndex = -1;
    const rows = payload.results || [];
    if (!rows.length) {
      const empty = document.createElement('li');
      empty.className = 'px-3 py-2 text-muted-foreground';
      empty.textContent = payload.error || 'Keine passende Entität';
      list.appendChild(empty);
      return;
    }
    rows.forEach((row) => {
      const item = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('role', 'option');
      button.className = 'block w-full px-3 py-2 text-left hover:bg-muted';
      const name = document.createElement('span');
      name.className = 'block';
      name.textContent = row.name || row.id;
      const meta = document.createElement('span');
      meta.className = 'block text-xs text-muted-foreground';
      meta.textContent = row.id + ' · ' + row.state + (row.unit ? ' ' + row.unit : '');
      button.append(name, meta);
      button.addEventListener('click', () => {
        input.value = row.id;
        closeSearch();
      });
      item.appendChild(button);
      list.appendChild(item);
    });
  }

  document.addEventListener('input', (event) => {
    const input = event.target.closest('[data-entity-search]');
    if (!input) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { searchEntities(input); }, 160);
  });
  document.addEventListener('focusin', (event) => {
    const input = event.target.closest('[data-entity-search]');
    if (!input || input.value.trim().length < 2) return;
    searchEntities(input);
  });
  document.addEventListener('keydown', (event) => {
    const input = event.target.closest('[data-entity-search]');
    if (!input) return;
    const list = input.parentElement.querySelector('[data-results]');
    const buttons = list ? [...list.querySelectorAll('button')] : [];
    if (event.key === 'Escape') {
      closeSearch();
      return;
    }
    if (!buttons.length || (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Enter')) return;
    event.preventDefault();
    if (event.key === 'ArrowDown') searchIndex = Math.min(buttons.length - 1, searchIndex + 1);
    if (event.key === 'ArrowUp') searchIndex = Math.max(0, searchIndex - 1);
    buttons.forEach((button, index) => button.classList.toggle('bg-muted', index === searchIndex));
    if (event.key === 'Enter' && buttons[searchIndex]) buttons[searchIndex].click();
  });
  document.addEventListener('click', (event) => {
    if (!event.target.closest('.entity')) closeSearch();
  });

  function formatX(value, axis) {
    if (axis === 'day') return String(Math.round(value));
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(date);
  }

  function kwhText(value) {
    return new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value) + ' kWh';
  }

  function clampWindow(min, max, bounds) {
    if (!bounds || bounds.length < 2) return [min, max];
    const span = max - min;
    const width = bounds[1] - bounds[0];
    if (span >= width) return [bounds[0], bounds[1]];
    if (min < bounds[0]) return [bounds[0], bounds[0] + span];
    if (max > bounds[1]) return [bounds[1] - span, bounds[1]];
    return [min, max];
  }

  function fitAxes(chart) {
    const scale = chart.options.scales.x;
    let maxY = 0.2;
    let maxY1 = 0.2;
    chart.data.datasets.forEach((set) => {
      if (set.hidden) return;
      (set.data || []).forEach((point) => {
        if (!point || typeof point !== 'object') return;
        const y = point.y;
        if (point.x < scale.min || point.x > scale.max || y === null || Number.isNaN(y)) return;
        if ((set.yAxisID || 'y') === 'y1') maxY1 = Math.max(maxY1, y);
        else maxY = Math.max(maxY, y);
      });
    });
    chart.options.scales.y.max = maxY * 1.28;
    if (chart.options.scales.y1) chart.options.scales.y1.max = maxY1 * 1.12;
  }

  function applyWindow(chart, payload, min, max) {
    const next = clampWindow(min, max, payload.bounds);
    chart.options.scales.x.min = next[0];
    chart.options.scales.x.max = next[1];
    fitAxes(chart);
    chart.update('none');
  }

  function bindPan(box) {
    if (box._panBound) return;
    box._panBound = true;
    let drag = null;
    box.addEventListener('pointerdown', (event) => {
      if (event.button !== 0 || !box._chart) return;
      const scale = box._chart.scales.x;
      drag = { id: event.pointerId, x: event.clientX, min: scale.min, max: scale.max };
    });
    box.addEventListener('pointermove', (event) => {
      if (!drag || event.pointerId !== drag.id || !box._chart) return;
      const dx = event.clientX - drag.x;
      if (Math.abs(dx) < 4) return;
      if (!box.hasPointerCapture(event.pointerId)) box.setPointerCapture(event.pointerId);
      box.classList.add('is-panning');
      const area = box._chart.chartArea;
      const pixel = Math.max(1, area.right - area.left);
      const span = drag.max - drag.min;
      applyWindow(box._chart, box._payload, drag.min - (dx / pixel) * span, drag.max - (dx / pixel) * span);
    });
    const end = (event) => {
      if (!drag || event.pointerId !== drag.id) return;
      drag = null;
      box.classList.remove('is-panning');
    };
    box.addEventListener('pointerup', end);
    box.addEventListener('pointercancel', end);
    box.addEventListener('wheel', (event) => {
      if (!box._chart || Math.abs(event.deltaX) <= Math.abs(event.deltaY)) return;
      event.preventDefault();
      const area = box._chart.chartArea;
      const pixel = Math.max(1, area.right - area.left);
      const scale = box._chart.scales.x;
      const span = scale.max - scale.min;
      const shift = (event.deltaX / pixel) * span;
      applyWindow(box._chart, box._payload, scale.min + shift, scale.max + shift);
    }, { passive: false });
  }

  async function renderChart(box) {
    const response = await fetch(box.dataset.url);
    const payload = await response.json();
    const canvas = box.querySelector('canvas');
    box.querySelectorAll('p').forEach((node) => node.remove());
    if (box._chart) box._chart.destroy();
    const category = payload.axis === 'category';
    const datasets = (payload.series || []).filter((series) => series.data && series.data.length).map((series) => ({
      label: series.label,
      data: series.data,
      emsKey: series.key,
      type: series.type || 'line',
      yAxisID: series.axis || 'y',
      borderColor: color(series.color),
      backgroundColor: series.type === 'bar' ? color(series.color) : color(series.color),
      borderDash: series.dash ? [5, 4] : undefined,
      pointRadius: 0,
      tension: 0.25,
      borderWidth: series.type === 'bar' ? 0 : 2,
      maxBarThickness: 18,
      borderRadius: series.type === 'bar' ? 4 : 0,
      spanGaps: true,
    }));
    if (!datasets.length) {
      const note = document.createElement('p');
      note.className = 'px-2 py-6 text-sm text-muted-foreground';
      note.textContent = payload.error || 'Noch keine Verlaufsdaten.';
      box.appendChild(note);
      return;
    }
    const days = Number(payload.days || 1);
    box.style.minWidth = '';
    if (box.dataset.scroll === '1') {
      const parent = box.parentElement ? box.parentElement.clientWidth : 640;
      box.style.minWidth = Math.max(parent, days * 88) + 'px';
    }
    const todayBand = {
      id: 'todayBand',
      beforeDatasetsDraw(chart) {
        const range = payload.today;
        if (!range || range.length < 2 || category) return;
        const scale = chart.scales.x;
        const area = chart.chartArea;
        const x0 = scale.getPixelForValue(range[0]);
        const x1 = scale.getPixelForValue(range[1]);
        const ctx = chart.ctx;
        ctx.save();
        ctx.fillStyle = color('pv');
        ctx.globalAlpha = 0.12;
        ctx.fillRect(x0, area.top, Math.max(2, x1 - x0), area.bottom - area.top);
        ctx.restore();
      },
    };
    const dayMarks = {
      id: 'dayMarks',
      afterBuildTicks(chart, args) {
        const scale = args && args.scale ? args.scale : chart.scales.x;
        const marks = payload.marks || [];
        if (!scale || scale.axis !== 'x' || !marks.length) return;
        const ticks = marks.filter((mark) => mark.x >= scale.min && mark.x <= scale.max).map((mark) => ({ value: mark.x }));
        if (ticks.length) scale.ticks = ticks;
      },
      beforeDatasetsDraw(chart) {
        const scale = chart.scales.x;
        const area = chart.chartArea;
        if (!scale || !area) return;
        const ctx = chart.ctx;
        ctx.save();
        ctx.strokeStyle = color('muted');
        ctx.globalAlpha = 0.35;
        (payload.marks || []).forEach((mark) => {
          if (mark.start < scale.min || mark.start > scale.max) return;
          const x = scale.getPixelForValue(mark.start);
          ctx.beginPath();
          ctx.moveTo(x, area.top);
          ctx.lineTo(x, area.bottom);
          ctx.stroke();
        });
        ctx.restore();
      },
      afterDatasetsDraw(chart) {
        const scale = chart.scales.x;
        const yScale = chart.scales.y;
        const area = chart.chartArea;
        const forecast = chart.data.datasets.find((set) => set.emsKey === 'forecast' && !set.hidden);
        if (!forecast || !scale || !yScale || !area) return;
        const ctx = chart.ctx;
        ctx.save();
        ctx.fillStyle = color('export');
        ctx.font = '600 11px Inter, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';
        (payload.marks || []).forEach((mark) => {
          if (mark.kwh === null || mark.kwh === undefined || mark.peak === null) return;
          if (mark.peak < scale.min || mark.peak > scale.max) return;
          const x = scale.getPixelForValue(mark.peak);
          const y = yScale.getPixelForValue(mark.peak_y || 0) - 4;
          if (x < area.left - 8 || x > area.right + 8) return;
          ctx.fillText(kwhText(mark.kwh), x, Math.max(area.top + 12, y));
        });
        ctx.restore();
      },
    };
    const xScale = category
      ? { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 }, grid: { display: false } }
      : {
          type: 'linear',
          min: box.dataset.pan === '1' && payload.view ? payload.view[0] : undefined,
          max: box.dataset.pan === '1' && payload.view ? payload.view[1] : undefined,
          ticks: {
            maxTicksLimit: payload.marks ? 12 : (box.dataset.scroll === '1' ? Math.min(Math.max(days, 6), 16) : 6),
            maxRotation: 0,
            autoSkip: !payload.marks,
            callback: (value) => {
              if (!payload.marks) return formatX(value, payload.axis);
              const mark = payload.marks.find((item) => Math.abs(item.x - value) < 60000);
              return mark ? mark.label.split(' ') : '';
            },
          },
        };
    box._payload = payload;
    box._chart = new Chart(canvas, {
      type: category ? 'bar' : 'line',
      plugins: payload.marks ? [todayBand, dayMarks] : [todayBand],
      data: category ? { labels: payload.labels || [], datasets } : { datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: payload.marks ? 8 : 0 } },
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } },
        scales: {
          x: xScale,
          y: { beginAtZero: true, ticks: { maxTicksLimit: 5 } },
          y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, display: datasets.some((set) => set.yAxisID === 'y1') },
        },
      },
    });
    if (box.dataset.pan === '1') {
      fitAxes(box._chart);
      box._chart.update('none');
      bindPan(box);
    }
  }

  document.querySelectorAll('[data-chart]').forEach((box) => { renderChart(box); });
  document.querySelectorAll('[data-power-tools]').forEach((bar) => {
    bar.addEventListener('click', (event) => {
      const button = event.target.closest('button');
      const box = bar.parentElement ? bar.parentElement.querySelector('[data-pan]') : null;
      if (!button || !box || !box._chart || !box._payload) return;
      const chart = box._chart;
      const payload = box._payload;
      if (button.dataset.window) {
        const day = 86400000;
        const today = payload.today ? payload.today[0] : payload.view[0];
        const name = button.dataset.window === 'reset' ? '3' : button.dataset.window;
        let next = payload.view;
        if (name === 'today') next = [today, today + day];
        if (name === '7') next = [today, today + 7 * day];
        if (name === 'all') next = payload.bounds || payload.view;
        applyWindow(chart, payload, next[0], next[1]);
        bar.querySelectorAll('[data-window]').forEach((item) => {
          if (item.dataset.window === 'reset') return;
          item.setAttribute('aria-pressed', item.dataset.window === name ? 'true' : 'false');
        });
        if (button.dataset.window === 'reset') {
          chart.data.datasets.forEach((set) => { set.hidden = false; });
          bar.querySelectorAll('[data-series]').forEach((item) => item.setAttribute('aria-pressed', 'true'));
          applyWindow(chart, payload, next[0], next[1]);
        }
        return;
      }
      if (button.dataset.series) {
        const set = chart.data.datasets.find((item) => item.emsKey === button.dataset.series);
        if (!set) return;
        set.hidden = !set.hidden;
        button.setAttribute('aria-pressed', set.hidden ? 'false' : 'true');
        fitAxes(chart);
        chart.update();
      }
    });
  });
  document.querySelectorAll('[data-ranges]').forEach((bar) => {
    bar.addEventListener('click', (event) => {
      const button = event.target.closest('[data-range]');
      if (!button) return;
      const box = bar.parentElement.querySelector('[data-chart]');
      if (!box) return;
      box.dataset.url = bar.dataset.chartBase + button.dataset.range;
      renderChart(box);
    });
  });

  function applyFlows(flows) {
    document.querySelectorAll('[data-flow]').forEach((path) => {
      const key = path.dataset.flow;
      let amount = Number(flows[key] || 0);
      let reverse = false;
      if (key === 'battery') {
        amount = Math.max(flows.bat_charge || 0, flows.bat_discharge || 0);
        reverse = (flows.bat_charge || 0) > (flows.bat_discharge || 0);
      } else if (key === 'grid') {
        amount = Math.max(flows.grid_import || 0, flows.grid_export || 0);
        reverse = (flows.grid_export || 0) > (flows.grid_import || 0);
        path.style.stroke = amount > 0.02 && reverse ? 'var(--c-export)' : (amount > 0.02 ? 'var(--c-import)' : 'var(--c-border)');
      }
      if (key !== 'grid') {
        path.style.stroke = amount > 0.02 ? 'var(' + (flowColor[key] || '--c-fg') + ')' : 'var(--c-border)';
      }
      path.style.strokeWidth = amount > 0.02 ? String(1.6 + Math.min(amount, 6) * 0.4) : '1.2';
      path.classList.toggle('flow-move', amount > 0.02);
      path.classList.toggle('flow-rev', reverse);
    });
  }

  async function tick() {
    try {
      const response = await fetch(base + '/api/live');
      if (!response.ok) return;
      const data = await response.json();
      document.querySelectorAll('[data-live]').forEach((node) => {
        const key = node.dataset.live;
        if (data[key] !== undefined && data[key] !== null) node.textContent = data[key];
      });
      document.querySelectorAll('[data-live-svg]').forEach((node) => {
        const key = node.dataset.liveSvg;
        if (data[key]) node.textContent = data[key];
      });
      const dot = document.querySelector('[data-live-dot]');
      if (dot) {
        dot.classList.toggle('bg-export', !!data.connected);
        dot.classList.toggle('bg-import', !data.connected);
      }
      if (data.flows) applyFlows(data.flows);
    } catch (error) {
      /* nächste Runde */
    }
  }

  if (document.querySelector('[data-live]')) {
    tick();
    setInterval(tick, 5000);
  }
})();
