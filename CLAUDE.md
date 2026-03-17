# CLAUDE.md — Zotero Self-Hosted Server Project

## Project Goal
Build a self-hosted Zotero sync server for internal (corporate) use where zotero.org is blocked.
**Scope: Metadata sync only (items, collections, tags, notes, groups). NO file/PDF sync.**

## Core Principle
**Minimize code changes.** Only patch what is strictly necessary for self-hosting. Prefer config-level solutions over code modifications. Do not refactor, reorganize, or "improve" existing code.

## Repository Structure
This is a fork of `zotero/dataserver` (PHP, AGPL-3.0). The companion client repo is `zotero/zotero`.

## Architecture Overview

### Database Layout (5 logical DBs, single MySQL instance)

| DB Name | Purpose | Schema File | Notes |
|---------|---------|-------------|-------|
| `zotero_master` | Users, groups, libraries, keys, shards | `misc/master.sql` + `misc/coredata.sql` | Core metadata |
| `zotero_shard1` | Items, collections, tags, notes, creators | `misc/shard.sql` + `misc/triggers.sql` | One shard is enough |
| `zotero_ids` | Distributed unique ID generation (Flickr ticket server pattern) | `misc/ids.sql` | Single instance OK |
| `zotero_www` | User authentication (username/password/email) | **NOT in this repo** — must create manually | See WWW DB section |
| `zotero_fulltext` | Full-text search index | N/A | **Skip — not needed** |

### Services Required (Minimal)

- **MySQL 8.0** — all 4 DBs in one instance
- **Memcached** — caching layer (can set `MEMCACHED_ENABLED=false` but many code paths assume it)
- **Redis** — used for request limiting, notifications (graceful fallback on failure)
- **PHP 8.x + php-fpm** — with extensions: mysqli, memcached, redis, mbstring, xml, json, curl
- **Nginx** — reverse proxy to php-fpm

### Services NOT Required (for metadata-only sync)

- AWS S3 / MinIO (file storage)
- Elasticsearch (fulltext search)
- Translation server
- Citation server (citeproc)
- SNS/SQS (notifications)
- StatsD (metrics)
- Scribe (logging)

## Key Files

### Server Config
- `include/config/config.inc.php-sample` → Copy to `config.inc.php`
- `include/config/dbconnect.inc.php-sample` → Copy to `dbconnect.inc.php`
- `include/header.inc.php` — Bootstrap/initialization (AWS SDK + ES init here — needs patching)
- `include/config/routes.inc.php` — All API routes

### Authentication Flow
- `controllers/ApiController.php:196-319` — Auth handling (3 methods: HTTP Basic super-user, API key, session cookie)
- `model/auth/Password.inc.php` — Password auth against `zotero_www.users` table
- `model/Keys.inc.php` — API key auth against `master.keys` table
- `model/Users.inc.php` — User management, references WWW DB extensively

### Sync-Critical Controllers
- `controllers/ItemsController.php` — Item CRUD + file handling (file parts need to be disabled/stubbed)
- `controllers/CollectionsController.php` — Collection sync
- `controllers/TagsController.php` — Tag sync
- `controllers/SearchesController.php` — Saved searches sync
- `controllers/KeysController.php` — API key management
- `controllers/GroupsController.php` — Group library management
- `controllers/SettingsController.php` — User/library settings sync
- `controllers/DeletedController.php` — Deletion sync log

### Can Be Disabled/Stubbed
- `controllers/StorageController.php` — File storage admin (not needed)
- `controllers/FullTextController.php` — Fulltext indexing (not needed)
- `controllers/TTSController.php` — Text-to-speech (not needed)
- `controllers/MappingsController.php` — Item type/field mappings (keep — client needs this)

## Critical Issues To Solve

### 1. WWW Database (Authentication)
The `zotero_www` DB schema is NOT in this repo. It belongs to the Zotero website codebase.

**Tables referenced by dataserver code:**
- `users` (userID, username, password, email, role, ...) — Password auth + user validation
- `users_email` (userID, email) — Email-based login lookup
- `users_meta` (userID, metaKey, metaValue) — Real name lookup
- `sessions` (id, userID, modified, lifetime) — Session-based auth
- `GDN_User` (UserID, Banned) — Vanilla Forums banned-user check

