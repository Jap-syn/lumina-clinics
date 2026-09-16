#!/usr/bin/env bash
# One command from a clean checkout to a running, seeded system.
set -euo pipefail
cd "$(dirname "$0")/.."

command -v composer >/dev/null || { echo "composer is required"; exit 1; }
php -m | grep -q pdo_pgsql || { echo "the pdo_pgsql extension is required"; exit 1; }

[ -f .env ] || cp .env.example .env
composer install
php artisan key:generate

createdb lumina      2>/dev/null || true
createdb lumina_test 2>/dev/null || true

php artisan migrate --seed
echo
echo "Ready. Run:  php artisan serve"
echo "  client   http://localhost:8000"
echo "  diary    http://localhost:8000/diary   (token: lumina-reception)"
