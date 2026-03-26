#!/bin/bash
# Zotero DataServer — Native (non-Docker) installation script
# Tested on: Ubuntu 20.04 / Debian 10+ / RHEL 8+ (with adjustments)
# Requires: root or sudo
set -euo pipefail

DATASERVER_DIR="$(cd "$(dirname "$0")/.." && pwd)"
MYSQL_ROOT_PW="${MYSQL_ROOT_PW:-zotero_root_pw}"
ZOTERO_DB_USER="${ZOTERO_DB_USER:-zotero}"
ZOTERO_DB_PASS="${ZOTERO_DB_PASS:-zotero_app_pw}"
AUTH_SALT="${AUTH_SALT:-zotero_self_hosted_salt}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin_secret_change_me}"
SERVER_PORT="${SERVER_PORT:-8080}"

has_systemd() {
    command -v systemctl &>/dev/null && systemctl is-system-running &>/dev/null 2>&1
}

echo "=== Zotero DataServer Native Install ==="
echo "Project dir : $DATASERVER_DIR"
echo "MySQL root pw: $MYSQL_ROOT_PW"
echo "DB user/pass : $ZOTERO_DB_USER / $ZOTERO_DB_PASS"
echo "Server port  : $SERVER_PORT"
echo ""

# ── 1. Detect package manager ──
if command -v apt-get &>/dev/null; then
    PKG=apt
elif command -v yum &>/dev/null; then
    PKG=yum
else
    echo "ERROR: Unsupported package manager. Install dependencies manually."
    exit 1
fi

# ── 2. Install system packages ──
echo ">>> Installing system packages..."
if [ "$PKG" = "apt" ]; then
    apt-get update
    # Add PHP 7.4 PPA if not available (Ubuntu 22.04+)
    if ! apt-cache show php7.4-fpm &>/dev/null 2>&1; then
        apt-get install -y software-properties-common
        add-apt-repository -y ppa:ondrej/php
        apt-get update
    fi
    apt-get install -y \
        mariadb-server \
        memcached \
        redis-server \
        nginx \
        php7.4-fpm \
        php7.4-mysql \
        php7.4-memcached \
        php7.4-redis \
        php7.4-mbstring \
        php7.4-xml \
        php7.4-curl \
        php7.4-intl \
        php7.4-igbinary \
        php7.4-ds \
        composer \
        git \
        unzip
elif [ "$PKG" = "yum" ]; then
    # RHEL/CentOS — use remi repo for PHP 7.4
    yum install -y epel-release
    yum install -y https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %rhel).rpm || true
    yum module reset php -y 2>/dev/null || true
    yum module enable php:remi-7.4 -y 2>/dev/null || true
    yum install -y \
        mysql-server \
        memcached \
        redis \
        nginx \
        php-fpm \
        php-mysqlnd \
        php-pecl-memcached \
        php-pecl-redis5 \
        php-mbstring \
        php-xml \
        php-pecl-igbinary \
        php-intl \
        composer \
        git \
        unzip
fi

# ── 3. Configure PHP ──
echo ">>> Configuring PHP..."

ZOTERO_INI="short_open_tag = On
memory_limit = 256M
max_execution_time = 120
post_max_size = 50M
upload_max_filesize = 50M"

# Apply to both FPM and CLI
for INI_DIR in /etc/php/7.4/fpm/conf.d /etc/php/7.4/cli/conf.d /etc/php.d; do
    if [ -d "$INI_DIR" ]; then
        echo "$ZOTERO_INI" > "$INI_DIR/99-zotero.ini"
        echo "  Created $INI_DIR/99-zotero.ini"
    fi
done

# Fix PHP-FPM pool to listen on socket or TCP
PHP_FPM_POOL=""
if [ -f /etc/php/7.4/fpm/pool.d/www.conf ]; then
    PHP_FPM_POOL=/etc/php/7.4/fpm/pool.d/www.conf
elif [ -f /etc/php-fpm.d/www.conf ]; then
    PHP_FPM_POOL=/etc/php-fpm.d/www.conf
fi

