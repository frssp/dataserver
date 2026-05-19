#!/bin/sh
set -e

cd /var/www/dataserver

# Composer (and the submodule update below) shells out to git, which
# refuses to operate on a working tree owned by a different uid than
# the process — common with bind mounts from a host user. Whitelist
# this path so those commands don't abort with "dubious ownership".
git config --global --add safe.directory /var/www/dataserver \
    || echo "WARNING: could not configure git safe.directory."

# Install composer dependencies if vendor is missing or the autoloader is broken.
# A stale bind mount can leave vendor/autoload.php referencing a
# ComposerAutoloaderInit<hash> class that no longer exists in
# vendor/composer/autoload_real.php, producing a fatal "Class not found"
# error. Detect this by trying to actually load the autoloader.
needs_composer_install=0
if [ ! -f vendor/autoload.php ] || [ ! -f vendor/composer/autoload_real.php ]; then
    needs_composer_install=1
elif ! php -d display_errors=0 -r "require 'vendor/autoload.php';" >/dev/null 2>&1; then
    echo "vendor/autoload.php failed to load (likely a hash mismatch); regenerating..."
    needs_composer_install=1
fi

if [ "$needs_composer_install" = "1" ]; then
    echo "Installing composer dependencies..."
    rm -rf vendor/composer vendor/autoload.php
    # Don't let a composer failure kill php-fpm — surface the error and
    # keep the container alive so it can be debugged from outside.
    if ! composer install --no-dev --no-interaction; then
        echo "ERROR: composer install failed. php-fpm will start anyway,"
        echo "       but PHP requests that need the autoloader will 500."
        echo "       Likely causes: composer.lock out of sync with composer.json"
        echo "       (run 'composer update'), or a network/proxy issue."
    fi
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
