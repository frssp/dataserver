# Self-Hosted Zotero Sync Server — Setup Guide

A guide for deploying a private Zotero metadata sync server using Docker.
This setup supports items, collections, tags, saved searches, notes, and group libraries.
File/PDF sync is **not included** — this is metadata only.

---

## Prerequisites

- Docker Desktop (macOS/Windows) or Docker Engine (Linux)
- Docker Compose plugin
- Git (to clone the dataserver repo)

---

## 1. Clone the Repository

```bash
git clone --recurse-submodules https://github.com/zotero/dataserver.git
cd dataserver
```

If you already cloned without `--recurse-submodules`:
```bash
git submodule update --init
```

---

## 2. Create Configuration Files

### 2a. `include/config/config.inc.php`

```php
<?
class Z_CONFIG {
    public static $API_ENABLED = true;
    public static $READ_ONLY = false;
    public static $MAINTENANCE_MESSAGE = '';
    public static $BACKOFF = 0;

    public static $TESTING_SITE = true;
    public static $DEV_SITE = true;

    public static $DEBUG_LOG = true;

    public static $BASE_URI = '';
    public static $API_BASE_URI = 'http://localhost:8080/';
    public static $WWW_BASE_URI = 'http://localhost:8080/';

    public static $AUTH_SALT = 'CHANGE_ME_TO_A_RANDOM_STRING';
    public static $API_SUPER_USERNAME = 'admin';
    public static $API_SUPER_PASSWORD = 'CHANGE_ME_SUPER_PASSWORD';

    // AWS — dummy credentials (not used for metadata sync)
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

    // Not needed for self-hosted
    public static $TRANSLATION_SERVERS = [];
    public static $CITATION_SERVERS = [];
    public static $SEARCH_HOSTS = [''];

    public static $GLOBAL_ITEMS_URL = '';
    public static $ATTACHMENT_PROXY_URL = '';
    public static $ATTACHMENT_PROXY_SECRET = '';

    // TTS — disabled
    public static $TTS_TABLE = 'TTS';
    public static $S3_BUCKET_TTS = '';
    public static $TTS_AUDIO_DOMAIN = '';
    public static $TTS_CREDIT_LIMITS = [
        'standard' => ['free' => 0, 'personal' => 0, 'institutional' => 0],
        'premium' => ['free' => 0, 'personal' => 0, 'institutional' => 0],
    ];
    public static $TTS_DAILY_LIMIT_MINUTES = 0;

    // Monitoring — disabled
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

    public static $CACHE_VERSION_ATOM_ENTRY = 1;
    public static $CACHE_VERSION_BIB = 1;
    public static $CACHE_VERSION_RESPONSE_JSON_COLLECTION = 1;
    public static $CACHE_VERSION_RESPONSE_JSON_ITEM = 1;
    public static $CACHE_ENABLED_ITEM_RESPONSE_JSON = true;
}
?>
```

> **Important:** Change `AUTH_SALT` and `API_SUPER_PASSWORD` to your own secret values.
> The `AUTH_SALT` is used for password hashing — once set, do not change it or all passwords will break.

### 2b. `include/config/dbconnect.inc.php`

```php
<?
function Zotero_dbConnectAuth($db) {
    $charset = 'utf8mb4';
    $host = 'mysql';
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
        return [
            'host' => false, 'replicas' => [], 'port' => false,
            'db' => false, 'user' => $user, 'pass' => $pass,
            'charset' => $charset, 'state' => 'up'
        ];
    }
    else if ($db == 'id1' || $db == 'id2') {
        return [
            'host' => $host, 'replicas' => [], 'port' => $port,
            'db' => 'zotero_ids', 'user' => $user, 'pass' => $pass,
            'charset' => $charset, 'state' => 'up'
        ];
    }
    else if ($db == 'www1' || $db == 'www2') {
        return [
            'host' => $host, 'replicas' => [], 'port' => $port,
            'db' => 'zotero_www_dev', 'user' => $user, 'pass' => $pass,
            'charset' => $charset, 'state' => 'up'
        ];
    }
    else {
        throw new Exception("Invalid db '$db'");
    }
}
?>
```

---

## 3. Apply Code Patches (2 files)

Only two lines of source code need to change.

### 3a. `include/header.inc.php` — Wrap Elasticsearch init

Find the Elasticsearch section (around line 255) and wrap it in a conditional:

```php
// Before:
$esConfig = [
    'hosts' => Z_CONFIG::$SEARCH_HOSTS
];
Z_Core::$ES = \Elasticsearch\ClientBuilder::fromConfig($esConfig, true);

// After:
if (!empty(Z_CONFIG::$SEARCH_HOSTS[0])) {
    $esConfig = [
        'hosts' => Z_CONFIG::$SEARCH_HOSTS
    ];
    Z_Core::$ES = \Elasticsearch\ClientBuilder::fromConfig($esConfig, true);
}
```

### 3b. `model/Item.inc.php` — Add missing property

Add `private $dataSources = [];` to the class properties (around line 44):

