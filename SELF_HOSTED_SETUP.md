# Self-Hosted Zotero Server — Setup Guide

## Quick Start

### 1. Create Docker Infrastructure

```yaml
# docker/docker-compose.yml
version: '3.8'

services:
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: zotero_root_pw
    ports:
      - "3306:3306"
    volumes:
      - mysql_data:/var/lib/mysql
      - ./init-db:/docker-entrypoint-initdb.d
    command: >
      --default-authentication-plugin=mysql_native_password
      --character-set-server=utf8mb4
      --collation-server=utf8mb4_unicode_ci
      --default-time-zone='+00:00'
      --sql-mode=STRICT_ALL_TABLES
      --event-scheduler=ON

  memcached:
    image: memcached:1.6-alpine
    ports:
      - "11211:11211"

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  php-fpm:
    build:
      context: ..
      dockerfile: docker/Dockerfile.php
    volumes:
      - ..:/var/www/dataserver
    depends_on:
      - mysql
      - memcached
      - redis

  nginx:
    image: nginx:alpine
    ports:
      - "8080:80"
    volumes:
      - ./nginx.conf:/etc/nginx/conf.d/default.conf
      - ..:/var/www/dataserver
    depends_on:
      - php-fpm

volumes:
  mysql_data:
```

### 2. PHP Dockerfile

```dockerfile
# docker/Dockerfile.php
FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    libmemcached-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    zlib1g-dev \
    unzip \
    git \
    && pecl install memcached redis \
    && docker-php-ext-enable memcached redis \
    && docker-php-ext-install mysqli mbstring xml curl \
    && apt-get clean

# Short open tags required by Zotero codebase
RUN echo "short_open_tag = On" > /usr/local/etc/php/conf.d/zotero.ini

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/dataserver
RUN composer install --no-dev --no-interaction 2>/dev/null || true
```

### 3. Nginx Config

```nginx
# docker/nginx.conf
server {
    listen 80;
    server_name _;
    root /var/www/dataserver/htdocs;
    index index.php;

    # API versioning header
    add_header Zotero-API-Version 3 always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass php-fpm:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }
}
```

### 4. Database Initialization

```sql
-- docker/init-db/01-create-databases.sql

-- Master DB
CREATE DATABASE IF NOT EXISTS zotero_master CHARACTER SET utf8mb4;

-- Shard DB
CREATE DATABASE IF NOT EXISTS zotero_shard1 CHARACTER SET utf8mb4;

-- ID Server DB
CREATE DATABASE IF NOT EXISTS zotero_ids CHARACTER SET utf8mb4;

-- WWW DB (authentication)
CREATE DATABASE IF NOT EXISTS zotero_www CHARACTER SET utf8mb4;

-- Create application user
CREATE USER IF NOT EXISTS 'zotero'@'%' IDENTIFIED BY 'zotero_app_pw';
GRANT ALL PRIVILEGES ON zotero_master.* TO 'zotero'@'%';
GRANT ALL PRIVILEGES ON zotero_shard1.* TO 'zotero'@'%';
GRANT ALL PRIVILEGES ON zotero_ids.* TO 'zotero'@'%';
GRANT ALL PRIVILEGES ON zotero_www.* TO 'zotero'@'%';
FLUSH PRIVILEGES;
```

```sql
-- docker/init-db/02-master-schema.sql
USE zotero_master;
SOURCE /docker-entrypoint-initdb.d/master.sql;
SOURCE /docker-entrypoint-initdb.d/coredata.sql;

-- Shard infrastructure: single host, single shard
INSERT INTO shardHosts VALUES (1, '127.0.0.1', 3306, 'up');
INSERT INTO shards VALUES (1, 1, 'zotero_shard1', 'up', 0);
```

```sql
-- docker/init-db/03-shard-schema.sql
USE zotero_shard1;
SOURCE /docker-entrypoint-initdb.d/shard.sql;
SOURCE /docker-entrypoint-initdb.d/triggers.sql;
```

```sql
-- docker/init-db/04-ids-schema.sql
USE zotero_ids;
SOURCE /docker-entrypoint-initdb.d/ids.sql;
```

