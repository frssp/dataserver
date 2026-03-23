<?php
/*
 * Zotero Self-Hosted Admin Panel
 * Single-file web UI for managing users, groups, and API keys.
 * Protected by HTTP Basic Auth using API_SUPER credentials from config.
 */

set_include_path("../include");
require_once("header.inc.php");

// ── Auth ──────────────────────────────────────────────────────────────
$realm = 'Zotero Admin';
if (
	!isset($_SERVER['PHP_AUTH_USER']) ||
	$_SERVER['PHP_AUTH_USER'] !== Z_CONFIG::$API_SUPER_USERNAME ||
	$_SERVER['PHP_AUTH_PW'] !== Z_CONFIG::$API_SUPER_PASSWORD
) {
	header('WWW-Authenticate: Basic realm="' . $realm . '"');
	header('HTTP/1.0 401 Unauthorized');
	echo 'Authentication required.';
	exit;
}

// ── Helpers ───────────────────────────────────────────────────────────
function wwwDB() {
	$dev = Z_ENV_TESTING_SITE ? "_dev" : "";
	return "zotero_www{$dev}";
}

function jsonResponse($data, $code = 200) {
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode($data);
	exit;
}

function generateKey() {
	$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	$key = '';
	for ($i = 0; $i < 24; $i++) {
		$key .= $chars[random_int(0, strlen($chars) - 1)];
	}
	return $key;
}

