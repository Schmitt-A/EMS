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
    const fill = event.target.closest('[data-fill]');
    if (!fill) return;
    const input = document.querySelector('[name="' + fill.dataset.fill + '"]');
    if (input) input.value = fill.dataset.value || '';
  });

  let searchTimer = 0;
  document.addEventListener('input', (event) => {
    const input = event.target.closest('[data-entity-search]');
    if (!input) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
      let list = input.parentElement.querySelector('[data-results]');
      if (!list) {
        list = document.createElement('ul');
        list.setAttribute('data-results', '');
        list.className = 'mt-1 max-h-48 overflow-auto rounded-lg border border-border bg-card text-sm';
        input.insertAdjacentElement('afterend', list);
      }
      const response = await fetch(base + '/api/entities?q=' + encodeURIComponent(input.value));
      const payload = await response.json();
      list.innerHTML = '';
      (payload.results || []).forEach((row) => {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'block w-full px-3 py-2 text-left hover:bg-muted';
        button.textContent = (row.name || row.id) + ' · ' + row.state + (row.unit ? ' ' + row.unit : '');
        button.addEventListener('click', () => {
          input.value = row.id;
          list.innerHTML = '';
        });
        item.appendChild(button);
        list.appendChild(item);
      });
    }, 180);
  });

  function formatX(value, axis) {
    if (axis === 'day') return String(Math.round(value));
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(date);
  }

  async function renderChart(box) {
    const response = await fetch(box.dataset.url);
    const payload = await response.json();
    const canvas = box.querySelector('canvas');
    box.querySelectorAll('p').forEach((node) => node.remove());
    if (box._chart) box._chart.destroy();
    const datasets = (payload.series || []).filter((series) => series.data && series.data.length).map((series) => ({
      label: series.label,
      data: series.data,
      type: series.type || 'line',
      yAxisID: series.axis || 'y',
      borderColor: color(series.color),
      backgroundColor: color(series.color),
      borderDash: series.dash ? [5, 4] : undefined,
      pointRadius: 0,
      tension: 0.25,
      borderWidth: series.type === 'bar' ? 0 : 2,
      spanGaps: true,
    }));
    if (!datasets.length) {
      const note = document.createElement('p');
      note.className = 'px-2 py-6 text-sm text-muted-foreground';
      note.textContent = payload.error || 'Noch keine Verlaufsdaten.';
      box.appendChild(note);
      return;
    }
    box._chart = new Chart(canvas, {
      data: { datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } },
        scales: {
          x: { type: 'linear', ticks: { maxTicksLimit: 6, callback: (value) => formatX(value, payload.axis) } },
          y: { beginAtZero: true, ticks: { maxTicksLimit: 5 } },
          y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, display: datasets.some((set) => set.yAxisID === 'y1') },
        },
      },
    });
  }

  document.querySelectorAll('[data-chart]').forEach((box) => { renderChart(box); });
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