```sql
-- docker/init-db/05-www-schema.sql
USE zotero_www;

-- Minimal users table for authentication
-- Schema reverse-engineered from test_reset and Password.inc.php
CREATE TABLE IF NOT EXISTS users (
  userID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(255) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,  -- bcrypt hash
  email VARCHAR(255),
  secondary_email VARCHAR(255),
  role ENUM('member','admin','deleted') NOT NULL DEFAULT 'member',
  preferences TEXT,
  permissions TEXT,
  attributes TEXT,
  active TINYINT(1) NOT NULL DEFAULT 1,
  dateInserted TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dateUpdated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  countVisits INT NOT NULL DEFAULT 0,
  slug VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users_email (
  userID INT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  PRIMARY KEY (userID, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users_meta (
  userID INT UNSIGNED NOT NULL,
  metaKey VARCHAR(255) NOT NULL,
  metaValue TEXT,
  PRIMARY KEY (userID, metaKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(255) NOT NULL PRIMARY KEY,
  userID INT UNSIGNED NOT NULL,
  modified INT UNSIGNED NOT NULL,
  lifetime INT UNSIGNED NOT NULL DEFAULT 86400,
  KEY (userID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vanilla Forums compatibility (banned user check)
CREATE TABLE IF NOT EXISTS GDN_User (
  UserID INT UNSIGNED NOT NULL PRIMARY KEY,
  Banned TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5. Server Config Files

```php
<?
// include/config/config.inc.php
class Z_CONFIG {
    public static $API_ENABLED = true;
    public static $READ_ONLY = false;
    public static $MAINTENANCE_MESSAGE = '';
    public static $BACKOFF = 0;

    public static $TESTING_SITE = true;  // enables dev features
    public static $DEV_SITE = true;

    public static $DEBUG_LOG = true;

    public static $BASE_URI = '';
    public static $API_BASE_URI = 'http://localhost:8080/';
    public static $WWW_BASE_URI = 'http://localhost:8080/';

    // Super-user for admin operations
    public static $AUTH_SALT = 'your_random_salt_here';
    public static $API_SUPER_USERNAME = 'admin';
    public static $API_SUPER_PASSWORD = 'admin_secret_change_me';

    // AWS — dummy values, S3 not used
    public static $AWS_REGION = 'us-east-1';
    public static $AWS_ACCESS_KEY = 'dummy';
    public static $AWS_SECRET_KEY = 'dummy';
    public static $S3_BUCKET = '';
    public static $S3_BUCKET_CACHE = '';
    public static $S3_BUCKET_FULLTEXT = '';
    public static $S3_BUCKET_ERRORS = '';
    public static $SNS_ALERT_TOPIC = '';

    // Redis
    public static $REDIS_HOSTS = [
        'default' => ['host' => 'redis:6379'],
        'request-limiter' => ['host' => 'redis:6379'],
        'notifications' => ['host' => 'redis:6379'],
    ];
    public static $REDIS_PREFIX = 'zotero_';

    // Memcached
    public static $MEMCACHED_ENABLED = true;
    public static $MEMCACHED_SERVERS = ['memcached:11211:1'];

    // Not needed — disable
    public static $TRANSLATION_SERVERS = [];
    public static $CITATION_SERVERS = [];
    public static $SEARCH_HOSTS = [''];

    public static $GLOBAL_ITEMS_URL = '';
    public static $ATTACHMENT_PROXY_URL = '';
    public static $ATTACHMENT_PROXY_SECRET = '';

    // Disable TTS
    public static $TTS_TABLE = 'TTS';
    public static $S3_BUCKET_TTS = '';
    public static $TTS_AUDIO_DOMAIN = '';
    public static $TTS_CREDIT_LIMITS = [
        'standard' => ['free' => 0, 'personal' => 0, 'institutional' => 0],
        'premium' => ['free' => 0, 'personal' => 0, 'institutional' => 0],
    ];
    public static $TTS_DAILY_LIMIT_MINUTES = 0;

