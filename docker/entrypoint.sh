#!/bin/sh
set -e

DB_HOST="${DB_HOST:-database}"
DB_USER="${POSTGRES_USER:-app}"
DB_NAME="${POSTGRES_DB:-app}"

until pg_isready -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" >/dev/null 2>&1; do
  echo "entrypoint: waiting for postgres at $DB_HOST ..."
  sleep 1
done

# only one container may migrate, or concurrent starts race each other
# creating doctrine_migration_versions. compose sets this to 0 on the worker.
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

exec "$@"
