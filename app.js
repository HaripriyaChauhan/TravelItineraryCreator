// App controller: startup, navigation, click handling, forms. (Owner: Kashish - Frontend Lead)
const $ = s => document.querySelector(s);
const NAV = [['home', '🏠 Home'], ['trips', '🧳 My Trips'], ['bookings', '📅 Bookings'], ['budget', '💰 Budget'], ['reminders', '🔔 Reminders'], ['suggestions', '✨ Suggestions'], ['payments', '💳 Payments'], ['settings', '⚙️ Settings']];
let authMode = 'login';

const toast = m => { const t = $('#toast'); t.textContent = m; t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 2500); };
const uid = () => Math.random().toString(36).slice(2, 10);
const closeModal = () => $('#modal').classList.remove('open');

function render() {
  $('#nav').innerHTML = NAV.map(([k, l]) => `<button data-a="nav" data-v="${k}" class="${S.view === k || (S.view === 'trip' && k === 'trips') ? 'on' : ''}">${l}</button>`).join('');
  $('#userChip').textContent = '👤 ' + S.user.name;
  $('#hello').textContent = new Date().toDateString();
  $('#main').innerHTML = VIEWS[S.view]();
  const cb = $('#cartbar'), h = S.view === 'checkout' ? '' : cartBarHtml(); cb.innerHTML = h; cb.classList.toggle('hidden', !h); planTick();
}

// Generic popup form. fields: [{n,l,t,v,o}] ; o = select options
function form(title, fields, cb) {
  const m = $('#modal');
  m.innerHTML = `<form class="box"><h3>${title}</h3>${fields.map(f => `<label>${f.l}${f.o
    ? `<select name="${f.n}">${f.o.map(o => `<option ${o == f.v ? 'selected' : ''}>${o}</option>`).join('')}</select>`
    : `<input name="${f.n}" type="${f.t || 'text'}" value="${esc(f.v ?? '')}" step="any" ${f.opt ? '' : 'required'}>`}</label>`).join('')}
    <div class="row"><button type="button" class="btn ghost" data-a="close">Cancel</button><button class="btn">Save</button></div></form>`;
  m.classList.add('open');
  m.querySelector('form').onsubmit = guard(async e => { e.preventDefault(); await cb(Object.fromEntries(new FormData(e.target))); closeModal(); if (S.user) render(); });
}

async function persist(t) {
  const { trip } = await API.saveTrip(t);
  const i = S.trips.findIndex(x => x.id === trip.id);
  i < 0 ? S.trips.push(trip) : (S.trips[i] = trip);
  return trip;
}

const tripFields = (t = {}) => [
  { n: 'name', l: 'Trip name', v: t.name }, { n: 'destination', l: 'Destination', v: t.destination },
  { n: 'start', l: 'Start date', t: 'date', v: t.start }, { n: 'end', l: 'End date', t: 'date', v: t.end },
  { n: 'travellers', l: 'Travellers', t: 'number', v: t.travellers ?? 1 }, { n: 'budget', l: 'Budget', t: 'number', v: t.budget ?? 150000 },
  { n: 'image', l: 'Cover image URL (optional)', v: t.image, opt: 1 }];

const addDays = n => new Date(Date.now() + n * 864e5).toISOString().slice(0, 10);

const HOME_AIRPORT = 'NBO';
function ensureExplore(t) {
  const c = S.explore[t.id];
  if (c && c.dest === t.destination) return Promise.resolve(c);
  return API.explore(t.destination || '').then(x => { S.explore[t.id] = { ...x, dest: t.destination }; })
    .catch(e => { S.explore[t.id] = { error: e.message, dest: t.destination }; })
    .then(() => { if (['suggestions', 'trip'].includes(S.view)) render(); return S.explore[t.id]; });
}
async function showHotel(name, city, i, price) {
  const m = $('#modal'); m.innerHTML = '<div class="box">Loading hotel details...</div>'; m.classList.add('open');
  try {
    const { hotel: h } = await API.hotel({ name, city, ...(price ? { price } : {}) });
    m.innerHTML = `<div class="box" style="width:min(520px,94vw);max-height:90vh;overflow:auto"><h3>🏨 ${esc(h.name)}</h3>
      <div class="mute">${'⭐'.repeat(h.stars)} · ${h.rating}/10 (${h.reviews} reviews)<br>📍 ${esc(h.address)}</div><p>${esc(h.description)}</p>
      <div><b>Check-in</b> ${h.check_in} · <b>Check-out</b> ${h.check_out}</div>
      <h4>Rooms</h4>${h.rooms.map(r => `<div class="item"><span>${esc(r.type)} <span class="mute">sleeps ${r.sleeps}</span></span><b>${money(r.price)}/night</b></div>`).join('')}
      <h4>Amenities</h4><div class="row">${h.amenities.map(x => `<span class="badge">${esc(x)}</span>`).join('')}</div>
      <h4>Nearby</h4>${h.nearby.map(n => `<div class="mute">📍 ${esc(n)}</div>`).join('')}<p class="mute">${esc(h.policy)}</p>
      <div class="row"><button class="btn ghost" data-a="close">Close</button>${i != null ? `<button class="btn" data-a="addResult" data-i="${i}">Add to trip</button>` : ''}</div></div>`;
  } catch (e) { closeModal(); toast(e.message); }
}