    // Monitoring — disable
    public static $STATSD_ENABLED = false;
    public static $STATSD_PREFIX = '';
    public static $STATSD_HOST = 'localhost';
    public static $STATSD_PORT = 8125;

    public static $LOG_TO_SCRIBE = false;
    public static $LOG_ADDRESS = '';
    public static $LOG_PORT = 1463;
    public static $LOG_TIMEZONE = 'UTC';
    public static $LOG_TARGET_DEFAULT = 'errors';

    public static $HTMLCLEAN_SERVER_URL = '';
    public static $CLI_PHP_PATH = '/usr/local/bin/php';

    // Use local error logging instead of S3
    public static $ERROR_PATH = '/var/www/dataserver/tmp/errors/';

    public static $CACHE_VERSION_ATOM_ENTRY = 1;
    public static $CACHE_VERSION_BIB = 1;
    public static $CACHE_VERSION_RESPONSE_JSON_COLLECTION = 1;
    public static $CACHE_VERSION_RESPONSE_JSON_ITEM = 1;
    public static $CACHE_ENABLED_ITEM_RESPONSE_JSON = true;
}
?>
```

```php
<?
// include/config/dbconnect.inc.php
function Zotero_dbConnectAuth($db) {
    $charset = 'utf8mb4';
    $host = 'mysql';  // docker service name
    $port = 3306;
    $user = 'zotero';
    $pass = 'zotero_app_pw';

    if ($db == 'master') {
        return [
            'host' => $host, 'replicas' => [], 'port' => $port,
            'db' => 'zotero_master', 'user' => $user, 'pass' => $pass,
            'charset' => $charset, 'state' => 'up'
        ];
    }
    else if ($db == 'shard') {
        // Shard connections are resolved dynamically via shardHosts table,
        // but credentials are provided here
        return [
            'host' => false, 'replicas' => [], 'port' => false,
            'db' => false, 'user' => $user, 'pass' => $pass,
            'charset' => $charset, 'state' => 'up'
        ];
    }
    else if ($db == 'id1' || $db == 'id2') {
        // Both ID servers point to same instance
        return [
            'host' => $host, 'replicas' => [], 'port' => $port,
            'db' => 'zotero_ids', 'user' => $user, 'pass' => $pass,
            'charset' => $charset, 'state' => 'up'
        ];
    }
    else if ($db == 'www1' || $db == 'www2') {
        // Both WWW connections point to same instance
        return [
            'host' => $host, 'replicas' => [], 'port' => $port,
            'db' => 'zotero_www', 'user' => $user, 'pass' => $pass,
            'charset' => $charset, 'state' => 'up'
        ];
    }
    else {
        throw new Exception("Invalid db '$db'");
    }
}
?>
```

### 6. Admin Script for User/Key Management

```php
#!/usr/bin/env php
<?
// admin/create_user.php
// Usage: php create_user.php <username> <password> [email]

set_include_path(dirname(__DIR__) . "/include");
require("header.inc.php");

if ($argc < 3) {
    die("Usage: php create_user.php <username> <password> [email]\n");
}

$username = $argv[1];
$password = $argv[2];
$email = $argv[3] ?? "$username@internal.corp";

// 1. Create user in www DB (for password auth)
$hash = password_hash($password, PASSWORD_BCRYPT);
$sql = "INSERT INTO zotero_www.users (username, password, email, role, active) VALUES (?, ?, ?, 'member', 1)";
Zotero_WWW_DB_1::query($sql, [$username, $hash, $email]);
$userID = Zotero_WWW_DB_1::valueQuery("SELECT LAST_INSERT_ID()");
Zotero_WWW_DB_1::close();

echo "Created www user: ID=$userID, username=$username\n";

// 2. Create user in master DB (creates library + shard assignment)
$libraryID = Zotero_Users::add($userID, $username);
echo "Created master user: libraryID=$libraryID\n";

// 3. Create API key with full access
Zotero_DB::beginTransaction();

$keyObj = new Zotero_Key;
$keyObj->userID = $userID;
$keyObj->name = "Auto-generated key for $username";

