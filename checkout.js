// Checkout, payments, trip summary, receipts. Flow: add items (cart) -> Review summary -> Pay -> Confirmation + receipt.
// (Owner: Paul / Kashish)  Money rules live on the server (api/payments.php, api/trips.php) - this file only displays and asks.
const METHODS = {
  mpesa: { name: 'M-Pesa', icon: '📱', sub: 'Lipa na M-Pesa: approve on your phone' },
  airtel: { name: 'Airtel Money', icon: '📲', sub: 'Approve on your phone' },
  card: { name: 'Visa / Mastercard', icon: '💳', sub: 'Debit or credit card' },
};
const TYPE_LABEL = { flight: 'Flights', hotel: 'Accommodation', activity: 'Activities', restaurant: 'Restaurants' };
const PAY_STATUS = { success: ['Paid', 'paid'], pending: ['Pending', 'reserved'], failed: ['Failed', 'failed'], cancelled: ['Cancelled', 'confirmed'], expired: ['Expired', 'confirmed'] };

// ---------- small helpers (also covered by tests/run-tests.js) ----------
function normPhone(p) { const m = String(p || '').replace(/[\s\-()]/g, '').match(/^(?:\+?254|0)?([71]\d{8})$/); return m ? '254' + m[1] : null; }
const fmtPhone = p => { const n = normPhone(p); return n ? '0' + n.slice(3, 6) + ' ' + n.slice(6, 9) + ' ' + n.slice(9) : (p || ''); };
const coTrip = () => S.trips.find(t => t.id === S.co.tripId);
const coItems = t => unpaidItems(t).filter(b => S.co.sel && S.co.sel.has(b.id));
const coTotal = t => coItems(t).reduce((s, b) => s + +b.price, 0);
const when = ts => ts ? new Date(ts * 1000).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
const cfgNow = () => S.cfg || { mode: 'mock', mpesa_max: 500000, mock_delay: 6, methods: { mpesa: true, airtel: true, card: true } };

// Build bookings from search results. The signed "quote" lets the server verify the price (unit_price x qty).
function bookFlight(f, travellers, seats = []) {
  return { id: uid(), type: 'flight', title: `Flight ${f.from}→${f.to} (${f.number})`, date: f.date, time: f.depart, duration: f.duration,
    detail: `${f.airline} · ${travellers} traveller${travellers > 1 ? 's' : ''} × ${money(f.unit_price)}`, status: 'Reserved',
    ref: f.ref, unit_price: f.unit_price, qty: travellers, quote: f.quote, seats: seats.join(',') };
}
function bookVenue(r, o) {
  const per = r.type === 'hotel' ? `${o.qty} night${o.qty > 1 ? 's' : ''} × ${money(r.unit_price)}` : `${o.qty} × ${money(r.unit_price)} per person`;
  return { id: uid(), type: r.type, title: r.name, date: o.date, time: o.time, duration: r.duration || 60, detail: per, status: 'Reserved',
    opens: r.opens || '', closes: r.closes || '', ref: r.ref, unit_price: r.unit_price, qty: o.qty, quote: r.quote };
}

// ---------- cart bar (shown on every screen while something is reserved but unpaid) ----------
function cartBarHtml() {
  const t = S.trips.find(x => x.id === S.tripId && unpaidItems(x).length) || S.trips.find(x => unpaidItems(x).length);
  if (!t) return '';
  const items = unpaidItems(t);
  return `<div class="cartbar-inner"><span>🧾 <b>${items.length}</b> item${items.length > 1 ? 's' : ''} reserved for <b>${esc(t.name)}</b>, awaiting payment</span>
    <span class="cart-total">${money(reserved(t))}</span><button class="btn" data-a="openCheckout" data-trip="${t.id}">Review &amp; pay →</button></div>`;
}