const actFields = (a = {}) => [
  { n: 'title', l: 'Title', v: a.title }, { n: 'date', l: 'Date', t: 'date', v: a.date }, { n: 'time', l: 'Time', t: 'time', v: a.time || '09:00' },
  { n: 'duration', l: 'Duration (minutes)', t: 'number', v: a.duration || 60 }, { n: 'type', l: 'Type', o: ['activity', 'restaurant'], v: a.type },
  { n: 'opens', l: 'Venue opens (optional)', t: 'time', v: a.opens, opt: 1 }, { n: 'closes', l: 'Venue closes (optional)', t: 'time', v: a.closes, opt: 1 }];

const A = {
  nav: ({ v }) => { if (v !== 'checkout') stopPoll(); S.view = v; render(); if (v === 'suggestions') S.trips.forEach(ensureExplore); if (v === 'payments') loadHistory(); },
  close: closeModal,
  openTrip: ({ id }) => { S.tripId = id; S.tab = 'itinerary'; S.view = 'trip'; render(); },
  tab: ({ t }) => { S.tab = t; render(); if (t === 'explore') ensureExplore(getTrip()); },
  bTab: ({ t }) => { S.bTab = t; S.results = []; S.prefill = {}; render(); },
  logout: guard(async () => { await API.logout(); location.reload(); }),
  newTrip: ({ q } = {}) => form('New trip', tripFields({ destination: q }), async v => { const t = await persist(v); S.tripId = t.id; S.view = 'trip'; }),
  editTrip: () => { const t = getTrip(); form('Edit trip', tripFields(t), v => persist({ ...t, ...v })); },
  delTrip: guard(async () => { if (!confirm('Delete this trip?')) return; await API.deleteTrip(S.tripId); S.trips = S.trips.filter(t => t.id !== S.tripId); S.view = 'trips'; render(); }),
  addAct: () => { const t = getTrip(); form('Add activity', actFields({ date: t.start }), v => { t.activities.push({ id: uid(), ...v }); return persist(t); }); },
  editAct: ({ id }) => { const t = getTrip(), a = t.activities.find(x => x.id === id); form('Edit activity', actFields(a), v => { Object.assign(a, v); return persist(t); }); },
  editBooking: ({ id }) => {
    const t = S.trips.find(x => x.bookings.some(b => b.id === id)), b = t.bookings.find(x => x.id === id);
    form('Edit booking', [{ n: 'type', l: 'Type', o: ['flight', 'hotel', 'activity', 'restaurant'], v: b.type }, { n: 'title', l: 'Title', v: b.title }, { n: 'date', l: 'Date', t: 'date', v: b.date },
      { n: 'time', l: 'Time', t: 'time', v: b.time }, { n: 'detail', l: 'Details', v: b.detail, opt: 1 }], v => { Object.assign(b, v); return persist(t); }); },
  editExpense: ({ id }) => {
    const t = getTrip(), x = t.expenses.find(e => e.id === id);
    form('Edit expense', [{ n: 'title', l: 'Description', v: x.title }, { n: 'amount', l: 'Amount', t: 'number', v: x.amount }, { n: 'cat', l: 'Category', o: Object.keys(CATS), v: x.cat }, { n: 'date', l: 'Date', t: 'date', v: x.date }],
      v => { Object.assign(x, v); return persist(t); }); },
  forgot: () => form('Reset password', [{ n: 'email', l: 'Account email', t: 'email' }], async v => {
    const r = await API.resetRequest(v);
    alert('"email" - your reset code is: ' + r.token);
    setTimeout(() => form('Enter reset code', [{ n: 'email', l: 'Email', t: 'email', v: v.email }, { n: 'token', l: 'Code' }, { n: 'password', l: 'New password', t: 'password' }],
      async x => { await API.resetConfirm(x); toast('Password updated - please log in'); }), 0);
  }),
  applyFix: guard(async ({ trip, id, time }) => { const t = S.trips.find(x => x.id === trip); t.activities.find(a => a.id === id).time = time; await persist(t); render(); toast('Fix applied'); }),
  addResult: ({ i }) => {
    const r = S.results[i];
    if (!S.trips.length) return toast('Create a trip first');
    const t0 = getTrip() || S.trips[0], hotel = r.type === 'hotel';
    const fields = [{ n: 'trip', l: 'Trip', o: S.trips.map(t => t.name), v: t0.name }, { n: 'date', l: hotel ? 'Check-in date' : 'Date', t: 'date', v: t0.start },
      { n: 'time', l: 'Time', t: 'time', v: r.opens || (hotel ? '15:00' : '12:00') }];
    if (hotel) fields.push({ n: 'nights', l: 'Nights', t: 'number', v: Math.max(1, nDays(t0) - 1) });
    form('Add to trip', fields, async v => {
      const t = S.trips.find(x => x.name === v.trip), qty = hotel ? Math.max(1, parseInt(v.nights) || 1) : t.travellers;
      t.bookings.push(bookVenue(r, { date: v.date, time: v.time, qty })); await persist(t); S.tripId = t.id;
      toast('Reserved. Review & pay from the cart at the bottom.'); });
  },
  addBooking: () => { const t = getTrip(); form('Add a booking made elsewhere (not paid here)', [
    { n: 'type', l: 'Type', o: ['flight', 'hotel', 'activity', 'restaurant'] }, { n: 'title', l: 'Title' }, { n: 'date', l: 'Date', t: 'date', v: t.start },
    { n: 'time', l: 'Time', t: 'time', v: '12:00' }, { n: 'detail', l: 'Details (provider)', opt: 1 }],
    v => { t.bookings.push({ id: uid(), status: 'Confirmed', duration: 60, ...v }); return persist(t); }); },
  delBooking: guard(async ({ id }) => {
    const t = S.trips.find(x => x.bookings.some(b => b.id === id));
    if (t.bookings.find(b => b.id === id).status === 'Paid') return toast('Paid bookings are locked. Contact support to cancel.');
    t.bookings = t.bookings.filter(b => b.id !== id); await persist(t); render(); }),
  addExpense: () => { const t = getTrip(); form('Add expense', [
    { n: 'title', l: 'Description' }, { n: 'amount', l: 'Amount', t: 'number' }, { n: 'cat', l: 'Category', o: Object.keys(CATS) }, { n: 'date', l: 'Date', t: 'date', v: today() }],
    v => { t.expenses.push({ id: uid(), ...v }); return persist(t); }); },
  delExpense: guard(async ({ id }) => { const t = getTrip(); t.expenses = t.expenses.filter(e => e.id !== id); await persist(t); render(); }),
  hotelInfo: ({ id }) => { const t = S.trips.find(x => x.bookings.some(b => b.id === id)), b = t.bookings.find(x => x.id === id); showHotel(b.title, (t.destination || '').split(',')[0].trim(), null); },
  hotelInfoResult: ({ i }) => { const r = S.results[i]; showHotel(r.name, r.name.split(' - ')[1] || '', i, r.price); },
  addIdea: ({ trip, kind, i }) => {
    const t = S.trips.find(x => x.id === trip), e = S.explore[trip][kind][i];
    form('Add to itinerary', actFields({ title: e[0], date: t.start, time: kind === 'do' ? '10:00' : '09:30', duration: 120, type: 'activity' }), v => { t.activities.push({ id: uid(), ...v }); return persist(t); });
  },
  goSearch: guard(async ({ trip, type, dir, date, city }) => {
    const t = S.trips.find(x => x.id === trip); S.tripId = t.id; S.view = 'bookings'; S.bTab = type; S.results = []; S.flights = [];
    if (type === 'flight') {
      const x = await ensureExplore(t), to = x.iata || '';
      const [f, tt] = dir === 'ret' ? [to, HOME_AIRPORT] : [HOME_AIRPORT, to];
      S.prefill = { from: f, to: tt, date }; render();
      if (f && tt && f !== tt) S.flights = (await API.flights({ from: f, to: tt, date })).flights;
    } else { S.prefill = { city }; render(); S.results = (await API.search({ type, city })).results; }
    render();
  }),
  addFlight: ({ i }) => {
    const f = S.flights[i];
    if (!S.trips.length) return toast('Create a trip first');
    form('Add flight to which trip?', [{ n: 'trip', l: 'Trip', o: S.trips.map(t => t.name), v: (getTrip() || S.trips[0]).name }], v => {
      const t = S.trips.find(x => x.name === v.trip);
      setTimeout(() => openSeatPicker({ title: `${f.airline} ${f.number} · ${f.from}→${f.to} · ${fdate(f.date)}`, ref: f.ref, qty: t.travellers, existing: [], onConfirm: async (codes, fee) => {
        const bk = bookFlight(f, t.travellers, codes); bk.detail += seatSuffix(codes, fee); t.bookings.push(bk); await persist(t); S.tripId = t.id;
        closeModal(); render(); toast('Flight reserved. Review & pay from the cart at the bottom.'); } }), 0);
    });
  },
};

