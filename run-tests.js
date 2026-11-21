// QA test suite (Owner: Yogi). Run with:  node tests/run-tests.js
const fs = require('fs'), vm = require('vm');
const ctx = vm.createContext({ console, Date, Math, Object, String, Number, Set, Array });
vm.runInContext("var guard = f => f, toast = () => {}, closeModal = () => {}, render = () => {}, uid = () => 'id', persist = async () => {}; var $ = () => ({ innerHTML: '', classList: { add() {} } }); var document = { querySelector: () => null };", ctx);
['conflicts', 'views', 'checkout', 'seats', 'planner'].forEach(f => vm.runInContext(fs.readFileSync(__dirname + `/../js/${f}.js`, 'utf8').replace(/^const /gm, 'var '), ctx));
let pass = 0, fail = 0;
const test = (name, fn) => { try { fn(); pass++; console.log('PASS', name); } catch (e) { fail++; console.log('FAIL', name, '-', e.message); } };
const eq = (a, b) => { if (a !== b) throw new Error(`expected ${b}, got ${a}`); };
const trip = (o = {}) => vm.runInContext(`(${JSON.stringify({ id: 't1', name: 'T', destination: 'X', start: '2026-12-01', end: '2026-12-02', travellers: 2, budget: 1000, activities: [], bookings: [], expenses: [], ...o })})`, ctx);
const an = t => { ctx.t = t; return vm.runInContext('analyseTrip(t)', ctx); };

test('detects overlapping events', () => eq(an(trip({ activities: [{ id: 'a', date: '2026-12-01', time: '10:00', title: 'A', duration: 60 }, { id: 'b', date: '2026-12-01', time: '10:30', title: 'B', duration: 60 }] })).conflicts.size, 2));
test('no conflict when back to back', () => eq(an(trip({ activities: [{ id: 'a', date: '2026-12-01', time: '10:00', title: 'A', duration: 60 }, { id: 'b', date: '2026-12-01', time: '11:00', title: 'B', duration: 60 }] })).conflicts.size, 0));
test('different days never conflict', () => eq(an(trip({ activities: [{ id: 'a', date: '2026-12-01', time: '10:00', title: 'A' }, { id: 'b', date: '2026-12-02', time: '10:00', title: 'B' }] })).conflicts.size, 0));
test('opening hours violation flagged', () => eq(an(trip({ activities: [{ id: 'a', date: '2026-12-01', time: '07:00', title: 'Museum', duration: 60, opens: '09:00', closes: '17:00' }] })).conflicts.size, 1));
test('over budget alert', () => eq(an(trip({ expenses: [{ amount: 1500 }] })).alerts.some(a => /Over budget/.test(a.msg)), true));
test('80% budget warning', () => eq(an(trip({ expenses: [{ amount: 850 }] })).alerts[0].level, 'amber'));
test('suggested fix produced for activity overlap', () => eq(an(trip({ activities: [{ id: 'a', date: '2026-12-01', time: '10:00', title: 'A', duration: 60 }, { id: 'b', date: '2026-12-01', time: '10:30', title: 'B', duration: 60 }] })).fixes[0].time, '11:00'));
test('empty days produce tips', () => eq(an(trip()).tips.length, 2));

const bs = t => { ctx.t = t; return vm.runInContext('bookingSuggestions(t)', ctx); };
test('empty trip suggests flight, hotel, restaurants, tours', () => eq(bs(trip()).map(x => x.type).join(), 'flight,hotel,restaurant,activity'));
test('one flight -> suggests return flight', () => eq(bs(trip({ bookings: [{ id: 'f', type: 'flight', date: '2026-12-01', time: '10:00', title: 'F' }] }))[0].dir, 'ret'));
test('fully booked trip has no hotel/flight nudges', () => eq(bs(trip({ bookings: [{ type: 'flight' }, { type: 'flight' }, { type: 'hotel' }, { type: 'activity' }, { type: 'restaurant', date: '2026-12-01' }, { type: 'restaurant', date: '2026-12-02' }] })).length, 0));