// ---------- trip summary (used in the Summary tab, the checkout review and the final confirmation) ----------
function itineraryHtml(t) {
  const { conflicts } = analyseTrip(t), ev = tripEvents(t).sort((a, b) => a.start - b.start); let h = '';
  for (let i = 0; i < nDays(t); i++) {
    const d = new Date(new Date(t.start).getTime() + i * 864e5).toISOString().slice(0, 10), items = ev.filter(e => e.date === d);
    h += `<div class="day"><div><b>Day ${i + 1}</b><div class="mute">${fdate(d)}</div></div><div>${items.map(e => `<div class="item ${conflicts.has(e.id) ? 'bad' : ''}"><span>${ICON[e.type] || '📌'} ${e.time} – ${esc(e.title)} ${conflicts.has(e.id) ? '⚠️' : ''}</span>${e.src === 'book' ? `<span class="badge ${{ Paid: '', Reserved: 'reserved', Confirmed: 'confirmed' }[e.status] ?? ''}">${{ Paid: 'paid ✓', Reserved: 'unpaid', Confirmed: 'booked' }[e.status] || ''}</span>` : ''}</div>`).join('') || '<span class="mute">Nothing planned</span>'}</div></div>`;
  }
  return h;
}
function tripSummaryHtml(t) {
  const paid = t.bookings.filter(b => b.status === 'Paid').reduce((s, b) => s + +b.price, 0), rv = reserved(t);
  const other = t.expenses.filter(e => !String(e.id).startsWith('pay_')).reduce((s, e) => s + +e.amount, 0), left = t.budget - paid - rv - other;
  const label = { Paid: 'Paid ✓', Reserved: 'Awaiting payment', Confirmed: 'Booked elsewhere' };
  return `<div class="summary-doc">
  <div class="card"><h2>${esc(t.name)}</h2><div class="sum-grid"><div><span>📍 Destination</span><b>${esc(t.destination)}</b></div><div><span>📅 Dates</span><b>${fdate(t.start)} – ${fdate(t.end)}</b><small class="mute">${nDays(t)} days</small></div>
    <div><span>👥 Travellers</span><b>${t.travellers}</b></div><div><span>💰 Budget</span><b>${money(t.budget)}</b></div></div></div>
  <div class="card"><h3>Itinerary</h3>${itineraryHtml(t)}</div>
  <div class="card"><h3>Bookings and payment status</h3>${t.bookings.length ? `<div class="table-wrap"><table class="tbl"><tr><th>Item</th><th>Date</th><th>Status</th><th class="r">Amount</th></tr>
    ${t.bookings.map(b => `<tr><td>${ICON[b.type]} ${esc(b.title)}<br><small class="mute">${esc(b.detail || '')}</small></td><td>${fdate(b.date)} ${b.time || ''}</td><td><span class="badge ${{ Paid: '', Reserved: 'reserved', Confirmed: 'confirmed' }[b.status] ?? ''}">${label[b.status] || b.status}</span></td><td class="r">${b.price > 0 ? money(b.price) : '–'}</td></tr>`).join('')}</table></div>` : '<p class="mute">No bookings yet.</p>'}</div>
  <div class="card"><h3>Costs</h3><div class="item"><span>Paid</span><b>${money(paid)}</b></div><div class="item"><span>Awaiting payment</span><b>${money(rv)}</b></div>
    <div class="item"><span>Other expenses you added</span><b>${money(other)}</b></div><div class="item"><span>Budget</span><b>${money(t.budget)}</b></div>
    <div class="item"><span><b>${left < 0 ? 'Over budget by' : 'Budget left'}</b></span><b style="color:${left < 0 ? 'var(--red)' : 'var(--green)'}">${money(Math.abs(left))}</b></div></div></div>`;
}

// ---------- checkout screens ----------
const stepper = n => `<div class="stepper noprint">${['Review', 'Payment', 'Confirmation'].map((l, i) => `<div class="step ${i + 1 < n ? 'done' : i + 1 === n ? 'on' : ''}"><span>${i + 1 < n ? '✓' : i + 1}</span>${l}</div>`).join('<i></i>')}</div>`;

function vCheckout() {
  const c = S.co; if (c.step === 'done') return coDone();
  const t = coTrip(); if (!t) return vTrips();
  return { review: coReview, method: coMethod, processing: coProcessing }[c.step](t);
}