Object.assign(A, CO_ACTIONS, SEAT_ACTIONS, PL_ACTIONS);
document.addEventListener('click', e => {
  const el = e.target.closest('[data-a]'); if (!el) return;
  e.preventDefault(); A[el.dataset.a]?.({ ...el.dataset });
});
document.addEventListener('change', e => { if (e.target.dataset.sel === 'trip') { S.tripId = e.target.value; render(); } });
document.addEventListener('submit', guard(async e => {
  const f = e.target.dataset.form; if (!f) return;
  e.preventDefault(); const v = Object.fromEntries(new FormData(e.target));
  if (f === 'search') A.newTrip({ q: v.q });
  if (f === 'profile') { S.user = (await API.updateProfile(v)).user; toast('Saved'); render(); }
  if (f === 'venues') { S.results = (await API.search({ type: S.bTab, city: v.city })).results; render(); }
  if (f === 'flights') { const b = e.target.querySelector('button'); b.textContent = '...'; try { S.flights = (await API.flights(v)).flights; } finally { render(); } }
}));

// ---- Auth screen ----
$('#authSwitch').onclick = e => {
  e.preventDefault(); authMode = authMode === 'login' ? 'register' : 'login';
  document.querySelectorAll('.reg').forEach(x => x.classList.toggle('hidden', authMode === 'login'));
  $('#authForm .btn').textContent = authMode === 'login' ? 'Log in' : 'Create account';
  $('#authSwitch').textContent = authMode === 'login' ? 'No account? Register' : 'Have an account? Log in';
};
$('#authForm').onsubmit = async e => {
  e.preventDefault();
  try { const r = await API[authMode](Object.fromEntries(new FormData(e.target))); S.user = r.user; await start(); }
  catch (err) { $('#authErr').textContent = err.message; }
};