# ── 4. Start services ──
echo ">>> Starting services..."
if has_systemd; then
    # systemd environment (bare-metal / VM)
    systemctl enable --now mariadb 2>/dev/null || systemctl enable --now mysql 2>/dev/null || systemctl enable --now mysqld 2>/dev/null || true
    systemctl enable --now memcached
    systemctl enable --now redis-server 2>/dev/null || systemctl enable --now redis 2>/dev/null || true
    systemctl enable --now php7.4-fpm 2>/dev/null || systemctl enable --now php-fpm 2>/dev/null || true
else
    # No systemd (K8s pod, Docker container, etc.) — start processes directly
    echo "  (no systemd detected — starting services directly)"
    # MariaDB / MySQL
    if [ -x /usr/bin/mysqld_safe ]; then
        mysqld_safe --skip-syslog &
    elif [ -x /usr/sbin/mariadbd ]; then
        mariadbd --user=mysql &
    elif [ -x /usr/sbin/mysqld ]; then
        mysqld --user=mysql &
    fi
    # Wait for MySQL to be ready
    for i in $(seq 1 30); do
        mysqladmin ping &>/dev/null && break
        sleep 1
    done
    # Memcached
    memcached -d -u memcache -m 64 -p 11211 2>/dev/null || memcached -d -u nobody -m 64 -p 11211 2>/dev/null || true
    # Redis
    redis-server --daemonize yes --bind 127.0.0.1 2>/dev/null || true
    # PHP-FPM — ensure socket directory exists
    mkdir -p /run/php
    php-fpm7.4 -D 2>/dev/null || php-fpm -D 2>/dev/null || true
fi