function orderSide(t, button) {
  const items = coItems(t), total = coTotal(t), committed = spent(t) + reserved(t), over = committed > t.budget;
  return `<aside class="card co-side"><h3>Order summary</h3>${items.map(b => `<div class="item"><span>${ICON[b.type]} ${esc(b.title)}</span><span>${money(b.price)}</span></div>`).join('') || '<p class="mute">No items selected.</p>'}
    <div class="co-total"><span>Total to pay</span><b>${money(total)}</b></div><p class="mute" style="margin:4px 0 10px">Prices include applicable taxes. Currency: Kenya Shillings (KES).</p>
    <div class="alert ${over ? 'red' : 'blue'}" style="font-size:.85rem">${over ? '⚠️ This puts you over your budget.' : '✅ Within your budget:'} ${money(committed)} of ${money(t.budget)} committed.</div>${button}</aside>`;
}

function coReview(t) {
  const items = unpaidItems(t), c = S.co, sel = coItems(t), paid = t.bookings.filter(b => b.status === 'Paid'), red = analyseTrip(t).alerts.filter(a => a.level === 'red').length;
  if (!items.length) return `${stepper(1)}<div class="card center"><h2>Nothing to pay right now</h2><p class="mute">Search flights, hotels and activities, add them to your trip, then come back here to pay.</p>
    <div class="row" style="justify-content:center"><button class="btn" data-a="nav" data-v="bookings">Find bookings</button><button class="btn ghost" data-a="openTrip" data-id="${t.id}">Back to trip</button></div></div>${paid.length ? tripSummaryHtml(t) : ''}`;
  const groups = Object.entries(TYPE_LABEL).map(([k, l]) => ({ k, l, list: items.filter(b => b.type === k) })).filter(g => g.list.length);
  return `${stepper(1)}<h1>Review your trip</h1><p class="mute">Check everything below, choose what to pay for now, then continue to payment.</p>
  <div class="co-grid"><div>
    <div class="card"><div class="sum-grid"><div><span>🧳 Trip</span><b>${esc(t.name)}</b></div><div><span>📍 Destination</span><b>${esc(t.destination)}</b></div><div><span>📅 Dates</span><b>${fdate(t.start)} – ${fdate(t.end)}</b></div><div><span>👥 Travellers</span><b>${t.travellers}</b></div></div></div>
    ${red ? `<div class="alert amber">⚠️ Your itinerary has ${red} scheduling conflict${red > 1 ? 's' : ''}. You can still pay, but check <a href="#" data-a="nav" data-v="suggestions">Smart Suggestions</a> first.</div>` : ''}
    <div class="card"><div class="row between"><h3>Items to pay</h3><a href="#" data-a="coAll">${sel.length === items.length ? 'Deselect all' : 'Select all'}</a></div>
      ${groups.map(g => `<h4>${ICON[g.k]} ${g.l}</h4>${g.list.map(b => `<label class="co-item"><input type="checkbox" ${c.sel.has(b.id) ? 'checked' : ''} data-a="coToggle" data-id="${b.id}">
        <span><b>${esc(b.title)}</b><br><small class="mute">${fdate(b.date)} ${b.time || ''} · ${esc(b.detail || '')}</small></span><b>${money(b.price)}</b></label>`).join('')}`).join('')}</div>
    <details class="card"><summary><b>Day-by-day itinerary</b></summary>${itineraryHtml(t)}</details>
    ${paid.length ? `<div class="card"><h3>Already paid ✓</h3>${paid.map(b => `<div class="item"><span>${ICON[b.type]} ${esc(b.title)}</span><span>${money(b.price)}</span></div>`).join('')}</div>` : ''}
    <button class="btn ghost" data-a="openTrip" data-id="${t.id}">← Back to trip</button>
  </div>${orderSide(t, `${coTotal(t) > cfgNow().mpesa_max ? `<p class="mute" style="font-size:.82rem">M-Pesa allows up to ${money(cfgNow().mpesa_max)} per transaction. Select fewer items and pay in parts, or use card.</p>` : ''}
    <button class="btn" style="width:100%" data-a="coNext" ${sel.length ? '' : 'disabled'}>Continue to payment →</button>`)}</div>`;
}

