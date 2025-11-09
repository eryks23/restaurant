// calendar.js
// Klient kalendarza do współpracy z get-availability.php
// Backend przyjmuje parametry: from=YYYY-MM-DD&to=YYYY-MM-DD
// Odpowiedź: { ok: true, count: N, slots: [{ id, date, time, capacity, reserved_count, available }] }

// AUTOMATYCZNE DOBIERANIE API_BASE (poprawione)
(function(){
  let API_BASE;
  const PROJECT_PATH = '/vectron-utk-sim'; // zmień, jeśli potrzebujesz innej ścieżki w localhost

  if (location.protocol === 'file:') {
    try {
      const lp = location.pathname || '';
      let normalized = lp.replace(/\\/g, '/').replace(/^\/(?=[A-Za-z]:\/)/, '');
      const marker = '/xampp/htdocs';
      const idx = normalized.toLowerCase().indexOf(marker);
      if (idx !== -1) {
        const relative = normalized.slice(idx + marker.length);
        const baseUrl = 'http://localhost' + (relative.startsWith('/') ? '' : '/') + relative;
        const folder = baseUrl.replace(/\/[^\/]*$/, '');
        API_BASE = folder + '/api/get-availability.php';
        console.info('⚠️ file: mode -> mapped API:', API_BASE);
      } else {
        API_BASE = 'http://localhost' + PROJECT_PATH + '/api/get-availability.php';
        console.warn('⚠️ file: mode but XAMPP path not detected. Using default API_BASE:', API_BASE);
      }
    } catch (e) {
      API_BASE = 'http://localhost' + PROJECT_PATH + '/api/get-availability.php';
      console.warn('⚠️ Error mapping file: -> http:, fallback API_BASE:', API_BASE, e);
    }

    console.warn('If HTTP requests to ' + API_BASE + ' fail, run XAMPP and open via http://localhost');
  } else {
    API_BASE = (location.origin || '') + PROJECT_PATH + '/api/get-availability.php';
  }

  window.__SIM_API_BASE = API_BASE;
})();