function checkReminders() {
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  reminders().forEach(r => { const d = new Date(r.date + 'T' + (r.time || '09:00')) - Date.now();
    if (d > 0 && d < 864e5) new Notification('Upcoming: ' + r.title, { body: r.date + ' ' + (r.time || '') }); });
}

// ---- Drag & drop: drop an activity on a day to move it, or on another item to swap ----
let dragId = null;
document.addEventListener('dragstart', e => { const i = e.target.closest('[data-drag]'); if (i) { dragId = i.dataset.drag; e.dataTransfer.effectAllowed = 'move'; } });
document.addEventListener('dragover', e => { if (dragId && e.target.closest('.day')) e.preventDefault(); });
document.addEventListener('drop', guard(async e => {
  const day = e.target.closest('.day'); if (!dragId || !day) return;
  e.preventDefault();
  const t = getTrip(), a = t.activities.find(x => x.id === dragId); dragId = null; if (!a) return;
  const onItem = e.target.closest('[data-drop]'), other = onItem && t.activities.find(x => x.id === onItem.dataset.drop);
  if (other && other.id !== a.id) { [a.date, other.date] = [other.date, a.date]; [a.time, other.time] = [other.time, a.time]; }
  else a.date = day.dataset.date;
  await persist(t); render(); toast('Itinerary updated');
}));

async function start() {
  $('#auth').classList.add('hidden'); $('#app').classList.remove('hidden');
  S.trips = (await API.trips()).trips; try { S.cfg = await API.payConfig(); } catch { /* demo defaults */ } S.view = 'home'; render(); checkReminders();
}
(async () => {
  try { S.user = (await API.me()).user; } catch { S.user = null; }
  S.user ? start() : $('#auth').classList.remove('hidden');
})();