function coMethod(t) {
  const c = S.co, cfg = cfgNow(), total = coTotal(t), tooBig = c.method === 'mpesa' && total > cfg.mpesa_max;
  const fields = c.method === 'card'
    ? `<label>Card number<input id="co-card" inputmode="numeric" autocomplete="cc-number" placeholder="1234 5678 9012 3456"></label>
       <div class="row"><label style="flex:1">Expiry (MM/YY)<input id="co-exp" placeholder="MM/YY" maxlength="5"></label><label style="flex:1">Security code<input id="co-cvv" inputmode="numeric" maxlength="4" placeholder="CVV"></label></div>
       <p class="mute"> card details are checked and never stored. A real launch uses a hosted card page (Pesapal / Flutterwave).</p>`
    : `<label>${METHODS[c.method].name} phone number<input id="co-phone" inputmode="tel" value="${esc(c.phone)}" placeholder="0712 345 678"></label>
       <p class="mute">You will get a prompt on this phone to enter your PIN. Format: 07xx, 01xx or +2547xx.</p>`;
  return `${stepper(2)}<h1>Choose how to pay</h1><div class="co-grid"><div class="card">
    <div class="methods">${Object.entries(METHODS).map(([k, v]) => { const on = cfg.methods[k]; return `<div class="method ${c.method === k ? 'on' : ''} ${on ? '' : 'off'}" ${on ? `data-a="coMethod" data-m="${k}"` : ''}><span class="mi">${v.icon}</span><div><b>${v.name}</b><div class="mute">${on ? v.sub : 'Coming soon'}</div></div></div>`; }).join('')}</div>
    ${fields}${c.err ? `<div class="alert red">${esc(c.err)}${c.seatConflict ? `<div style="margin-top:8px"><button class="btn sm" data-a="seatEdit" data-id="${c.seatConflict}">💺 Choose new seats</button></div>` : ''}</div>` : ''}
    ${tooBig ? `<div class="alert amber">M-Pesa allows up to ${money(cfg.mpesa_max)} per transaction. Go back and select fewer items, or choose another method.</div>` : ''}
    <div class="row"><button class="btn ghost" data-a="coBack">← Back</button></div></div>
    ${orderSide(t, `<button class="btn" style="width:100%" data-a="coPay" ${tooBig || c.busy ? 'disabled' : ''}>${c.busy ? 'Sending…' : 'Pay ' + money(total)}</button><p class="mute" style="font-size:.8rem;text-align:center;margin-top:8px">🔒 Secure payment</p>`)}</div>`;
}

function coProcessing(t) {
  const c = S.co, p = c.pay, m = METHODS[p.method];
  return `${stepper(2)}<div class="card center proc"><div class="spinner"></div>
    <h2>${p.method === 'card' ? 'Processing your card…' : 'Check your phone'}</h2>
    <p>${p.method === 'card' ? `Authorising <b>${money(p.amount)}</b> on card ${esc(p.phone)}.` : `We sent a request for <b>${money(p.amount)}</b> to <b>${esc(fmtPhone(c.phone) || p.phone)}</b>. Enter your ${m.name} PIN to approve it.`}</p>
    <ol class="proc-steps"><li class="ok">Request sent</li><li class="on">Waiting for your approval</li><li>Payment confirmed</li></ol>
    <p class="mute">Waiting <span id="co-elapsed">${c.elapsed}</span>s · please keep this page open</p>
    ${cfgNow().mode === 'mock' ? '<p class="mute" style="font-size:.82rem"> the phone prompt is simulated.</p>' : ''}
    <button class="btn ghost" data-a="coStop">Stop waiting</button></div>`;
}

