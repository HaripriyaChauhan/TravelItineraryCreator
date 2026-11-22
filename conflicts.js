// Smart Conflict Engine. (Owner: Paul Otula)
// Input: a trip. Output: { conflicts:[ids], alerts:[{level,msg}], tips:[string] }
const toMin = t => { const [h, m] = (t || '00:00').split(':').map(Number); return h * 60 + m; };

function tripEvents(trip) {
  const acts = trip.activities.map(a => ({ ...a, src: 'act' }));
  const books = trip.bookings.filter(b => b.time).map(b => ({ ...b, src: 'book' }));
  return [...acts, ...books].map(e => ({ ...e, start: toMin(e.time), end: toMin(e.time) + (+e.duration || 60) }));
}

function analyseTrip(trip) {
  const ev = tripEvents(trip), bad = new Set(), alerts = [], tips = [], fixes = [];
  const hhmm = m => String(Math.floor(m / 60) % 24).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
  // 1. Time overlaps on the same date
  for (let i = 0; i < ev.length; i++) for (let j = i + 1; j < ev.length; j++) {
    const a = ev[i], b = ev[j];
    if (a.date === b.date && a.start < b.end && b.start < a.end) {
      bad.add(a.id); bad.add(b.id);
      alerts.push({ level: 'red', msg: `"${a.title}" (${a.time}) overlaps with "${b.title}" (${b.time}) on ${a.date}.` });
      const mv = b.src === 'act' ? b : a.src === 'act' ? a : null, anchor = mv === b ? a : b;
      if (mv && !fixes.some(f => f.id === mv.id)) fixes.push({ id: mv.id, title: mv.title, time: hhmm(anchor.end) });
    }
  }
  // 1b. Venue opening hours
  ev.forEach(e => {
    if (e.opens && e.closes && (e.start < toMin(e.opens) || e.end > toMin(e.closes))) {
      bad.add(e.id); alerts.push({ level: 'red', msg: `"${e.title}" (${e.time}) falls outside opening hours ${e.opens}-${e.closes}.` });
    }
  });
  // 2. Budget (money already spent + reserved bookings that still await payment)
  const spent = trip.expenses.reduce((s, e) => s + +e.amount, 0);
  const rsv = trip.bookings.filter(b => b.status === 'Reserved').reduce((s, b) => s + (+b.price || 0), 0), committed = spent + rsv;
  if (trip.budget && committed > trip.budget) alerts.push({ level: 'red', msg: `Over budget by KES ${Math.round(committed - trip.budget).toLocaleString('en-US')} (including reserved bookings).` });
  else if (trip.budget && committed > trip.budget * 0.8) alerts.push({ level: 'amber', msg: `You've committed ${Math.round(committed / trip.budget * 100)}% of your budget.` });
  // 3. Busy / empty days + tight gaps
  const byDate = {};
  ev.forEach(e => (byDate[e.date] = byDate[e.date] || []).push(e));
  Object.entries(byDate).forEach(([d, list]) => {
    if (list.length > 5) tips.push(`${d} has ${list.length} items - consider spreading them out.`);
    list.sort((a, b) => a.start - b.start);
    for (let i = 1; i < list.length; i++) {
      const gap = list[i].start - list[i - 1].end;
      if (gap >= 0 && gap < 30 && !bad.has(list[i].id)) tips.push(`Only ${gap} min between "${list[i - 1].title}" and "${list[i].title}" on ${d} - allow travel time.`);
    }
  });
  for (let d = new Date(trip.start); d <= new Date(trip.end); d.setDate(d.getDate() + 1)) {
    const k = d.toISOString().slice(0, 10);
    if (!byDate[k]) tips.push(`${k} has nothing planned yet.`);
  }
  return { conflicts: bad, alerts, tips, fixes };
}

// Booking nudges: what is missing from this trip? (Owner: Paul)
function bookingSuggestions(t) {
  const s = [], has = ty => t.bookings.filter(b => b.type === ty), city = (t.destination || '').split(',')[0].trim() || 'your destination';
  const fl = has('flight');
  if (!fl.length) s.push({ icon: '✈️', type: 'flight', dir: 'out', date: t.start, city, msg: `No flight booked yet. Find flights to ${city} for ${t.start}.` });
  else if (fl.length < 2) s.push({ icon: '🛫', type: 'flight', dir: 'ret', date: t.end, city, msg: `You have an outbound flight but no return flight for ${t.end}.` });
  if (!has('hotel').length) s.push({ icon: '🏨', type: 'hotel', city, msg: `No accommodation booked. Browse hotels in ${city}.` });
  let days = 0, noFood = 0;
  for (let d = new Date(t.start); d <= new Date(t.end); d.setDate(d.getDate() + 1)) {
    const k = d.toISOString().slice(0, 10); days++;
    if (![...t.activities, ...t.bookings].some(e => e.date === k && e.type === 'restaurant')) noFood++;
  }
  if (noFood) s.push({ icon: '🍽️', type: 'restaurant', city, msg: `${noFood} of ${days} days have no restaurant planned. Reserve a table in ${city}.` });
  if (!has('activity').length) s.push({ icon: '🎟️', type: 'activity', city, msg: `No tours or experiences booked. See what's on in ${city}.` });
  return s;
}
