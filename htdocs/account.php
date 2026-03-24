<?php
/*
 * Zotero Self-Hosted User Account Page
 * Lets users manage their own account: password, groups, API keys.
 * Authenticated via HTTP Basic Auth against the www users table.
 */

set_include_path("../include");
require_once("header.inc.php");

// ── Auth ──────────────────────────────────────────────────────────────
$realm = 'Zotero Account';
$currentUserID = null;
$currentUsername = null;

// Handle logout: if ?action=logout, always return 401 to clear cached credentials
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
	header('WWW-Authenticate: Basic realm="' . $realm . '"');
	header('HTTP/1.0 401 Unauthorized');
	echo 'Logged out. <a href="account.php">Log in again</a>';
	exit;
}

if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
	header('WWW-Authenticate: Basic realm="' . $realm . '"');
	header('HTTP/1.0 401 Unauthorized');
	echo 'Please log in with your Zotero username and password.';
	exit;
}

$authResult = Zotero_Users::authenticate('password', [
	'username' => $_SERVER['PHP_AUTH_USER'],
	'password' => $_SERVER['PHP_AUTH_PW']
]);

if (!$authResult) {
	header('WWW-Authenticate: Basic realm="' . $realm . '"');
	header('HTTP/1.0 401 Unauthorized');
	echo 'Invalid username or password.';
	exit;
}