function receiptHtml(p) {
  const ok = p.status === 'success';
  return `<div class="receipt"><div class="row between"><b>✈️ Travel Itinerary Creator</b><span class="mute">${ok ? 'PAYMENT RECEIPT' : 'PAYMENT ATTEMPT'}</span></div>
    <table class="kv"><tr><td>Reference</td><td><b>${esc(p.reference)}</b></td></tr>
    <tr><td>${METHODS[p.method].name} confirmation</td><td><b>${p.receipt ? esc(p.receipt) : ok ? 'Sent by SMS' : '–'}</b></td></tr>
    <tr><td>Date</td><td>${when(ok ? p.paid_at : p.created_at)}</td></tr><tr><td>Paid with</td><td>${METHODS[p.method].name} · ${esc(p.phone)}</td></tr>
    <tr><td>Trip</td><td>${esc(p.trip_name)}</td></tr><tr><td>Status</td><td>${esc(PAY_STATUS[p.status]?.[0] || p.status)}</td></tr></table>
    <table class="tbl"><tr><th>Item</th><th class="r">Amount</th></tr>${p.items.map(i => `<tr><td>${ICON[i.type] || ''} ${esc(i.title)}${i.detail ? `<br><small class="mute">💺 ${esc(i.detail)}</small>` : ''}</td><td class="r">${money(i.amount)}</td></tr>`).join('')}
    <tr><td><b>Total ${ok ? 'paid' : ''}</b></td><td class="r"><b>${money(p.amount)}</b></td></tr></table></div>`;
}

function coDone() {
  const c = S.co, p = c.pay; if (!p) return vTrips();
  const t = S.trips.find(x => x.id === p.trip_id), ok = p.status === 'success';
  if (ok) return `${c.fromHistory ? '' : stepper(3)}<div class="card center success"><div class="bigmark ok">✓</div><h1>${c.fromHistory ? 'Payment receipt' : 'Payment successful'}</h1>
    <p>${c.fromHistory ? '' : `Thank you! Your bookings for <b>${esc(p.trip_name)}</b> are confirmed.`}</p></div>${receiptHtml(p)}
    <div class="row noprint" style="margin:14px 0"><button class="btn" data-a="coPrint">🖨️ Print receipt</button>${t ? `<button class="btn ghost" data-a="coToTrip" data-id="${t.id}">View trip summary</button>` : ''}<button class="btn ghost" data-a="nav" data-v="payments">All payments</button></div>
    ${t && !c.fromHistory ? `<h2>Your trip at a glance</h2>${tripSummaryHtml(t)}` : ''}`;
  const title = p.status === 'cancelled' ? 'Payment cancelled' : p.status === 'expired' ? 'No response received' : 'Payment not completed';
  return `${c.fromHistory ? '' : stepper(3)}<div class="card center fail"><div class="bigmark bad">✕</div><h2>${title}</h2><p>${esc(p.message || 'The payment did not go through.')}</p>
    <p class="mute">Your items are still reserved and nothing was booked as paid. If money was deducted from your account, it will be matched automatically or refunded by your provider.</p>
    <div class="row noprint" style="justify-content:center">${c.fromHistory ? '<button class="btn" data-a="nav" data-v="payments">← Back to payments</button>' : '<button class="btn" data-a="coRetry">Try again</button><button class="btn ghost" data-a="coBackReview">Change items</button>'}</div></div>${receiptHtml(p)}`;
}

function vPayments() {
  return `<h1>Payments</h1><p class="mute">Every payment attempt and receipt for your trips.</p>${S.history.length ? `<div class="card table-wrap"><table class="tbl"><tr><th>Date</th><th>Trip</th><th>Method</th><th class="r">Amount</th><th>Status</th><th></th></tr>
    ${S.history.map(p => `<tr><td>${when(p.created_at)}</td><td>${esc(p.trip_name)}</td><td>${METHODS[p.method].icon} ${METHODS[p.method].name}</td><td class="r">${money(p.amount)}</td>
      <td><span class="badge ${PAY_STATUS[p.status]?.[1] || ''}">${PAY_STATUS[p.status]?.[0] || p.status}</span></td><td><button class="btn ghost sm" data-a="viewPay" data-id="${p.id}">${p.status === 'success' ? 'Receipt' : 'Details'}</button></td></tr>`).join('')}</table></div>`
    : '<div class="card center"><p class="mute">No payments yet. Reserve flights, hotels or activities and pay from the cart.</p></div>'}`;
}

Object.assign(VIEWS, { checkout: vCheckout, payments: vPayments });