**From test_reset line 68, approximate `users` schema:**
```sql
CREATE TABLE users (
  userID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,  -- bcrypt hash
  email VARCHAR(255),
  secondary_email VARCHAR(255),
  role ENUM('member','admin','deleted') DEFAULT 'member',
  col6 VARCHAR(255),
  col7 VARCHAR(255),
  col8 VARCHAR(255),
  active TINYINT(1) DEFAULT 1,
  dateAdded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  dateModified TIMESTAMP,
  col12 INT DEFAULT 0,
  slug VARCHAR(255)
);

CREATE TABLE users_email (
  userID INT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  PRIMARY KEY (userID, email)
);

CREATE TABLE users_meta (
  userID INT UNSIGNED NOT NULL,
  metaKey VARCHAR(255) NOT NULL,
  metaValue TEXT,
  PRIMARY KEY (userID, metaKey)
);

CREATE TABLE sessions (
  id VARCHAR(255) NOT NULL PRIMARY KEY,
  userID INT UNSIGNED NOT NULL,
  modified INT UNSIGNED NOT NULL,
  lifetime INT UNSIGNED NOT NULL DEFAULT 86400
);

-- Vanilla Forums table (for banned-user check)
CREATE TABLE GDN_User (
  UserID INT UNSIGNED NOT NULL PRIMARY KEY,
  Banned TINYINT(1) NOT NULL DEFAULT 0
);
```

### 2. AWS SDK Initialization in header.inc.php
`header.inc.php:221-253` unconditionally creates `Aws\Sdk` instance.
**Options:**
- (a) Provide dummy AWS credentials in config (won't be called for metadata-only)
- (b) Patch header.inc.php to skip AWS init when S3 buckets are empty
- Option (b) is cleaner.

### 3. Elasticsearch Initialization in header.inc.php
`header.inc.php:256-259` unconditionally creates ES client.
**Options:**
- (a) Set `$SEARCH_HOSTS = ['localhost:9200']` and let it fail gracefully
- (b) Patch to skip when `$SEARCH_HOSTS` is empty
- Either works; fulltext endpoints will 500 but won't be called.

### 4. File Sync Endpoints (S3 calls)
`controllers/ItemsController.php` has S3 upload/download code around line 739+.
These routes exist in `routes.inc.php` (lines 23-36: `/file`, `/file/view`).
**Strategy:** Return 501 or 403 for file-related routes, or just let them fail naturally since S3 isn't configured.

### 5. Initial User + API Key Bootstrapping
No web UI for user registration. Need an admin script to:
1. Insert user into `zotero_www.users` (with bcrypt password)
2. Call `Zotero_Users::add($userID, $username)` to create master DB user + library + shard assignment
3. Insert API key into `master.keys` + `master.keyPermissions`

Reference: `misc/test_reset` lines 58-69 for manual user creation pattern.
Reference: `misc/test_setup` for PHP-based item creation.

## Client Configuration

### Endpoint Override (NO client rebuild needed for testing)
In Zotero client, the sync URL is resolved as:
```javascript
// chrome/content/zotero/xpcom/sync/syncRunner.js:49
let url = options.baseURL || Zotero.Prefs.get("api.url") || ZOTERO_CONFIG.API_URL;
```

Set hidden pref `extensions.zotero.api.url` to your server URL (e.g., `https://zotero-api.internal.corp/`).

Also disable streaming: set `extensions.zotero.streaming.enabled` to `false`.

### For Production: Patch config.mjs
```javascript
// resource/config.mjs — change these:
API_URL: 'https://your-internal-server.corp/',
STREAMING_URL: '',  // disable
```

## Development Phases

### Phase 1: Docker Compose + DB Init
- docker-compose.yml with MySQL, Memcached, Redis, PHP-FPM, Nginx
- SQL init scripts for all 4 DBs
- Config files (config.inc.php, dbconnect.inc.php, nginx.conf)

### Phase 2: Patch Server Code
- Patch `header.inc.php` to make AWS/ES initialization conditional
- Create admin CLI script for user/key management
- Stub or disable file sync endpoints
- Handle `GDN_User` table reference (create empty table or patch code)

### Phase 3: Integration Test
- Start services, verify API responds at `/`
- Create test user + API key via admin script
- Test `GET /keys/current` with API key
- Test `GET /users/{id}/items` (empty library)
- Test `POST /users/{id}/items` (create item)

### Phase 4: Client Connection
- Configure Zotero client hidden pref
- Test full sync cycle
- Test group library creation and sharing

### Phase 5: Production Hardening
- HTTPS (internal CA or Let's Encrypt)
- LDAP/SSO integration (replace Password auth plugin)
- User management web UI or CLI tools
- Backup strategy for MySQL
- Custom client build with hardcoded internal URLs

## Coding Conventions
- PHP code uses `<?` short tags (not `<?php` in model/include files)
- Class naming: `Zotero_ClassName` for models, `ClassNameController` for controllers
- DB access: `Zotero_DB::query()` / `::valueQuery()` / `::columnQuery()` / `::rowQuery()`
- Memcached: `Z_Core::$MC->get()` / `->set()` / `->delete()`
- Config: `Z_CONFIG::$PROPERTY_NAME` (static class properties)
- Error handling: `$this->e400()`, `$this->e403()`, `$this->e404()`, `$this->e500()` in controllers

## Test Infrastructure
- `misc/test_reset` — Shell script to reset all test DBs (GOLD MINE for understanding setup)
- `misc/test_setup` — PHP script to create sample data
- Tests in `tests/remote/` and `tests/remote-php/` — Integration tests against running server