// Smoke test: every screen renders without throwing
const t = trip({ activities: [{ id: 'a', type: 'activity', date: '2026-12-01', time: '10:00', title: 'A <b>', duration: 60 }], bookings: [{ id: 'b', type: 'flight', title: 'F', date: '2026-12-01', time: '08:00', duration: 60 }, { id: 'b2', type: 'hotel', title: 'Hotel', date: '2026-12-01', time: '15:00', duration: 30, status: 'Reserved', price: 40000, unit_price: 8000, qty: 5 }, { id: 'b3', type: 'flight', title: 'Paid flight', date: '2026-12-02', time: '08:00', duration: 60, status: 'Paid', price: 50000 }], expenses: [{ id: 'e', cat: 'Flights', title: 'F', amount: 100, date: '2026-12-01' }] });
Object.keys(vm.runInContext('VIEWS', ctx)).forEach(v => test('renders view: ' + v, () => {
  ctx.v = v; ctx.tr = t;
  const html = vm.runInContext(`S.user = { name: 'Test User', email: 'a@b.c', currency: 'USD' }; S.trips = [tr]; S.tripId = 't1'; S.view = v; S.tab='itinerary'; S.history=[]; S.co={tripId:'t1',step:'review',sel:new Set(['b2'])}; VIEWS[v]()`, ctx);
  if (!html || html.length < 20) throw new Error('empty');
}));
test('HTML is escaped (XSS)', () => { const h = vm.runInContext('tItinerary(S.trips[0])', ctx); if (h.includes('A <b>')) throw new Error('unescaped'); });

// ---- payments / checkout ----
const run = code => vm.runInContext(code, ctx);
test('phone numbers are normalised like Safaricom expects', () => {
  eq(run("normPhone('0712345678')"), '254712345678'); eq(run("normPhone('+254 712 345 678')"), '254712345678');
  eq(run("normPhone('712345678')"), '254712345678'); eq(run("normPhone('0112345678')"), '254112345678');
  eq(run("normPhone('12345')"), null); eq(run("normPhone('0212345678')"), null); eq(run("normPhone('')"), null);
});
test('phone shown in friendly format', () => eq(run("fmtPhone('254712345678')"), '0712 345 678'));
test('only Reserved bookings with a price are in the cart (paid / external are not)', () => eq(run('unpaidItems(tr).length'), 1));
test('cart total = sum of reserved prices', () => eq(run('reserved(tr)'), 40000));
test('selected total follows the checkbox selection', () => { eq(run('coTotal(tr)'), 40000); eq(run("S.co.sel = new Set(); coTotal(tr)"), 0); run("S.co.sel = new Set(['b2'])"); });
test('money shows Kenya shillings', () => eq(run('money(1234567.4)'), 'KES 1,234,567'));
test('reserved bookings count towards the budget warning', () => {
  const a = an(trip({ budget: 100000, bookings: [{ id: 'r', type: 'hotel', date: '2026-12-01', time: '15:00', title: 'H', status: 'Reserved', price: 120000 }] }));
  eq(a.alerts.some(x => /Over budget/.test(x.msg)), true);
});
test('cart bar shows when something awaits payment', () => { run("S.tripId = 't1'"); if (!run('cartBarHtml()').includes('KES 40,000')) throw new Error('missing'); });
test('cart bar hidden when nothing awaits payment', () => { run("var saved = S.trips; S.trips = [Object.assign({}, tr, { bookings: [] })]"); eq(run('cartBarHtml()'), ''); run('S.trips = saved'); });
const payObj = (o = {}) => Object.assign({ id: 'p1', reference: 'TIC-ABCD1234', trip_id: 't1', trip_name: 'T', method: 'mpesa', phone: '254712***678', amount: 40000, status: 'success', receipt: 'SAB12CD34E', message: 'ok', items: [{ id: 'b2', type: 'hotel', title: 'Hotel <b>', amount: 40000 }], created_at: 1790000000, paid_at: 1790000050 }, o);
for (const step of ['review', 'method', 'processing']) test('checkout step renders: ' + step, () => {
  ctx.po = payObj({ status: 'pending' });
  const html = run(`S.co = { tripId: 't1', step: '${step}', sel: new Set(['b2']), method: 'mpesa', phone: '0712345678', pay: po, elapsed: 3 }; vCheckout()`);
  if (html.length < 100) throw new Error('empty');
});
for (const m of ['airtel', 'card']) test('payment form renders for ' + m, () => { if (run(`S.co = { tripId: 't1', step: 'method', sel: new Set(['b2']), method: '${m}', phone: '' }; vCheckout()`).length < 100) throw new Error('empty'); });
test('M-Pesa limit warning appears for big totals', () => {
  run("S.co = { tripId: 't1', step: 'method', sel: new Set(['b2']), method: 'mpesa', phone: '' }; S.trips[0].bookings[1].price = 600000");
  const h = run('vCheckout()'); run('S.trips[0].bookings[1].price = 40000'); if (!h.includes('per transaction')) throw new Error('no warning');
});
test('success screen shows receipt code and escapes item names', () => {
  ctx.po = payObj(); const h = run("S.co = { tripId: 't1', step: 'done', pay: po, sel: new Set() }; vCheckout()");
  if (!h.includes('SAB12CD34E') || !h.includes('Payment successful')) throw new Error('content'); if (h.includes('Hotel <b>')) throw new Error('XSS');
});
test('failed / cancelled screens offer a retry', () => {
  for (const st of ['failed', 'cancelled', 'expired']) { ctx.po = payObj({ status: st, receipt: null, message: 'No' }); if (!run("S.co = { tripId: 't1', step: 'done', pay: po, sel: new Set() }; vCheckout()").includes('coRetry')) throw new Error(st); }
});
test('payment history renders (empty and filled)', () => {
  if (!run('S.history = []; vPayments()').includes('No payments yet')) throw new Error('empty'); ctx.po = payObj();
  if (!run('S.history = [po]; vPayments()').includes('Receipt')) throw new Error('filled'); run('S.history = []');
});
test('trip summary shows costs and status', () => { const h = run('tripSummaryHtml(tr)'); if (!h.includes('Awaiting payment') || !h.includes('Paid ✓') || !h.includes('Budget')) throw new Error('missing parts'); });