# ── 5. Initialize MySQL databases ──
# Check if databases already exist
TABLE_EXISTS=$(mysql -u root -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='zotero_master' AND TABLE_NAME='libraries';" 2>/dev/null || echo "0")

if [ "$TABLE_EXISTS" -gt 0 ]; then
    echo ">>> Databases already initialized, skipping schema setup."
else
    echo ">>> Setting up MySQL databases..."
    mysql -u root <<EOSQL
CREATE DATABASE IF NOT EXISTS zotero_master CHARACTER SET utf8mb4;
CREATE DATABASE IF NOT EXISTS zotero_shard1 CHARACTER SET utf8mb4;
CREATE DATABASE IF NOT EXISTS zotero_ids CHARACTER SET utf8mb4;
CREATE DATABASE IF NOT EXISTS zotero_www_dev CHARACTER SET utf8mb4;

CREATE USER IF NOT EXISTS '${ZOTERO_DB_USER}'@'localhost' IDENTIFIED BY '${ZOTERO_DB_PASS}';
GRANT ALL PRIVILEGES ON zotero_master.* TO '${ZOTERO_DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON zotero_shard1.* TO '${ZOTERO_DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON zotero_ids.* TO '${ZOTERO_DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON zotero_www_dev.* TO '${ZOTERO_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOSQL

    # Load schemas
    echo ">>> Loading database schemas..."
    SCHEMA_DIR="$DATASERVER_DIR/docker/init-db"
    mysql -u root zotero_master < "$SCHEMA_DIR/master.schema"
    mysql -u root zotero_master < "$SCHEMA_DIR/coredata.schema"
    mysql -u root zotero_master -e "INSERT IGNORE INTO shardHosts VALUES (1, 'localhost', 3306, 'up');"
    mysql -u root zotero_master -e "INSERT IGNORE INTO shards VALUES (1, 1, 'zotero_shard1', 'up', 0);"

    mysql -u root zotero_shard1 < "$SCHEMA_DIR/shard.schema"
    mysql -u root zotero_shard1 < "$SCHEMA_DIR/triggers.schema"

    mysql -u root zotero_ids < "$SCHEMA_DIR/ids.schema"

    mysql -u root zotero_www_dev < "$SCHEMA_DIR/04-www-schema.sql"

    echo ">>> Seeding initial user (testuser/test123)..."
    mysql -u root < "$SCHEMA_DIR/05-seed-data.sql"
fi

# ── 6. PHP dependencies ──
# vendor/ is committed to the repo — no composer needed at deploy time.
# If vendor/ is missing, try composer as fallback.
if [ ! -f "$DATASERVER_DIR/vendor/autoload.php" ]; then
    echo ">>> vendor/ not found, running composer install..."
    cd "$DATASERVER_DIR"
    composer install --no-dev --no-interaction
else
    echo ">>> vendor/ already present, skipping composer."
fi

# ── 7. Verify zotero-schema ──
# zotero-schema is committed directly (not as submodule) for air-gapped environments.
if [ ! -f "$DATASERVER_DIR/htdocs/zotero-schema/schema.json" ]; then
    echo "ERROR: htdocs/zotero-schema/schema.json not found. This should be in the repo."
    exit 1
fi

# ── 8. Create tmp directory ──
mkdir -p "$DATASERVER_DIR/tmp"
chmod 777 "$DATASERVER_DIR/tmp"

# ── 9. Generate config files ──
echo ">>> Generating config files..."
CONFIG_DIR="$DATASERVER_DIR/include/config"

# config.inc.php — preserve existing config (admin may have changed passwords)
if [ -f "$CONFIG_DIR/config.inc.php" ]; then
    echo "  config.inc.php already exists, skipping (delete it manually to regenerate)."
else
cat > "$CONFIG_DIR/config.inc.php" <<EOCFG
<?
class Z_CONFIG {
	public static \$API_ENABLED = true;
	public static \$READ_ONLY = false;
	public static \$MAINTENANCE_MESSAGE = 'Server updates in progress. Please try again in a few minutes.';
	public static \$BACKOFF = 0;

	public static \$TESTING_SITE = true;
	public static \$DEV_SITE = false;

	public static \$DEBUG_LOG = false;

	public static \$BASE_URI = 'http://localhost:${SERVER_PORT}/';
	public static \$API_BASE_URI = 'http://localhost:${SERVER_PORT}/';
	public static \$WWW_BASE_URI = 'http://localhost:${SERVER_PORT}/';

	public static \$AUTH_SALT = '${AUTH_SALT}';
	public static \$API_SUPER_USERNAME = '${ADMIN_USER}';
	public static \$API_SUPER_PASSWORD = '${ADMIN_PASS}';

	public static \$AWS_REGION = 'us-east-1';
	public static \$AWS_ACCESS_KEY = 'dummy';
	public static \$AWS_SECRET_KEY = 'dummy';
	public static \$S3_BUCKET = '';
	public static \$S3_BUCKET_CACHE = '';
	public static \$S3_BUCKET_FULLTEXT = '';
	public static \$S3_BUCKET_ERRORS = '';
	public static \$SNS_ALERT_TOPIC = '';

	public static \$REDIS_HOSTS = [
		'default' => ['host' => '127.0.0.1:6379'],
		'request-limiter' => ['host' => '127.0.0.1:6379'],
		'notifications' => ['host' => '127.0.0.1:6379'],
		'fulltext-migration' => ['host' => '127.0.0.1:6379', 'cluster' => false]
	];
	public static \$REDIS_PREFIX = '';

	public static \$MEMCACHED_ENABLED = true;
	public static \$MEMCACHED_SERVERS = ['127.0.0.1:11211:1'];

	public static \$TRANSLATION_SERVERS = [];
	public static \$CITATION_SERVERS = [];
	public static \$SEARCH_HOSTS = [''];

	public static \$GLOBAL_ITEMS_URL = '';
	public static \$ATTACHMENT_PROXY_URL = '';
	public static \$ATTACHMENT_PROXY_SECRET = '';

	public static \$STATSD_ENABLED = false;
	public static \$STATSD_PREFIX = '';
	public static \$STATSD_HOST = '';
	public static \$STATSD_PORT = 8125;

	public static \$LOG_TO_SCRIBE = false;
	public static \$LOG_ADDRESS = '';
	public static \$LOG_PORT = 1463;
	public static \$LOG_TIMEZONE = 'UTC';
	public static \$LOG_TARGET_DEFAULT = 'errors';

	public static \$HTMLCLEAN_SERVER_URL = '';
	public static \$CLI_PHP_PATH = '/usr/bin/php';

	public static \$CACHE_VERSION_ATOM_ENTRY = 1;
	public static \$CACHE_VERSION_BIB = 1;
	public static \$CACHE_VERSION_RESPONSE_JSON_COLLECTION = 1;
	public static \$CACHE_VERSION_RESPONSE_JSON_ITEM = 1;
	public static \$CACHE_ENABLED_ITEM_RESPONSE_JSON = true;
}
?>
EOCFG
fi

# dbconnect.inc.php — preserve existing
if [ -f "$CONFIG_DIR/dbconnect.inc.php" ]; then
    echo "  dbconnect.inc.php already exists, skipping."
else
cat > "$CONFIG_DIR/dbconnect.inc.php" <<EOCFG
<?
function Zotero_dbConnectAuth(\$db) {
	\$charset = '';

	if (\$db == 'master') {
		\$host = '127.0.0.1';
		\$port = 3306;
		\$replicas = [];
		\$db = 'zotero_master';
		\$user = '${ZOTERO_DB_USER}';
		\$pass = '${ZOTERO_DB_PASS}';
		\$state = 'up';
	}
	else if (\$db == 'shard') {
		\$host = false;
		\$port = false;
		\$db = false;
		\$user = '${ZOTERO_DB_USER}';
		\$pass = '${ZOTERO_DB_PASS}';
	}
	else if (\$db == 'id1' || \$db == 'id2') {
		\$host = '127.0.0.1';
		\$port = 3306;
		\$db = 'zotero_ids';
		\$user = '${ZOTERO_DB_USER}';
		\$pass = '${ZOTERO_DB_PASS}';
	}
	else if (\$db == 'www1' || \$db == 'www2') {
		\$host = '127.0.0.1';
		\$port = 3306;
		\$db = 'zotero_www_dev';
		\$user = '${ZOTERO_DB_USER}';
		\$pass = '${ZOTERO_DB_PASS}';
	}
	else {
		throw new Exception("Invalid db '\$db'");
	}
	return [
		'host' => \$host,
		'replicas' => !empty(\$replicas) ? \$replicas : [],
		'port' => \$port,
		'db' => \$db,
		'user' => \$user,
		'pass' => \$pass,
		'charset' => \$charset,
		'state' => !empty(\$state) ? \$state : 'up'
	];
}
?>
EOCFG
fi

# Make config readable by PHP-FPM (www-data)
chmod 644 "$CONFIG_DIR/config.inc.php" "$CONFIG_DIR/dbconnect.inc.php"

# ── 10. Nginx config ──
echo ">>> Configuring Nginx..."

# Determine PHP-FPM socket path
PHP_FPM_SOCK="/run/php/php7.4-fpm.sock"
if [ ! -S "$PHP_FPM_SOCK" ] && [ -S /run/php-fpm/www.sock ]; then
    PHP_FPM_SOCK="/run/php-fpm/www.sock"
fi

cat > /etc/nginx/sites-available/zotero <<EONGINX
server {
    listen ${SERVER_PORT};
    server_name _;
    root ${DATASERVER_DIR}/htdocs;
    index index.php;

    location = / {
        try_files /home.html =404;
    }

    location = /library {
        return 301 /library/;
    }

    location /library/ {
        try_files \$uri /library/index.html =404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
    }
}
EONGINX

# Enable site
if [ -d /etc/nginx/sites-enabled ]; then
    ln -sf /etc/nginx/sites-available/zotero /etc/nginx/sites-enabled/zotero
    rm -f /etc/nginx/sites-enabled/default
elif [ -d /etc/nginx/conf.d ]; then
    cp /etc/nginx/sites-available/zotero /etc/nginx/conf.d/zotero.conf
fi

nginx -t
if has_systemd; then
    systemctl restart nginx
else
    # Kill existing nginx and start fresh
    nginx -s stop 2>/dev/null || true
    nginx
fi

# ── 11. Run schema update ──
echo ">>> Running schema update..."
cd "$DATASERVER_DIR/admin" && php -d short_open_tag=On schema_update 2>&1 || echo "WARNING: schema_update failed (may already be up to date)."

echo ""
echo "=== Installation complete ==="
echo ""
echo "Services running:"
echo "  - MySQL     : localhost:3306"
echo "  - Memcached : localhost:11211"
echo "  - Redis     : localhost:6379"
echo "  - PHP-FPM   : $PHP_FPM_SOCK"
echo "  - Nginx     : localhost:${SERVER_PORT}"
echo ""
echo "Test user:"
echo "  Username : testuser"
echo "  Password : test123"
echo "  API Key  : GmYMvkzxnJFeCKfDhBBD4ONv"
echo ""
echo "Verify: curl http://localhost:${SERVER_PORT}/keys/current -H 'Zotero-API-Key: GmYMvkzxnJFeCKfDhBBD4ONv'"
echo ""