// ── API Handler ───────────────────────────────────────────────────────
if (isset($_GET['action'])) {
	$action = $_GET['action'];
	$wwwDB = wwwDB();

	// Admin actions may write to DB, but requests arrive as GET
	// (from JS fetch with query params). Disable read-only mode
	// that header.inc.php sets for GET requests.
	Zotero_DB::readOnly(false);
 
	try {
		switch ($action) {

		// ── Status ────────────────────────────────────────────────
		case 'status':
			$users = Zotero_DB::valueQuery("SELECT COUNT(*) FROM zotero_master.users");
			$groups = Zotero_DB::valueQuery("SELECT COUNT(*) FROM zotero_master.groups");
			$keys = Zotero_DB::valueQuery("SELECT COUNT(*) FROM zotero_master.`keys`");
			$libraries = Zotero_DB::valueQuery("SELECT COUNT(*) FROM zotero_master.libraries");
			$items = Zotero_DB::valueQuery("SELECT COUNT(*) FROM items", false, 1);
			$collections = Zotero_DB::valueQuery("SELECT COUNT(*) FROM collections", false, 1);
			$schema = Zotero_DB::valueQuery("SELECT value FROM zotero_master.settings WHERE name='schemaVersion'");
			jsonResponse([
				'users' => (int)$users, 'groups' => (int)$groups, 'keys' => (int)$keys,
				'libraries' => (int)$libraries, 'items' => (int)$items,
				'collections' => (int)$collections, 'schema' => (int)$schema
			]);

		// ── Users ─────────────────────────────────────────────────
		case 'user.list':
			$rows = Zotero_DB::query(
				"SELECT u.userID, u.username, u.libraryID, w.email
				 FROM zotero_master.users u
				 LEFT JOIN $wwwDB.users w ON u.userID = w.userID
				 ORDER BY u.userID"
			);
			jsonResponse($rows ?: []);

		case 'user.add':
			$input = json_decode(file_get_contents('php://input'), true);
			$username = trim($input['username'] ?? '');
			$password = $input['password'] ?? '';
			$email = trim($input['email'] ?? '');
			if (!$username || !$password || !$email) jsonResponse(['error' => 'Username, password, and email are required.'], 400);

			$existing = Zotero_DB::valueQuery("SELECT userID FROM zotero_master.users WHERE username=?", $username);
			if ($existing) jsonResponse(['error' => "User '$username' already exists."], 409);

			$salt = Z_CONFIG::$AUTH_SALT;
			$hash = sha1($salt . $password);

			Zotero_DB::beginTransaction();

			// www user first (auto-increment)
			Zotero_DB::query(
				"INSERT INTO $wwwDB.users (username, password, email, active, dateAdded, dateModified, slug) VALUES (?, ?, ?, 1, NOW(), NOW(), ?)",
				[$username, $hash, $email, $username]
			);
			$userID = Zotero_DB::valueQuery("SELECT LAST_INSERT_ID()");

			// library
			Zotero_DB::query("INSERT INTO zotero_master.libraries (libraryType, lastUpdated, version, shardID, hasData) VALUES ('user', NOW(), 0, 1, 0)");
			$libraryID = Zotero_DB::valueQuery("SELECT LAST_INSERT_ID()");

			// master user
			Zotero_DB::query("INSERT INTO zotero_master.users (userID, libraryID, username) VALUES (?, ?, ?)", [$userID, $libraryID, $username]);

			// shard library
			$shardID = Zotero_Shards::getByLibraryID($libraryID);
			Zotero_DB::query(
				"INSERT INTO shardLibraries (libraryID, libraryType, lastUpdated, version, storageUsage) VALUES (?, 'user', NOW(), 1, 0)",
				[$libraryID], $shardID
			);

			// API key
			$key = generateKey();
			Zotero_DB::query("INSERT INTO zotero_master.`keys` (`key`, userID, name, dateAdded, lastUsed) VALUES (?, ?, 'Full Access Key', NOW(), NOW())", [$key, $userID]);
			$keyID = Zotero_DB::valueQuery("SELECT LAST_INSERT_ID()");
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, ?, 'library', 1)", [$keyID, $libraryID]);
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, ?, 'notes', 1)", [$keyID, $libraryID]);
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, ?, 'write', 1)", [$keyID, $libraryID]);
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, 0, 'library', 1)", [$keyID]);
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, 0, 'notes', 1)", [$keyID]);
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, 0, 'write', 1)", [$keyID]);

			Zotero_DB::commit();
			jsonResponse(['userID' => (int)$userID, 'libraryID' => (int)$libraryID, 'apiKey' => $key], 201);

		case 'user.passwd':
			$input = json_decode(file_get_contents('php://input'), true);
			$userID = (int)($input['userID'] ?? 0);
			$password = $input['password'] ?? '';
			if (!$userID || !$password) jsonResponse(['error' => 'userID and password required.'], 400);
			$hash = sha1(Z_CONFIG::$AUTH_SALT . $password);
			Zotero_DB::query("UPDATE $wwwDB.users SET password=?, dateModified=NOW() WHERE userID=?", [$hash, $userID]);
			jsonResponse(['ok' => true]);

		case 'user.delete':
			$userID = (int)($_GET['userID'] ?? 0);
			if (!$userID) jsonResponse(['error' => 'userID required.'], 400);

			Zotero_DB::beginTransaction();
			Zotero_DB::query("DELETE FROM $wwwDB.users WHERE userID=?", [$userID]);
			$keys = Zotero_DB::query("SELECT keyID FROM zotero_master.`keys` WHERE userID=?", [$userID]);
			if ($keys) {
				foreach ($keys as $k) {
					Zotero_DB::query("DELETE FROM zotero_master.keyPermissions WHERE keyID=?", [$k['keyID']]);
				}
				Zotero_DB::query("DELETE FROM zotero_master.`keys` WHERE userID=?", [$userID]);
			}
			$libraryID = Zotero_DB::valueQuery("SELECT libraryID FROM zotero_master.users WHERE userID=?", [$userID]);
			if ($libraryID) {
				$shardID = Zotero_Shards::getByLibraryID($libraryID);
				Zotero_DB::query("DELETE FROM shardLibraries WHERE libraryID=?", [$libraryID], $shardID);
				Zotero_DB::query("DELETE FROM zotero_master.libraries WHERE libraryID=?", [$libraryID]);
			}
			Zotero_DB::query("DELETE FROM zotero_master.users WHERE userID=?", [$userID]);
			Zotero_DB::query("DELETE FROM zotero_master.groupUsers WHERE userID=?", [$userID]);
			Zotero_DB::commit();
			jsonResponse(['ok' => true]);

		case 'user.info':
			$userID = (int)($_GET['userID'] ?? 0);
			$user = Zotero_DB::rowQuery(
				"SELECT u.userID, u.username, u.libraryID, w.email, w.dateAdded, w.dateModified
				 FROM zotero_master.users u LEFT JOIN $wwwDB.users w ON u.userID = w.userID WHERE u.userID=?", [$userID]
			);
			$keys = Zotero_DB::query("SELECT keyID, `key`, name, dateAdded, lastUsed FROM zotero_master.`keys` WHERE userID=?", [$userID]);
			$groups = Zotero_DB::query(
				"SELECT g.groupID, g.name, gu.role FROM zotero_master.groupUsers gu JOIN zotero_master.groups g ON gu.groupID=g.groupID WHERE gu.userID=?", [$userID]
			);
			$user['keys'] = $keys ?: [];
			$user['groups'] = $groups ?: [];
			jsonResponse($user);

		// ── Groups ────────────────────────────────────────────────
		case 'group.list':
			$rows = Zotero_DB::query(
				"SELECT g.groupID, g.name, g.type, g.libraryEditing, g.libraryReading, g.fileEditing,
				        g.description, g.dateAdded, u.username AS owner,
				        (SELECT COUNT(*) FROM zotero_master.groupUsers gu WHERE gu.groupID=g.groupID) AS members
				 FROM zotero_master.groups g
				 JOIN zotero_master.groupUsers gu2 ON g.groupID=gu2.groupID AND gu2.role='owner'
				 JOIN zotero_master.users u ON gu2.userID=u.userID
				 ORDER BY g.groupID"
			);
			jsonResponse($rows ?: []);

		case 'group.create':
			$input = json_decode(file_get_contents('php://input'), true);
			$ownerUsername = trim($input['owner'] ?? '');
			$name = trim($input['name'] ?? '');
			$type = $input['type'] ?? 'Private';
			$editing = $input['libraryEditing'] ?? 'members';
			$reading = $input['libraryReading'] ?? 'members';
			if (!$ownerUsername || !$name) jsonResponse(['error' => 'Owner and name required.'], 400);

			$ownerID = Zotero_DB::valueQuery("SELECT userID FROM zotero_master.users WHERE username=?", $ownerUsername);
			if (!$ownerID) jsonResponse(['error' => "Owner '$ownerUsername' not found."], 404);

			$group = new Zotero_Group;
			$group->ownerUserID = (int)$ownerID;
			$group->name = $name;
			$group->type = $type;
			$group->libraryEditing = $editing;
			$group->libraryReading = $reading;
			$group->fileEditing = 'none';
			$group->description = $input['description'] ?? '';
			$group->url = '';
			$group->hasImage = false;
			$group->save();

			jsonResponse(['groupID' => $group->id, 'libraryID' => $group->libraryID], 201);

		case 'group.delete':
			$groupID = (int)($_GET['groupID'] ?? 0);
			$group = Zotero_Groups::get($groupID);
			if (!$group) jsonResponse(['error' => 'Group not found.'], 404);
			$group->erase();
			jsonResponse(['ok' => true]);

		case 'group.members':
			$groupID = (int)($_GET['groupID'] ?? 0);
			$rows = Zotero_DB::query(
				"SELECT gu.userID, u.username, gu.role, gu.joined
				 FROM zotero_master.groupUsers gu JOIN zotero_master.users u ON gu.userID=u.userID
				 WHERE gu.groupID=? ORDER BY FIELD(gu.role,'owner','admin','member'), gu.joined", [$groupID]
			);
			jsonResponse($rows ?: []);

		case 'group.adduser':
			$input = json_decode(file_get_contents('php://input'), true);
			$groupID = (int)($input['groupID'] ?? 0);
			$username = trim($input['username'] ?? '');
			$role = $input['role'] ?? 'member';

			$group = Zotero_Groups::get($groupID);
			if (!$group) jsonResponse(['error' => 'Group not found.'], 404);
			$userID = Zotero_DB::valueQuery("SELECT userID FROM zotero_master.users WHERE username=?", $username);
			if (!$userID) jsonResponse(['error' => "User '$username' not found."], 404);

			if ($group->hasUser((int)$userID)) {
				$group->updateUser((int)$userID, $role);
			} else {
				$group->addUser((int)$userID, $role);
			}
			jsonResponse(['ok' => true]);

		case 'group.removeuser':
			$input = json_decode(file_get_contents('php://input'), true);
			$groupID = (int)($input['groupID'] ?? 0);
			$userID = (int)($input['userID'] ?? 0);
			$group = Zotero_Groups::get($groupID);
			if (!$group) jsonResponse(['error' => 'Group not found.'], 404);
			$group->removeUser($userID);
			jsonResponse(['ok' => true]);

		case 'group.update':
			$input = json_decode(file_get_contents('php://input'), true);
			$groupID = (int)($input['groupID'] ?? 0);
			$group = Zotero_Groups::get($groupID);
			if (!$group) jsonResponse(['error' => 'Group not found.'], 404);
			if (isset($input['name'])) $group->name = trim($input['name']);
			if (isset($input['type'])) $group->type = $input['type'];
			if (isset($input['libraryEditing'])) $group->libraryEditing = $input['libraryEditing'];
			if (isset($input['libraryReading'])) $group->libraryReading = $input['libraryReading'];
			if (isset($input['description'])) $group->description = trim($input['description']);
			$group->save();
			jsonResponse(['ok' => true]);

		// ── Keys ──────────────────────────────────────────────────
		case 'key.list':
			$userID = (int)($_GET['userID'] ?? 0);
			$rows = Zotero_DB::query(
				"SELECT keyID, `key`, name, dateAdded, lastUsed FROM zotero_master.`keys` WHERE userID=? ORDER BY keyID", [$userID]
			);
			jsonResponse($rows ?: []);

		case 'key.create':
			$input = json_decode(file_get_contents('php://input'), true);
			$userID = (int)($input['userID'] ?? 0);
			$name = trim($input['name'] ?? 'Full Access Key');
			$libraryID = Zotero_DB::valueQuery("SELECT libraryID FROM zotero_master.users WHERE userID=?", [$userID]);
			if (!$libraryID) jsonResponse(['error' => 'User not found.'], 404);

			$key = generateKey();
			Zotero_DB::query("INSERT INTO zotero_master.`keys` (`key`, userID, name, dateAdded, lastUsed) VALUES (?, ?, ?, NOW(), NOW())", [$key, $userID, $name]);
			$keyID = Zotero_DB::valueQuery("SELECT LAST_INSERT_ID()");
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, ?, 'library', 1)", [$keyID, $libraryID]);
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, ?, 'notes', 1)", [$keyID, $libraryID]);
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, ?, 'write', 1)", [$keyID, $libraryID]);
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, 0, 'library', 1)", [$keyID]);
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, 0, 'notes', 1)", [$keyID]);
			Zotero_DB::query("INSERT INTO zotero_master.keyPermissions VALUES (?, 0, 'write', 1)", [$keyID]);
			jsonResponse(['key' => $key, 'keyID' => (int)$keyID], 201);

		case 'key.delete':
			$keyID = (int)($_GET['keyID'] ?? 0);
			Zotero_DB::query("DELETE FROM zotero_master.keyPermissions WHERE keyID=?", [$keyID]);
			Zotero_DB::query("DELETE FROM zotero_master.`keys` WHERE keyID=?", [$keyID]);
			jsonResponse(['ok' => true]);

		case 'admin.passwd':
			$input = json_decode(file_get_contents('php://input'), true);
			$current = $input['current'] ?? '';
			$newPass = $input['password'] ?? '';
			if (!$newPass) jsonResponse(['error' => 'New password is required.'], 400);
			if ($current !== Z_CONFIG::$API_SUPER_PASSWORD) jsonResponse(['error' => 'Current password is incorrect.'], 403);

			$configFile = realpath("../include/config/config.inc.php");
			$content = file_get_contents($configFile);
			$escaped = addcslashes($newPass, "'\\");
			$content = preg_replace(
				'/\$API_SUPER_PASSWORD\s*=\s*\'[^\']*\'/',
				'$API_SUPER_PASSWORD = \'' . $escaped . '\'',
				$content
			);
			file_put_contents($configFile, $content);
			jsonResponse(['ok' => true]);

		default:
			jsonResponse(['error' => 'Unknown action.'], 400);
		}
	}
	catch (Exception $e) {
		jsonResponse(['error' => $e->getMessage()], 500);
	}
}