// ---- seats ----
const seatMap = { ref: 'KQ1|NBO|ATH|2026-12-01', letters: ['A', 'B', 'C', 'D', 'E', 'F'], rows: Array.from({ length: 30 }, (_, i) => { const n = i + 1; return n <= 3 ? { n, t: 'extra', p: 4500 } : n <= 9 ? { n, t: 'preferred', p: 1500 } : n === 12 || n === 13 ? { n, t: 'exit', p: 3500 } : { n, t: 'standard', p: 0 }; }), taken: ['4A', '5B'] };
ctx.seatMap = seatMap;
test('seat fees follow the row type (extra 4,500 / exit 3,500 / standard free)', () => eq(run("seatFeeOf(seatMap, ['1A', '12B', '20C'])"), 8000));
test('seat picker renders the cabin: 30 rows x 6, taken seats disabled', () => {
  const h = run("S.seat = { title: 'KQ1', qty: 2, map: seatMap, sel: ['', ''], active: 0, existing: [] }; seatPickerHtml()");
  eq((h.match(/data-a="seatPick"/g) || []).length, 180); eq((h.match(/ disabled /g) || []).length, 2); if (!h.includes('Passenger 2') || !h.includes('Extra legroom')) throw new Error('missing parts');
});
test('picking seats assigns passengers in order and numbers them', () => {
  run("S.seat = { title: 'KQ1', qty: 2, map: seatMap, sel: ['', ''], active: 0, existing: [] }; SEAT_ACTIONS.seatPick({ code: '1A' }); SEAT_ACTIONS.seatPick({ code: '1B' })");
  eq(run('S.seat.sel.join()'), '1A,1B'); if (!run('seatPickerHtml()').includes('>2</button>')) throw new Error('numbering');
});
test('clicking a chosen seat frees it; picking more than the party size replaces the active seat', () => {
  run("SEAT_ACTIONS.seatPick({ code: '1A' })"); eq(run('S.seat.sel.join()'), ',1B'); run("SEAT_ACTIONS.seatPick({ code: '2A' }); SEAT_ACTIONS.seatPick({ code: '2B' })"); eq(run('S.seat.sel.filter(Boolean).length'), 2);
});
test('seat suffix is added to / removed from the booking detail', () => {
  eq(run("seatSuffix(['1A', '1B'], 9000)"), ' · Seats 1A, 1B (+KES 9,000)'); eq(run("seatBase('Emirates · 2 travellers × KES 86,950 · Seats 1A, 1B (+KES 9,000)')"), 'Emirates · 2 travellers × KES 86,950'); eq(run('seatSuffix([], 0)'), '');
});
test('reserved flights show a seat button, paid flights do not', () => {
  const html = (st) => run(`bookingRow({ id: 'f', type: 'flight', title: 'F', date: '2026-12-01', time: '10:00', ref: 'KQ1|NBO|ATH|2026-12-01', status: '${st}', price: 5, qty: 1, seats: '' })`);
  if (!html('Reserved').includes('Choose seats')) throw new Error('missing'); if (html('Paid').includes('seatEdit')) throw new Error('should be locked');
});

// ---- budget + planner ----
const planData = (o = {}) => Object.assign({ feasible: true, shortfall: 0, reduced_nights: null, nights: 5, complete: false, flights_unavailable: false, package: { total: 99750, left: 250, items: [{ cat: 'hotel', label: 'Hotel', cost: 35000, rating: 4.5, booking: { title: 'Plaza <i>', detail: '4 nights × KES 8,750' } }] },
  categories: { activity: { label: 'Activity', options: [{ cost: 2200, rating: 4.1, recommended: false, booking: { title: 'Tour', detail: 'x' } }] } } }, o);
