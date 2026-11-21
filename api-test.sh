#!/bin/sh
# Backend integration test (Owner: Yogi). Usage:  sh tests/api-test.sh   (needs php + curl)
cd "$(dirname "$0")/.." || exit 1
PORT=8765; J=/tmp/tic-cookies.txt; rm -f $J
TIC_MOCK_DELAY=1 php -S localhost:$PORT >/dev/null 2>&1 & PID=$!
for i in 1 2 3 4 5 6 7 8 9 10 11 12; do curl -s -o /dev/null "localhost:$PORT/api/auth.php?action=me" && break; sleep 0.5; done   # wait until the server is up
U="http://localhost:$PORT/api"; E="t$(date +%s)@test.com"; P=0; F=0
chk() { if echo "$2" | grep -q "$3"; then P=$((P+1)); echo "PASS $1"; else F=$((F+1)); echo "FAIL $1 -> $2"; fi; }
c() { curl -s -b $J -c $J -H 'Content-Type: application/json' "$@"; }
chk "unauthenticated trips blocked" "$(c $U/trips.php)" "Please log in"
chk "register" "$(c -d "{\"name\":\"Test\",\"email\":\"$E\",\"password\":\"secret1\"}" "$U/auth.php?action=register")" '"user"'
chk "duplicate email rejected" "$(c -d "{\"name\":\"T\",\"email\":\"$E\",\"password\":\"secret1\"}" "$U/auth.php?action=register")" "already registered"
chk "short password rejected" "$(c -d '{"name":"T","email":"x@y.com","password":"1"}' "$U/auth.php?action=register")" "at least 6"
c "$U/auth.php?action=logout" >/dev/null
chk "wrong password" "$(c -d "{\"email\":\"$E\",\"password\":\"nope\"}" "$U/auth.php?action=login")" "Wrong email"
chk "login" "$(c -d "{\"email\":\"$E\",\"password\":\"secret1\"}" "$U/auth.php?action=login")" '"user"'
chk "me" "$(c "$U/auth.php?action=me")" "$E"
TRIP='{"name":"Greece","destination":"Athens","start":"2026-12-01","end":"2026-12-05","travellers":2,"budget":2000,"activities":[{"id":"a1","type":"activity","title":"Acropolis","date":"2026-12-01","time":"09:00","duration":120,"opens":"08:00","closes":"20:00"}],"bookings":[{"id":"b1","type":"flight","title":"EK212","date":"2026-12-01","time":"10:30","duration":400,"detail":"Emirates"}],"expenses":[{"id":"e1","cat":"Flights","title":"EK212","amount":600,"date":"2026-12-01"}]}'
R=$(c -d "$TRIP" $U/trips.php); chk "create trip" "$R" '"id"'
ID=$(echo "$R" | sed 's/.*"trip":{"id":"\([^"]*\)".*/\1/')
chk "bad dates rejected" "$(c -d '{"name":"x","start":"2026-12-05","end":"2026-12-01"}' $U/trips.php)" "before start"
chk "list returns nested data" "$(c $U/trips.php)" '"Acropolis"'
chk "update trip (add expense)" "$(c -d "$(echo "$TRIP" | sed "s/\"name\":\"Greece\"/\"id\":\"$ID\",\"name\":\"Greece2\"/; s/\"expenses\":\[/\"expenses\":[{\"id\":\"e2\",\"cat\":\"Food\",\"title\":\"Dinner\",\"amount\":50,\"date\":\"2026-12-02\"},/")" $U/trips.php)" '"Dinner"'
chk "flights api" "$(c "$U/flights.php?from=NBO&to=ATH&date=2026-12-01")" '"airline"'
chk "hotel search api" "$(c "$U/search.php?type=hotel&city=Athens")" '"Grand Hotel'
chk "explore: Athens -> Greece" "$(c "$U/suggest.php?destination=Athens%2C%20Santorini")" '"country":"Greece"'
chk "explore: Nairobi -> Kenya" "$(c "$U/suggest.php?destination=Nairobi")" '"country":"Kenya"'
chk "explore: unknown city fallback" "$(c "$U/suggest.php?destination=Atlantis")" '"matched":false'
chk "hotel details api" "$(c "$U/hotel.php?name=Grand%20Hotel%20-%20Athens&city=Athens&price=120")" '"rooms"'
chk "password reset flow" "$(T=$(c -d "{\"email\":\"$E\"}" "$U/auth.php?action=reset_request" | sed 's/.*"token":"\([0-9]*\)".*/\1/'); c -d "{\"email\":\"$E\",\"token\":\"$T\",\"password\":\"newpass1\"}" "$U/auth.php?action=reset_confirm")" '"ok"'
c "$U/auth.php?action=logout" >/dev/null
chk "login with new password" "$(c -d "{\"email\":\"$E\",\"password\":\"newpass1\"}" "$U/auth.php?action=login")" '"user"'
# another user must not see / delete this trip
c "$U/auth.php?action=logout" >/dev/null
c -d '{"name":"Other","email":"other'$E'","password":"secret1"}' "$U/auth.php?action=register" >/dev/null
chk "other user sees no trips" "$(c $U/trips.php)" '"trips":\[\]'
chk "other user cannot overwrite" "$(c -d "{\"id\":\"$ID\",\"name\":\"Hack\",\"start\":\"2026-12-01\",\"end\":\"2026-12-02\"}" $U/trips.php)" "not found"
c "$U/auth.php?action=logout" >/dev/null
c -d "{\"email\":\"$E\",\"password\":\"newpass1\"}" "$U/auth.php?action=login" >/dev/null
c -X DELETE "$U/trips.php?id=$ID" >/dev/null
chk "delete trip" "$(c $U/trips.php)" '"trips":\[\]'
php tests/pay-test.php $PORT || F=$((F+1))
kill $PID 2>/dev/null; echo; echo "$P passed, $F failed"; [ $F -eq 0 ]
