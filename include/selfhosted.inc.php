<?
// Shared helpers for the self-hosted web pages
// (htdocs/admin.php, htdocs/account.php, htdocs/library-auth.php)

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

function generateAPIKey() {
	$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	$key = '';
	for ($i = 0; $i < 24; $i++) {
		$key .= $chars[random_int(0, strlen($chars) - 1)];
	}
	return $key;
}

// Grant a key full access to the user's own library plus the global
// (libraryID 0) permissions that cover group libraries.
function addDefaultKeyPermissions($keyID, $libraryID) {
	$perms = [
		[$libraryID, 'library'], [$libraryID, 'notes'], [$libraryID, 'write'],
		[0, 'library'], [0, 'notes'], [0, 'write']
	];
	foreach ($perms as [$permLibraryID, $permission]) {
		Zotero_DB::query(
			"INSERT INTO zotero_master.keyPermissions VALUES (?, ?, ?, 1)",
			[$keyID, $permLibraryID, $permission]
		);
	}
}

// Create a complete user account in one transaction:
//   www user (auth) + master library + master user + shard library + API key.
// Mirrors the admin.php `user.add` flow so the public register page and the
// admin panel stay behaviourally identical. Returns
// ['userID' => int, 'libraryID' => int, 'apiKey' => string].
// Throws Exception (code 409) if the username is already taken.
function createUserAccount($username, $password, $email, $keyName = 'Web Library') {
	$wwwDB = wwwDB();

	$existing = Zotero_DB::valueQuery(
		"SELECT userID FROM zotero_master.users WHERE username=?", $username
	);
	if ($existing) {
		throw new Exception("Username '$username' is already taken.", 409);
	}

	// Password.inc.php tries password_verify() first, salted SHA1 second
	$hash = password_hash($password, PASSWORD_BCRYPT);

	Zotero_DB::beginTransaction();
	try {
		// www user first (auto-increment supplies the userID). A UNIQUE index
		// on username turns a concurrent duplicate signup into a caught error
		// instead of a second account slipping past the SELECT check above.
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
		$key = generateAPIKey();
		Zotero_DB::query("INSERT INTO zotero_master.`keys` (`key`, userID, name, dateAdded, lastUsed) VALUES (?, ?, ?, NOW(), NOW())", [$key, $userID, $keyName]);
		$keyID = Zotero_DB::valueQuery("SELECT LAST_INSERT_ID()");
		addDefaultKeyPermissions($keyID, $libraryID);

		Zotero_DB::commit();
	}
	catch (Exception $e) {
		Zotero_DB::rollback();
		if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
			throw new Exception("Username '$username' is already taken.", 409);
		}
		throw $e;
	}

	return ['userID' => (int)$userID, 'libraryID' => (int)$libraryID, 'apiKey' => $key];
}

// CSRF protection for state-changing actions: require POST plus a custom
// header. Cross-origin forms/<img> tags can't send custom headers, and a
// cross-origin fetch with one triggers a CORS preflight that fails.
function requireAjaxPost() {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		jsonResponse(['error' => 'POST required for this action.'], 405);
	}
	if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
		jsonResponse(['error' => 'Missing X-Requested-With header.'], 403);
	}
}
?>
