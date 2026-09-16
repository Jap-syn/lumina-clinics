#!/usr/bin/env bash
#
# Fires N simultaneous booking requests at one slot on a running instance.
# Exactly one should come back 201. Everything else should be 409 or 422.
#
#   ./scripts/race.sh https://lumina.up.railway.app 1 4 2026-10-20T15:00:00+07:00 20
#
set -uo pipefail

BASE="${1:?usage: race.sh BASE_URL BRANCH_ID TREATMENT_ID STARTS_AT [N]}"
BRANCH="${2:?}"
TREATMENT="${3:?}"
STARTS_AT="${4:?}"
N="${5:-20}"

TMP="$(mktemp -d)"
echo "Firing ${N} simultaneous bookings at ${STARTS_AT}"

seq 1 "$N" | xargs -P "$N" -I{} sh -c "
  curl -s -o '${TMP}/body-{}' -w '%{http_code}' \
    -X POST '${BASE}/api/bookings' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{\"branch_id\":${BRANCH},\"treatment_id\":${TREATMENT},\"starts_at\":\"${STARTS_AT}\",\"client\":{\"name\":\"Racer {}\",\"phone\":\"0999{}\"}}' \
    > '${TMP}/code-{}'
"

echo
echo "HTTP status  count"
cat "${TMP}"/code-* | sort | uniq -c | awk '{printf "%-12s %s\n", $2, $1}'

CREATED=$(grep -l . "${TMP}"/code-* | xargs cat | grep -c '^201$' || true)
echo
if [ "$CREATED" -eq 1 ]; then
  echo "PASS - exactly one booking was created."
else
  echo "FAIL - ${CREATED} bookings were created; expected exactly 1."
fi
rm -rf "$TMP"