```php
protected $_serverDateModified;

private $dataSources = [];    // <-- add this line
private $itemData = array();
```

---

## 4. Start the Server

```bash
cd docker/
docker compose up -d
```

First startup takes 1-2 minutes (builds PHP image, installs dependencies, initializes database).
Check progress:

```bash
docker compose logs php-fpm --tail 20
```

You should see:
```
DB schema is up to date (42 >= 42)
[...] NOTICE: fpm is running, pid 1
[...] NOTICE: ready to handle connections
```

### Verify it works

```bash
curl http://localhost:8080/
# Should return: "Nothing to see here."
```

---

## 5. Create Users

The seed data creates one default user. To add more users, use the admin scripts below.

### Default user (created automatically)

| Field    | Value                      |
|----------|----------------------------|
| Username | `testuser`                 |
| Password | `test123`                  |
| API Key  | `GmYMvkzxnJFeCKfDhBBD4ONv`|

### Add a new user

Run inside the PHP container:

```bash
docker compose exec php-fpm bash
```

Then execute this PHP script (change values as needed):

```php
php -r "
  \$user = 'newuser';
  \$pass = 'newpassword';
  \$email = 'user@example.com';
  \$salt = 'CHANGE_ME_TO_A_RANDOM_STRING';  // must match AUTH_SALT in config

  \$hash = sha1(\$salt . \$pass);

  \$m = new mysqli('mysql','zotero','zotero_app_pw','zotero_master');

  // Create library
  \$m->query(\"INSERT INTO libraries (libraryType, lastUpdated, version, shardID, hasData) VALUES ('user', NOW(), 0, 1, 0)\");
  \$libID = \$m->insert_id;

  // Create master user
  \$m->query(\"INSERT INTO users (libraryID, username) VALUES (\$libID, '\$user')\");
  \$userID = \$m->insert_id;

  // Create shard library
  \$s = new mysqli('mysql','zotero','zotero_app_pw','zotero_shard1');
  \$s->query(\"INSERT INTO shardLibraries (libraryID, libraryType, lastUpdated, version, storageUsage) VALUES (\$libID, 'user', NOW(), 1, 0)\");

  // Create www user
  \$w = new mysqli('mysql','zotero','zotero_app_pw','zotero_www_dev');
  \$w->query(\"INSERT INTO users (userID, username, password, email, active, dateAdded, dateModified, slug) VALUES (\$userID, '\$user', '\$hash', '\$email', 1, NOW(), NOW(), '\$user')\");

  // Create API key with full access
  \$key = bin2hex(random_bytes(12));
  \$m->query(\"INSERT INTO \\\`keys\\\` (\\\`key\\\`, userID, name, dateAdded, lastUsed) VALUES ('\$key', \$userID, 'Full Access Key', NOW(), NOW())\");
  \$keyID = \$m->insert_id;
  \$m->query(\"INSERT INTO keyPermissions VALUES (\$keyID, \$libID, 'library', 1)\");
  \$m->query(\"INSERT INTO keyPermissions VALUES (\$keyID, \$libID, 'notes', 1)\");
  \$m->query(\"INSERT INTO keyPermissions VALUES (\$keyID, \$libID, 'write', 1)\");
  \$m->query(\"INSERT INTO keyPermissions VALUES (\$keyID, 0, 'library', 1)\");
  \$m->query(\"INSERT INTO keyPermissions VALUES (\$keyID, 0, 'notes', 1)\");
  \$m->query(\"INSERT INTO keyPermissions VALUES (\$keyID, 0, 'write', 1)\");

  echo \"User created: \$user (ID: \$userID, Library: \$libID, API Key: \$key)\n\";
"
```

Save the printed API key — you'll need it for client setup.

---

## 6. Create a Group Library

Groups allow multiple users to share a collection of references.
Group creation requires admin (super user) credentials.

```bash
# Create group (owner = user ID 1)
curl -u "admin:CHANGE_ME_SUPER_PASSWORD" \
  -X POST -H "Content-Type: text/xml" \
  -d '<?xml version="1.0"?>
  <group owner="1" name="My Research Group" type="Private"
         libraryEditing="members" libraryReading="members" fileEditing="none">
    <description>Shared references for the team</description>
  </group>' \
  http://localhost:8080/groups
```

### Add a member to the group

```bash
# Add user 2 as a member of group 1
curl -u "admin:CHANGE_ME_SUPER_PASSWORD" \
  -X PUT \
  http://localhost:8080/groups/1/users/2
```

### Group types

| Type           | Who can join     | Who can see      |
|----------------|------------------|------------------|
| `Private`      | Invited only     | Members only     |
| `PublicClosed`  | Invited only     | Anyone           |
| `PublicOpen`    | Anyone           | Anyone           |

### Editing permissions

- `libraryEditing`: `admins` or `members`
- `libraryReading`: `members` or `all`
- `fileEditing`: `none`, `admins`, or `members`

---

## 7. Connect the Zotero Desktop Client

### Step 1: Set the API URL

Open Zotero and go to **Settings → Advanced → Config Editor** (type "I accept the risk" if prompted).

