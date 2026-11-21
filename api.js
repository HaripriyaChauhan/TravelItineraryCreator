// All server calls live here. (Owner: Tilak - Database & API)
const API = {
  async call(path, opts = {}) {
    const r = await fetch('api/' + path, { headers: { 'Content-Type': 'application/json' }, ...opts });
    const d = await r.json().catch(() => ({ error: 'Server error - is PHP running?' }));
    if (!r.ok) { const e = new Error(d.error || 'Request failed'); e.data = d; throw e; }
    return d;
  },
  post: (path, body) => API.call(path, { method: 'POST', body: JSON.stringify(body) }),
  me: () => API.call('auth.php?action=me'),
  login: b => API.post('auth.php?action=login', b),
  register: b => API.post('auth.php?action=register', b),
  logout: () => API.call('auth.php?action=logout'),
  updateProfile: b => API.post('auth.php?action=update', b),
  resetRequest: b => API.post('auth.php?action=reset_request', b),
  resetConfirm: b => API.post('auth.php?action=reset_confirm', b),
  search: q => API.call('search.php?' + new URLSearchParams(q)),
  explore: d => API.call('suggest.php?' + new URLSearchParams({ destination: d })),
  hotel: q => API.call('hotel.php?' + new URLSearchParams(q)),
  payConfig: () => API.call('payments.php?action=config'),
  payCreate: b => API.post('payments.php?action=create', b),
  payStatus: id => API.call('payments.php?action=status&id=' + encodeURIComponent(id)),
  payHistory: () => API.call('payments.php?action=history'),
  payReceipt: id => API.call('payments.php?action=receipt&id=' + encodeURIComponent(id)),
  seats: q => API.call('seats.php?' + new URLSearchParams(q)),
  plan: q => API.call('plan.php?' + new URLSearchParams(q)),
  seed: b => API.post('seed.php', b),
  trips: () => API.call('trips.php'),
  saveTrip: t => API.post('trips.php', t),
  deleteTrip: id => API.call('trips.php?id=' + id, { method: 'DELETE' }),
  flights: q => API.call('flights.php?' + new URLSearchParams(q)),
};

// Wraps a click action so any error becomes a toast message instead of a silent failure (toast() lives in app.js; used at call time).
const guard = fn => async (...a) => { try { await fn(...a); } catch (e) { toast(e.message); } };