// ---------- behaviour ----------
let pollTimer = null;
const stopPoll = () => { clearInterval(pollTimer); pollTimer = null; };
function startPoll() {
  stopPoll(); let n = 0;
  pollTimer = setInterval(async () => {
    if (S.view !== 'checkout' || S.co.step !== 'processing') return stopPoll();
    S.co.elapsed++; n++; const el = $('#co-elapsed'); if (el) el.textContent = S.co.elapsed;
    if (n % 3) return;                                              // ask the server every 3 seconds
    try {
      const r = await API.payStatus(S.co.pay.id); S.co.pay = r.payment;
      if (r.payment.status !== 'pending') {
        stopPoll(); S.trips = (await API.trips()).trips;            // bookings are now "Paid"
        try { S.user = (await API.me()).user; } catch { /* keep going */ }
        S.co.step = 'done'; render();
      }
    } catch { /* network blip - keep polling */ }
  }, 1000);
}
async function loadHistory() {
  try {
    let r = await API.payHistory();
    const pend = r.payments.filter(p => p.status === 'pending');
    if (pend.length) { await Promise.all(pend.map(p => API.payStatus(p.id).catch(() => null))); r = await API.payHistory(); S.trips = (await API.trips()).trips; }
    S.history = r.payments; if (S.view === 'payments') render();
  } catch { /* ignore */ }
}
const coSaveInputs = () => { const el = document.querySelector('#co-phone'); if (el) S.co.phone = el.value; };

const CO_ACTIONS = {
  openCheckout: ({ trip }) => {
    const t = S.trips.find(x => x.id === trip); S.tripId = trip;
    S.co = { tripId: trip, step: 'review', sel: new Set(unpaidItems(t).map(b => b.id)), method: 'mpesa', phone: S.user.phone ? fmtPhone(S.user.phone) : '', pay: null, err: '', elapsed: 0 };
    S.view = 'checkout'; render(); window.scrollTo(0, 0);
  },
  coToggle: ({ id }) => { S.co.sel.has(id) ? S.co.sel.delete(id) : S.co.sel.add(id); render(); },
  coAll: () => { const t = coTrip(), all = unpaidItems(t); S.co.sel = S.co.sel.size === all.length ? new Set() : new Set(all.map(b => b.id)); render(); },
  coNext: () => { if (!coItems(coTrip()).length) return; S.co.step = 'method'; S.co.err = ''; render(); window.scrollTo(0, 0); },
  coBack: () => { coSaveInputs(); S.co.step = 'review'; render(); },
  coBackReview: () => { S.co.step = 'review'; render(); },
  coMethod: ({ m }) => { coSaveInputs(); S.co.method = m; S.co.err = ''; render(); },
  coRetry: () => { S.co.step = 'method'; S.co.err = ''; render(); },
  coStop: () => { stopPoll(); S.co.step = 'method'; S.co.err = 'We stopped waiting. If you already approved the payment on your phone, it will appear under Payments shortly.'; render(); },
  coPrint: () => window.print(),
  coToTrip: ({ id }) => { S.tripId = id; S.tab = 'summary'; S.view = 'trip'; render(); window.scrollTo(0, 0); },
  viewPay: ({ id }) => { const p = S.history.find(x => x.id === id); S.co = { tripId: p.trip_id, step: 'done', pay: p, fromHistory: true, sel: new Set() }; S.view = 'checkout'; render(); },
  coPay: async () => {
    coSaveInputs(); const c = S.co, t = coTrip(); c.err = '';
    const body = { trip_id: t.id, method: c.method, booking_ids: [...c.sel] };
    if (c.method === 'card') body.card = { number: document.querySelector('#co-card').value, exp: document.querySelector('#co-exp').value, cvv: document.querySelector('#co-cvv').value };
    else { if (!normPhone(c.phone)) { c.err = 'Enter a valid Kenyan phone number, e.g. 0712 345 678'; return render(); } body.phone = c.phone; }
    c.busy = true; render();
    try { const r = await API.payCreate(body); c.pay = r.payment; c.step = 'processing'; c.elapsed = 0; startPoll(); }
    catch (e) { c.err = e.message; c.seatConflict = e.data?.seat_conflict || null; }
    finally { c.busy = false; render(); }
  },
};
