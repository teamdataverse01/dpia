#!/bin/sh
# Container start: prepare the database, then serve. Safe to run on every deploy.
set -e

# wait for the database (Railway may start the app first)
i=0
until php -r 'require "vendor/autoload.php"; App\Infrastructure\PdoConnection::fromConfig(App\Support\Config::load(".env"));' 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 30 ]; then echo "database not reachable"; exit 1; fi
  echo "waiting for database..."; sleep 2
done

if [ "${RESET_DEMO_DATA:-0}" = "1" ]; then
  echo "RESET_DEMO_DATA=1: wiping and reloading demo data (remove the variable afterwards)"
  php bin/console demo:reset
fi
php bin/console migrate
if [ "${SEED_DEMO:-0}" = "1" ]; then
  php bin/console seed --demo     # skipped automatically when data already exists
else
  php bin/console seed
fi
php bin/console jobs:daily || true  # monitoring transitions + today's dashboard snapshot

exec php -S "0.0.0.0:${PORT:-8080}" -t public public/index.php
