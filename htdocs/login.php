<?php
/*
 * Zotero Client Login Session Page
 *
 * Handles the browser-based login flow initiated by Zotero 7+ clients.
 * The client opens /login?session=<token> in a browser; the user submits
 * username/password here, and we complete the session server-side by
 * calling Zotero_LoginSessions::complete() directly (no super-user HTTP
 * round-trip needed since we're already inside the dataserver).
 */

set_include_path(dirname(__DIR__) . "/include");
require_once("header.inc.php");

// Standalone page — disable API read-only mode set by header.inc.php
Zotero_DB::commitReadSnapshot();
Zotero_DB::readOnly(false);

$sessionToken = $_REQUEST['session'] ?? '';
$error = '';
$state = 'form'; // form | completed | expired | notfound | missing

$session = null;
if (!$sessionToken) {
	$state = 'missing';
}
else {
	$session = Zotero_LoginSessions::getByToken($sessionToken);
	if (!$session) {
		$state = 'notfound';
	}
	else if ($session->isCompleted()) {
		$state = 'completed';
	}
	else if ($session->isExpired()) {
		$state = 'expired';
	}
}

if ($state === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$username = trim($_POST['username'] ?? '');
	$password = $_POST['password'] ?? '';

	if (!$username || !$password) {
		$error = 'Username and password required';
	}
	else {
		$userID = Zotero_Users::authenticate('password', [
			'username' => $username,
			'password' => $password
		]);

		if (!$userID) {
			$error = 'Invalid username or password';
		}
		else {
			$access = [
				'user'   => ['library' => true, 'notes' => true, 'write' => true, 'files' => true],
				'groups' => ['all' => ['library' => true, 'write' => true]],
			];
			try {
				Zotero_LoginSessions::complete($session, (int) $userID, $access);
				$state = 'completed';
			}
			catch (Exception $e) {
				error_log("login.php complete() failed: " . $e->getMessage());
				$error = 'Failed to complete login: ' . $e->getMessage();
			}
		}
	}
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Zotero Client Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
       background: #f4f4f4; margin: 0; padding: 40px 20px; color: #222; }
.card { max-width: 380px; margin: 40px auto; background: #fff; padding: 28px 32px;
        border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
h1 { font-size: 18px; margin: 0 0 6px; }
.sub { color: #666; font-size: 13px; margin-bottom: 20px; }
label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 4px; }
input[type=text], input[type=password] { width: 100%; box-sizing: border-box;
        padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
button { margin-top: 18px; width: 100%; padding: 10px; background: #cc2936;
         color: #fff; border: 0; border-radius: 4px; font-size: 14px;
         font-weight: 600; cursor: pointer; }
button:hover { background: #a8222d; }
.error { background: #fee; border: 1px solid #f99; color: #900; padding: 8px 12px;
         border-radius: 4px; font-size: 13px; margin-bottom: 12px; }
.ok { background: #efe; border: 1px solid #9c9; color: #060; padding: 12px;
      border-radius: 4px; font-size: 14px; }
</style>
</head>
<body>
<div class="card">
<?php if ($state === 'missing'): ?>
	<h1>Missing session token</h1>
	<p class="sub">This page is reached from the Zotero client. Open Zotero and click "Sign In" again.</p>
<?php elseif ($state === 'notfound'): ?>
	<h1>Session not found</h1>
	<p class="sub">The login session token is invalid. Return to Zotero and retry.</p>
<?php elseif ($state === 'expired'): ?>
	<h1>Session expired</h1>
	<p class="sub">Login sessions are valid for 15 minutes. Return to Zotero and click "Sign In" again.</p>
<?php elseif ($state === 'completed'): ?>
	<h1>Signed in</h1>
	<div class="ok">You can return to the Zotero client. It will pick up the new API key automatically.</div>
<?php else: ?>
	<h1>Sign in to Zotero</h1>
	<p class="sub">Authorize this Zotero client to access your library.</p>
	<?php if ($error): ?><div class="error"><?= $h($error) ?></div><?php endif ?>
	<form method="post" action="/login?session=<?= $h($sessionToken) ?>">
		<label for="username">Username</label>
		<input id="username" name="username" type="text" autocomplete="username" required autofocus>
		<label for="password">Password</label>
		<input id="password" name="password" type="password" autocomplete="current-password" required>
		<button type="submit">Sign In</button>
	</form>
<?php endif ?>
</div>
</body>
</html>