(function () {
  'use strict';

  // --- Konfiguracja ---
  const API_BASE = window.__SIM_API_BASE || 'http://127.0.0.1:5000/vectron-utk-sim/api/get-availability.php';
  const POLL_MS = 30 * 1000; // odświeżanie co 30s
  const FETCH_TIMEOUT_MS = 8000;

  // --- DOM ---
  const slotsContainer = document.getElementById("slotsContainer");
  const bookingForm = document.getElementById("bookingForm");
  const bookingSlotInput = bookingForm
    ? bookingForm.querySelector('input[name="booking_slot_id"]')
    : document.getElementById('booking_slot_id');
  const datePicker = document.getElementById("datePicker");
  const calendarContainer = document.getElementById("calendarContainer");
  const calendarLabel = document.getElementById("calendarLabel");
  const calPrev = document.getElementById("calPrev");
  const calNext = document.getElementById("calNext");

  // --- Stan ---
  let currentAbortController = null;
  let pollIntervalId = null;
  let currentSelectedDate = null; // 'YYYY-MM-DD'
  let viewMonth = new Date();
  viewMonth.setDate(1);
  let latestRequestId = 0;

  // --- Formatowanie ---
  const formatter = new Intl.DateTimeFormat("pl-PL", {
    timeZone: "Europe/Warsaw",
    hour: "2-digit",
    minute: "2-digit"
  });

  // --- Utils ---
  function yyyyMMdd(date) {
    return date.getFullYear() + '-' +
      String(date.getMonth() + 1).padStart(2, '0') + '-' +
      String(date.getDate()).padStart(2, '0');
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, m =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  // fetch z timeout (wrzuca AbortController)
  function fetchWithTimeout(url, options = {}, timeout = FETCH_TIMEOUT_MS) {
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), timeout);
    const combined = { ...options, signal: controller.signal };
    return fetch(url, combined)
      .finally(() => clearTimeout(id));
  }

  // Budowanie URL (zawsze from/to) — backend wymaga from & to
  function buildAvailabilityUrl(param) {
    // jeśli podano string (pojedyncza data) — wysyłamy from=...&to=...
    if (typeof param === "string" && param.trim() !== "") {
      const d = param.trim();
      return `${API_BASE}?from=${encodeURIComponent(d)}&to=${encodeURIComponent(d)}`;
    }

    // jeśli podano obiekt z from/to
    if (param && typeof param === "object") {
      const from = (param.from ?? "").toString().trim();
      const to   = (param.to ?? "").toString().trim();
      if (from !== "" && to !== "") {
        return `${API_BASE}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
      }
      if (from !== "" && to === "") {
        return `${API_BASE}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(from)}`;
      }
    }

    // fallback: użyj currentSelectedDate (jeśli ustawione)
    if (typeof currentSelectedDate !== 'undefined' && currentSelectedDate) {
      const d = String(currentSelectedDate).trim();
      return `${API_BASE}?from=${encodeURIComponent(d)}&to=${encodeURIComponent(d)}`;
    }

    // brak parametrów -> nie wysyłamy requestu
    return null;
  }

  // --- Pokazywanie błędów w UI ---
  function showError(message) {
    if (!slotsContainer) return;
    const msg = message || 'Wystąpił błąd. Spróbuj ponownie.';
    slotsContainer.innerHTML = `<div class="error">${escapeHtml(msg)}</div>`;
  }

  // --- Normalize backend response to slots array ---
  function normalizeResponseToSlots(resp) {
    if (!resp) return [];
    if (resp.ok === true && Array.isArray(resp.slots)) {
      return resp.slots.map(s => ({
        id: s.id ?? s.slot_id ?? null,
        date: s.date ?? null,
        time: s.time ?? null,
        capacity: typeof s.capacity !== 'undefined' ? parseInt(s.capacity, 10) : null,
        reserved_count: typeof s.reserved_count !== 'undefined' ? parseInt(s.reserved_count, 10) : 0,
        available: typeof s.available !== 'undefined' ? !!s.available : (typeof s.reserved_count !== 'undefined' && typeof s.capacity !== 'undefined' ? (parseInt(s.reserved_count,10) < parseInt(s.capacity,10)) : true),
        raw: s
      }));
    }

    if (Array.isArray(resp.slots)) {
      return resp.slots.map(s => ({
        id: s.id ?? s.slot_id ?? null,
        date: s.date ?? null,
        time: s.time ?? null,
        capacity: typeof s.capacity !== 'undefined' ? parseInt(s.capacity, 10) : null,
        reserved_count: typeof s.reserved_count !== 'undefined' ? parseInt(s.reserved_count, 10) : 0,
        available: typeof s.available !== 'undefined' ? !!s.available : true,
        raw: s
      }));
    }

    if (typeof resp === 'object' && !Array.isArray(resp)) {
      const slots = [];
      Object.keys(resp).forEach(k => {
        if (/^\d{4}-\d{2}-\d{2}$/.test(k) && Array.isArray(resp[k])) {
          resp[k].forEach(h => {
            if (typeof h === 'string') {
              slots.push({ id: null, date: k, time: h, available: true, raw: { date: k, time: h }});
            } else if (typeof h === 'object') {
              const time = h.time ?? h.hour ?? null;
              slots.push({ id: h.id ?? null, date: k, time: time, available: typeof h.available==='boolean'?h.available:true, raw: h });
            }
          });
        }
      });
      return slots;
    }

    return [];
  }

  // --- Parsowanie daty slotu do obiektu Date ---
  function parseSlotToDate(slot) {
    if (!slot) return null;
    const s = slot;
    if (s.date && s.time) {
      const time = s.time.length === 5 ? s.time + ':00' : s.time;
      const isoLocal = `${s.date}T${time}`;
      return new Date(isoLocal);
    }
    if (s.iso) {
      const t = s.iso.replace(' ', 'T');
      return new Date(t);
    }
    if (s.start_ts) {
      const t = s.start_ts.replace(' ', 'T');
      return new Date(t);
    }
    return null;
  }

  // --- Render slotów (dane z DB) ---
// --- Render slotów (dane z DB). Jeśli brak slotów -> wygeneruj domyślne kafelki 08:00-14:00 ---
function renderSlots(data) {
  console.debug('renderSlots called with:', data);
  if (!slotsContainer) return;

  const normalized = normalizeResponseToSlots(data);
  console.debug('renderSlots normalized ->', normalized);

  // jeśli backend zwrócił dostępne sloty
  if (normalized && normalized.length > 0) {
    normalized.sort((a, b) => {
      if (a.date === b.date) return (a.time || '').localeCompare(b.time || '');
      return (a.date || '').localeCompare(b.date || '');
    });

    slotsContainer.innerHTML = '';
    normalized.forEach(slot => {
      const el = document.createElement('div');
      el.classList.add('slot');
      el.setAttribute('tabindex', '0');
      el.setAttribute('role', 'button');

      const dateObj = parseSlotToDate(slot.raw);
      const label = dateObj && !isNaN(dateObj.getTime())
        ? formatter.format(dateObj)
        : ((slot.date && slot.time) ? `${slot.date} ${slot.time}` : '—');
      el.textContent = label;

      if (slot.available) {
        el.classList.add('available');
        el.addEventListener('click', () => selectSlot(el, slot.id));
      } else {
        el.classList.add('booked');
        el.setAttribute('aria-disabled', 'true');
      }

      try { el.dataset.slotRaw = JSON.stringify(slot.raw); } catch(e) { el.dataset.slotRaw = ''; }
      slotsContainer.appendChild(el);
    });
    return;
  }

  // --- BRAK slotów -> generujemy domyślne kafelki godzinowe ---
  const dateForTiles = (typeof currentSelectedDate !== 'undefined' && currentSelectedDate)
    ? currentSelectedDate
    : yyyyMMdd(new Date());

const dateObj = new Date(dateForTiles + 'T00:00'); // obiekt Date dla danego dnia
const day = dateObj.getDay(); // 0=Nd, 6=Sb

let startHour, endHour;
if (day === 0 || day === 6) { // Sobota/Niedziela
  startHour = 14;
  endHour = 21;
} else { // Pon–Pt
  startHour = 9;
  endHour = 20;
}

const times = [];
for (let h = startHour; h <= endHour; h++) {
  times.push(String(h).padStart(2,'0') + ':00');
}


  slotsContainer.innerHTML = '';

  // Pobierz input <time> z formularza
  const timeInput = document.querySelector('#timePicker');

  times.forEach(time => {
    const el = document.createElement('div');
    el.classList.add('slot', 'available');
    el.setAttribute('tabindex', '0');
    el.setAttribute('role', 'button');
    el.textContent = time;

    const syntheticId = `${dateForTiles} ${time}`;

    el.addEventListener('click', () => {
      // Zaznacz kafelek
      selectSlot(el, syntheticId);
      
      // Wpisz czas do inputa <time>
      if (timeInput) {
        timeInput.value = time;
        console.debug('Ustawiono timePicker.value =', time);
      }

      // Jeśli używasz też hidden inputa booking_slot_id
      if (typeof bookingSlotInput !== 'undefined' && bookingSlotInput) {
        bookingSlotInput.value = syntheticId;
      }
    });

    try {
      el.dataset.slotRaw = JSON.stringify({ id: syntheticId, date: dateForTiles, time });
    } catch (e) {
      el.dataset.slotRaw = '';
    }

    slotsContainer.appendChild(el);
  });
}


  // --- Select slot ---
  function selectSlot(el, id) {
    const prev = slotsContainer.querySelector('.slot.selected');
    if (prev) prev.classList.remove('selected');
    el.classList.add('selected');
    if (bookingSlotInput && id !== null) bookingSlotInput.value = id;
  }

  // --- Główna funkcja pobierająca dostępność (date string lub object from/to) ---
  async function fetchAvailability(param) {
    if (currentAbortController) {
      currentAbortController.abort();
      console.debug('fetchAvailability: previous request aborted.');
    }
    const myId = ++latestRequestId;
    currentAbortController = new AbortController();

    console.debug('fetchAvailability called. param:', param, 'currentSelectedDate:', currentSelectedDate);

    const url = buildAvailabilityUrl(param);
    if (!url) {
      console.warn('fetchAvailability: no URL (no params). param:', param);
      if (slotsContainer) slotsContainer.innerHTML = `<div class="error">Brak parametrów daty do pobrania dostępności.</div>`;
      return null;
    }

    console.debug('fetchAvailability: fetching URL:', url);
    if (slotsContainer) slotsContainer.innerHTML = '<div class="loading">Ładowanie…</div>';

    try {
      const resp = await fetchWithTimeout(url, { signal: currentAbortController.signal, headers: { 'Accept': 'application/json' } }, FETCH_TIMEOUT_MS);
      const text = await resp.text();
      let data = null;
      try {
        data = text ? JSON.parse(text) : null;
      } catch (e) {
        throw new Error('Invalid JSON from server: ' + e.message + ' - raw: ' + (text ? text.substring(0,500) : ''));
      }

      if (!resp.ok) {
        throw new Error(data?.error || `HTTP ${resp.status}`);
      }

      if (myId !== latestRequestId) {
        console.debug('fetchAvailability: stale response (myId mismatch), ignoring.');
        return;
      }

      console.debug('fetchAvailability result:', data);
      renderSlots(data);
      return data;
    } catch (err) {
      if (err.name === 'AbortError') {
        console.debug('fetchAvailability aborted for myId', myId);
        return;
      }
      console.error('fetchAvailability error:', err);
      showError(err.message || 'Błąd pobierania dostępności');
    }
  }

  // --- Pobieranie dostępności dla zakresu (from,to) - helper ---
  window.SimulatorCalendar = window.SimulatorCalendar || {};
  window.SimulatorCalendar.fetchAvailabilityRange = async function(from, to, { retries = 2, timeout = FETCH_TIMEOUT_MS } = {}) {
    if (!from || !to) throw new Error('fetchAvailabilityRange: from and to required');
    const base = API_BASE;
    const sep = base.includes('?') ? '&' : '?';
    const url = `${base}${sep}from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
    for (let attempt = 0; attempt <= retries; attempt++) {
      try {
        const resp = await fetchWithTimeout(url, { method: 'GET', headers: { 'Accept': 'application/json' } }, timeout);
        if (!resp.ok) {
          const txt = await resp.text().catch(()=>null);
          throw new Error(`HTTP ${resp.status} ${resp.statusText} - ${txt}`);
        }
        const data = await resp.json().catch(err => { throw new Error('Invalid JSON: ' + err.message); });
        return data;
      } catch (e) {
        console.warn('fetchAvailabilityRange attempt failed:', attempt+1, e);
        if (attempt === retries) throw e;
        await new Promise(r => setTimeout(r, 200 * (attempt+1)));
      }
    }
  };

  // --- Oznaczanie zarezerwowanych dni w miesiącu ---
  async function markReservedDaysForMonth(year, month) {
    const first = yyyyMMdd(new Date(year, month, 1));
    const last = yyyyMMdd(new Date(year, month + 1, 0));

    if (!calendarContainer) return;

    const url = buildAvailabilityUrl({ from: first, to: last });
    if (!url) {
      console.debug('markReservedDaysForMonth: no url (skipping):', first, last);
      return;
    }

    try {
      const resp = await fetchWithTimeout(url, { method: 'GET', headers: { 'Accept': 'application/json' } }, FETCH_TIMEOUT_MS);
      if (!resp.ok) {
        const txt = await resp.text().catch(()=>null);
        console.error('markReservedDaysForMonth: network error', resp.status, resp.statusText, txt);
        return;
      }
      const data = await resp.json().catch(() => null);
      if (!data) return;

      let grouped = {};
      if (Array.isArray(data.slots)) {
        data.slots.forEach(s => {
          const d = s.date || (s.iso ? s.iso.split('T')[0] : null);
          if (!d) return;
          grouped[d] = grouped[d] || [];
          grouped[d].push(s);
        });
      } else if (Array.isArray(data.days)) {
        data.days.forEach(d => { grouped[d.date] = d.slots || []; });
      } else {
        console.debug('markReservedDaysForMonth: unknown format', data);
      }

      Object.keys(grouped).forEach(date => {
        const btn = calendarContainer.querySelector(`[data-date="${date}"]`);
        if (!btn) return;
        const slots = grouped[date] || [];
        const total = slots.length;
        const booked = slots.filter(s => s.available === false || (typeof s.reserved_count !== 'undefined' && typeof s.capacity !== 'undefined' && parseInt(s.reserved_count,10) >= parseInt(s.capacity,10))).length;
        btn.classList.remove('fully-booked','partially-booked');
        btn.removeAttribute('aria-disabled');
        if (total > 0 && booked === total) {
          btn.classList.add('fully-booked');
          btn.setAttribute('aria-disabled','true');
        } else if (booked > 0) {
          btn.classList.add('partially-booked');
        }
      });

    } catch (e) {
      if (e.name === 'AbortError') {
        console.debug('markReservedDaysForMonth aborted.');
        return;
      }
      console.warn('markReservedDaysForMonth error:', e);
    }
  }

  // --- Render calendar month ---
  function getWeekdaysMonToFri(locale) {
    try {
      const base = new Date(Date.UTC(2021, 0, 4));
      const fmt = new Intl.DateTimeFormat(locale || 'pl-PL', { weekday: 'short' });
      const names = [];
      for (let i = 0; i < 7; i++) {
        const d = new Date(base);
        d.setDate(base.getDate() + i);
        names.push(fmt.format(d));
      }
      return names.map(n => n.charAt(0).toUpperCase() + n.slice(1).replace('.', ''));
    } catch {
      return ["Pon","Wt","Śr","Czw","Pt"];
    }
  }

  function renderCalendarMonth(dateObj) {
    if (!calendarContainer || !calendarLabel) return;
    calendarContainer.innerHTML = '';
    calendarContainer.classList.add('calendar-wrapper');

    const year = dateObj.getFullYear();
    const month = dateObj.getMonth();

    const monthLabel = dateObj.toLocaleString('pl-PL', { month: 'long', year: 'numeric' });
    calendarLabel.textContent = monthLabel.charAt(0).toUpperCase() + monthLabel.slice(1);

    const weekDays = getWeekdaysMonToFri('pl-PL');
    const header = document.createElement('div');
    header.className = 'calendar-headers';
    weekDays.forEach(name => {
      const h = document.createElement('div');
      h.className = 'calendar-day-header';
      h.textContent = name;
      header.appendChild(h);
    });
    calendarContainer.appendChild(header);

    const grid = document.createElement('div');
    grid.className = 'calendar-grid';
    grid.style.display = 'grid';
    grid.style.gridTemplateColumns = 'repeat(7, 1fr)';
    grid.style.gap = '0.5rem';

    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const totalDays = lastDay.getDate();

    function weekdayIndex(jsDay) {
      return (jsDay + 6) % 7;
    }

    const firstIdx = weekdayIndex(firstDay.getDay());
    const leading = firstIdx >= 0 ? firstIdx : 0;
    const cells = [];
    for (let i = 0; i < leading; i++) cells.push({ type: 'empty' });

    for (let d = 1; d <= totalDays; d++) {
      const cur = new Date(year, month, d);
      const idx = weekdayIndex(cur.getDay());
      if (idx === -1) continue;
      cells.push({ type: 'day', day: d, dateStr: yyyyMMdd(cur) });
    }

    const today = yyyyMMdd(new Date());
    cells.forEach(cell => {
      if (cell.type === 'empty') {
        const div = document.createElement('div');
        div.className = 'calendar-empty';
        div.innerHTML = '&nbsp;';
        grid.appendChild(div);
      } else {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'calendar-day';
        btn.textContent = cell.day;
        btn.setAttribute('data-date', cell.dateStr);
        if (cell.dateStr === today) btn.classList.add('today');
        if (currentSelectedDate === cell.dateStr) btn.classList.add('selected');

        btn.addEventListener('click', () => {
          calendarContainer.querySelectorAll('.calendar-day.selected').forEach(b => b.classList.remove('selected'));
          btn.classList.add('selected');
          onDateSelected(cell.dateStr);
        });

        grid.appendChild(btn);
      }
    });

    calendarContainer.appendChild(grid);

    // Po wyrenderowaniu oznacz dni (asynchronicznie)
    setTimeout(() => markReservedDaysForMonth(year, month), 0);
  }

  // --- On date selected ---
  async function onDateSelected(dateStr) {
    console.debug('onDateSelected called with:', dateStr);
    currentSelectedDate = dateStr;
    if (datePicker) datePicker.value = dateStr;

    if (slotsContainer) slotsContainer.innerHTML = '<div class="loading">Ładowanie…</div>';

    try {
      const res = await fetchAvailability(dateStr);
      console.debug('onDateSelected: fetchAvailability returned:', res);
    } catch (e) {
      console.error('onDateSelected: fetchAvailability threw:', e);
    }

    if (pollIntervalId) clearInterval(pollIntervalId);
    pollIntervalId = setInterval(() => fetchAvailability(dateStr), POLL_MS);
  }

  // --- Navigation handlers ---
  if (calPrev) calPrev.addEventListener('click', () => {
    viewMonth.setMonth(viewMonth.getMonth() - 1);
    renderCalendarMonth(viewMonth);
  });
  if (calNext) calNext.addEventListener('click', () => {
    viewMonth.setMonth(viewMonth.getMonth() + 1);
    renderCalendarMonth(viewMonth);
  });

  // --- Init ---
  function init() {
    if (datePicker) {
      datePicker.addEventListener('change', function () {
        if (this.value) onDateSelected(this.value);
      });
    }

    renderCalendarMonth(viewMonth);

    // wybierz dziś automatycznie do debugu / UX
    const today = yyyyMMdd(new Date());
    if (!currentSelectedDate) {
      const todayBtn = calendarContainer && calendarContainer.querySelector(`[data-date="${today}"]`);
      if (todayBtn) {
        calendarContainer.querySelectorAll('.calendar-day.selected').forEach(b => b.classList.remove('selected'));
        todayBtn.classList.add('selected');
        console.debug('init: auto-select today', today);
        onDateSelected(today);
      } else {
        console.debug('init: today button not found for', today);
      }
    }
  }

  window.SimulatorCalendar = window.SimulatorCalendar || {};
  window.SimulatorCalendar.init = init;
  window.SimulatorCalendar.renderSlots = renderSlots;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // --- MOCK (tylko jeśli jawnie ustawisz window.__SIM_USE_MOCK = true) ---
  if (window.__SIM_USE_MOCK) {
    console.info('SIMULATOR: MOCK enabled — generating local slots (08:00–14:00)');
    function generateSlotsForDate(dateStr) {
      const hours = [8,9,10,11,12,13,14];
      return hours.map(h => ({
        id: `${dateStr}-${h}`,
        date: dateStr,
        time: `${String(h).padStart(2,'0')}:00`,
        capacity: 1,
        reserved_count: 0,
        available: h !== 10
      }));
    }

    window.SimulatorCalendar.fetchAvailabilityRange = async function(from, to) {
      await new Promise(r => setTimeout(r, 80));
      if (from && to && from === to) {
        const slots = generateSlotsForDate(from);
        return { ok: true, count: slots.length, slots };
      }
      return { ok: true, count: 0, slots: [] };
    };

    function attachDateClickHandlersMock() {
      const selectors = ['[data-date]', '.calendar-day', '.day-tile'];
      selectors.forEach(sel => {
        document.querySelectorAll(sel).forEach(el => {
          if (el.dataset.simHandlerAttached) return;
          const date = el.getAttribute('data-date') || el.dataset.date;
          if (!date) return;
          el.addEventListener('click', async () => {
            const res = await window.SimulatorCalendar.fetchAvailabilityRange(date, date);
            if (typeof window.SimulatorCalendar.renderSlots === 'function') {
              window.SimulatorCalendar.renderSlots(res);
              return;
            }
            const container = document.querySelector('.slots, #slots, #slotsContainer');
            if (container) {
              container.innerHTML = res.slots.map(s => `<div class="slot">${s.time} ${s.available ? '' : '<span class="busy">(zajęte)</span>'}</div>`).join('');
            } else {
              console.info('MOCK slots for', date, res.slots);
            }
          });
          el.dataset.simHandlerAttached = '1';
        });
      });
    }

    document.addEventListener('DOMContentLoaded', attachDateClickHandlersMock);
    const mo = new MutationObserver(attachDateClickHandlersMock);
    mo.observe(document.body, { childList: true, subtree: true });
  }

})(); // koniec IIFE