Search for `extensions.zotero.api.url`. If it doesn't exist, right-click → New → String.

| Preference                  | Value                     |
|-----------------------------|---------------------------|
| `extensions.zotero.api.url` | `http://YOUR_SERVER:8080/`|

For local use: `http://localhost:8080/`

### Step 2: Restart Zotero

Quit Zotero completely and reopen it.

### Step 3: Log in

Go to **Settings → Sync** and enter:

- **Username:** your username (e.g., `testuser`)
- **Password:** your password (e.g., `test123`)

Click "Set Up Syncing". Zotero will authenticate against your server and create an API key automatically.

### Step 4: Sync

Press the green sync button (top right) or let it sync automatically.

### Alternative: Edit prefs.js directly

If the Config Editor doesn't let you create new prefs:

1. Close Zotero
2. Open your Zotero profile folder:
   - macOS: `~/Zotero/`
   - Windows: `%APPDATA%\Zotero\Zotero\Profiles\*.default\`
   - Linux: `~/.zotero/zotero/*.default/`
3. Edit `prefs.js` and add:
   ```
   user_pref("extensions.zotero.api.url", "http://YOUR_SERVER:8080/");
   ```
4. Reopen Zotero

---

## 8. Docker Operations

```bash
cd docker/

# Start all services
docker compose up -d

# Stop (data is preserved)
docker compose down

# Stop and delete all data (fresh start)
docker compose down -v

# View logs
docker compose logs php-fpm --tail 50
docker compose logs nginx --tail 50
docker compose logs mysql --tail 50

# Restart after config change
docker compose up -d --force-recreate php-fpm

# Rebuild after Dockerfile change
docker compose build php-fpm && docker compose up -d --force-recreate php-fpm

# Enter PHP container for debugging
docker compose exec php-fpm bash
```

---

## 9. Exposing to the Network

By default the server listens on `localhost:8080`. To make it accessible to other machines on your network:

### Option A: Change the port binding

In `docker-compose.yml`, change:
```yaml
ports:
  - "8080:80"        # localhost only
```
to:
```yaml
ports:
  - "0.0.0.0:8080:80"  # all interfaces
```

Then on client machines, set `extensions.zotero.api.url` to `http://YOUR_SERVER_IP:8080/`.

### Option B: Reverse proxy with HTTPS (recommended for production)

Put nginx/Caddy/Traefik in front with TLS. Example Caddy config:

```
zotero.internal.company.com {
    reverse_proxy localhost:8080
}
```

Then set `extensions.zotero.api.url` to `https://zotero.internal.company.com/`.

---

## 10. Backup and Restore

### Backup

```bash
cd docker/

# Dump all databases
docker compose exec mysql mysqldump -uzotero -pzotero_app_pw \
  --databases zotero_master zotero_shard1 zotero_ids zotero_www_dev \
  > backup_$(date +%Y%m%d).sql
```

### Restore

```bash
# Stop services, wipe data, restart MySQL
docker compose down -v
docker compose up -d mysql
sleep 10

# Restore from backup
docker compose exec -T mysql mysql -uroot -pzotero_root_pw < backup_20260317.sql

# Start everything
docker compose up -d
```

---

## Troubleshooting

### "Invalid username or password" in Zotero client
- Verify `extensions.zotero.api.url` is set correctly in Config Editor
- Restart Zotero after changing the pref
- Test the API directly: `curl -X POST -H "Content-Type: application/json" -d '{"username":"testuser","password":"test123","name":"test","access":{"user":{"library":true}}}' http://localhost:8080/keys`

### Server returns 500 errors
- Check PHP logs: `docker compose logs php-fpm --tail 50`
- Check nginx logs: `docker compose logs nginx --tail 50`

### Sync is slow or times out
- Check MySQL: `docker compose exec mysql mysqladmin -uroot -pzotero_root_pw status`
- Check Memcached: `docker compose exec memcached sh -c 'echo stats | nc localhost 11211'`

### "Class Normalizer not found" error
- Rebuild PHP with intl extension: `docker compose build php-fpm --no-cache && docker compose up -d --force-recreate php-fpm`

### Data lost after restart
- Make sure you use `docker compose down` (not `down -v`). The `-v` flag deletes the MySQL volume.

---

## Architecture Overview

```
┌──────────────┐     ┌─────────────────────────────────────────────┐
│ Zotero Client│────▶│ nginx (:8080)                               │
└──────────────┘     │   └─▶ php-fpm (:9000)                      │
                     │         ├─▶ MySQL    (master, shard, ids, www)
                     │         ├─▶ Memcached (caching)             │
                     │         └─▶ Redis     (rate limiting, queues)│
                     └─────────────────────────────────────────────┘
```

### Database layout

| Database          | Purpose                                   |
|-------------------|-------------------------------------------|
| `zotero_master`   | Users, API keys, libraries, groups, shards|
| `zotero_shard1`   | Item data, collections, tags, relations   |
| `zotero_ids`      | Auto-increment ID generation (ticket server)|
| `zotero_www_dev`  | Password authentication (username/hash)   |
