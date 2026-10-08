(function () {
  const base = window.EMS_BASE || '';
  const colorVar = {
    pv: '--c-pv', battery: '--c-battery', house: '--c-house', import: '--c-import',
    export: '--c-export', wallbox: '--c-wallbox', muted: '--c-muted', sun: '--c-sun', net: '--c-net',
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
    if (input.closest('[data-dual]')) return;
    const out = input.parentElement && input.parentElement.querySelector('[data-range-out]');
    if (!out) return;
    input.addEventListener('input', () => {
      const unit = (out.textContent.match(/[^\d,.\s-]+$/) || [''])[0];
      out.textContent = input.value + (unit ? ' ' + unit : '');
    });
  });

  document.querySelectorAll('[data-dual]').forEach((box) => {
    const inputs = [...box.querySelectorAll('input[type="range"]')];
    const fill = box.querySelector('[data-dual-fill]');
    const out = box.querySelector('[data-dual-out]');
    if (inputs.length < 2) return;
    const minInput = inputs[0];
    const maxInput = inputs[1];
    const lo = Number(box.dataset.min || minInput.min);
    const hi = Number(box.dataset.max || minInput.max);
    const paint = () => {
      let min = Number(minInput.value);
      let max = Number(maxInput.value);
      if (min > max) {
        if (document.activeElement === minInput) {
          maxInput.value = String(min);
          max = min;
        } else {
          minInput.value = String(max);
          min = max;
        }
      }
      const span = Math.max(1, hi - lo);
      if (fill) {
        const start = (min - lo) / span;
        const end = (max - lo) / span;
        fill.style.left = 'calc(0.85rem + (100% - 1.7rem) * ' + start + ')';
        fill.style.right = 'calc(0.85rem + (100% - 1.7rem) * ' + (1 - end) + ')';
      }
      if (out) out.textContent = min + '–' + max + ' A';
      minInput.style.zIndex = min > hi - 1 ? '5' : '3';
      maxInput.style.zIndex = '4';
    };
    minInput.addEventListener('input', paint);
    maxInput.addEventListener('input', paint);
    paint();
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

  function clampWindow(min, max, bounds) {
    if (!bounds || bounds.length < 2) return [min, max];
    const span = max - min;
    const width = bounds[1] - bounds[0];
    if (span >= width) return [bounds[0], bounds[1]];
    if (min < bounds[0]) return [bounds[0], bounds[0] + span];
    if (max > bounds[1]) return [bounds[1] - span, bounds[1]];
    return [min, max];
  }

  function formatEnergyTick(value) {
    return new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
  }

  function fitAxes(chart) {
    const box = chart.canvas && chart.canvas.closest('[data-chart]');
    const payload = box && box._payload;
    if (payload && payload.yLock) {
      const locked = Number(payload.yMax);
      const scale = chart.options.scales.y;
      scale.min = 0;
      scale.max = locked > 0 ? locked : 1;
      if (payload.yStep) scale.ticks.stepSize = Number(payload.yStep);
      return;
    }
    const scale = chart.options.scales.x;
    const stacked = !!(scale && scale.stacked);
    let maxY = 0.2;
    let maxY1 = 0.2;
    const buckets = {};
    chart.data.datasets.forEach((set) => {
      if (set.hidden) return;
      (set.data || []).forEach((point, index) => {
        const x = point && typeof point === 'object' && point.x !== undefined ? point.x : index;
        const y = point && typeof point === 'object' ? point.y : point;
        if (y === null || y === undefined || Number.isNaN(Number(y))) return;
        if (scale && scale.min !== undefined && x < scale.min) return;
        if (scale && scale.max !== undefined && x > scale.max) return;
        if ((set.yAxisID || 'y') === 'y1') {
          maxY1 = Math.max(maxY1, Number(y));
          return;
        }
        if (stacked) buckets[String(Math.round(x))] = (buckets[String(Math.round(x))] || 0) + Number(y);
        else maxY = Math.max(maxY, Number(y));
      });
    });
    Object.keys(buckets).forEach((key) => { maxY = Math.max(maxY, buckets[key]); });
    chart.options.scales.y.max = maxY * 1.28;
    if (chart.options.scales.y1) chart.options.scales.y1.max = Math.max(1.2, maxY1 * 1.12);
  }

  function applyWindow(chart, payload, min, max) {
    const next = clampWindow(min, max, payload.bounds);
    chart.$window = next;
    chart.options.scales.x.min = next[0];
    chart.options.scales.x.max = next[1];
    fitAxes(chart);
    chart.update('none');
  }

  function applyIndexWindow(chart, payload, min, max) {
    const last = Math.max(0, (payload.labels || []).length - 1);
    const bounds = chart.$indexBounds || [0, last];
    const loBound = bounds[0];
    const hiBound = Math.min(last, bounds[1]);
    let lo = min;
    let hi = max;
    const span = Math.max(0, hi - lo);
    const width = Math.max(0, hiBound - loBound);
    if (span >= width) {
      lo = loBound;
      hi = hiBound;
    } else if (lo < loBound) {
      hi += loBound - lo;
      lo = loBound;
    } else if (hi > hiBound) {
      lo -= hi - hiBound;
      hi = hiBound;
    }
    chart.$window = [lo, hi];
    chart.options.scales.x.min = lo - 0.5;
    chart.options.scales.x.max = hi + 0.5;
    fitAxes(chart);
    chart.update('none');
  }

  function bindPan(box) {
    if (box._panBound) return;
    box._panBound = true;
    let drag = null;
    box.addEventListener('pointerdown', (event) => {
      if (event.button !== 0 || !box._chart) return;
      const win = box._chart.$window || [box._chart.scales.x.min, box._chart.scales.x.max];
      drag = { id: event.pointerId, x: event.clientX, min: win[0], max: win[1] };
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
      const nextMin = drag.min - (dx / pixel) * span;
      if (box._payload && box._payload.pan === 'index') applyIndexWindow(box._chart, box._payload, nextMin, nextMin + span);
      else applyWindow(box._chart, box._payload, nextMin, nextMin + span);
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
      const win = box._chart.$window || [box._chart.scales.x.min, box._chart.scales.x.max];
      const span = win[1] - win[0];
      const shift = (event.deltaX / pixel) * span;
      if (box._payload && box._payload.pan === 'index') applyIndexWindow(box._chart, box._payload, win[0] + shift, win[1] + shift);
      else applyWindow(box._chart, box._payload, win[0] + shift, win[1] + shift);
    }, { passive: false });
  }

  async function renderChart(box) {
    const response = await fetch(box.dataset.url);
    const payload = await response.json();
    const canvas = box.querySelector('canvas');
    box.querySelectorAll('p').forEach((node) => node.remove());
    if (box._chart) box._chart.destroy();
    const category = payload.axis === 'category';
    const datasets = (payload.series || []).filter((series) => payload.keepEmpty ? Array.isArray(series.data) : (series.data && series.data.length)).map((series) => ({
      label: series.label,
      data: series.data,
      emsKey: series.key,
      hidden: !!series.hidden,
      type: series.type || 'line',
      yAxisID: series.axis || 'y',
      borderColor: color(series.color),
      backgroundColor: series.type === 'bar' ? color(series.color) : color(series.color),
      borderDash: series.dash ? [5, 4] : undefined,
      pointRadius: 0,
      tension: 0.25,
      stack: series.stack || undefined,
      borderWidth: series.type === 'bar' ? 0 : 2,
      spanGaps: true,
    }));
    const grouped = !!payload.grouped;
    datasets.forEach((set) => {
      if (set.type !== 'bar') return;
      if (grouped) {
        set.barPercentage = 1;
        set.categoryPercentage = 0.72;
        set.borderRadius = 0;
      } else {
        set.maxBarThickness = payload.stacked ? 36 : 18;
        set.borderRadius = 4;
      }
    });
    const hasPoint = datasets.some((set) => set.data && set.data.length);
    if (!datasets.length || !hasPoint) {
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
      const perDay = payload.stacked ? 32 : 88;
      box.style.minWidth = Math.max(parent, days * perDay) + 'px';
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
        let chosen = marks.filter((mark) => mark.x >= scale.min && mark.x <= scale.max);
        if (payload.tickSkip && chosen.length > 8) {
          const step = Math.ceil(chosen.length / 8);
          chosen = chosen.filter((_, index) => index % step === 0);
        }
        const ticks = chosen.map((mark) => ({ value: mark.x }));
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
      afterDraw(chart) {
        const scale = chart.scales.x;
        const yScale = chart.scales.y;
        const area = chart.chartArea;
        if (!scale || !yScale || !area) return;
        const ctx = chart.ctx;
        ctx.save();
        ctx.beginPath();
        ctx.rect(area.left, area.top, area.right - area.left, area.bottom - area.top);
        ctx.clip();
        ctx.fillStyle = color('export');
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';
        (payload.marks || []).forEach((mark) => {
          if (!mark.text) return;
          const dayEnd = mark.start + 86400000;
          const vis0 = Math.max(mark.start, scale.min);
          const vis1 = Math.min(dayEnd, scale.max);
          if (vis1 - vis0 < 3600000) return;
          const noon = mark.x;
          const xValue = noon >= scale.min && noon <= scale.max ? noon : (vis0 + vis1) / 2;
          const x = scale.getPixelForValue(xValue);
          if (x < area.left + 4 || x > area.right - 4) return;
          const dayPx = Math.abs(scale.getPixelForValue(dayEnd) - scale.getPixelForValue(mark.start));
          const size = dayPx < 72 ? 10 : 11;
          ctx.font = '600 ' + size + 'px Inter, sans-serif';
          let lines = [mark.text];
          if (ctx.measureText(mark.text).width > dayPx * 0.92) {
            const parts = String(mark.text).split(' ± ');
            if (parts.length === 2) lines = [parts[0] + ' kWh', '± ' + parts[1].replace(' kWh', '')];
          }
          const lineH = size + 2;
          const block = lines.length * lineH;
          let peak = null;
          (chart.data.datasets || []).forEach((set) => {
            if (set.hidden || (set.yAxisID || 'y') !== 'y') return;
            (set.data || []).forEach((point) => {
              if (!point || point.y === null || point.y === undefined || point.x === null || point.x === undefined) return;
              if (point.x < mark.start || point.x >= dayEnd) return;
              const y = Number(point.y);
              if (!Number.isFinite(y)) return;
              peak = peak === null ? y : Math.max(peak, y);
            });
          });
          const cap = Number(payload.yDataMax);
          const top = Number(payload.yMax);
          let bottom;
          if (peak === null && Number.isFinite(cap) && Number.isFinite(top)) {
            bottom = yScale.getPixelForValue((cap + top) / 2) + block / 2;
          } else {
            bottom = yScale.getPixelForValue(peak === null ? 0 : peak) - 8;
          }
          if (bottom - block < area.top + 1) bottom = area.top + 1 + block;
          if (bottom > area.bottom - 2) bottom = area.bottom - 2;
          lines.forEach((line, index) => {
            ctx.fillText(line, x, bottom - (lines.length - 1 - index) * lineH);
          });
        });
        ctx.restore();
      },
    };
    function extremaLabel(value, unit) {
      if (unit === 'soc') {
        return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 0 }).format(value) + ' %';
      }
      return new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value) + ' kWh';
    }
    const extremaMarks = {
      id: 'extremaMarks',
      afterDraw(chart) {
        const pack = payload.extrema;
        if (!pack) return;
        const unit = payload.unit || 'soc';
        const rows = pack[unit] || [];
        const xScale = chart.scales.x;
        const yScale = chart.scales.y;
        const area = chart.chartArea;
        if (!xScale || !yScale || !area || !rows.length) return;
        const ctx = chart.ctx;
        const gap = unit === 'soc' ? 0.5 : 0.05;
        ctx.save();
        ctx.font = '600 11px Inter, sans-serif';
        ctx.textAlign = 'center';
        rows.forEach((row) => {
          if (!row.min || !row.max) return;
          const same = Math.abs(row.min.y - row.max.y) < gap;
          const items = same ? [{ point: row.max, kind: 'max' }] : [{ point: row.max, kind: 'max' }, { point: row.min, kind: 'min' }];
          items.forEach((item) => {
            const point = item.point;
            if (point.x < xScale.min || point.x > xScale.max) return;
            const x = xScale.getPixelForValue(point.x);
            const y = yScale.getPixelForValue(point.y);
            if (x < area.left + 2 || x > area.right - 2) return;
            ctx.beginPath();
            ctx.fillStyle = color('battery');
            ctx.arc(x, Math.max(area.top, Math.min(area.bottom, y)), 3.5, 0, Math.PI * 2);
            ctx.fill();
            const text = (same ? '' : (item.kind === 'min' ? 'min ' : 'max ')) + extremaLabel(point.y, unit);
            ctx.fillStyle = color('fg');
            const below = item.kind === 'min' && !same;
            ctx.textBaseline = below ? 'top' : 'bottom';
            let ty = below ? y + 7 : y - 7;
            if (ty < area.top + 12) {
              ctx.textBaseline = 'top';
              ty = Math.min(area.bottom - 2, y + 7);
            }
            if (ty > area.bottom - 2) {
              ctx.textBaseline = 'bottom';
              ty = Math.max(area.top + 12, y - 7);
            }
            ctx.fillText(text, x, ty);
          });
        });
        ctx.restore();
      },
    };
    const energyAxis = {
      id: 'energyAxis',
      afterBuildTicks(chart, args) {
        const scale = args && args.scale;
        if (!scale || scale.axis !== 'y' || !payload.yLock) return;
        const step = Number(payload.yStep) || 0.5;
        const cap = Number(payload.yDataMax);
        if (!Number.isFinite(cap) || step <= 0) return;
        const ticks = [];
        for (let i = 0; i < 48; i += 1) {
          const value = Math.round(i * step * 1000) / 1000;
          if (value > cap + 0.001) break;
          ticks.push({ value });
        }
        if (ticks.length) scale.ticks = ticks;
      },
    };
    const stacked = !!payload.stacked;
    const indexPan = payload.pan === 'index';
    const axisTitle = (text) => ({ display: Boolean(text), text: text || '', color: color('muted'), font: { size: 11 } });
    const xScale = category
      ? {
          min: indexPan && payload.view ? payload.view[0] - 0.5 : undefined,
          max: indexPan && payload.view ? payload.view[1] + 0.5 : undefined,
          stacked,
          title: axisTitle(payload.xTitle),
          ticks: { maxRotation: 0, autoSkip: !stacked && (payload.labels || []).length > 10, maxTicksLimit: stacked ? (payload.labels || []).length : 10 },
          grid: { display: false },
        }
      : {
          type: 'linear',
          min: box.dataset.pan === '1' && payload.view ? payload.view[0] : undefined,
          max: box.dataset.pan === '1' && payload.view ? payload.view[1] : undefined,
          title: axisTitle(payload.xTitle),
          ticks: {
            maxTicksLimit: payload.marks ? 12 : (box.dataset.scroll === '1' ? Math.min(Math.max(days, 6), 16) : 6),
            maxRotation: 0,
            autoSkip: !payload.marks,
            callback: (value) => {
              if (!payload.marks) return formatX(value, payload.axis);
              const mark = payload.marks.find((item) => Math.abs(item.x - value) < 60000);
              if (!mark) return '';
              if (payload.tickSkip) return mark.label.split(' ').slice(1).join(' ') || mark.label;
              return mark.label.split(' ');
            },
          },
        };
    box._payload = payload;
    const chartPlugins = [todayBand];
    if (payload.marks) chartPlugins.push(dayMarks);
    if (payload.extrema) chartPlugins.push(extremaMarks);
    if (payload.yLock) chartPlugins.push(energyAxis);
    const chartOptions = {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      layout: { padding: { top: payload.marks && !payload.yLock ? 8 : 0 } },
      plugins: {
        legend: { display: payload.legend !== false, position: 'bottom', labels: { boxWidth: 10 } },
        tooltip: { enabled: !payload.yLock },
      },
      scales: {
        x: xScale,
        y: {
          beginAtZero: true,
          min: payload.yLock ? 0 : undefined,
          max: payload.yLock ? Number(payload.yMax) : undefined,
          stacked,
          title: axisTitle(payload.yTitle),
          ticks: payload.yStep
            ? {
                maxTicksLimit: 24,
                stepSize: Number(payload.yStep),
                callback: (value) => {
                  const digits = Number(payload.yDecimals ?? 1);
                  return new Intl.NumberFormat('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);
                },
              }
            : { maxTicksLimit: 5 },
        },
        y1: { position: 'right', beginAtZero: true, title: axisTitle(payload.y1Title), grid: { drawOnChartArea: false }, display: datasets.some((set) => set.yAxisID === 'y1') },
      },
    };
    if (payload.yLock) chartOptions.events = [];
    box._chart = new Chart(canvas, {
      type: category ? 'bar' : 'line',
      plugins: chartPlugins,
      data: category ? { labels: payload.labels || [], datasets } : { datasets },
      options: chartOptions,
    });
    if (payload.pan === 'index') {
      const view = payload.view || [0, 0];
      if (payload.windows && payload.bounds) box._chart.$indexBounds = payload.bounds.slice();
      applyIndexWindow(box._chart, payload, view[0], view[1]);
      bindPan(box);
    } else if (box.dataset.pan === '1') {
      if (payload.view) box._chart.$window = payload.view.slice();
      fitAxes(box._chart);
      box._chart.update('none');
      bindPan(box);
    } else if (payload.stacked) {
      fitAxes(box._chart);
      box._chart.update('none');
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
  document.querySelectorAll('[data-day-tools]').forEach((bar) => {
    bar.addEventListener('click', (event) => {
      const button = event.target.closest('button');
      const box = bar.parentElement ? bar.parentElement.querySelector('[data-pan]') : null;
      if (!button || !box || !box._chart || !box._payload) return;
      const chart = box._chart;
      const payload = box._payload;
      const last = Math.max(0, (payload.labels || []).length - 1);
      if (button.dataset.window) {
        const name = button.dataset.window === 'reset' ? '3' : button.dataset.window;
        let next = [0, Math.min(2, last)];
        if (name === 'today') next = [0, 0];
        if (name === '7') next = [0, Math.min(6, last)];
        if (name === 'all') next = [0, last];
        applyIndexWindow(chart, payload, next[0], next[1]);
        bar.querySelectorAll('[data-window]').forEach((item) => {
          if (item.dataset.window === 'reset') return;
          item.setAttribute('aria-pressed', item.dataset.window === name ? 'true' : 'false');
        });
        if (button.dataset.window === 'reset') {
          chart.data.datasets.forEach((set) => { set.hidden = false; });
          bar.querySelectorAll('[data-series]').forEach((item) => item.setAttribute('aria-pressed', 'true'));
          applyIndexWindow(chart, payload, next[0], next[1]);
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
  function showGoodness(payload, name) {
    const score = payload && payload.goodness ? payload.goodness[name] : null;
    const ratio = document.querySelector('[data-gute-ratio]');
    const days = document.querySelector('[data-gute-days]');
    if (!score || !ratio || !days) return;
    ratio.textContent = score.text;
    days.textContent = String(score.days);
  }
  document.querySelectorAll('[data-gute-tools]').forEach((bar) => {
    bar.addEventListener('click', (event) => {
      const button = event.target.closest('button');
      const box = bar.parentElement ? bar.parentElement.querySelector('[data-pan]') : null;
      if (!button || !box || !box._chart || !box._payload) return;
      const chart = box._chart;
      const payload = box._payload;
      if (button.dataset.window) {
        const name = button.dataset.window === 'reset' ? '3' : button.dataset.window;
        const span = (payload.windows || {})[name];
        if (!span) return;
        chart.$indexBounds = span.slice();
        const width = span[1] - span[0];
        const viewHi = width > 9 ? span[0] + 6 : span[1];
        applyIndexWindow(chart, payload, span[0], viewHi);
        showGoodness(payload, name);
        bar.querySelectorAll('[data-window]').forEach((item) => {
          if (item.dataset.window === 'reset') return;
          item.setAttribute('aria-pressed', item.dataset.window === name ? 'true' : 'false');
        });
        if (button.dataset.window === 'reset') {
          chart.data.datasets.forEach((set) => { set.hidden = false; });
          bar.querySelectorAll('[data-series]').forEach((item) => item.setAttribute('aria-pressed', 'true'));
          applyIndexWindow(chart, payload, span[0], viewHi);
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
  document.querySelectorAll('[data-open-dialog]').forEach((button) => {
    button.addEventListener('click', () => {
      const dialog = document.getElementById(button.dataset.openDialog);
      if (dialog && dialog.showModal) dialog.showModal();
    });
  });
  document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
    dialog.querySelectorAll('[data-close-dialog]').forEach((button) => {
      button.addEventListener('click', () => dialog.close());
    });
  });

  document.querySelectorAll('[data-zone-root]').forEach((root) => {
    const fields = {
      priority: root.querySelector('[data-zone="priority"]'),
      buffer: root.querySelector('[data-zone="buffer"]'),
      auto: root.querySelector('[data-zone="auto"]'),
    };
    if (!fields.priority || !fields.buffer || !fields.auto) return;
    const snap = (value) => Math.max(0, Math.min(100, Math.round(Number(value) / 5) * 5));
    const writeOut = (input) => {
      const out = input.parentElement && input.parentElement.querySelector('[data-range-out]');
      if (out) out.textContent = input.value + ' %';
    };
    const paint = (active) => {
      let priority = snap(fields.priority.value);
      let buffer = snap(fields.buffer.value);
      let auto = snap(fields.auto.value);
      if (active === 'buffer') {
        if (buffer < priority) priority = buffer;
        if (auto < buffer) auto = buffer;
      } else if (active === 'auto') {
        if (buffer > auto) buffer = auto;
        if (priority > buffer) priority = buffer;
      } else if (active === 'priority') {
        if (buffer < priority) buffer = priority;
        if (auto < buffer) auto = buffer;
      } else {
        if (buffer < priority) buffer = priority;
        if (auto < buffer) auto = buffer;
      }
      const next = { priority, buffer, auto };
      Object.keys(next).forEach((key) => {
        fields[key].value = String(next[key]);
        writeOut(fields[key]);
        root.querySelectorAll('[data-zone-read="' + key + '"]').forEach((node) => {
          node.textContent = String(next[key]);
        });
        root.querySelectorAll('[data-handle-value="' + key + '"]').forEach((node) => {
          node.textContent = String(next[key]);
        });
        root.querySelectorAll('[data-handle="' + key + '"]').forEach((node) => {
          node.setAttribute('aria-valuenow', String(next[key]));
        });
      });
      ['priority', 'buffer', 'auto'].forEach((key) => {
        root.querySelectorAll('[data-zone-mirror="' + key + '"]').forEach((input) => {
          input.value = String(next[key]);
          writeOut(input);
        });
      });
      root.querySelectorAll('[data-zone-tag="priority"]').forEach((node) => { node.hidden = !(priority > 0 && priority < 100); });
      root.querySelectorAll('[data-zone-tag="buffer"]').forEach((node) => { node.hidden = !(buffer > priority); });
      root.querySelectorAll('[data-zone-tag="auto"]').forEach((node) => { node.hidden = !(auto > buffer); });
      root.style.setProperty('--house', priority + '%');
      root.style.setProperty('--support', buffer + '%');
      root.style.setProperty('--auto', auto + '%');
    };
    Object.keys(fields).forEach((key) => {
      fields[key].addEventListener('input', () => paint(key));
    });
    root.querySelectorAll('[data-zone-mirror]').forEach((input) => {
      input.addEventListener('input', () => {
        const key = input.dataset.zoneMirror;
        if (!fields[key]) return;
        fields[key].value = input.value;
        paint(key);
      });
    });
    const names = { priority: 'priority', buffer: 'buffer', auto: 'auto' };
    root.querySelectorAll('[data-handle]').forEach((handle) => {
      const key = names[handle.dataset.handle] ? handle.dataset.handle : '';
      if (!key || !fields[key]) return;
      const moveTo = (clientY) => {
        const body = handle.closest('[data-zone-editor]')?.querySelector('[data-zone-body]');
        if (!body) return;
        const rect = body.getBoundingClientRect();
        const ratio = rect.height > 0 ? (rect.bottom - clientY) / rect.height : 0;
        fields[key].value = String(snap(ratio * 100));
        paint(key);
      };
      handle.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        handle.setPointerCapture(event.pointerId);
        moveTo(event.clientY);
      });
      handle.addEventListener('pointermove', (event) => {
        if (!handle.hasPointerCapture(event.pointerId)) return;
        moveTo(event.clientY);
      });
      handle.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown' && event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        const delta = (event.key === 'ArrowUp' || event.key === 'ArrowRight') ? 5 : -5;
        fields[key].value = String(snap(Number(fields[key].value) + delta));
        paint(key);
      });
    });
    paint('keep');
  });
  document.querySelectorAll('[data-battery-tools]').forEach((bar) => {
    bar.addEventListener('click', (event) => {
      const button = event.target.closest('button');
      const box = bar.parentElement ? bar.parentElement.querySelector('[data-pan]') : null;
      if (!button || !box || !box._chart || !box._payload) return;
      const chart = box._chart;
      const payload = box._payload;
      if (button.dataset.window) {
        const now = Number(payload.now);
        const day = 86400000;
        const spans = { 24: 1, 3: 3, 7: 7, 30: 30 };
        const width = (spans[button.dataset.window] || 1) * day;
        applyWindow(chart, payload, now - width, now);
        bar.querySelectorAll('[data-window]').forEach((item) => {
          item.setAttribute('aria-pressed', item.dataset.window === button.dataset.window ? 'true' : 'false');
        });
        return;
      }
      if (button.dataset.unit) {
        const unit = button.dataset.unit;
        const axis = payload.axes ? payload.axes[unit] : null;
        if (!axis) return;
        payload.unit = unit;
        payload.yMax = axis.yMax;
        payload.yStep = axis.yStep;
        payload.yDataMax = axis.yDataMax;
        payload.yDecimals = axis.yDecimals;
        payload.yTitle = axis.yTitle;
        chart.data.datasets.forEach((set) => { set.hidden = set.emsKey !== unit; });
        const scale = chart.options.scales.y;
        scale.title.text = axis.yTitle;
        scale.title.display = true;
        scale.min = 0;
        scale.max = Number(axis.yMax);
        if (scale.ticks) scale.ticks.stepSize = Number(axis.yStep);
        bar.querySelectorAll('[data-unit]').forEach((item) => {
          item.setAttribute('aria-pressed', item.dataset.unit === unit ? 'true' : 'false');
        });
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
      document.querySelectorAll('[data-soc-fill]').forEach((node) => {
        const raw = String(data.soc || '').replace('%', '').replace(',', '.').trim();
        const n = Number(raw);
        if (!Number.isFinite(n)) return;
        const host = node.closest('[data-battery]') || node.parentElement;
        if (!host) return;
        host.style.setProperty('--soc', Math.max(0, Math.min(100, n)) + '%');
        if (data.battery_flow) host.dataset.flow = data.battery_flow;
      });
      document.querySelectorAll('[data-car-battery]').forEach((host) => {
        const raw = data.car_soc_fill;
        const known = raw !== undefined && raw !== null && String(raw) !== '';
        host.dataset.carKnown = known ? '1' : '0';
        const n = known ? Number(raw) : 0;
        if (Number.isFinite(n)) host.style.setProperty('--soc', Math.max(0, Math.min(100, n)) + '%');
        if (data.car_flow) host.dataset.flow = data.car_flow;
      });
      document.querySelectorAll('[data-live-hide]').forEach((node) => {
        const value = data[node.dataset.liveHide];
        node.hidden = value === undefined || value === null || value === '';
      });
    } catch (error) {
      /* nächste Runde */
    }
  }

  function toggleSession(row) {
    const more = row.nextElementSibling;
    if (!more || !more.hasAttribute('data-session-more')) return;
    more.hidden = !more.hidden;
    row.setAttribute('aria-expanded', more.hidden ? 'false' : 'true');
  }
  document.addEventListener('click', (event) => {
    const row = event.target.closest('[data-session]');
    if (!row || event.target.closest('a, button, input, form, label')) return;
    toggleSession(row);
  });
  document.addEventListener('keydown', (event) => {
    const row = event.target.closest('[data-session]');
    if (!row || (event.key !== 'Enter' && event.key !== ' ')) return;
    if (event.target.closest('input, textarea, select, button')) return;
    event.preventDefault();
    toggleSession(row);
  });

  document.querySelectorAll('[data-tab]').forEach((link) => {
    let start = null;
    link.addEventListener('pointerdown', (event) => {
      if (event.pointerType === 'mouse') return;
      start = { x: event.clientX, y: event.clientY, id: event.pointerId };
    });
    link.addEventListener('pointerup', (event) => {
      if (!start || event.pointerId !== start.id) return;
      const dx = Math.abs(event.clientX - start.x);
      const dy = Math.abs(event.clientY - start.y);
      start = null;
      if (dx > 14 || dy > 14) return;
      event.preventDefault();
      const target = new URL(link.href, window.location.href);
      if (target.pathname !== window.location.pathname) window.location.assign(link.href);
    });
    link.addEventListener('pointercancel', () => { start = null; });
  });

  document.querySelectorAll('[data-colset]').forEach((box) => {
    const key = 'ems-cols-' + box.dataset.colset;
    let hidden = [];
    try { hidden = JSON.parse(localStorage.getItem(key) || '[]'); } catch (error) { hidden = []; }
    if (!Array.isArray(hidden)) hidden = [];
    const applyCols = () => {
      box.querySelectorAll('[data-col]').forEach((cell) => {
        cell.classList.toggle('hidden', hidden.includes(cell.dataset.col));
      });
      box.querySelectorAll('[data-col-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', hidden.includes(button.dataset.colToggle) ? 'false' : 'true');
      });
    };
    applyCols();
    box.addEventListener('click', (event) => {
      const button = event.target.closest('[data-col-toggle]');
      if (!button) return;
      const name = button.dataset.colToggle;
      hidden = hidden.includes(name) ? hidden.filter((item) => item !== name) : hidden.concat([name]);
      localStorage.setItem(key, JSON.stringify(hidden));
      applyCols();
    });
  });

  if (document.querySelector('[data-live]')) {
    tick();
    setInterval(tick, 5000);
  }
})();
