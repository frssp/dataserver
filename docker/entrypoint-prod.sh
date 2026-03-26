#!/bin/sh
set -e

cd /var/www/dataserver

# Ensure tmp directory exists
mkdir -p tmp
chown www-data:www-data tmp

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
max_wait=30
waited=0
while ! php -r "new mysqli(getenv('DB_HOST') ?: 'mysql', 'zotero', 'zotero_app_pw', 'zotero_master');" 2>/dev/null; do
    sleep 1
    waited=$((waited + 1))
    if [ $waited -ge $max_wait ]; then
        echo "WARNING: MySQL not ready after ${max_wait}s, starting anyway."
        break
    fi
done

# Run schema update if MySQL is ready
if [ $waited -lt $max_wait ]; then
    echo "Running schema update..."
    cd /var/www/dataserver/admin && php schema_update 2>&1 || echo "WARNING: schema_update failed (may already be up to date)."
    cd /var/www/dataserver
fi

exec "$@"
