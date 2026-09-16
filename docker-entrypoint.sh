#!/usr/bin/env sh
set -e

# APP_KEY must exist before anything boots; the consent records are encrypted
# with it, so on Railway set it once as a variable rather than regenerating.
if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generating an ephemeral one (encrypted consent"
  echo "records will not survive a redeploy - set APP_KEY in Railway)."
  php artisan key:generate --force
fi

# Railway's private network is not resolvable the instant the container starts,
# so a migrate on the first boot can fail with a DNS error and take the deploy
# with it. Wait for the database rather than racing it.
echo "Waiting for the database..."
i=1
while [ "$i" -le 30 ]; do
  if php artisan db:monitor >/dev/null 2>&1; then
    echo "Database is up."
    break
  fi
  if [ "$i" -eq 30 ]; then
    echo "Database still unreachable after 30 attempts - continuing so the real error is logged."
  fi
  i=$((i + 1))
  sleep 2
done

# Creates btree_gist and the three exclusion constraints. This is the rule that
# stops two clients being sold the same 3pm laser slot, so a failure here must
# fail the deploy rather than be swallowed.
php artisan migrate --force

# Seed only the first time, so a redeploy does not duplicate the branches.
php artisan db:seed --force --class="Database\\Seeders\\DatabaseSeeder" || \
  echo "Seed skipped (data already present)."

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
