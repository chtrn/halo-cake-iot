'use strict';
// Dashboard membaca API PHP dari origin yang sama. MQTT dibaca Node-RED, bukan browser.
const API = 'api/';
const el = (id) => document.getElementById(id);
const filters = { q: '', status: '', date: '', page: 1 };
let dashboardRequestRunning = false;
let historyRequestRunning = false;
let historyQueued = false;
let historyRefreshTimer;

function escaped(v) {
  return String(v ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

async function getJson(url) {
  const response = await fetch(url, { cache: 'no-store' });
  const raw = await response.text();
  const trimmed = raw.trimStart();
  if (trimmed.startsWith('<?php') || trimmed.startsWith('<?')) {
    throw new Error('Kode PHP ditampilkan mentah. Buka lewat Apache Laragon: http://localhost/halocake/ (bukan Live Server/file://). Tes /halocake/api/php-check.php');
  }
  let value;
  try {
    value = JSON.parse(raw);
  } catch (error) {
    if (response.status === 404) {
      throw new Error('API PHP tidak ditemukan (HTTP 404). Periksa lokasi folder C:\\laragon\\www\\halocake dan alamat URL');
    }
    throw new Error(`API tidak mengembalikan JSON valid (HTTP ${response.status}). Periksa /halocake/api/php-check.php dan /halocake/api/health.php`);
  }
  if (!response.ok || !value || !value.ok) throw new Error(value?.message || `HTTP ${response.status}`);
  return value;
}

function showError(message) {
  const item = el('api-error');
  item.textContent = `Backend belum dapat diakses: ${message}. Pastikan Apache, MySQL, dan database sudah siap.`;
  item.classList.add('show');
}
function clearError() { el('api-error').classList.remove('show'); }

function updateClock() {
  const now = new Date();
  el('date-text').textContent = new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
  }).format(now);
  el('time-text').textContent = new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23'
  }).format(now) + ' WIB';
}

function updateLive(data) {
  const info = data.live;
  el('crossing-name').textContent = `${data.crossing.name} · ${data.crossing.location}`;
  el('system-status').textContent = info.system_status;
  el('system-status').className = `big status-${info.system_status.toLowerCase()}`;
  el('barrier-status').textContent = info.barrier_status;
  el('barrier-status').className = `big status-${info.barrier_status === 'Terbuka' ? 'idle' : 'closed'}`;
  el('current-carriages').textContent = String(info.current_carriages).padStart(2, '0');
  document.querySelectorAll('.live-grid .live-card:nth-of-type(1) .tag').forEach((tag) => {
    tag.classList.toggle('chosen', tag.textContent.trim() === info.system_status);
  });
  document.querySelectorAll('.live-grid .live-card:nth-of-type(2) .tag').forEach((tag) => {
    tag.classList.toggle('chosen', tag.textContent.trim() === info.barrier_status);
  });
  [info.sensor_1, info.sensor_2].forEach((active, i) => {
    const status = el(`sensor-${i + 1}-status`);
    const row = el(`sensor-${i + 1}-row`);
    status.textContent = active ? 'Mendeteksi' : 'Tidak mendeteksi';
    status.className = `pill ${active ? 'on' : 'offp'}`;
    row.classList.toggle('off', !active);
  });
  const badge = el('iot-status');
  badge.className = `badge-mqtt ${info.data_recent ? 'mqtt-ok' : 'mqtt-off'}`;
  badge.textContent = info.data_recent ? 'Data IoT aktif' : 'Data IoT tidak aktif';
  el('last-seen').textContent = `Data perangkat terakhir: ${info.last_seen_at ? info.last_seen_at + ' WIB' : 'belum ada'}`;
}

