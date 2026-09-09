/* Конструктор план-графиков — клиентская часть. Без внешних зависимостей. */
(function () {
  'use strict';

  const CFG = window.APP_CONFIG || { api: 'api.php', export: 'export.php' };
  const TYPES = [
    { id: 'bar', label: 'Мероприятие' },
    { id: 'milestone', label: 'Ключевое событие' },
    { id: 'tentative', label: 'Ориентировочно' }
  ];
  const MONTHS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

  // ------------------------------------------------------------ состояние
  let plan = emptyPlan();
  let currentId = null;      // id сохранённого плана
  let dirty = false;

  function emptyPlan() {
    return {
      name: '', issuer: '', scale: 'day', startDate: nextMonday(), endDate: '',
      numbering: 'section', showComments: true, holidays: [], sections: []
    };
  }

  // ------------------------------------------------------------ даты
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function toISO(d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
  function parseISO(s) {
    if (!s || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return null;
    const d = new Date(s + 'T00:00:00Z');
    return isNaN(d.getTime()) ? null : d;
  }
  function addDays(iso, n) { const d = parseISO(iso); if (!d) return iso; d.setUTCDate(d.getUTCDate() + n); return toISO(d); }
  function diffDays(a, b) { return Math.round((parseISO(b) - parseISO(a)) / 86400000); }
  function weekday(iso) { const d = parseISO(iso); return d ? ((d.getUTCDay() + 6) % 7) + 1 : 1; } // 1 = пн
  function nextMonday() {
    const d = new Date(); d.setUTCHours(0, 0, 0, 0);
    const wd = (d.getUTCDay() + 6) % 7;
    d.setUTCDate(d.getUTCDate() + (wd === 0 ? 0 : 7 - wd));
    return toISO(d);
  }
  function fmtRu(iso) { const d = parseISO(iso); return d ? pad(d.getUTCDate()) + '.' + pad(d.getUTCMonth() + 1) + '.' + d.getUTCFullYear() : ''; }
  function fmtShort(iso) { const d = parseISO(iso); return d ? d.getUTCDate() + ' ' + MONTHS[d.getUTCMonth()] : ''; }
  function normDate(s) {
    s = (s || '').trim();
    let m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (m) return parseISO(s) ? s : null;
    m = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
    if (m) { const iso = m[3] + '-' + pad(+m[2]) + '-' + pad(+m[1]); return parseISO(iso) ? iso : null; }
    return null;
  }
  /** Сдвиг даты на n рабочих (или календарных) дней с сохранением "рабочести". */
  function shiftDate(iso, n, skipWeekends) {
    if (!skipWeekends || n === 0) return addDays(iso, n);
    let d = iso, step = n > 0 ? 1 : -1, left = Math.abs(n);
    while (left > 0) { d = addDays(d, step); if (weekday(d) <= 5) left--; }
    return d;
  }

  // ------------------------------------------------------------ утилиты
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));
  function el(tag, attrs, children) {
    const e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(k => {
      if (k === 'class') e.className = attrs[k];
      else if (k === 'text') e.textContent = attrs[k];
      else if (k === 'html') e.innerHTML = attrs[k];
      else if (k.indexOf('on') === 0) e.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== null && attrs[k] !== undefined) e.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(c => { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  let toastTimer = null;
  function toast(msg, isError) {
    const t = $('#toast');
    t.textContent = msg; t.className = 'toast' + (isError ? ' error' : ''); t.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.hidden = true; }, isError ? 7000 : 3000);
  }
  function api(action, params, post) {
    const url = CFG.api + '?action=' + encodeURIComponent(action) + (params && !post ? '&' + toQuery(params) : '');
    const opt = post ? { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: toQuery(params) } : {};
    return fetch(url, opt).then(r => r.json().then(j => { if (!r.ok) throw new Error(j.error || ('HTTP ' + r.status)); return j; }));
  }
  function toQuery(o) { return Object.keys(o).map(k => encodeURIComponent(k) + '=' + encodeURIComponent(o[k])).join('&'); }
  function markDirty() { dirty = true; schedulePreview(); }

  // ------------------------------------------------------------ модель
  function newTask(base) {
    const start = base || plan.startDate;
    return { name: '', responsible: '', comment: '', periods: [{ type: 'bar', start: start, end: shiftDate(start, 4, true) }] };
  }
  function newSection() { return { title: 'Новый раздел', tasks: [] }; }

  /** Приведение загруженного плана (пресет/файл) к рабочему виду: смещения -> даты. */
  function adoptPlan(src, baseDate) {
    const p = emptyPlan();
    p.name = src.name || ''; p.issuer = src.issuer || '';
    p.scale = src.scale === 'week' ? 'week' : 'day';
    p.numbering = src.numbering === 'global' ? 'global' : 'section';
    p.showComments = src.showComments !== false;
    p.startDate = baseDate || src.startDate || plan.startDate || nextMonday();
    p.endDate = src.endDate && src.startDate === p.startDate ? src.endDate : '';
    p.holidays = (src.holidays || []).map(normDate).filter(Boolean);
    p.sections = (src.sections || []).map(s => ({
      title: s.title || '',
      tasks: (s.tasks || []).map(t => ({
        name: t.name || '', responsible: t.responsible || '', comment: t.comment || '',
        periods: (t.periods || []).map(per => {
          const conv = v => (typeof v === 'number' ? addDays(p.startDate, v) : (normDate(String(v)) || p.startDate));
          let a = conv(per.start), b = conv(per.end === undefined ? per.start : per.end);
          if (a > b) { const x = a; a = b; b = x; }
          return { type: TYPES.some(t2 => t2.id === per.type) ? per.type : 'bar', start: a, end: b };
        })
      }))
    }));
    return p;
  }

  function shiftAll(n, skipWeekends) {
    plan.sections.forEach(s => s.tasks.forEach(t => t.periods.forEach(p => {
      p.start = shiftDate(p.start, n, skipWeekends); p.end = shiftDate(p.end, n, skipWeekends);
    })));
    if (plan.endDate) plan.endDate = addDays(plan.endDate, n);
  }

  function planRange() {
    let end = plan.endDate || null, minStart = plan.startDate;
    if (!end) {
      plan.sections.forEach(s => s.tasks.forEach(t => t.periods.forEach(p => { if (!end || p.end > end) end = p.end; if (p.start < minStart) minStart = p.start; })));
      end = end && end > plan.startDate ? end : addDays(plan.startDate, 27);
      if (plan.scale === 'day') end = addDays(end, 7 - weekday(end));
    }
    if (plan.scale === 'week') { const w = Math.ceil((diffDays(plan.startDate, end) + 1) / 7); end = addDays(plan.startDate, w * 7 - 1); }
    return { start: plan.startDate, end: end, minStart: minStart };
  }

  // ------------------------------------------------------------ форма параметров
  function bindSettings() {
    $('#fName').addEventListener('input', e => { plan.name = e.target.value; dirty = true; });
    $('#fIssuer').addEventListener('input', e => { plan.issuer = e.target.value; dirty = true; });
    $('#fStart').addEventListener('change', e => {
      const v = e.target.value;
      if (!parseISO(v)) { e.target.value = plan.startDate; return; }
      if ($('#fShiftWithStart').checked && plan.startDate) {
        const n = diffDays(plan.startDate, v);
        if (n) shiftAll(n, false);
      }
      plan.startDate = v; markDirty(); renderSections();
    });
    $('#fEnd').addEventListener('change', e => { plan.endDate = e.target.value || ''; markDirty(); });
    $('#fScale').addEventListener('change', e => { plan.scale = e.target.value; markDirty(); });
    $('#fNumbering').addEventListener('change', e => { plan.numbering = e.target.value; markDirty(); renderSections(); });
    $('#fComments').addEventListener('change', e => { plan.showComments = e.target.checked; markDirty(); renderSections(); });
    $('#fHolidays').addEventListener('change', e => {
      const bad = [];
      plan.holidays = e.target.value.split(/[\n,;]+/).map(s => s.trim()).filter(Boolean).map(s => { const d = normDate(s); if (!d) bad.push(s); return d; }).filter(Boolean);
      plan.holidays = Array.from(new Set(plan.holidays)).sort();
      e.target.value = plan.holidays.map(fmtRu).join('\n');
      if (bad.length) toast('Не распознаны даты: ' + bad.join(', '), true);
      markDirty();
    });
    $('#btnHolidaysRf').addEventListener('click', () => {
      const r = planRange(); const y1 = +r.start.slice(0, 4), y2 = +r.end.slice(0, 4);
      const fixed = ['01-01', '01-02', '01-03', '01-04', '01-05', '01-06', '01-07', '01-08', '02-23', '03-08', '05-01', '05-09', '06-12', '11-04'];
      const set = new Set(plan.holidays);
      for (let y = y1; y <= y2; y++) fixed.forEach(md => { const d = y + '-' + md; if (d >= r.start && d <= r.end) set.add(d); });
      plan.holidays = Array.from(set).sort();
      $('#fHolidays').value = plan.holidays.map(fmtRu).join('\n');
      $('.holidays').open = true; markDirty();
    });
    $('#btnShiftMinus').addEventListener('click', () => doShift(-1));
    $('#btnShiftPlus').addEventListener('click', () => doShift(1));
    $('#fPreview').addEventListener('change', schedulePreview);
  }
  function doShift(sign) {
    const n = parseInt($('#fShiftDays').value, 10) || 0;
    if (!n) return;
    shiftAll(sign * n, $('#fSkipWeekends').checked);
    renderSections(); markDirty();
    toast('Даты сдвинуты на ' + Math.abs(n) + ' дн. ' + (sign > 0 ? 'позже' : 'раньше'));
  }
  function fillSettings() {
    $('#fName').value = plan.name; $('#fIssuer').value = plan.issuer;
    $('#fStart').value = plan.startDate; $('#fEnd').value = plan.endDate || '';
    $('#fScale').value = plan.scale; $('#fNumbering').value = plan.numbering;
    $('#fComments').checked = !!plan.showComments;
    $('#fHolidays').value = plan.holidays.map(fmtRu).join('\n');
  }

  // ------------------------------------------------------------ разделы
  function renderSections() {
    const root = $('#sections');
    root.innerHTML = '';
    if (!plan.sections.length) {
      root.appendChild(el('div', { class: 'empty', text: 'Разделов пока нет. Загрузите пресет или добавьте раздел.' }));
      return;
    }
    let globalNo = 0;
    plan.sections.forEach((sec, si) => {
      const head = el('div', { class: 'section-head' }, [
        el('input', { type: 'text', class: 'sec-title', value: sec.title, placeholder: 'Название раздела',
          oninput: e => { sec.title = e.target.value; markDirty(); } }),
        el('span', { class: 'count', text: sec.tasks.length + ' мер.' }),
        el('button', { type: 'button', class: 'btn btn-sm', title: 'Выше', text: '↑', onclick: () => move(plan.sections, si, -1) }),
        el('button', { type: 'button', class: 'btn btn-sm', title: 'Ниже', text: '↓', onclick: () => move(plan.sections, si, 1) }),
        el('button', { type: 'button', class: 'btn btn-sm btn-danger', title: 'Удалить раздел', text: '✕', onclick: () => {
          if (!sec.tasks.length || confirm('Удалить раздел «' + sec.title + '» вместе с мероприятиями?')) { plan.sections.splice(si, 1); renderSections(); markDirty(); }
        } })
      ]);
      const thead = el('thead', null, [el('tr', null, [
        el('th', { class: 'num', text: '№' }),
        el('th', { class: 'col-name', text: 'Мероприятие' }),
        el('th', { class: 'col-resp', text: 'Ответственный' }),
        plan.showComments ? el('th', { class: 'col-comment', text: 'Комментарий' }) : null,
        el('th', { class: 'col-periods', text: 'Сроки' }),
        el('th', { class: 'col-actions' })
      ])]);
      const tbody = el('tbody');
      sec.tasks.forEach((task, ti) => {
        globalNo++;
        tbody.appendChild(renderTask(sec, task, ti, plan.numbering === 'global' ? globalNo : ti + 1));
      });
      const table = el('table', { class: 'tasks' }, [thead, tbody]);
      const foot = el('div', { class: 'add-task' }, [
        el('button', { type: 'button', class: 'btn btn-sm', text: '+ Мероприятие', onclick: () => {
          const last = sec.tasks.length ? sec.tasks[sec.tasks.length - 1] : null;
          const base = last && last.periods.length ? last.periods[last.periods.length - 1].end : plan.startDate;
          sec.tasks.push(newTask(base)); renderSections(); markDirty();
          const rows = $$('.section')[si].querySelectorAll('tbody tr'); const r = rows[rows.length - 1]; if (r) r.querySelector('textarea').focus();
        } })
      ]);
      root.appendChild(el('div', { class: 'section' }, [head, table, foot]));
    });
  }

  function renderTask(sec, task, ti, no) {
    const periodsBox = el('div', { class: 'periods' });
    const renderPeriods = () => {
      periodsBox.innerHTML = '';
      task.periods.forEach((p, pi) => {
        const sel = el('select', { class: 't-' + p.type, onchange: e => { p.type = e.target.value; sel.className = 't-' + p.type; markDirty(); } },
          TYPES.map(t => el('option', { value: t.id, text: t.label, selected: t.id === p.type ? 'selected' : null })));
        const inStart = el('input', { type: 'date', value: p.start, onchange: e => {
          if (!parseISO(e.target.value)) { e.target.value = p.start; return; }
          const len = diffDays(p.start, p.end); p.start = e.target.value;
          if (p.end < p.start) { p.end = addDays(p.start, Math.max(0, len)); inEnd.value = p.end; }
          markDirty();
        } });
        const inEnd = el('input', { type: 'date', value: p.end, onchange: e => {
          if (!parseISO(e.target.value)) { e.target.value = p.end; return; }
          p.end = e.target.value; if (p.end < p.start) { p.start = p.end; inStart.value = p.start; } markDirty();
        } });
        periodsBox.appendChild(el('div', { class: 'period' }, [sel, inStart, el('span', { class: 'dash', text: '—' }), inEnd,
          el('button', { type: 'button', class: 'btn btn-sm btn-ghost', title: 'Удалить период', text: '✕', onclick: () => { task.periods.splice(pi, 1); renderPeriods(); markDirty(); } })]));
      });
      periodsBox.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-ghost', text: '+ период', onclick: () => {
        const last = task.periods[task.periods.length - 1];
        const s = last ? shiftDate(last.end, 1, true) : plan.startDate;
        task.periods.push({ type: 'bar', start: s, end: shiftDate(s, 4, true) }); renderPeriods(); markDirty();
      } }));
    };
    renderPeriods();
    const cells = [
      el('td', { class: 'num', text: String(no) }),
      el('td', null, [autoArea(task.name, 'Название мероприятия', v => { task.name = v; })]),
      el('td', null, [autoArea(task.responsible, 'Эмитент / Организаторы', v => { task.responsible = v; })]),
      plan.showComments ? el('td', null, [autoArea(task.comment, 'Комментарий', v => { task.comment = v; })]) : null,
      el('td', null, [periodsBox]),
      el('td', null, [el('div', { class: 'actions' }, [
        el('button', { type: 'button', class: 'btn', title: 'Выше', text: '↑', onclick: () => move(sec.tasks, ti, -1) }),
        el('button', { type: 'button', class: 'btn', title: 'Ниже', text: '↓', onclick: () => move(sec.tasks, ti, 1) }),
        el('button', { type: 'button', class: 'btn', title: 'Дублировать', text: '⧉', onclick: () => { sec.tasks.splice(ti + 1, 0, JSON.parse(JSON.stringify(task))); renderSections(); markDirty(); } }),
        el('button', { type: 'button', class: 'btn btn-danger', title: 'Удалить', text: '✕', onclick: () => { sec.tasks.splice(ti, 1); renderSections(); markDirty(); } })
      ])])
    ];
    return el('tr', null, cells);
  }

  function autoArea(value, placeholder, onInput) {
    const ta = el('textarea', { rows: 1, placeholder: placeholder, oninput: e => { onInput(e.target.value); fit(e.target); markDirty(); } });
    ta.value = value;
    setTimeout(() => fit(ta), 0);
    return ta;
  }
  function fit(ta) { ta.style.height = 'auto'; ta.style.height = Math.max(30, ta.scrollHeight + 2) + 'px'; }

  function move(arr, i, dir) {
    const j = i + dir; if (j < 0 || j >= arr.length) return;
    const t = arr[i]; arr[i] = arr[j]; arr[j] = t; renderSections(); markDirty();
  }

  // ------------------------------------------------------------ предпросмотр
  let previewTimer = null;
  function schedulePreview() { clearTimeout(previewTimer); previewTimer = setTimeout(renderPreview, 250); }
  function renderPreview() {
    const box = $('#preview');
    if (!$('#fPreview').checked) { box.innerHTML = ''; return; }
    if (!plan.sections.length) { box.innerHTML = '<div class="empty">Нет данных</div>'; return; }
    const r = planRange(), isDay = plan.scale === 'day';
    const total = diffDays(r.start, r.end) + 1;
    const n = isDay ? total : Math.ceil(total / 7);
    if (n > 800) { box.innerHTML = '<div class="empty">Слишком длинный период для предпросмотра</div>'; return; }
    const hol = new Set(plan.holidays);
    const cols = [];
    for (let i = 0; i < n; i++) { const d = addDays(r.start, isDay ? i : i * 7); cols.push({ d: d, end: isDay ? d : addDays(d, 6), off: isDay && (weekday(d) >= 6 || hol.has(d)) }); }
    const hdr = el('tr', null, [el('th', { class: 'hn', text: '№' }), el('th', { class: 'hname', text: 'Мероприятие' }), el('th', { class: 'hresp', text: 'Ответственный' })]);
    cols.forEach((c, i) => hdr.appendChild(isDay
      ? el('th', { class: 'd' + (c.off ? ' wk' : '') }, [el('span', { text: fmtShort(c.d) })])
      : el('th', { class: 'w', text: String(i + 1), title: fmtRu(c.d) + ' — ' + fmtRu(c.end) })));
    if (plan.showComments) hdr.appendChild(el('th', { class: 'hcomm', text: 'Комментарий' }));
    const tbody = el('tbody');
    let g = 0;
    plan.sections.forEach(sec => {
      const tr = el('tr', { class: 'sec' });
      tr.appendChild(el('td', { colspan: 2, text: sec.title }));
      tr.appendChild(el('td', { colspan: cols.length + 1 + (plan.showComments ? 1 : 0) }));
      tbody.appendChild(tr);
      sec.tasks.forEach((t, ti) => {
        g++;
        const row = el('tr', null, [
          el('td', { class: 'hn', text: String(plan.numbering === 'global' ? g : ti + 1) }),
          el('td', { class: 'name', text: t.name }),
          el('td', { class: 'resp', text: t.responsible })
        ]);
        cols.forEach(c => {
          let cls = 'c';
          if (c.off) cls += ' c-wk';
          else {
            let k = null;
            t.periods.forEach(p => { if (p.start <= c.end && p.end >= c.d && (!k || p.type === 'milestone')) k = p.type; });
            if (k) cls += ' c-' + k;
          }
          row.appendChild(el('td', { class: cls }));
        });
        if (plan.showComments) row.appendChild(el('td', { class: 'comm', text: t.comment }));
        tbody.appendChild(row);
      });
    });
    box.innerHTML = '';
    box.appendChild(el('table', null, [el('thead', null, [hdr]), tbody]));
    if (r.minStart < r.start) toast('Есть мероприятия раньше даты начала плана (' + fmtRu(r.start) + ')', true);
  }

  // ------------------------------------------------------------ пресеты и сохранения
  function loadPresetList() {
    return api('presets').then(j => {
      const s = $('#presetSelect'); s.innerHTML = '';
      j.items.forEach(it => s.appendChild(el('option', { value: it.id, text: it.name })));
    }).catch(e => toast('Не удалось получить список пресетов: ' + e.message, true));
  }
  function loadSavedList(selectId) {
    return api('list').then(j => {
      const s = $('#savedSelect'); s.innerHTML = '';
      if (!j.items.length) s.appendChild(el('option', { value: '', text: '— нет сохранённых —' }));
      j.items.forEach(it => s.appendChild(el('option', { value: it.id, text: it.name + ' (' + it.updated + ')' })));
      if (selectId) s.value = selectId;
    }).catch(e => toast('Не удалось получить список планов: ' + e.message, true));
  }
  function confirmDiscard() { return !dirty || confirm('Несохранённые изменения будут потеряны. Продолжить?'); }
  function applyPlan(p, id) {
    plan = p; currentId = id || null; dirty = false;
    fillSettings(); renderSections(); renderPreview();
  }

  function bindToolbar() {
    $('#btnPreset').addEventListener('click', () => {
      const id = $('#presetSelect').value; if (!id || !confirmDiscard()) return;
      api('preset', { id: id }).then(src => {
        let base = src.startDate || null;
        const wanted = $('#fStart').value;
        if (!src.startDate) base = wanted || nextMonday();
        else if (wanted && wanted !== src.startDate && confirm('Пресет рассчитан от ' + fmtRu(src.startDate) + '. Перенести его на дату начала ' + fmtRu(wanted) + '?')) base = wanted;
        const p = adoptPlan(src, base);
        if (!p.issuer && plan.issuer) p.issuer = plan.issuer;
        applyPlan(p, null); dirty = true;
        toast('Пресет загружен: ' + src.name);
      }).catch(e => toast(e.message, true));
    });
    $('#btnOpen').addEventListener('click', () => {
      const id = $('#savedSelect').value; if (!id || !confirmDiscard()) return;
      api('load', { id: id }).then(src => { applyPlan(adoptPlan(src, src.startDate), id); toast('План открыт'); }).catch(e => toast(e.message, true));
    });
    $('#btnDelete').addEventListener('click', () => {
      const id = $('#savedSelect').value; if (!id) return;
      if (!confirm('Удалить сохранённый план?')) return;
      api('delete', { id: id }, true).then(() => { if (currentId === id) currentId = null; loadSavedList(); toast('План удалён'); }).catch(e => toast(e.message, true));
    });
    const save = asNew => {
      const params = { plan: JSON.stringify(plan) };
      if (currentId && !asNew) params.id = currentId;
      api('save', params, true).then(j => { currentId = j.id; dirty = false; plan.name = j.name; $('#fName').value = j.name; loadSavedList(j.id); toast('Сохранено: ' + j.name); }).catch(e => toast('Ошибка сохранения: ' + e.message, true));
    };
    $('#btnSave').addEventListener('click', () => save(false));
    $('#btnSaveAs').addEventListener('click', () => save(true));

    $('#btnExportJson').addEventListener('click', () => {
      const blob = new Blob([JSON.stringify(plan, null, 1)], { type: 'application/json' });
      downloadBlob(blob, (plan.name || 'план-график') + '.json');
    });
    $('#btnImport').addEventListener('click', () => { if (confirmDiscard()) $('#fileImport').click(); });
    $('#fileImport').addEventListener('change', e => {
      const f = e.target.files[0]; if (!f) return;
      const fr = new FileReader();
      fr.onload = () => {
        try { const src = JSON.parse(fr.result); applyPlan(adoptPlan(src, src.startDate || $('#fStart').value), null); dirty = true; toast('План импортирован'); }
        catch (err) { toast('Не удалось прочитать JSON: ' + err.message, true); }
        e.target.value = '';
      };
      fr.readAsText(f, 'UTF-8');
    });
    $('#btnXlsx').addEventListener('click', () => exportXlsx(false));
  }

  function exportXlsx(force) {
    const btn = $('#btnXlsx'); btn.disabled = true;
    const body = Object.assign({}, plan, force ? { force: 1 } : {});
    const json = JSON.stringify(body);
    // 1) проверка на сервере, 2) обычная отправка формы — браузер сам сохранит файл с именем из заголовка
    fetch(CFG.export + '?validate=1', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: toQuery({ plan: json }) })
      .then(r => r.json())
      .then(j => {
        if (j.errors && !force) {
          if (confirm('Замечания:\n— ' + j.errors.join('\n— ') + '\n\nВсё равно сформировать файл?')) return exportXlsx(true);
          return;
        }
        let frame = $('#dlframe');
        if (!frame) { frame = el('iframe', { id: 'dlframe', name: 'dlframe', hidden: '' }); document.body.appendChild(frame); }
        const form = el('form', { method: 'POST', action: CFG.export, target: 'dlframe', hidden: '' }, [el('input', { type: 'hidden', name: 'plan', value: json })]);
        document.body.appendChild(form); form.submit(); form.remove();
        toast('Файл формируется…');
      })
      .catch(e => toast('Ошибка выгрузки: ' + e.message, true))
      .then(() => { btn.disabled = false; });
  }
  function downloadBlob(blob, name) {
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }

  // ------------------------------------------------------------ старт
  document.addEventListener('DOMContentLoaded', () => {
    bindSettings(); bindToolbar();
    $('#btnAddSection').addEventListener('click', () => { plan.sections.push(newSection()); renderSections(); markDirty(); const t = $$('.sec-title'); if (t.length) t[t.length - 1].select(); });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    fillSettings(); renderSections(); renderPreview();
    loadPresetList(); loadSavedList();
  });
})();
