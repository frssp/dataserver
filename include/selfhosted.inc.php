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