function updateStats(stat) {
  el('total-trains').innerHTML = `${stat.total_trains_7_days}<small>kereta / 7 hari terakhir</small>`;
  el('today-carriages').innerHTML = `${stat.total_carriages_today}<span>gerbong</span>`;
  el('today-average').innerHTML = `${escaped(stat.average_close_label_today)}<span>menit</span>`;
  const top = Math.max(4, Math.ceil(Math.max(...stat.daily.map((day) => day.trains)) / 4) * 4);
  document.querySelectorAll('.chart .y-label').forEach((item, i) => {
    item.textContent = String(top - (i * top / 4));
  });
  const bars = el('chart-bars');
  bars.replaceChildren();
  stat.daily.forEach((day, i) => {
    const heightPct = 100 * day.trains / top;
    const bar = document.createElement('div');
    bar.className = 'bar-col';
    const count = document.createElement('span');
    count.className = 'bar-val';
    count.style.bottom = `calc(${heightPct}% + 4px)`;
    count.textContent = day.trains;
    const fill = document.createElement('div');
    fill.className = `bar${i === 6 ? ' active' : ''}`;
    fill.style.height = `${heightPct}%`;
    const dayText = document.createElement('span');
    dayText.className = `bar-name${i === 6 ? ' active' : ''}`;
    dayText.textContent = new Intl.DateTimeFormat('id-ID', {
      day: '2-digit', month: 'short', timeZone: 'UTC'
    }).format(new Date(day.date + 'T00:00:00Z'));
    bar.append(count, fill, dayText);
    bars.append(bar);
  });
  const rows = el('recap-body');
  rows.replaceChildren();
  stat.recap.forEach((day) => {
    const tr = document.createElement('tr');
    const formatted = new Intl.DateTimeFormat('id-ID', {
      day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC'
    }).format(new Date(day.date + 'T00:00:00Z'));
    [formatted, day.trains, day.carriages, day.average_close_label].forEach((v) => {
      const td = document.createElement('td');
      td.textContent = v;
      tr.append(td);
    });
    rows.append(tr);
  });
}

async function refreshDashboard() {
  if (dashboardRequestRunning) return;
  dashboardRequestRunning = true;
  try {
    const data = await getJson(API + 'dashboard.php');
    updateLive(data);
    updateStats(data.statistics);
    clearError();
  } catch (e) {
    el('iot-status').className = 'badge-mqtt mqtt-off';
    el('iot-status').textContent = 'Server tidak terhubung';
    showError(e.message);
  } finally {
    dashboardRequestRunning = false;
  }
}

function queryString() {
  const q = new URLSearchParams();
  if (filters.q) q.set('q', filters.q);
  if (filters.status) q.set('status', filters.status);
  if (filters.date) q.set('date', filters.date);
  q.set('page', String(filters.page));
  return q.toString();
}

async function refreshHistory() {
  if (historyRequestRunning) { historyQueued = true; return; }
  historyRequestRunning = true;
  const queryAtStart = queryString();
  try {
    const data = await getJson(API + 'history.php?' + queryAtStart);
    if (queryAtStart !== queryString()) { historyQueued = true; return; }
    const body = el('history-body');
    body.replaceChildren();
    if (data.rows.length === 0) {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = 5;
      cell.textContent = 'Belum ada aktivitas sesuai filter';
      row.append(cell); body.append(row);
    }
    data.rows.forEach((event) => {
      const row = document.createElement('tr');
      const badgeClass = { Idle: 'idle', Warning: 'warning', Closed: 'closed' }[event.status] || 'idle';
      row.innerHTML = `<td>${escaped(event.id)}</td><td>${escaped(event.date)} WIB</td>`
        + `<td>${escaped(event.description)}</td>`
        + `<td><span class="tag ${badgeClass}">${escaped(event.status)}</span></td>`
        + `<td>${Number(event.carriages)}</td>`;
      body.append(row);
    });
    filters.page = data.page;
    const from = data.total ? ((data.page - 1) * 8 + 1) : 0;
    const to = data.total ? (from + data.rows.length - 1) : 0;
    el('history-range').textContent = `Menampilkan ${from}–${to} dari ${data.total} aktivitas`;
    el('page-current').textContent = `${data.page}/${data.pages}`;
    el('page-prev').disabled = data.page <= 1;
    el('page-next').disabled = data.page >= data.pages;
    clearError();
  } catch (e) { showError(e.message); }
  finally {
    historyRequestRunning = false;
    if (historyQueued) { historyQueued = false; refreshHistory(); }
  }
}

function resetFilter() {
  filters.q = el('history-search').value.trim();
  filters.status = el('history-status').value;
  filters.date = el('history-date').value;
  filters.page = 1;
  refreshHistory();
}

let debounce;
el('history-search').addEventListener('input', () => {
  clearTimeout(debounce);
  debounce = setTimeout(resetFilter, 250);
});
el('history-status').addEventListener('change', resetFilter);
el('history-date').addEventListener('change', resetFilter);
el('page-prev').addEventListener('click', () => {
  if (filters.page > 1) { filters.page--; refreshHistory(); }
});
el('page-next').addEventListener('click', () => {
  filters.page++; refreshHistory();
});
el('export-csv').addEventListener('click', () => {
  const url = API + 'export.php?' + queryString();
  window.location.assign(url);
});

updateClock();
setInterval(updateClock, 1000);
refreshDashboard();
refreshHistory();
setInterval(refreshDashboard, 2000);
historyRefreshTimer = setInterval(refreshHistory, 8000);