// ── HTML UI ───────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zotero Admin</title>
<style>
:root {
	--bg: #f5f6fa;
	--card: #fff;
	--border: #e1e4e8;
	--primary: #bb2929;
	--primary-hover: #961f1f;
	--text: #24292e;
	--text-muted: #586069;
	--success: #28a745;
	--danger: #d73a49;
	--warning: #f0ad4e;
	--radius: 8px;
	--shadow: 0 1px 3px rgba(0,0,0,.08);
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; }

/* Layout */
/* ── Unified Nav (zotero.org style) ──────────────────────────────── */
.site-nav { background: #fff; border-bottom: 1px solid #ddd; padding: 0 24px; display: flex; align-items: center; height: 56px; }
.site-nav .logo { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 26px; font-weight: 300; letter-spacing: -0.5px; text-decoration: none; margin-right: 32px; color: inherit; }
.site-nav .logo .z { color: #c1302b; }
.site-nav .logo .rest { color: #333; }
.site-nav .nav-links { display: flex; align-items: center; gap: 0; flex: 1; }
.site-nav .nav-links a { display: flex; align-items: center; padding: 0 16px; height: 56px; color: #444; font-size: 14px; font-weight: 500; text-decoration: none; border-bottom: 3px solid transparent; transition: color .15s, border-color .15s; }
.site-nav .nav-links a:hover { color: #111; text-decoration: none; }
.site-nav .nav-links a.active { color: #c1302b; border-bottom-color: #c1302b; }
.site-nav .nav-right { display: flex; align-items: center; gap: 12px; margin-left: auto; }
.site-nav .nav-user { font-size: 14px; font-weight: 500; color: #333; }
.site-nav .nav-logout { font-size: 12px; color: #999; cursor: pointer; background: none; border: 1px solid #ddd; padding: 4px 12px; border-radius: 4px; }
.site-nav .nav-logout:hover { color: #333; border-color: #999; }
.container { max-width: 1100px; margin: 0 auto; padding: 24px; }

/* Sub-tabs (consistent with account.php) */
.page-tabs { display: flex; gap: 0; border-bottom: 2px solid #ddd; margin-bottom: 24px; }
.page-tab { padding: 10px 20px; font-size: 13px; font-weight: bold; color: #666; cursor: pointer; border: 1px solid transparent; border-bottom: 2px solid transparent; margin-bottom: -2px; background: none; transition: all .15s; }
.page-tab:hover { color: #333; }
.page-tab.active { color: #333; border-color: #ddd #ddd #fff; background: #fff; border-bottom-color: #fff; border-top: 2px solid #c1302b; }

/* Cards */
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow); margin-bottom: 24px; }

/* Stats */
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; text-align: center; box-shadow: var(--shadow); }
.stat .num { font-size: 28px; font-weight: 700; color: var(--primary); }
.stat .label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; }

/* Tables */
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th { text-align: left; padding: 10px 12px; background: #f6f8fa; border-bottom: 2px solid var(--border); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); }
td { padding: 10px 12px; border-bottom: 1px solid var(--border); }
tr:hover td { background: #f9fafb; }
.empty-row td { text-align: center; color: var(--text-muted); padding: 30px; }

/* Buttons */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; background: var(--card); color: var(--text); }
.btn:hover { background: #f0f0f0; }
.btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
.btn-primary:hover { background: var(--primary-hover); }
.btn-danger { color: var(--danger); border-color: var(--danger); }
.btn-danger:hover { background: var(--danger); color: #fff; }
.btn-sm { padding: 4px 10px; font-size: 12px; }
.btn-group { display: flex; gap: 6px; }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }

/* Forms */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 100; align-items: center; justify-content: center; }
.modal-overlay.show { display: flex; }
.modal { background: var(--card); border-radius: var(--radius); padding: 24px; width: 440px; max-width: 90vw; box-shadow: 0 8px 30px rgba(0,0,0,.15); }
.modal h3 { margin-bottom: 16px; font-size: 16px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px; color: var(--text-muted); }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; font-family: inherit; }
.form-group textarea { height: 60px; resize: vertical; }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(187,41,41,.1); }
.form-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 18px; }

/* Badges */
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.badge-owner { background: #fff3cd; color: #856404; }
.badge-admin { background: #cce5ff; color: #004085; }
.badge-member { background: #e2e3e5; color: #383d41; }
.badge-private { background: #f8d7da; color: #721c24; }
.badge-public { background: #d4edda; color: #155724; }

/* Key display */
.key-display { font-family: "SF Mono", "Fira Code", monospace; background: #f6f8fa; padding: 10px 14px; border-radius: 6px; border: 1px solid var(--border); font-size: 15px; letter-spacing: .5px; margin: 10px 0; user-select: all; word-break: break-all; }

/* Toast */
.toast { position: fixed; top: 20px; right: 20px; padding: 12px 20px; border-radius: 6px; color: #fff; font-size: 14px; font-weight: 500; z-index: 200; opacity: 0; transition: opacity .3s; pointer-events: none; }
.toast.show { opacity: 1; }
.toast-success { background: var(--success); }
.toast-error { background: var(--danger); }

/* Panel visibility */
.panel { display: none; }
.panel.active { display: block; }

/* Member chips */
.member-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.member-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: #f6f8fa; border: 1px solid var(--border); border-radius: 16px; font-size: 13px; }
.member-chip .remove { cursor: pointer; color: var(--danger); font-weight: bold; font-size: 15px; line-height: 1; }
.member-chip .remove:hover { color: #a71d2a; }
</style>
</head>
<body>

<nav class="site-nav">
	<a href="/" class="logo"><span class="z">z</span><span class="rest">otero</span></a>
	<div class="nav-links">
		<a href="/library/">Web Library</a>
		<a href="/account.php">Account</a>
		<a href="/admin.php" class="active">Admin</a>
	</div>
	<div class="nav-right">
		<button class="nav-logout" onclick="showModal('modal-admin-passwd')">Change Password</button>
		<button class="nav-logout" onclick="adminLogout()">Log Out</button>
	</div>
</nav>

<div class="container">
	<!-- Tabs -->
	<div class="page-tabs">
		<button class="page-tab active" id="tab-users" onclick="showTab('users')">Users</button>
		<button class="page-tab" id="tab-groups" onclick="showTab('groups')">Groups</button>
		<button class="page-tab" id="tab-keys" onclick="showTab('keys')">API Keys</button>
		<button class="page-tab" id="tab-status" onclick="showTab('status')">Status</button>
	</div>

	<!-- Users Panel -->
	<div class="panel active" id="panel-users">
		<div class="toolbar">
			<div style="font-size:13px;color:var(--text-muted)" id="user-count"></div>
			<button class="btn btn-primary" onclick="showAddUser()">+ Add User</button>
		</div>
		<table>
			<thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Library</th><th>Actions</th></tr></thead>
			<tbody id="user-table"></tbody>
		</table>
	</div>

	<!-- Groups Panel -->
	<div class="panel" id="panel-groups">
		<div class="toolbar">
			<div style="font-size:13px;color:var(--text-muted)" id="group-count"></div>
			<button class="btn btn-primary" onclick="showAddGroup()">+ Create Group</button>
		</div>
		<table>
			<thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Owner</th><th>Members</th><th>Editing</th><th>Actions</th></tr></thead>
			<tbody id="group-table"></tbody>
		</table>
	</div>

	<!-- Keys Panel -->
	<div class="panel" id="panel-keys">
		<div class="toolbar">
			<div style="font-size:13px;color:var(--text-muted)">Select a user to view their API keys</div>
		</div>
		<div id="key-user-select" style="margin-bottom:16px"></div>
		<table>
			<thead><tr><th>ID</th><th>Key</th><th>Name</th><th>Created</th><th>Last Used</th><th>Actions</th></tr></thead>
			<tbody id="key-table"><tr class="empty-row"><td colspan="6">Select a user above</td></tr></tbody>
		</table>
	</div>
	<!-- Status Panel -->
	<div class="panel" id="panel-status">
		<h2 style="font-size:20px;font-weight:normal;color:#333;margin-bottom:20px;border-bottom:1px solid #ddd;padding-bottom:10px">Server Status</h2>
		<div class="stats" id="stats"></div>
	</div>
</div>

<!-- Modals -->
<div class="modal-overlay" id="modal-add-user">
	<div class="modal">
		<h3>Add User</h3>
		<div class="form-group"><label>Username</label><input id="new-username" autocomplete="off"></div>
		<div class="form-group"><label>Password</label><input id="new-password" type="password"></div>
		<div class="form-group"><label>Email</label><input id="new-email" type="email"></div>
		<div class="form-actions">
			<button class="btn" onclick="closeModal('modal-add-user')">Cancel</button>
			<button class="btn btn-primary" onclick="addUser()">Create User</button>
		</div>
	</div>
</div>

<div class="modal-overlay" id="modal-user-created">
	<div class="modal">
		<h3>User Created</h3>
		<p style="margin-bottom:10px">The user has been created. Share this API key with them:</p>
		<div class="key-display" id="created-api-key"></div>
		<p style="font-size:12px;color:var(--text-muted)">This key will not be shown again. Copy it now.</p>
		<div class="form-actions"><button class="btn btn-primary" onclick="closeModal('modal-user-created')">Done</button></div>
	</div>
</div>

<div class="modal-overlay" id="modal-passwd">
	<div class="modal">
		<h3>Change Password</h3>
		<input type="hidden" id="passwd-userid">
		<p style="margin-bottom:10px">Changing password for <strong id="passwd-username"></strong></p>
		<div class="form-group"><label>New Password</label><input id="passwd-new" type="password"></div>
		<div class="form-actions">
			<button class="btn" onclick="closeModal('modal-passwd')">Cancel</button>
			<button class="btn btn-primary" onclick="changePassword()">Update</button>
		</div>
	</div>
</div>

<div class="modal-overlay" id="modal-add-group">
	<div class="modal">
		<h3>Create Group</h3>
		<div class="form-group"><label>Group Name</label><input id="group-name"></div>
		<div class="form-group"><label>Owner (username)</label><input id="group-owner"></div>
		<div class="form-group">
			<label>Type</label>
			<select id="group-type">
				<option value="Private">Private</option>
				<option value="PublicClosed">Public (Closed)</option>
				<option value="PublicOpen">Public (Open)</option>
			</select>
		</div>
		<div class="form-group">
			<label>Library Editing</label>
			<select id="group-editing"><option value="members">All Members</option><option value="admins">Admins Only</option></select>
		</div>
		<div class="form-group">
			<label>Library Reading</label>
			<select id="group-reading"><option value="members">Members Only</option><option value="all">Anyone</option></select>
		</div>
		<div class="form-group"><label>Description</label><textarea id="group-desc"></textarea></div>
		<div class="form-actions">
			<button class="btn" onclick="closeModal('modal-add-group')">Cancel</button>
			<button class="btn btn-primary" onclick="addGroup()">Create</button>
		</div>
	</div>
</div>

<div class="modal-overlay" id="modal-edit-group">
	<div class="modal" style="width:520px">
		<h3>Group Settings</h3>
		<input type="hidden" id="edit-grp-id">
		<div class="form-group"><label>Group Name</label><input id="edit-grp-name"></div>
		<div class="form-group">
			<label>Type</label>
			<select id="edit-grp-type">
				<option value="PublicOpen">Public, Open Membership</option>
				<option value="PublicClosed">Public, Closed Membership</option>
				<option value="Private">Private</option>
			</select>
		</div>
		<div class="form-group">
			<label>Library Reading</label>
			<select id="edit-grp-reading"><option value="all">Anyone</option><option value="members">Members Only</option></select>
		</div>
		<div class="form-group">
			<label>Library Editing</label>
			<select id="edit-grp-editing"><option value="members">All Members</option><option value="admins">Admins Only</option></select>
		</div>
		<div class="form-group"><label>Description</label><textarea id="edit-grp-desc"></textarea></div>
		<div class="form-actions">
			<button class="btn" onclick="closeModal('modal-edit-group')">Cancel</button>
			<button class="btn btn-primary" onclick="saveGroupSettings()">Save Changes</button>
		</div>
	</div>
</div>

<div class="modal-overlay" id="modal-group-members">
	<div class="modal" style="width:520px">
		<h3>Group Members — <span id="members-group-name"></span></h3>
		<div id="members-list" style="margin:12px 0"></div>
		<div style="display:flex;gap:8px;margin-top:16px">
			<input id="add-member-username" placeholder="Username" style="flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:14px">
			<select id="add-member-role" style="padding:8px;border:1px solid var(--border);border-radius:6px;font-size:14px">
				<option value="member">Member</option><option value="admin">Admin</option>
			</select>
			<button class="btn btn-primary" onclick="addMember()">Add</button>
		</div>
		<div class="form-actions"><button class="btn" onclick="closeModal('modal-group-members')">Close</button></div>
	</div>
</div>

<div class="modal-overlay" id="modal-user-info">
	<div class="modal" style="width:520px">
		<h3>User Details</h3>
		<div id="user-info-content"></div>
		<div class="form-actions"><button class="btn" onclick="closeModal('modal-user-info')">Close</button></div>
	</div>
</div>

<div class="modal-overlay" id="modal-admin-passwd">
	<div class="modal">
		<h3>Change Admin Password</h3>
		<div class="form-group"><label>Current Password</label><input id="admin-current-pw" type="password"></div>
		<div class="form-group"><label>New Password</label><input id="admin-new-pw" type="password"></div>
		<div class="form-group"><label>Confirm New Password</label><input id="admin-confirm-pw" type="password"></div>
		<div class="form-actions">
			<button class="btn" onclick="closeModal('modal-admin-passwd')">Cancel</button>
			<button class="btn btn-primary" onclick="changeAdminPassword()">Update</button>
		</div>
	</div>
</div>

<div class="toast" id="toast"></div>

<script>
const API = 'admin.php';
let currentGroupID = null;
let allUsers = [];

async function api(action, opts = {}) {
	const params = new URLSearchParams({action, ...opts.params});
	const url = `${API}?${params}`;
	const fetchOpts = {method: opts.method || 'GET'};
	if (opts.body) {
		fetchOpts.method = 'POST';
		fetchOpts.headers = {'Content-Type': 'application/json'};
		fetchOpts.body = JSON.stringify(opts.body);
	}
	const res = await fetch(url, fetchOpts);
	const data = await res.json();
	if (!res.ok) throw new Error(data.error || 'Request failed');
	return data;
}

function toast(msg, type = 'success') {
	const el = document.getElementById('toast');
	el.textContent = msg;
	el.className = `toast toast-${type} show`;
	setTimeout(() => el.classList.remove('show'), 3000);
}

function showTab(name) {
	document.querySelectorAll('.page-tab').forEach(t => t.classList.remove('active'));
	document.getElementById('tab-' + name).classList.add('active');
	document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
	document.getElementById('panel-' + name).classList.add('active');
	if (name === 'keys') renderKeyUserSelect();
}

function showModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }

// ── Stats ─────────────────────────────────────────────────────────
async function loadStats() {
	const s = await api('status');
	document.getElementById('stats').innerHTML = [
		['Users', s.users], ['Groups', s.groups], ['Items', s.items],
		['Collections', s.collections], ['API Keys', s.keys], ['Schema', 'v' + s.schema]
	].map(([label, num]) => `<div class="stat"><div class="num">${num}</div><div class="label">${label}</div></div>`).join('');
}

// ── Users ─────────────────────────────────────────────────────────
async function loadUsers() {
	allUsers = await api('user.list');
	const tbody = document.getElementById('user-table');
	if (!allUsers.length) {
		tbody.innerHTML = '<tr class="empty-row"><td colspan="5">No users</td></tr>';
		return;
	}
	tbody.innerHTML = allUsers.map(u => `<tr>
		<td>${u.userID}</td>
		<td><strong>${esc(u.username)}</strong></td>
		<td>${esc(u.email || '')}</td>
		<td>${u.libraryID}</td>
		<td class="btn-group">
			<button class="btn btn-sm" onclick="showUserInfo(${u.userID})">Info</button>
			<button class="btn btn-sm" onclick="showPasswd(${u.userID},'${esc(u.username)}')">Password</button>
			<button class="btn btn-sm btn-danger" onclick="deleteUser(${u.userID},'${esc(u.username)}')">Delete</button>
		</td>
	</tr>`).join('');
	document.getElementById('user-count').textContent = `${allUsers.length} user(s)`;
}

function showAddUser() {
	['new-username','new-password','new-email'].forEach(id => document.getElementById(id).value = '');
	showModal('modal-add-user');
	document.getElementById('new-username').focus();
}

async function addUser() {
	try {
		const res = await api('user.add', {body: {
			username: document.getElementById('new-username').value,
			password: document.getElementById('new-password').value,
			email: document.getElementById('new-email').value
		}});
		closeModal('modal-add-user');
		document.getElementById('created-api-key').textContent = res.apiKey;
		showModal('modal-user-created');
		loadUsers(); loadStats();
	} catch(e) { toast(e.message, 'error'); }
}

function showPasswd(userID, username) {
	document.getElementById('passwd-userid').value = userID;
	document.getElementById('passwd-username').textContent = username;
	document.getElementById('passwd-new').value = '';
	showModal('modal-passwd');
	document.getElementById('passwd-new').focus();
}

async function changePassword() {
	try {
		await api('user.passwd', {body: {
			userID: parseInt(document.getElementById('passwd-userid').value),
			password: document.getElementById('passwd-new').value
		}});
		closeModal('modal-passwd');
		toast('Password updated');
	} catch(e) { toast(e.message, 'error'); }
}

async function deleteUser(userID, username) {
	if (!confirm(`Delete user "${username}"? This cannot be undone.`)) return;
	try {
		await api('user.delete', {params: {userID}});
		toast('User deleted');
		loadUsers(); loadStats();
	} catch(e) { toast(e.message, 'error'); }
}

async function showUserInfo(userID) {
	const u = await api('user.info', {params: {userID}});
	let html = `<table style="width:100%;font-size:14px;margin-bottom:12px">
		<tr><td style="width:100px;color:var(--text-muted)">Username</td><td><strong>${esc(u.username)}</strong></td></tr>
		<tr><td style="color:var(--text-muted)">User ID</td><td>${u.userID}</td></tr>
		<tr><td style="color:var(--text-muted)">Library ID</td><td>${u.libraryID}</td></tr>
		<tr><td style="color:var(--text-muted)">Email</td><td>${esc(u.email || 'N/A')}</td></tr>
		<tr><td style="color:var(--text-muted)">Created</td><td>${u.dateAdded || 'N/A'}</td></tr>
	</table>`;
	if (u.keys && u.keys.length) {
		html += '<div style="font-weight:600;font-size:13px;margin-bottom:6px">API Keys</div>';
		u.keys.forEach(k => { html += `<div class="key-display" style="font-size:12px;padding:6px 10px;margin:4px 0">${k.key} <span style="color:var(--text-muted)">(${esc(k.name)})</span></div>`; });
	}
	if (u.groups && u.groups.length) {
		html += '<div style="font-weight:600;font-size:13px;margin:12px 0 6px">Groups</div><div class="member-list">';
		u.groups.forEach(g => { html += `<span class="member-chip">${esc(g.name)} <span class="badge badge-${g.role}">${g.role}</span></span>`; });
		html += '</div>';
	}
	document.getElementById('user-info-content').innerHTML = html;
	showModal('modal-user-info');
}

// ── Groups ────────────────────────────────────────────────────────
async function loadGroups() {
	const groups = await api('group.list');
	const tbody = document.getElementById('group-table');
	if (!groups.length) {
		tbody.innerHTML = '<tr class="empty-row"><td colspan="7">No groups</td></tr>';
		return;
	}
	tbody.innerHTML = groups.map(g => {
		const typeBadge = g.type === 'Private' ? 'private' : 'public';
		return `<tr>
			<td>${g.groupID}</td>
			<td><strong>${esc(g.name)}</strong></td>
			<td><span class="badge badge-${typeBadge}">${g.type}</span></td>
			<td>${esc(g.owner)}</td>
			<td>${g.members}</td>
			<td>${g.libraryEditing}</td>
			<td class="btn-group">
				<button class="btn btn-sm" onclick="editGroup(${g.groupID},'${esc(g.name)}','${g.type}','${g.libraryReading}','${g.libraryEditing}','${esc(g.description || '')}')">Settings</button>
				<button class="btn btn-sm" onclick="showMembers(${g.groupID},'${esc(g.name)}')">Members</button>
				<button class="btn btn-sm btn-danger" onclick="deleteGroup(${g.groupID},'${esc(g.name)}')">Delete</button>
			</td>
		</tr>`;
	}).join('');
	document.getElementById('group-count').textContent = `${groups.length} group(s)`;
}

function showAddGroup() {
	['group-name','group-owner','group-desc'].forEach(id => document.getElementById(id).value = '');
	showModal('modal-add-group');
	document.getElementById('group-name').focus();
}

async function addGroup() {
	try {
		await api('group.create', {body: {
			name: document.getElementById('group-name').value,
			owner: document.getElementById('group-owner').value,
			type: document.getElementById('group-type').value,
			libraryEditing: document.getElementById('group-editing').value,
			libraryReading: document.getElementById('group-reading').value,
			description: document.getElementById('group-desc').value
		}});
		closeModal('modal-add-group');
		toast('Group created');
		loadGroups(); loadStats();
	} catch(e) { toast(e.message, 'error'); }
}

async function deleteGroup(groupID, name) {
	if (!confirm(`Delete group "${name}"? All group data will be lost.`)) return;
	try {
		await api('group.delete', {params: {groupID}});
		toast('Group deleted');
		loadGroups(); loadStats();
	} catch(e) { toast(e.message, 'error'); }
}

async function showMembers(groupID, name) {
	currentGroupID = groupID;
	document.getElementById('members-group-name').textContent = name;
	document.getElementById('add-member-username').value = '';
	await refreshMembers();
	showModal('modal-group-members');
}

async function refreshMembers() {
	const members = await api('group.members', {params: {groupID: currentGroupID}});
	const el = document.getElementById('members-list');
	if (!members.length) { el.innerHTML = '<div style="color:var(--text-muted)">No members</div>'; return; }
	el.innerHTML = '<div class="member-list">' + members.map(m =>
		`<span class="member-chip">
			<strong>${esc(m.username)}</strong>
			<span class="badge badge-${m.role}">${m.role}</span>
			${m.role !== 'owner' ? `<span class="remove" onclick="removeMember(${m.userID},'${esc(m.username)}')">&times;</span>` : ''}
		</span>`
	).join('') + '</div>';
}

async function addMember() {
	try {
		await api('group.adduser', {body: {
			groupID: currentGroupID,
			username: document.getElementById('add-member-username').value,
			role: document.getElementById('add-member-role').value
		}});
		document.getElementById('add-member-username').value = '';
		toast('Member added');
		refreshMembers(); loadGroups();
	} catch(e) { toast(e.message, 'error'); }
}

async function removeMember(userID, username) {
	if (!confirm(`Remove ${username} from this group?`)) return;
	try {
		await api('group.removeuser', {body: {groupID: currentGroupID, userID}});
		toast('Member removed');
		refreshMembers(); loadGroups();
	} catch(e) { toast(e.message, 'error'); }
}

function editGroup(groupID, name, type, reading, editing, desc) {
	document.getElementById('edit-grp-id').value = groupID;
	document.getElementById('edit-grp-name').value = name;
	document.getElementById('edit-grp-type').value = type;
	document.getElementById('edit-grp-reading').value = reading;
	document.getElementById('edit-grp-editing').value = editing;
	document.getElementById('edit-grp-desc').value = desc;
	showModal('modal-edit-group');
}

async function saveGroupSettings() {
	const groupID = document.getElementById('edit-grp-id').value;
	const name = document.getElementById('edit-grp-name').value.trim();
	if (!name) { toast('Group name is required.', 'error'); return; }
	try {
		await api('group.update', {body: {
			groupID: parseInt(groupID),
			name,
			type: document.getElementById('edit-grp-type').value,
			libraryReading: document.getElementById('edit-grp-reading').value,
			libraryEditing: document.getElementById('edit-grp-editing').value,
			description: document.getElementById('edit-grp-desc').value
		}});
		toast('Group settings updated');
		closeModal('modal-edit-group');
		loadGroups();
	} catch(e) { toast(e.message, 'error'); }
}

// ── Keys ──────────────────────────────────────────────────────────
let currentKeyUserID = null;

function renderKeyUserSelect() {
	const el = document.getElementById('key-user-select');
	el.innerHTML = `<div style="position:relative;max-width:300px">
		<input id="key-user-search" type="text" placeholder="Search users..." autocomplete="off"
			style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:14px"
			onfocus="showUserDropdown()" oninput="filterUserDropdown()">
		<div id="key-user-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:var(--card);border:1px solid var(--border);border-top:none;border-radius:0 0 6px 6px;box-shadow:var(--shadow);z-index:50"></div>
	</div>`;
	document.addEventListener('click', function(e) {
		if (!e.target.closest('#key-user-select')) document.getElementById('key-user-dropdown').style.display = 'none';
	});
}

function showUserDropdown() { filterUserDropdown(); document.getElementById('key-user-dropdown').style.display = 'block'; }

function filterUserDropdown() {
	const q = document.getElementById('key-user-search').value.toLowerCase();
	const dd = document.getElementById('key-user-dropdown');
	const filtered = allUsers.filter(u => u.username.toLowerCase().includes(q));
	if (!filtered.length) { dd.innerHTML = '<div style="padding:8px 12px;color:var(--text-muted)">No matches</div>'; return; }
	dd.innerHTML = filtered.map(u =>
		`<div style="padding:8px 12px;cursor:pointer;font-size:14px" onmousedown="selectKeyUser(${u.userID},'${esc(u.username)}')"
			onmouseenter="this.style.background='#f0f0f0'" onmouseleave="this.style.background=''">${esc(u.username)} <span style="color:var(--text-muted)">(ID: ${u.userID})</span></div>`
	).join('');
}

function selectKeyUser(userID, username) {
	document.getElementById('key-user-search').value = username;
	document.getElementById('key-user-dropdown').style.display = 'none';
	loadKeys(userID);
}

async function loadKeys(userID) {
	currentKeyUserID = userID;
	const keys = await api('key.list', {params: {userID}});
	const tbody = document.getElementById('key-table');
	const username = allUsers.find(u => u.userID == userID)?.username || '';
	if (!keys.length) {
		tbody.innerHTML = `<tr class="empty-row"><td colspan="6">No keys for ${esc(username)}</td></tr>`;
		return;
	}
	tbody.innerHTML = keys.map(k => `<tr>
		<td>${k.keyID}</td>
		<td><code style="font-size:12px">${k.key}</code></td>
		<td>${esc(k.name)}</td>
		<td>${k.dateAdded}</td>
		<td>${k.lastUsed}</td>
		<td class="btn-group">
			<button class="btn btn-sm btn-danger" onclick="deleteKey(${k.keyID},'${k.key}')">Delete</button>
		</td>
	</tr>`).join('');

	// Add "New Key" row
	tbody.innerHTML += `<tr><td colspan="6" style="text-align:center;padding:12px">
		<button class="btn btn-sm btn-primary" onclick="createKey(${userID})">+ New Key for ${esc(username)}</button>
	</td></tr>`;
}

async function createKey(userID) {
	try {
		const res = await api('key.create', {body: {userID, name: 'Full Access Key'}});
		toast('Key created: ' + res.key);
		loadKeys(userID); loadStats();
	} catch(e) { toast(e.message, 'error'); }
}

async function deleteKey(keyID, key) {
	if (!confirm(`Delete key ${key}?`)) return;
	try {
		await api('key.delete', {params: {keyID}});
		toast('Key deleted');
		if (currentKeyUserID) loadKeys(currentKeyUserID);
		loadStats();
	} catch(e) { toast(e.message, 'error'); }
}

// ── Helpers ───────────────────────────────────────────────────────
function esc(s) {
	const d = document.createElement('div');
	d.textContent = s;
	return d.innerHTML;
}

// ── Admin Logout ──────────────────────────────────────────────
function adminLogout() {
	const xhr = new XMLHttpRequest();
	xhr.open('GET', 'admin.php', true);
	xhr.setRequestHeader('Authorization', 'Basic ' + btoa('_logout:_logout'));
	xhr.onreadystatechange = function() {
		if (xhr.readyState === 4) window.location.href = '/';
	};
	xhr.send();
}

// ── Admin Password ────────────────────────────────────────────
async function changeAdminPassword() {
	const current = document.getElementById('admin-current-pw').value;
	const newPw = document.getElementById('admin-new-pw').value;
	const confirmPw = document.getElementById('admin-confirm-pw').value;
	if (!current || !newPw) { toast('Please fill in all fields', 'error'); return; }
	if (newPw !== confirmPw) { toast('New passwords do not match', 'error'); return; }
	try {
		await api('admin.passwd', {body: {current, password: newPw}});
		closeModal('modal-admin-passwd');
		['admin-current-pw','admin-new-pw','admin-confirm-pw'].forEach(id => document.getElementById(id).value = '');
		toast('Admin password updated. Reloading...');
		// Clear cached Basic Auth and force re-login with new password
		setTimeout(() => {
			const xhr = new XMLHttpRequest();
			xhr.open('GET', 'admin.php', true);
			xhr.setRequestHeader('Authorization', 'Basic ' + btoa('_logout:_logout'));
			xhr.onreadystatechange = function() {
				if (xhr.readyState === 4) window.location.reload();
			};
			xhr.send();
		}, 1500);
	} catch(e) { toast(e.message, 'error'); }
}

// ── Init ──────────────────────────────────────────────────────────
loadStats();
loadUsers();
loadGroups();
</script>
</body>
</html>
