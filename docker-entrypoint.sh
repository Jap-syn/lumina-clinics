#!/usr/bin/env sh
set -e

# The consent records are encrypted with APP_KEY, so a key that changes on every
# deploy makes yesterday's ID numbers unreadable. Set it in Railway.
#
# If it is missing we generate one in memory with --show. NOT `key:generate`:
# that writes to .env, which is gitignored and therefore absent from the image,
# so it dies with "file_get_contents(/app/.env): No such file or directory" and
# takes the container with it. A missing key should be a loud warning, not a
# crash loop.
if [ -z "$APP_KEY" ]; then
  echo "WARNING: APP_KEY is not set. Generating an ephemeral one."
  echo "WARNING: Encrypted consent records will NOT survive the next deploy."
  echo "WARNING: Set APP_KEY in the Railway variables to fix this."
  APP_KEY="$(php artisan key:generate --show)"
  export APP_KEY
fi

php /app/scripts/wait-for-db.php

# Creates btree_gist and the three exclusion constraints. This is the rule that
# stops two clients being sold the same 3pm laser slot, so a failure here must
# fail the deploy rather than be swallowed.
php artisan migrate --force

# Seed only the first time, so a redeploy does not duplicate the branches.
php artisan db:seed --force --class="Database\\Seeders\\DatabaseSeeder" || \
  echo "Seed skipped (data already present)."

# --no-reload matters twice over. PHP_CLI_SERVER_WORKERS is ignored without it
# ("Unable to respect the PHP_CLI_SERVER_WORKERS environment variable without
# the --no-reload flag"), so the race script would be measuring a single-threaded
# server; and the file watcher it disables has no purpose in an immutable image.
LISTEN_PORT="${PORT:-8080}"
echo "Listening on 0.0.0.0:${LISTEN_PORT} (Railway's target port must match this)."

exec php artisan serve --host=0.0.0.0 --port="${LISTEN_PORT}" --no-reload
