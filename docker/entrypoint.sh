#!/bin/sh
set -e

cd /var/www/dataserver

# Install composer dependencies if needed
if [ ! -f vendor/autoload.php ]; then
    echo "Installing composer dependencies..."
    composer install --no-dev --no-interaction
fi

# Initialize git submodule if needed
if [ ! -f htdocs/zotero-schema/schema.json ]; then
    echo "Initializing zotero-schema submodule..."
    git submodule update --init htdocs/zotero-schema 2>/dev/null || \
        echo "WARNING: Could not init submodule. Clone it manually if needed."
fi

# Ensure tmp directory is writable
mkdir -p tmp
chmod 777 tmp

# Wait for MySQL to be ready before running schema update
echo "Waiting for MySQL..."
max_wait=30
waited=0
while ! php -r "new mysqli('mysql', 'zotero', 'zotero_app_pw', 'zotero_master');" 2>/dev/null; do
    sleep 1
    waited=$((waited + 1))
    if [ $waited -ge $max_wait ]; then
        echo "WARNING: MySQL not ready after ${max_wait}s, skipping schema update."
        break
    fi
done

# Run schema update if MySQL is ready
if [ $waited -lt $max_wait ]; then
    echo "Running schema update..."
    cd /var/www/dataserver/admin && php schema_update 2>&1 || echo "WARNING: schema_update failed (may already be up to date)."
    cd /var/www/dataserver
fi

exec php-fpm