$currentUserID = (int) $authResult;
$currentUsername = Zotero_Users::getUsername($currentUserID, true);

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

		// ── Profile ───────────────────────────────────────────────
		case 'profile':
			$user = Zotero_DB::rowQuery(
				"SELECT u.userID, u.username, u.libraryID, w.email, w.dateAdded
				 FROM zotero_master.users u
				 LEFT JOIN $wwwDB.users w ON u.userID = w.userID
				 WHERE u.userID=?", [$currentUserID]
			);
			$itemCount = 0;
			$collCount = 0;
			try {
				$libraryID = $user['libraryID'];
				$shardID = Zotero_Shards::getByLibraryID($libraryID);
				$itemCount = (int) Zotero_DB::valueQuery(
					"SELECT COUNT(*) FROM items JOIN shardLibraries USING (libraryID) WHERE libraryID=?",
					[$libraryID], $shardID
				);
				$collCount = (int) Zotero_DB::valueQuery(
					"SELECT COUNT(*) FROM collections WHERE libraryID=?",
					[$libraryID], $shardID
				);
			} catch (Exception $e) {}
			$user['items'] = $itemCount;
			$user['collections'] = $collCount;
			jsonResponse($user);

		case 'passwd':
			$input = json_decode(file_get_contents('php://input'), true);
			$currentPass = $input['currentPassword'] ?? '';
			$newPass = $input['newPassword'] ?? '';
			if (!$currentPass || !$newPass) jsonResponse(['error' => 'Both current and new password required.'], 400);
			if (strlen($newPass) < 6) jsonResponse(['error' => 'New password must be at least 6 characters.'], 400);

			// Verify current password
			$verify = Zotero_Users::authenticate('password', [
				'username' => $currentUsername,
				'password' => $currentPass
			]);
			if (!$verify) jsonResponse(['error' => 'Current password is incorrect.'], 403);

			$hash = sha1(Z_CONFIG::$AUTH_SALT . $newPass);
			Zotero_DB::query("UPDATE $wwwDB.users SET password=?, dateModified=NOW() WHERE userID=?", [$hash, $currentUserID]);

			// Clear old auth cache
			Z_Core::$MC->delete('userAuthHash_' . hash('sha256', $currentUsername . $currentPass));

			jsonResponse(['ok' => true]);

		case 'email':
			$input = json_decode(file_get_contents('php://input'), true);
			$email = trim($input['email'] ?? '');
			if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['error' => 'Valid email required.'], 400);
			Zotero_DB::query("UPDATE $wwwDB.users SET email=?, dateModified=NOW() WHERE userID=?", [$email, $currentUserID]);
			jsonResponse(['ok' => true]);

		// ── Groups ────────────────────────────────────────────────
		case 'group.list':
			$rows = Zotero_DB::query(
				"SELECT g.groupID, g.name, g.type, g.libraryEditing, g.libraryReading,
				        g.description, g.dateAdded, gu.role,
				        (SELECT COUNT(*) FROM zotero_master.groupUsers gu2 WHERE gu2.groupID=g.groupID) AS members,
				        (SELECT u2.username FROM zotero_master.groupUsers gu3
				         JOIN zotero_master.users u2 ON gu3.userID=u2.userID
				         WHERE gu3.groupID=g.groupID AND gu3.role='owner') AS owner
				 FROM zotero_master.groupUsers gu
				 JOIN zotero_master.groups g ON gu.groupID=g.groupID
				 WHERE gu.userID=?
				 ORDER BY g.groupID", [$currentUserID]
			);
			jsonResponse($rows ?: []);

		case 'group.create':
			$input = json_decode(file_get_contents('php://input'), true);
			$name = trim($input['name'] ?? '');
			$type = $input['type'] ?? 'Private';
			$editing = $input['libraryEditing'] ?? 'members';
			$reading = $input['libraryReading'] ?? 'members';
			$desc = trim($input['description'] ?? '');
			if (!$name) jsonResponse(['error' => 'Group name is required.'], 400);

			$group = new Zotero_Group;
			$group->ownerUserID = $currentUserID;
			$group->name = $name;
			$group->type = $type;
			$group->libraryEditing = $editing;
			$group->libraryReading = $reading;
			$group->fileEditing = 'none';
			$group->description = $desc;
			$group->url = '';
			$group->hasImage = false;
			$group->save();

			jsonResponse(['groupID' => $group->id, 'libraryID' => $group->libraryID], 201);

		case 'group.leave':
			$groupID = (int)($_GET['groupID'] ?? 0);
			$group = Zotero_Groups::get($groupID);
			if (!$group) jsonResponse(['error' => 'Group not found.'], 404);
			if (!$group->hasUser($currentUserID)) jsonResponse(['error' => 'You are not in this group.'], 400);
			// Owners can't leave, they must delete
			$role = Zotero_DB::valueQuery(
				"SELECT role FROM zotero_master.groupUsers WHERE groupID=? AND userID=?",
				[$groupID, $currentUserID]
			);
			if ($role === 'owner') jsonResponse(['error' => 'Owners cannot leave. Delete the group instead or transfer ownership via admin.'], 400);
			$group->removeUser($currentUserID);
			jsonResponse(['ok' => true]);

		case 'group.delete':
			$groupID = (int)($_GET['groupID'] ?? 0);
			$group = Zotero_Groups::get($groupID);
			if (!$group) jsonResponse(['error' => 'Group not found.'], 404);
			// Only owner can delete
			$role = Zotero_DB::valueQuery(
				"SELECT role FROM zotero_master.groupUsers WHERE groupID=? AND userID=?",
				[$groupID, $currentUserID]
			);
			if ($role !== 'owner') jsonResponse(['error' => 'Only the group owner can delete it.'], 403);
			$group->erase();
			jsonResponse(['ok' => true]);

		case 'group.members':
			$groupID = (int)($_GET['groupID'] ?? 0);
			// Check user is a member
			$group = Zotero_Groups::get($groupID);
			if (!$group || !$group->hasUser($currentUserID)) jsonResponse(['error' => 'Access denied.'], 403);
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

			// Only owner/admin can add members
			$myRole = Zotero_DB::valueQuery(
				"SELECT role FROM zotero_master.groupUsers WHERE groupID=? AND userID=?",
				[$groupID, $currentUserID]
			);
			if (!in_array($myRole, ['owner', 'admin'])) jsonResponse(['error' => 'Only owners and admins can add members.'], 403);

			$group = Zotero_Groups::get($groupID);
			if (!$group) jsonResponse(['error' => 'Group not found.'], 404);
			$userID = Zotero_DB::valueQuery("SELECT userID FROM zotero_master.users WHERE username=?", $username);
			if (!$userID) jsonResponse(['error' => "User '$username' not found."], 404);

			if ($group->hasUser((int)$userID)) {
				if ($myRole === 'owner') $group->updateUser((int)$userID, $role);
				else jsonResponse(['error' => 'User is already a member. Only owners can change roles.'], 400);
			} else {
				$group->addUser((int)$userID, $role);
			}
			jsonResponse(['ok' => true]);

		case 'group.removeuser':
			$input = json_decode(file_get_contents('php://input'), true);
			$groupID = (int)($input['groupID'] ?? 0);
			$userID = (int)($input['userID'] ?? 0);

			$myRole = Zotero_DB::valueQuery(
				"SELECT role FROM zotero_master.groupUsers WHERE groupID=? AND userID=?",
				[$groupID, $currentUserID]
			);
			if (!in_array($myRole, ['owner', 'admin'])) jsonResponse(['error' => 'Only owners and admins can remove members.'], 403);

			$targetRole = Zotero_DB::valueQuery(
				"SELECT role FROM zotero_master.groupUsers WHERE groupID=? AND userID=?",
				[$groupID, $userID]
			);
			if ($targetRole === 'owner') jsonResponse(['error' => 'Cannot remove the owner.'], 400);
			if ($myRole === 'admin' && $targetRole === 'admin') jsonResponse(['error' => 'Admins cannot remove other admins.'], 400);

			$group = Zotero_Groups::get($groupID);
			$group->removeUser($userID);
			jsonResponse(['ok' => true]);

		case 'group.update':
			$input = json_decode(file_get_contents('php://input'), true);
			$groupID = (int)($input['groupID'] ?? 0);
			$group = Zotero_Groups::get($groupID);
			if (!$group) jsonResponse(['error' => 'Group not found.'], 404);
			$role = Zotero_DB::valueQuery(
				"SELECT role FROM zotero_master.groupUsers WHERE groupID=? AND userID=?",
				[$groupID, $currentUserID]
			);
			if ($role !== 'owner') jsonResponse(['error' => 'Only the group owner can edit settings.'], 403);

			if (isset($input['name'])) $group->name = trim($input['name']);
			if (isset($input['type'])) $group->type = $input['type'];
			if (isset($input['libraryEditing'])) $group->libraryEditing = $input['libraryEditing'];
			if (isset($input['libraryReading'])) $group->libraryReading = $input['libraryReading'];
			if (isset($input['description'])) $group->description = trim($input['description']);
			$group->save();
			jsonResponse(['ok' => true]);

		// ── Keys ──────────────────────────────────────────────────
		case 'key.list':
			$rows = Zotero_DB::query(
				"SELECT keyID, `key`, name, dateAdded, lastUsed FROM zotero_master.`keys` WHERE userID=? ORDER BY keyID",
				[$currentUserID]
			);
			jsonResponse($rows ?: []);

		case 'key.create':
			$input = json_decode(file_get_contents('php://input'), true);
			$name = trim($input['name'] ?? 'My API Key');
			$libraryID = Zotero_DB::valueQuery("SELECT libraryID FROM zotero_master.users WHERE userID=?", [$currentUserID]);

			$key = generateKey();
			Zotero_DB::query("INSERT INTO zotero_master.`keys` (`key`, userID, name, dateAdded, lastUsed) VALUES (?, ?, ?, NOW(), NOW())", [$key, $currentUserID, $name]);
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
			// Verify this key belongs to the current user
			$owner = Zotero_DB::valueQuery("SELECT userID FROM zotero_master.`keys` WHERE keyID=?", [$keyID]);
			if ((int)$owner !== $currentUserID) jsonResponse(['error' => 'Not your key.'], 403);
			Zotero_DB::query("DELETE FROM zotero_master.keyPermissions WHERE keyID=?", [$keyID]);
			Zotero_DB::query("DELETE FROM zotero_master.`keys` WHERE keyID=?", [$keyID]);
			jsonResponse(['ok' => true]);

		// ── All users (for group member picker) ───────────────────
		case 'users':
			$rows = Zotero_DB::query("SELECT userID, username FROM zotero_master.users ORDER BY username");
			jsonResponse($rows ?: []);

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
<title>Zotero | Settings</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.5; background: #fff; }
a { color: #38c; text-decoration: none; }
a:hover { text-decoration: underline; }

/* ── Unified Nav (zotero.org style) ──────────────────────────────── */
.site-nav { background: #fff; border-bottom: 1px solid #ddd; padding: 0 24px; display: flex; align-items: center; height: 56px; }
.site-nav .logo { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 26px; font-weight: 300; letter-spacing: -0.5px; text-decoration: none; margin-right: 32px; }
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

/* ── Content ─────────────────────────────────────────────────────── */
.content { max-width: 980px; margin: 0 auto; padding: 20px; }
.breadcrumb { font-size: 12px; color: #999; margin-bottom: 16px; }
.breadcrumb a { color: #38c; }
h2 { font-size: 24px; font-weight: normal; color: #333; margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
h3 { font-size: 16px; font-weight: bold; color: #333; margin: 24px 0 12px; }
h3:first-child { margin-top: 0; }

/* ── Sub-nav ─────────────────────────────────────────────────────── */
.sub-nav { margin-bottom: 24px; font-size: 13px; }
.sub-nav a { color: #38c; margin-right: 6px; }
.sub-nav .sep { color: #999; margin: 0 2px; }

/* ── Page tabs ───────────────────────────────────────────────────── */
.page-tabs { display: flex; gap: 0; border-bottom: 2px solid #ddd; margin-bottom: 24px; }
.page-tab { padding: 10px 20px; font-size: 13px; font-weight: bold; color: #666; cursor: pointer; border: 1px solid transparent; border-bottom: 2px solid transparent; margin-bottom: -2px; background: none; transition: all .15s; }
.page-tab:hover { color: #333; }
.page-tab.active { color: #333; border-color: #ddd #ddd #fff; background: #fff; border-bottom-color: #fff; border-top: 2px solid #900; }

/* ── Panels ──────────────────────────────────────────────────────── */
.panel { display: none; }
.panel.active { display: block; }

/* ── Profile section ─────────────────────────────────────────────── */
.profile-grid { display: grid; grid-template-columns: 140px 1fr; gap: 8px 16px; font-size: 13px; margin-bottom: 20px; }
.profile-grid .label { color: #666; text-align: right; font-weight: bold; }
.profile-grid .value { color: #333; }

/* ── Tables ──────────────────────────────────────────────────────── */
table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 16px; }
th { text-align: left; padding: 8px 10px; background: #f5f5f5; border: 1px solid #ddd; font-weight: bold; font-size: 12px; color: #555; }
td { padding: 8px 10px; border: 1px solid #eee; vertical-align: middle; }
tr:hover td { background: #fafafa; }
.empty-row td { text-align: center; color: #999; padding: 20px; font-style: italic; }

/* ── Buttons ─────────────────────────────────────────────────────── */
.btn { display: inline-block; padding: 5px 14px; font-size: 12px; font-weight: bold; border: 1px solid #bbb; border-radius: 3px; background: linear-gradient(to bottom, #fafafa, #e6e6e6); color: #333; cursor: pointer; text-decoration: none; }
.btn:hover { background: linear-gradient(to bottom, #fff, #eee); text-decoration: none; }
.btn-red { background: linear-gradient(to bottom, #d44, #b22); border-color: #900; color: #fff; }
.btn-red:hover { background: linear-gradient(to bottom, #e55, #c33); }
.btn-sm { padding: 3px 10px; font-size: 11px; }
.btn-link { background: none; border: none; color: #38c; font-weight: normal; padding: 0; cursor: pointer; font-size: 12px; }
.btn-link:hover { text-decoration: underline; }
.btn-group { display: inline-flex; gap: 4px; }

/* ── Group type cards (matching Zotero style) ────────────────────── */
.type-cards { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin: 16px 0 20px; }
.type-card { border: 1px solid #ddd; padding: 16px; cursor: pointer; transition: border-color .15s, background .15s; }
.type-card:hover { border-color: #999; }
.type-card.selected { border-color: #900; background: #fef8f8; }
.type-card h4 { font-size: 14px; margin-bottom: 8px; }
.type-card p { font-size: 12px; color: #666; margin-bottom: 10px; line-height: 1.4; }
.type-card input[type="radio"] { margin-right: 4px; }
.type-card label { font-size: 12px; cursor: pointer; }

/* ── Forms ────────────────────────────────────────────────────────── */
.form-row { margin-bottom: 12px; }
.form-row label { display: block; font-weight: bold; margin-bottom: 4px; }
.form-row .hint { font-size: 11px; color: #999; margin-bottom: 4px; }
input[type="text"], input[type="password"], input[type="email"], select, textarea {
	padding: 6px 8px; border: 1px solid #ccc; border-radius: 3px; font-size: 13px; font-family: inherit; width: 100%; max-width: 400px;
}
input:focus, select:focus, textarea:focus { outline: none; border-color: #69c; box-shadow: 0 0 4px rgba(102,153,204,.3); }
textarea { height: 60px; resize: vertical; }

/* ── Badges ──────────────────────────────────────────────────────── */
.role-badge { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
.role-owner { background: #ffc; color: #860; border: 1px solid #da6; }
.role-admin { background: #def; color: #048; border: 1px solid #8bf; }
.role-member { background: #eee; color: #555; border: 1px solid #ccc; }

/* ── Key display ─────────────────────────────────────────────────── */
.key-mono { font-family: "SF Mono", "Menlo", "Monaco", monospace; font-size: 12px; background: #f5f5f5; padding: 3px 6px; border: 1px solid #ddd; user-select: all; }
.key-display-big { font-family: "SF Mono", "Menlo", "Monaco", monospace; font-size: 16px; background: #f5f5f5; padding: 12px 16px; border: 1px solid #ddd; margin: 12px 0; user-select: all; word-break: break-all; letter-spacing: .5px; }

/* ── Members ─────────────────────────────────────────────────────── */
.member-row { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px solid #eee; font-size: 13px; }
.member-row:last-child { border-bottom: none; }
.member-row .name { font-weight: bold; min-width: 100px; }
.member-row .remove-link { color: #c00; font-size: 11px; cursor: pointer; }
.member-row .remove-link:hover { text-decoration: underline; }

/* ── Modal ────────────────────────────────────────────────────────── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 100; align-items: center; justify-content: center; }
.modal-overlay.show { display: flex; }
.modal { background: #fff; border: 1px solid #999; padding: 20px 24px; width: 460px; max-width: 90vw; box-shadow: 0 4px 20px rgba(0,0,0,.2); }
.modal h3 { font-size: 16px; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid #ddd; }
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; padding-top: 12px; border-top: 1px solid #eee; }

/* ── Toast ────────────────────────────────────────────────────────── */
.toast { position: fixed; top: 20px; right: 20px; padding: 10px 18px; border-radius: 3px; color: #fff; font-size: 13px; font-weight: bold; z-index: 200; opacity: 0; transition: opacity .3s; pointer-events: none; }
.toast.show { opacity: 1; }
.toast-success { background: #4a4; }
.toast-error { background: #c44; }

/* ── Footer ──────────────────────────────────────────────────────── */
.footer { background: #404040; color: #999; padding: 16px 0; margin-top: 40px; text-align: center; font-size: 12px; }
.footer a { color: #ccc; margin: 0 6px; }
</style>
</head>
<body>

<!-- Unified Nav -->
<nav class="site-nav">
	<a href="/" class="logo"><span class="z">z</span><span class="rest">otero</span></a>
	<div class="nav-links">
		<a href="/library/">Web Library</a>
		<a href="/account.php" class="active">Account</a>
		<a href="/admin.php">Admin</a>
	</div>
	<div class="nav-right">
		<span class="nav-user"><?= htmlspecialchars($currentUsername) ?></span>
		<button class="nav-logout" onclick="logout();return false;">Log Out</button>
	</div>
</nav>

<!-- Content -->
<div class="content">

	<div class="page-tabs">
		<button class="page-tab active" id="tab-settings" onclick="showTab('settings')">Settings</button>
		<button class="page-tab" id="tab-groups" onclick="showTab('groups')">Groups</button>
		<button class="page-tab" id="tab-keys" onclick="showTab('keys')">API Keys</button>
	</div>

	<!-- ══ Settings Panel ══════════════════════════════════════════ -->
	<div class="panel active" id="panel-settings">
		<div class="breadcrumb"><a href="/">Home</a> &gt; Settings</div>
		<h2>Account Settings</h2>

		<h3>Profile</h3>
		<div class="profile-grid">
			<div class="label">Username</div><div class="value" id="p-username">&mdash;</div>
			<div class="label">Email</div><div class="value" id="p-email">&mdash;</div>
			<div class="label">Library Items</div><div class="value" id="p-items">&mdash;</div>
			<div class="label">Collections</div><div class="value" id="p-collections">&mdash;</div>
			<div class="label">Member Since</div><div class="value" id="p-since">&mdash;</div>
		</div>

		<h3>Change Email</h3>
		<div class="form-row">
			<label>New Email Address</label>
			<input type="email" id="new-email" style="max-width:300px">
		</div>
		<button class="btn" onclick="changeEmail()">Update Email</button>

		<h3>Change Password</h3>
		<div class="form-row"><label>Current Password</label><input type="password" id="current-pass" style="max-width:300px"></div>
		<div class="form-row"><label>New Password</label><input type="password" id="new-pass" style="max-width:300px"></div>
		<div class="form-row"><label>Confirm New Password</label><input type="password" id="confirm-pass" style="max-width:300px"></div>
		<button class="btn" onclick="changePassword()">Update Password</button>
	</div>

	<!-- ══ Groups Panel ═══════════════════════════════════════════ -->
	<div class="panel" id="panel-groups">
		<div class="breadcrumb"><a href="/">Home</a> &gt; Groups</div>
		<h2>Groups</h2>

		<div class="sub-nav">
			<a href="#" onclick="showGroupList();return false;">My Groups</a>
			<span class="sep">&middot;</span>
			<a href="#" onclick="showNewGroup();return false;">Create a New Group</a>
		</div>

		<!-- Group list view -->
		<div id="group-list-view">
			<table>
				<thead><tr><th>Group Name</th><th>Type</th><th>Your Role</th><th>Members</th><th></th></tr></thead>
				<tbody id="group-table"><tr class="empty-row"><td colspan="5">Loading...</td></tr></tbody>
			</table>
		</div>

		<!-- New group view -->
		<div id="group-new-view" style="display:none">
			<h3 style="margin-top:0">Create a New Group</h3>

			<div class="form-row">
				<label>Group Name</label>
				<div class="hint">Choose a name for your group</div>
				<input type="text" id="grp-name" style="max-width:500px">
			</div>

			<label style="font-weight:bold;display:block;margin-bottom:8px">Group Type</label>
			<div class="type-cards">
				<div class="type-card selected" onclick="selectType(this,'PublicOpen')">
					<h4>Public, Open Membership</h4>
					<p>Anyone can view your group online and join the group instantly.</p>
					<label><input type="radio" name="grp-type" value="PublicOpen" checked> Choose a Public, Open Membership</label>
				</div>
				<div class="type-card" onclick="selectType(this,'PublicClosed')">
					<h4>Public, Closed Membership</h4>
					<p>Anyone can view your group online, but members must apply or be invited.</p>
					<label><input type="radio" name="grp-type" value="PublicClosed"> Choose Public, Closed Membership</label>
				</div>
				<div class="type-card" onclick="selectType(this,'Private')">
					<h4>Private Membership</h4>
					<p>Only members can view your group online and must be invited to join.</p>
					<label><input type="radio" name="grp-type" value="Private"> Choose Private Membership</label>
				</div>
			</div>

			<div class="form-row">
				<label>Library Editing</label>
				<select id="grp-editing" style="max-width:200px"><option value="members">All Members</option><option value="admins">Admins Only</option></select>
			</div>

			<div class="form-row">
				<label>Description <span style="font-weight:normal;color:#999">(optional)</span></label>
				<textarea id="grp-desc" style="max-width:500px"></textarea>
			</div>

			<button class="btn btn-red" onclick="createGroup()">Create Group</button>
		</div>
	</div>

	<!-- ══ Keys Panel ════════════════════════════════════════════ -->
	<div class="panel" id="panel-keys">
		<div class="breadcrumb"><a href="/">Home</a> &gt; Settings &gt; API Keys</div>
		<h2>API Keys</h2>

		<p style="margin-bottom:16px;color:#666">
			API keys allow the Zotero desktop client and third-party applications to access your Zotero library.
			Your main key was created automatically when your account was set up.
		</p>

		<table>
			<thead><tr><th>Key</th><th>Name</th><th>Created</th><th>Last Used</th><th></th></tr></thead>
			<tbody id="key-table"><tr class="empty-row"><td colspan="5">Loading...</td></tr></tbody>
		</table>

		<button class="btn" onclick="showCreateKey()">Create New Private Key</button>
	</div>

</div>

<!-- Footer -->
<div class="footer">
	Self-Hosted Zotero Server
</div>

<!-- ══ Modals ══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-members">
	<div class="modal" style="width:500px">
		<h3>Group Members &mdash; <span id="members-group-name"></span></h3>
		<div id="members-list"></div>
		<div id="members-add-section" style="display:none;margin-top:16px;padding-top:12px;border-top:1px solid #eee">
			<label style="font-weight:bold;font-size:12px;display:block;margin-bottom:6px">Add Member</label>
			<div style="display:flex;gap:8px">
				<input type="text" id="add-member-name" placeholder="Username" style="flex:1;max-width:200px">
				<select id="add-member-role" style="max-width:120px"><option value="member">Member</option><option value="admin">Admin</option></select>
				<button class="btn btn-sm" onclick="addMember()">Add</button>
			</div>
		</div>
		<div class="modal-actions"><button class="btn" onclick="closeModal('modal-members')">Close</button></div>
	</div>
</div>

<div class="modal-overlay" id="modal-edit-group">
	<div class="modal" style="width:520px">
		<h3>Group Settings</h3>
		<input type="hidden" id="edit-grp-id">
		<div class="form-row"><label>Group Name</label><input type="text" id="edit-grp-name"></div>
		<div class="form-row">
			<label>Group Type</label>
			<select id="edit-grp-type">
				<option value="PublicOpen">Public, Open Membership</option>
				<option value="PublicClosed">Public, Closed Membership</option>
				<option value="Private">Private</option>
			</select>
		</div>
		<div class="form-row">
			<label>Library Reading</label>
			<select id="edit-grp-reading">
				<option value="all">Anyone</option>
				<option value="members">Members Only</option>
			</select>
		</div>
		<div class="form-row">
			<label>Library Editing</label>
			<select id="edit-grp-editing">
				<option value="members">All Members</option>
				<option value="admins">Admins Only</option>
			</select>
		</div>
		<div class="form-row"><label>Description</label><textarea id="edit-grp-desc" style="height:60px"></textarea></div>
		<div class="modal-actions">
			<button class="btn" onclick="closeModal('modal-edit-group')">Cancel</button>
			<button class="btn btn-red" onclick="saveGroupSettings()">Save Changes</button>
		</div>
	</div>
</div>

<div class="modal-overlay" id="modal-create-key">
	<div class="modal">
		<h3>Create New API Key</h3>
		<div class="form-row"><label>Key Description</label><input type="text" id="key-name" value="My API Key"></div>
		<div class="modal-actions">
			<button class="btn" onclick="closeModal('modal-create-key')">Cancel</button>
			<button class="btn btn-red" onclick="createKey()">Create Key</button>
		</div>
	</div>
</div>

<div class="modal-overlay" id="modal-key-created">
	<div class="modal">
		<h3>Key Created</h3>
		<p>Your new API key has been created. Copy it now &mdash; it will not be shown again.</p>
		<div class="key-display-big" id="created-key"></div>
		<div class="modal-actions"><button class="btn" onclick="closeModal('modal-key-created')">Done</button></div>
	</div>
</div>

<div class="toast" id="toast"></div>

<script>
const API = 'account.php';
const currentUserID = <?= $currentUserID ?>;
let currentGroupID = null;
let myRoleInCurrentGroup = null;
let selectedGroupType = 'PublicOpen';

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

function showModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// ── Tabs ──────────────────────────────────────────────────────────
function showTab(name) {
	document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
	document.getElementById('panel-' + name).classList.add('active');
	document.querySelectorAll('.page-tab').forEach(t => t.classList.remove('active'));
	const tab = document.getElementById('tab-' + name);
	if (tab) tab.classList.add('active');
	history.replaceState(null, '', 'account.php?tab=' + name);
}

function showGroupList() {
	document.getElementById('group-list-view').style.display = '';
	document.getElementById('group-new-view').style.display = 'none';
}

function showNewGroup() {
	document.getElementById('group-list-view').style.display = 'none';
	document.getElementById('group-new-view').style.display = '';
	document.getElementById('grp-name').value = '';
	document.getElementById('grp-desc').value = '';
	document.getElementById('grp-name').focus();
}

function selectType(el, type) {
	selectedGroupType = type;
	document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
	el.classList.add('selected');
	el.querySelector('input[type=radio]').checked = true;
}

// ── Profile ───────────────────────────────────────────────────────
async function loadProfile() {
	const p = await api('profile');
	document.getElementById('p-username').textContent = p.username;
	document.getElementById('p-email').textContent = p.email || 'Not set';
	document.getElementById('p-items').textContent = p.items;
	document.getElementById('p-collections').textContent = p.collections;
	document.getElementById('p-since').textContent = p.dateAdded || 'N/A';
}

async function changePassword() {
	const newPass = document.getElementById('new-pass').value;
	if (!newPass) { toast('Enter a new password.', 'error'); return; }
	if (newPass !== document.getElementById('confirm-pass').value) {
		toast('Passwords do not match.', 'error'); return;
	}
	try {
		await api('passwd', {body: {
			currentPassword: document.getElementById('current-pass').value,
			newPassword: newPass
		}});
		['current-pass','new-pass','confirm-pass'].forEach(id => document.getElementById(id).value = '');
		toast('Password updated successfully.');
	} catch(e) { toast(e.message, 'error'); }
}

async function changeEmail() {
	const email = document.getElementById('new-email').value;
	if (!email) { toast('Enter an email address.', 'error'); return; }
	try {
		await api('email', {body: {email}});
		document.getElementById('new-email').value = '';
		toast('Email updated');
		loadProfile();
	} catch(e) { toast(e.message, 'error'); }
}

// ── Groups ────────────────────────────────────────────────────────
async function loadGroups() {
	const groups = await api('group.list');
	const tbody = document.getElementById('group-table');
	if (!groups.length) {
		tbody.innerHTML = '<tr class="empty-row"><td colspan="5">You are not a member of any groups. <a href="#" onclick="showTab(\'groups\');showNewGroup();return false;">Create one</a>.</td></tr>';
		return;
	}
	tbody.innerHTML = groups.map(g => {
		let actions = `<button class="btn-link" onclick="showMembers(${g.groupID},'${esc(g.name)}','${g.role}')">Members</button>`;
		if (g.role === 'owner') {
			actions += ` &middot; <button class="btn-link" onclick="editGroup(${g.groupID},'${esc(g.name)}','${g.type}','${g.libraryReading}','${g.libraryEditing}','${esc(g.description || '')}')">Settings</button>`;
			actions += ` &middot; <button class="btn-link" style="color:#c00" onclick="deleteGroup(${g.groupID},'${esc(g.name)}')">Delete</button>`;
		} else {
			actions += ` &middot; <button class="btn-link" style="color:#c00" onclick="leaveGroup(${g.groupID},'${esc(g.name)}')">Leave</button>`;
		}
		return `<tr>
			<td><strong>${esc(g.name)}</strong>${g.description ? '<br><span style="color:#999;font-size:12px">' + esc(g.description) + '</span>' : ''}</td>
			<td>${g.type}</td>
			<td><span class="role-badge role-${g.role}">${g.role}</span></td>
			<td>${g.members}</td>
			<td>${actions}</td>
		</tr>`;
	}).join('');
}

async function createGroup() {
	const name = document.getElementById('grp-name').value.trim();
	if (!name) { toast('Enter a group name.', 'error'); return; }
	const reading = selectedGroupType === 'Private' ? 'members' : 'all';
	try {
		await api('group.create', {body: {
			name,
			type: selectedGroupType,
			libraryEditing: document.getElementById('grp-editing').value,
			libraryReading: reading,
			description: document.getElementById('grp-desc').value
		}});
		toast('Group created');
		showGroupList();
		loadGroups();
	} catch(e) { toast(e.message, 'error'); }
}

async function deleteGroup(groupID, name) {
	if (!confirm(`Delete group "${name}"? All group library data will be permanently lost.`)) return;
	try {
		await api('group.delete', {params: {groupID}});
		toast('Group deleted');
		loadGroups();
	} catch(e) { toast(e.message, 'error'); }
}

async function leaveGroup(groupID, name) {
	if (!confirm(`Leave group "${name}"?`)) return;
	try {
		await api('group.leave', {params: {groupID}});
		toast('Left group');
		loadGroups();
	} catch(e) { toast(e.message, 'error'); }
}

async function showMembers(groupID, name, myRole) {
	currentGroupID = groupID;
	myRoleInCurrentGroup = myRole;
	document.getElementById('members-group-name').textContent = name;
	document.getElementById('add-member-name').value = '';
	document.getElementById('members-add-section').style.display =
		(myRole === 'owner' || myRole === 'admin') ? 'block' : 'none';
	await refreshMembers();
	showModal('modal-members');
}

async function refreshMembers() {
	const members = await api('group.members', {params: {groupID: currentGroupID}});
	const el = document.getElementById('members-list');
	const canManage = myRoleInCurrentGroup === 'owner' || myRoleInCurrentGroup === 'admin';
	el.innerHTML = members.map(m => {
		const canRemove = canManage && m.role !== 'owner' &&
			!(myRoleInCurrentGroup === 'admin' && m.role === 'admin');
		return `<div class="member-row">
			<span class="name">${esc(m.username)}</span>
			<span class="role-badge role-${m.role}">${m.role}</span>
			${canRemove ? `<span class="remove-link" onclick="removeMember(${m.userID},'${esc(m.username)}')">remove</span>` : ''}
		</div>`;
	}).join('');
}

async function addMember() {
	try {
		await api('group.adduser', {body: {
			groupID: currentGroupID,
			username: document.getElementById('add-member-name').value,
			role: document.getElementById('add-member-role').value
		}});
		document.getElementById('add-member-name').value = '';
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
async function loadKeys() {
	const keys = await api('key.list');
	const tbody = document.getElementById('key-table');
	if (!keys.length) {
		tbody.innerHTML = '<tr class="empty-row"><td colspan="5">No API keys. Create one to sync your library.</td></tr>';
		return;
	}
	tbody.innerHTML = keys.map(k => `<tr>
		<td><code class="key-mono">${k.key}</code></td>
		<td>${esc(k.name)}</td>
		<td>${k.dateAdded}</td>
		<td>${k.lastUsed}</td>
		<td><button class="btn-link" style="color:#c00" onclick="deleteKey(${k.keyID},'${k.key}')">Delete</button></td>
	</tr>`).join('');
}

function showCreateKey() {
	document.getElementById('key-name').value = 'My API Key';
	showModal('modal-create-key');
}

async function createKey() {
	try {
		const res = await api('key.create', {body: {name: document.getElementById('key-name').value}});
		closeModal('modal-create-key');
		document.getElementById('created-key').textContent = res.key;
		showModal('modal-key-created');
		loadKeys();
	} catch(e) { toast(e.message, 'error'); }
}

async function deleteKey(keyID, key) {
	if (!confirm(`Delete key ${key}? Any client using this key will stop working.`)) return;
	try {
		await api('key.delete', {params: {keyID}});
		toast('Key deleted');
		loadKeys();
	} catch(e) { toast(e.message, 'error'); }
}

// ── Logout ────────────────────────────────────────────────────────
function logout() {
	// Hit the logout endpoint which always returns 401 to clear cached Basic Auth
	const xhr = new XMLHttpRequest();
	xhr.open('GET', 'account.php?action=logout', true);
	xhr.setRequestHeader('Authorization', 'Basic ' + btoa('_logout:_logout'));
	xhr.onreadystatechange = function() {
		if (xhr.readyState === 4) {
			// Redirect to the logout page directly — browser will prompt for login
			window.location.href = 'account.php?action=logout';
		}
	};
	xhr.send();
}

// ── Init ──────────────────────────────────────────────────────────
loadProfile();
loadGroups();
loadKeys();

// Auto-switch tab based on URL param
const urlTab = new URLSearchParams(location.search).get('tab');
if (urlTab && document.getElementById('panel-' + urlTab)) {
	showTab(urlTab);
}
</script>
</body>
</html>
