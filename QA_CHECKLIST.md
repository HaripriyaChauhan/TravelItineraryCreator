# Manual QA Checklist (Yogi) - tick as you test, note bugs in the table at the bottom
Automated: `node tests/run-tests.js` (17 tests: conflict engine, all screens render, XSS escaping)

## Authentication
- [ ] Register with valid details -> lands on Home
- [ ] Register with existing email -> error shown
- [ ] Register with password < 6 chars -> error shown
- [ ] Wrong password -> "Wrong email or password"
- [ ] Refresh page while logged in -> still logged in
- [ ] Forgot password -> code popup -> set new password -> login works with new one
- [ ] Log out (Settings) -> returns to login screen
## Trips & Itinerary
- [ ] Create trip; end date before start date -> error toast
- [ ] Edit trip, delete trip (confirm dialog)
- [ ] Add / edit / delete activity
- [ ] Drag activity onto another day -> date changes; drag onto another activity -> they swap
## Bookings & APIs
- [ ] Flight search NBO -> ATH shows 6 results; same from/to -> error
- [ ] "Add to trip" adds booking AND a Flights expense
- [ ] Hotel / Activities / Restaurants search (city "Athens") and add to trip
- [ ] Edit and delete a booking
- [ ] Hotel search -> Details popup shows rooms, amenities, address; also works from a saved hotel booking
## Smart Suggestions / Explore
- [ ] Trip to "Nairobi" / "Athens" / "Bali" shows the right country guide; unknown city shows general tips
- [ ] "+ Plan" on a place adds it to the itinerary
- [ ] Trip with no flight/hotel shows "Suggested bookings"; "Search" opens a pre-filled search with results
- [ ] After adding a flight, a return-flight nudge appears
## Payments (demo mode)
- [ ] Add a flight / hotel / activity to a trip -> cart bar appears at the bottom with the correct total
- [ ] Review screen: untick an item -> order summary total changes; "Continue" disabled when nothing is ticked
- [ ] Invalid phone (e.g. 123) rejected; `0712 345 678` accepted; phone remembered next time (Settings)
- [ ] M-Pesa: waiting screen -> "Payment successful" with an M-Pesa code; items turn **Paid** and show a lock; budget "Paid" increases and an expense "Paid via M-Pesa" appears
- [ ] Phone ending 0000 -> "Payment cancelled"; 1111 -> insufficient funds; "Try again" works and items stay Reserved
- [ ] Card 4111 1111 1111 1111 succeeds; wrong number / expired date rejected; card ending 0002 declined
- [ ] Total over KES 250,000 with M-Pesa -> warning, Pay disabled; paying in two parts works
- [ ] Payments page lists every attempt; Receipt opens; Print receipt / Print summary work
- [ ] A trip with paid bookings cannot be deleted; paid bookings cannot be edited/deleted
## Seats, budget tools, samples
- [ ] Bookings → search a flight → *Add to trip* → seat map: pick a seat per traveller (green, numbered); taken seats cannot be clicked; fees shown; *Confirm seats* adds the flight with the fee in the total
- [ ] *Skip* adds the flight without seats; **💺 Choose/Change seats** works while unpaid; a paid flight shows its seats and is locked; receipt lists the seats
- [ ] Budget page: **✎ Edit budget** saves (e.g. 300,000 → 700,000); −10% / +10% buttons work; warning when budget < committed money
- [ ] **Plan within your budget**: with a big budget shows a package + "Add this package"; with a small one shows a shorter stay or "not enough, need KES X more" + *Raise budget*; every suggested total ≤ available money
- [ ] Bookings: *Only show what I can afford* hides pricier results and says how many are hidden; untick it to see them marked "over budget"
- [ ] M-Pesa: totals up to KES 500,000 can be paid; above that the warning appears
- [ ] Load sample trip → chooser shows both trips; load **Greece Adventure** (unpaid, has seats + a conflict) and **Bali Escape** (all paid, 2 receipts, no conflicts, no empty days); loading the same one twice is refused
## Conflicts, Budget, Reminders
- [ ] Two overlapping activities show red + warning icon, and appear in Suggestions with "Apply fix"
- [ ] Activity outside venue hours is flagged
- [ ] Expenses update spent/remaining/pie; over budget turns bar red
- [ ] Reminders list upcoming items; browser notifications button works
## Cross-platform
- [ ] Chrome, Firefox, Edge  - [ ] Phone width (<800px)  - [ ] Two different users can't see each other's trips

## Bug report
| # | Where | Steps | Expected | Actual | Status |
|---|-------|-------|----------|--------|--------|
