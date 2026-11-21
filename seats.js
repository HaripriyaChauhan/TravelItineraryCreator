// Flight seat selection (Owner: Haripriya). The SERVER owns prices and availability (api/seats.php, api/trips.php);
// this file draws the cabin, lets each traveller pick a seat, and sends the choice back with the booking.
const SEAT_NAME = { extra: 'Extra legroom', preferred: 'Preferred', exit: 'Exit row', standard: 'Standard' };
const seatFeeOf = (map, codes) => codes.reduce((s, c) => s + (map.rows[parseInt(c, 10) - 1]?.p || 0), 0);
const seatBase = d => String(d || '').split(' · Seats')[0];                       // booking detail without the seat suffix
const seatSuffix = (codes, fee) => codes.length ? ` · Seats ${codes.join(', ')}${fee ? ` (+${money(fee)})` : ''}` : '';

function seatCell(s, code) {
  const row = s.map.rows[parseInt(code, 10) - 1], taken = s.map.taken.includes(code), idx = s.sel.indexOf(code);
  return `<button class="seat t-${row.t} ${taken ? 'taken' : ''} ${idx >= 0 ? 'sel' : ''}" ${taken ? 'disabled' : ''} data-a="seatPick" data-code="${code}"
    title="${code} · ${SEAT_NAME[row.t]} · ${row.p ? money(row.p) : 'Free'}${taken ? ' · taken' : ''}">${idx >= 0 ? idx + 1 : ''}</button>`;
}

function seatPickerHtml() {
  const s = S.seat, L = s.map.letters, codes = s.sel.filter(Boolean), fee = seatFeeOf(s.map, codes);
  const price = t => s.map.rows.find(r => r.t === t)?.p || 0;
  const rows = s.map.rows.map(r => `${r.n === 12 ? '<div class="exit-strip">EXIT</div>' : ''}<div class="srow"><span class="rn">${r.n}</span>${L.slice(0, 3).map(l => seatCell(s, r.n + l)).join('')}<span class="aisle"></span>${L.slice(3).map(l => seatCell(s, r.n + l)).join('')}<span class="rn">${r.n}</span></div>${r.n === 13 ? '<div class="exit-strip">EXIT</div>' : ''}`).join('');
  return `<div class="box seatbox"><div class="row between"><h3>💺 Choose your seats</h3><button class="btn ghost sm" data-a="close">✕</button></div>
    <div class="mute">${esc(s.title)} · ${s.qty} passenger${s.qty > 1 ? 's' : ''}</div>
    <div class="pax">${s.sel.map((c, i) => `<button class="pax-chip ${s.active === i ? 'on' : ''}" data-a="seatActive" data-i="${i}">Passenger ${i + 1}<b>${c || 'no seat yet'}</b></button>`).join('')}</div>
    <div class="legend"><span><i class="t-extra"></i>Extra legroom ${money(price('extra'))}</span><span><i class="t-exit"></i>Exit row ${money(price('exit'))}</span><span><i class="t-preferred"></i>Preferred ${money(price('preferred'))}</span><span><i class="t-standard"></i>Standard (free)</span><span><i class="taken"></i>Taken</span></div>
    <div class="plane"><div class="srow head"><span class="rn"></span>${L.slice(0, 3).map(l => `<span class="sl">${l}</span>`).join('')}<span class="aisle"></span>${L.slice(3).map(l => `<span class="sl">${l}</span>`).join('')}<span class="rn"></span></div>${rows}</div>
    <div class="seat-foot"><div><b>${codes.length ? codes.join(', ') : 'No seats chosen'}</b><div class="mute">${fee ? `Seat fees: ${money(fee)}` : 'No seat fees'}</div></div>
      <div class="row"><button class="btn ghost" data-a="seatSkip">${s.existing && s.existing.length ? 'Clear seats' : 'Skip'}</button><button class="btn" data-a="seatConfirm">${codes.length ? 'Confirm seats' : 'Continue without seats'}</button></div></div>
    <p class="mute" style="margin:0;font-size:.78rem">Skipping is fine: a seat is then assigned free at check-in. A seat is only held for you once the booking is paid.</p></div>`;
}

function paintSeats() {
  const old = document.querySelector('#modal .plane'), top = old ? old.scrollTop : 0;      // keep the scroll position when a seat is clicked
  $('#modal').innerHTML = seatPickerHtml(); const p = document.querySelector('#modal .plane'); if (p) p.scrollTop = top;
}

// o = { title, ref, qty, existing: ['12A'], exclude: bookingId, onConfirm(codes, fee) }
async function openSeatPicker(o) {
  const m = $('#modal'); m.innerHTML = '<div class="box">Loading seat map…</div>'; m.classList.add('open');
  try {
    const map = await API.seats({ ref: o.ref, exclude: o.exclude || '' });
    const sel = Array.from({ length: o.qty }, (_, i) => { const c = (o.existing || [])[i]; return c && !map.taken.includes(c) ? c : ''; });
    S.seat = { ...o, map, sel, active: Math.max(0, sel.indexOf('')) }; paintSeats();
  } catch (e) { closeModal(); toast(e.message); }
}

const SEAT_ACTIONS = {
  seatActive: ({ i }) => { S.seat.active = +i; paintSeats(); },
  seatPick: ({ code }) => {
    const s = S.seat, at = s.sel.indexOf(code);
    if (at >= 0) { s.sel[at] = ''; s.active = at; }                                      // click a chosen seat again to free it
    else { let i = s.sel[s.active] === '' ? s.active : s.sel.indexOf(''); if (i < 0) i = s.active; s.sel[i] = code; const nx = s.sel.indexOf(''); s.active = nx >= 0 ? nx : i; }
    paintSeats();
  },
  seatSkip: guard(async () => { await S.seat.onConfirm([], 0); }),
  seatConfirm: guard(async () => {
    const s = S.seat, codes = s.sel.filter(Boolean);
    if (codes.length && codes.length < s.qty) return toast('Choose a seat for every passenger, or skip seat selection');
    await s.onConfirm(codes, seatFeeOf(s.map, codes));
  }),
  seatEdit: ({ id }) => {                                                               // change seats on a reserved (unpaid) flight
    const t = S.trips.find(x => x.bookings.some(b => b.id === id)), b = t.bookings.find(x => x.id === id);
    if (!b.ref) return toast('Seats can only be chosen for flights booked here');
    openSeatPicker({ title: b.title, ref: b.ref, qty: b.qty, existing: (b.seats || '').split(',').filter(Boolean), exclude: b.id, onConfirm: async (codes, fee) => {
      b.seats = codes.join(','); b.detail = seatBase(b.detail) + seatSuffix(codes, fee); await persist(t);
      if (S.co) S.co.seatConflict = null; closeModal(); render(); toast(codes.length ? 'Seats saved' : 'Seats cleared'); } });
  },
};
