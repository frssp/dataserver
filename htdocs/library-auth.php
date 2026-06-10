<?php
/*
 * Authentication endpoint for Zotero Web Library SPA.
 * POST: login with {username, password}, returns API key.
 * GET ?action=me: verify stored key and return user info.
 */

set_include_path(dirname(__DIR__) . "/include");
require_once("header.inc.php");

// ── CORS ─────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Zotero-API-Key');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}

// Standalone page — disable API read-only mode set by header.inc.php
Zotero_DB::commitReadSnapshot();
Zotero_DB::readOnly(false);

require_once("selfhosted.inc.php");

// ── GET ?action=me — verify key & return user info ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'me') {
	$apiKey = $_SERVER['HTTP_ZOTERO_API_KEY'] ?? '';
	if (!$apiKey) jsonResponse(['error' => 'Zotero-API-Key header required'], 401);

	$row = Zotero_DB::rowQuery(
		"SELECT keyID, userID, name FROM zotero_master.`keys` WHERE `key`=?", [$apiKey]
	);
	if (!$row) jsonResponse(['error' => 'Invalid API key'], 401);

	$userID = (int) $row['userID'];
	$username = Zotero_Users::getUsername($userID, true);

	$groups = Zotero_DB::query(
		"SELECT g.groupID, g.name, g.type, g.libraryID
		 FROM zotero_master.groupUsers gu
		 JOIN zotero_master.groups g ON gu.groupID=g.groupID
		 WHERE gu.userID=?
		 ORDER BY g.name", [$userID]
	);

	jsonResponse([
		'userID'   => $userID,
		'username' => $username,
		'groups'   => $groups ?: []
	]);
}

// ── POST — login & return API key ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (!$username || !$password) {
	jsonResponse(['error' => 'username and password required'], 400);
}

$userID = Zotero_Users::authenticate('password', [
	'username' => $username,
	'password' => $password
]);

if (!$userID) {
	jsonResponse(['error' => 'Invalid username or password'], 401);
}

$userID = (int) $userID;
$username = Zotero_Users::getUsername($userID, true);

// Look for existing key
$existing = Zotero_DB::rowQuery(
	"SELECT keyID, `key`, name FROM zotero_master.`keys` WHERE userID=? LIMIT 1", [$userID]
);

if ($existing) {
	jsonResponse([
		'userID'   => $userID,
		'username' => $username,
		'apiKey'   => $existing['key']
	]);
}

// Create new key
Zotero_DB::readOnly(false);
$key = generateAPIKey();
$libraryID = Zotero_DB::valueQuery(
	"SELECT libraryID FROM zotero_master.users WHERE userID=?", [$userID]
);

Zotero_DB::query(
	"INSERT INTO zotero_master.`keys` (`key`, userID, name, dateAdded, lastUsed) VALUES (?, ?, ?, NOW(), NOW())",
	[$key, $userID, 'Web Library']
);
$keyID = Zotero_DB::valueQuery("SELECT LAST_INSERT_ID()");

addDefaultKeyPermissions($keyID, $libraryID);

jsonResponse([
	'userID'   => $userID,
	'username' => $username,
	'apiKey'   => $key
], 201);
