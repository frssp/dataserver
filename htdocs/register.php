<?php
/*
 * Zotero Self-Hosted Public Registration Page
 *
 * Open self-service signup for internal use. Anyone who can reach this page
 * may create an account (username/password/email). On success the account
 * gets a library and a full-access API key, and the browser is logged in to
 * the Web Library SPA automatically (the API key is written to localStorage
 * in the same shape the SPA expects, then we redirect to /library/).
 *
 * GET             — render the signup form
 * POST ?action=register {username,password,email} — create account, return
 *                   {userID, username, apiKey} as JSON
 */

set_include_path(dirname(__DIR__) . "/include");
require_once("header.inc.php");

// CORS not needed: same-origin only.

// Standalone page — disable API read-only mode set by header.inc.php
Zotero_DB::commitReadSnapshot();
Zotero_DB::readOnly(false);

require_once("selfhosted.inc.php");

// ── POST ?action=register — create account & return API key ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'register') {
	// Block trivial cross-origin form spam (JS fetch sends the header).
	requireAjaxPost();
	Zotero_DB::readOnly(false);

	$input = json_decode(file_get_contents('php://input'), true);
	$username = trim($input['username'] ?? '');
	$password = (string)($input['password'] ?? '');
	$email = trim($input['email'] ?? '');

	// ── Validation ──────────────────────────────────────────────────
	if (!$username || !$password || !$email) {
		jsonResponse(['error' => 'Username, password, and email are all required.'], 400);
	}
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/', $username)) {
		jsonResponse(['error' => 'Username must be 3-32 characters: letters, digits, dot, underscore or hyphen.'], 400);
	}
	if (strlen($password) < 8) {
		jsonResponse(['error' => 'Password must be at least 8 characters.'], 400);
	}
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		jsonResponse(['error' => 'Please enter a valid email address.'], 400);
	}

	try {
		$result = createUserAccount($username, $password, $email);
	}
	catch (Exception $e) {
		$code = $e->getCode();
		jsonResponse(['error' => $e->getMessage()], ($code >= 400 && $code < 600) ? $code : 500);
	}

	jsonResponse([
		'userID'   => $result['userID'],
		'username' => $username,
		'apiKey'   => $result['apiKey'],
	], 201);
}

// ── HTML form ────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — Zotero</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: #f5f6fa; color: #333; min-height: 100vh; display: flex; flex-direction: column; }
.site-nav { background: #fff; border-bottom: 1px solid #ddd; padding: 0 24px; display: flex; align-items: center; height: 56px; }
.site-nav .logo { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 26px; font-weight: 300; letter-spacing: -0.5px; text-decoration: none; margin-right: 32px; }
.site-nav .logo .z { color: #c1302b; }
.site-nav .logo .rest { color: #333; }
.site-nav .nav-links { display: flex; align-items: center; gap: 0; flex: 1; }
.site-nav .nav-links a { display: flex; align-items: center; padding: 0 16px; height: 56px; color: #444; font-size: 14px; font-weight: 500; text-decoration: none; border-bottom: 3px solid transparent; transition: color .15s, border-color .15s; }
.site-nav .nav-links a:hover { color: #111; border-bottom-color: #c1302b; }
.main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 20px; }
.card { width: 400px; max-width: 100%; background: #fff; padding: 28px 32px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
h1 { font-size: 20px; font-weight: 400; margin: 0 0 6px; }
.sub { color: #666; font-size: 13px; margin-bottom: 20px; }
label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 4px; }
input { width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
input:focus { outline: none; border-color: #c1302b; box-shadow: 0 0 0 3px rgba(193,48,43,.12); }
.hint { font-size: 11px; color: #999; margin-top: 3px; }
button { margin-top: 20px; width: 100%; padding: 11px; background: #c1302b; color: #fff; border: 0; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background .15s; }
button:hover { background: #a8222d; }
button:disabled { background: #d99; cursor: default; }
.msg { padding: 9px 12px; border-radius: 4px; font-size: 13px; margin-bottom: 14px; display: none; }
.msg.error { background: #fee; border: 1px solid #f99; color: #900; display: block; }
.msg.ok { background: #efe; border: 1px solid #9c9; color: #060; display: block; }
.alt { text-align: center; font-size: 13px; color: #666; margin-top: 18px; }
.alt a { color: #c1302b; text-decoration: none; font-weight: 500; }
.alt a:hover { text-decoration: underline; }
</style>
</head>
<body>
<nav class="site-nav">
	<a href="/" class="logo"><span class="z">z</span><span class="rest">otero</span></a>
	<div class="nav-links">
		<a href="/library/">Web Library</a>
		<a href="/account.php">Account</a>
		<a href="/admin.php">Admin</a>
		<a href="/manual.html">Guide</a>
	</div>
</nav>
<div class="main">
	<div class="card">
		<h1>Create your account</h1>
		<p class="sub">Sign up to start syncing your Zotero library.</p>
		<div class="msg" id="msg"></div>
		<form id="form" autocomplete="on">
			<label for="username">Username</label>
			<input id="username" name="username" type="text" autocomplete="username" required autofocus>
			<div class="hint">3-32 chars: letters, digits, . _ -</div>

			<label for="email">Email</label>
			<input id="email" name="email" type="email" autocomplete="email" required>

			<label for="password">Password</label>
			<input id="password" name="password" type="password" autocomplete="new-password" required>
			<div class="hint">At least 8 characters</div>

			<label for="confirm">Confirm password</label>
			<input id="confirm" name="confirm" type="password" autocomplete="new-password" required>

			<button type="submit" id="submit">Create account</button>
		</form>
		<div class="alt">Already have an account? <a href="/library/">Sign in to the Web Library</a></div>
	</div>
</div>

<script>
const API_KEY_STORAGE = 'zotero_api_key';
const USER_INFO_STORAGE = 'zotero_user_info';
const form = document.getElementById('form');
const msgEl = document.getElementById('msg');
const submitBtn = document.getElementById('submit');

function showMsg(text, type) {
	msgEl.textContent = text;
	msgEl.className = 'msg ' + type;
}

form.addEventListener('submit', async (e) => {
	e.preventDefault();
	const username = document.getElementById('username').value.trim();
	const email = document.getElementById('email').value.trim();
	const password = document.getElementById('password').value;
	const confirm = document.getElementById('confirm').value;

	if (password !== confirm) { showMsg('Passwords do not match.', 'error'); return; }
	if (password.length < 8) { showMsg('Password must be at least 8 characters.', 'error'); return; }

	submitBtn.disabled = true;
	submitBtn.textContent = 'Creating…';
	try {
		const res = await fetch('register.php?action=register', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
			body: JSON.stringify({ username, password, email }),
		});
		const data = await res.json();
		if (!res.ok) throw new Error(data.error || 'Registration failed.');

		// Log the new user straight into the Web Library SPA: write the auth
		// blob the SPA reads on boot (same origin → shared localStorage), then
		// redirect. verifySession() will re-validate the key and load groups.
		const userInfo = { userID: data.userID, username: data.username, apiKey: data.apiKey, groups: [] };
		localStorage.setItem(API_KEY_STORAGE, data.apiKey);
		localStorage.setItem(USER_INFO_STORAGE, JSON.stringify(userInfo));

		showMsg('Account created! Redirecting to your library…', 'ok');
		setTimeout(() => { window.location.href = '/library/'; }, 800);
	} catch (err) {
		showMsg(err.message, 'error');
		submitBtn.disabled = false;
		submitBtn.textContent = 'Create account';
	}
});
</script>
</body>
</html>
