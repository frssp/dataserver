#!/bin/sh
set -e

cd /var/www/dataserver

# Install composer dependencies if needed
if [ ! -f vendor/autoload.php ]; then
    echo "Installing composer dependencies..."
    composer install --no-dev --no-interaction
fi

# Generate config files if missing (both are .gitignored)
if [ ! -f include/config/config.inc.php ]; then
    echo "Creating include/config/config.inc.php from sample..."
    cp include/config/config.inc.php-sample include/config/config.inc.php
fi

if [ ! -f include/config/dbconnect.inc.php ]; then
    echo "Creating include/config/dbconnect.inc.php for docker..."
    cat > include/config/dbconnect.inc.php <<'EOCFG'
<?
function Zotero_DBConnectAuth($db) {
	$charset = '';

	if ($db == 'master') {
		$host = 'mysql';
		$port = 3306;
		$replicas = [];
		$db = 'zotero_master';
		$user = 'zotero';
		$pass = 'zotero_app_pw';
		$state = 'up';
	}
	else if ($db == 'shard') {
		$host = false;
		$port = false;
		$db = false;
		$user = 'zotero';
		$pass = 'zotero_app_pw';
	}
	else if ($db == 'id1' || $db == 'id2') {
		$host = 'mysql';
		$port = 3306;
		$db = 'zotero_ids';
		$user = 'zotero';
		$pass = 'zotero_app_pw';
	}
	else if ($db == 'www1' || $db == 'www2') {
		$host = 'mysql';
		$port = 3306;
		$db = 'zotero_www_dev';
		$user = 'zotero';
		$pass = 'zotero_app_pw';
	}
	else {
		throw new Exception("Invalid db '$db'");
	}
	return [
		'host' => $host,
		'replicas' => !empty($replicas) ? $replicas : [],
		'port' => $port,
		'db' => $db,
		'user' => $user,
		'pass' => $pass,
		'charset' => $charset,
		'state' => !empty($state) ? $state : 'up'
	];
}
?>
EOCFG
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