// Grant personal library access
$keyObj->setPermission($libraryID, 'library', true);
$keyObj->setPermission($libraryID, 'notes', true);
$keyObj->setPermission($libraryID, 'write', true);

// Grant access to all groups
$keyObj->setPermission(0, 'group', true);
$keyObj->setPermission(0, 'write', true);

$keyObj->save();

Zotero_DB::commit();

echo "Created API key: {$keyObj->key}\n";
echo "\nDone! User can now sync with:\n";
echo "  API URL: " . Z_CONFIG::$API_BASE_URI . "\n";
echo "  API Key: {$keyObj->key}\n";
?>
```

```php
#!/usr/bin/env php
<?
// admin/create_group.php
// Usage: php create_group.php <group_name> <owner_user_id>

set_include_path(dirname(__DIR__) . "/include");
require("header.inc.php");

if ($argc < 3) {
    die("Usage: php create_group.php <group_name> <owner_user_id>\n");
}

$groupName = $argv[1];
$ownerUserID = (int)$argv[2];

if (!Zotero_Users::exists($ownerUserID)) {
    die("Error: User $ownerUserID does not exist in master DB\n");
}

Zotero_DB::beginTransaction();

$shardID = Zotero_Shards::getNextShard();
$libraryID = Zotero_Libraries::add('group', $shardID);

$group = new Zotero_Group;
$group->libraryID = $libraryID;
$group->name = $groupName;
$group->slug = strtolower(str_replace(' ', '_', $groupName));
$group->type = 'Private';
$group->libraryEditing = 'members';
$group->libraryReading = 'members';
$group->fileEditing = 'none';  // No file sync
$group->description = '';
$group->url = '';
$group->save();

// Add owner
$group->addUser($ownerUserID, 'owner');

Zotero_DB::commit();

echo "Created group: ID={$group->id}, name=$groupName, libraryID=$libraryID\n";
echo "Owner: userID=$ownerUserID\n";
?>
```

## Code Patches Required

### Patch 1: header.inc.php — Conditional AWS/ES init

In `include/header.inc.php`, wrap AWS SDK init (lines ~221-253) and ES init (lines ~256-259):

```php
// AWS — only init if S3 bucket is configured
if (!empty(Z_CONFIG::$S3_BUCKET) || !empty(Z_CONFIG::$AWS_ACCESS_KEY)) {
    // ... existing AWS init code ...
    Z_Core::$AWS = new Aws\Sdk($awsConfig);
} else {
    Z_Core::$AWS = null;
}

// Elasticsearch — only init if search hosts configured
if (!empty(Z_CONFIG::$SEARCH_HOSTS[0])) {
    $esConfig = ['hosts' => Z_CONFIG::$SEARCH_HOSTS];
    Z_Core::$ES = \Elasticsearch\ClientBuilder::fromConfig($esConfig, true);
} else {
    Z_Core::$ES = null;
}
```

### Patch 2: Guard S3 calls in Storage model

In `model/Storage.inc.php`, add null checks for `Z_Core::$AWS` before any S3 operations.

### Patch 3: Guard GDN_User query

In `model/Users.inc.php:540`, wrap the banned-user query in try/catch or check if table exists:

```php
try {
    $invalidUserIDs = Zotero_WWW_DB_2::columnQuery($sql, $userIDs);
} catch (Exception $e) {
    // GDN_User table may not exist in self-hosted setup
    Z_Core::logError("WARNING: Banned user check failed: $e");
    $invalidUserIDs = [];
}
```

### Patch 4: Shard DB connection handling

In `include/Shards.inc.php`, the shard host `address` from the DB is used to connect.
The `shardHosts` INSERT must use the Docker MySQL hostname:
```sql
INSERT INTO shardHosts VALUES (1, 'mysql', 3306, 'up');
```

## Zotero Client Config

### Hidden Preferences (about:config style, no rebuild needed)
```
extensions.zotero.api.url = https://your-server:8080/
extensions.zotero.streaming.enabled = false
```

### For Custom Build (resource/config.mjs)
```javascript
API_URL: 'https://your-server.corp/',
STREAMING_URL: '',
```