const planFor = d => { ctx.pd = d; return run("S.plans['t1'] = { sig: planSig(tr), data: pd }; planHtml(tr)"); };
test('available money = budget - paid/spent - reserved', () => eq(run('availableToSpend(tr)'), 1000 - 100 - 40000 < 0 ? 0 : 1));
test('planner card: loading state, then package with total + add button', () => {
  if (!run("S.plans = {}; planHtml(tr)").includes('Finding the best options')) throw new Error('loading');
  run("tr.budget = 500000"); const h = planFor(planData()); run('tr.budget = 1000');
  if (!h.includes('Best package') || !h.includes('KES 99,750') || !h.includes('planAddPkg') || !h.includes('4 nights')) throw new Error('package'); if (h.includes('Plaza <i>')) throw new Error('XSS');
});
test('planner card: shorter stay, infeasible, complete, no money, error', () => {
  run('tr.budget = 500000');
  if (!planFor(planData({ reduced_nights: 4 })).includes('instead of 5')) throw new Error('reduced');
  const bad = planFor(planData({ feasible: false, shortfall: 1950 })); if (!bad.includes('not enough') || !bad.includes('KES 1,950') || !bad.includes('budgetSet')) throw new Error('infeasible');
  if (!planFor(planData({ complete: true })).includes('already covered')) throw new Error('complete');
  if (!run("S.plans['t1'] = { sig: planSig(tr), error: 'Bad dates' }; planHtml(tr)").includes('Bad dates')) throw new Error('error');
  run('tr.budget = 1000'); if (!/over budget by/.test(planFor(planData())) ) throw new Error('over budget message');
});
test('budget tab has edit + nudge buttons and the planner', () => {
  const h = run("S.plans = {}; tBudget(tr)"); if (!h.includes('editBudget') || !h.includes('data-p="10"') || !h.includes('Plan within your budget')) throw new Error('missing');
});
test('search hides options you cannot afford and says how many', () => {
  run("tr.budget = 120000; tr.bookings = tr.bookings.filter(b => b.status !== 'Reserved'); S.expensesBackup = tr.expenses; tr.expenses = []; S.tripId = 't1'; S.bTab = 'flight'; S.onlyBudget = true");
  ctx.fl = [{ airline: 'A', number: 'A1', from: 'NBO', to: 'ATH', date: '2026-12-01', depart: '10:00', arrive: '18:00', duration: 480, stops: 0, price: 50000, unit_price: 50000, ref: 'r1' }, { airline: 'B', number: 'B2', from: 'NBO', to: 'ATH', date: '2026-12-01', depart: '10:00', arrive: '18:00', duration: 480, stops: 0, price: 90000, unit_price: 90000, ref: 'r2' }];
  run('S.flights = fl; S.trips[0].travellers = 2');   // 2 travellers: 100,000 fits, 180,000 does not
  const on = run('vBookings()'); if (!on.includes('A1') || on.includes('B2</b>') || !on.includes('hidden')) throw new Error('filter on');
  run('S.onlyBudget = false'); const off = run('vBookings()'); if (!off.includes('B2') || !off.includes('over budget')) throw new Error('filter off');
  run('S.onlyBudget = true; S.flights = []; S.trips[0].travellers = 2; tr.expenses = S.expensesBackup');
});
test('hotel cost uses nights; others use travellers', () => { eq(run("resultCost({ type: 'hotel', unit_price: 10000 }, { start: '2026-12-01', end: '2026-12-06', travellers: 2 })"), 50000); eq(run("resultCost({ type: 'activity', unit_price: 3000 }, { start: '2026-12-01', end: '2026-12-06', travellers: 2 })"), 6000); });
test('sample chooser offers both trips and marks loaded ones', () => {
  run("S.trips = [tr]"); const h = run('sampleChooserHtml()'); if (!h.includes('Greece Adventure') || !h.includes('Bali Escape') || (h.match(/seedKind/g) || []).length !== 2) throw new Error('both');
  run("S.trips = [Object.assign({}, tr, { name: 'Bali Escape' })]"); const h2 = run('sampleChooserHtml()'); if (!h2.includes('Already in your trips') || (h2.match(/seedKind/g) || []).length !== 1) throw new Error('loaded');
  run('S.trips = [tr]');
});
test('M-Pesa default limit is KES 500,000', () => eq(run('cfgNow().mpesa_max'), 500000));

console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
