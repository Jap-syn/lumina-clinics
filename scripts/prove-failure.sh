#!/usr/bin/env bash
#
# Proves the critical tests can actually fail.
#
# Applies a patch that removes one rule, runs the test that guards that rule,
# captures the failing output, then puts the rule back and re-runs to show green.
#
#   ./scripts/prove-failure.sh 01      # the double booking rule
#   ./scripts/prove-failure.sh 02      # the double payment rule
#
set -uo pipefail
cd "$(dirname "$0")/.."

WHICH="${1:-01}"

case "$WHICH" in
  01) PATCH="docs/patches/01-remove-room-overlap-constraint.patch"
      FILTER="DoubleBookingTest"
      LABEL="no double booking" ;;
  02) PATCH="docs/patches/02-remove-double-payment-guard.patch"
      FILTER="test_two_different_keys_on_one_booking_still_charge_once"
      LABEL="no double payment" ;;
  *)  echo "usage: $0 [01|02]"; exit 2 ;;
esac

OUT="docs/failing-output-${WHICH}.txt"

echo "==> Removing the '${LABEL}' rule with ${PATCH}"
git apply "$PATCH" || { echo "Could not apply the patch. Is the tree clean?"; exit 1; }

echo "==> Running ${FILTER} with the rule removed (this SHOULD fail)"
{
  echo "\$ git apply ${PATCH}"
  echo "\$ php artisan test --filter=${FILTER}"
  echo
  php artisan test --filter="$FILTER" 2>&1
} | tee "$OUT"

echo
echo "==> Restoring the rule"
git apply -R "$PATCH"

echo "==> Re-running ${FILTER} with the rule back (this SHOULD pass)"
php artisan test --filter="$FILTER"

echo
echo "Failing output saved to ${OUT} - paste it into the README."
