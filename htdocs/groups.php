<?php
/*
 * Public Group Directory / Search
 *
 * Lists PUBLIC groups (type != 'Private') so anyone can find them WITHOUT
 * logging in. Private groups are never exposed here. Whether a visitor can
 * actually read a group's library depends on its libraryReading setting
 * ('all' = anyone, 'members' = members only) — surfaced as a badge.
 *
 * GET                 — render the directory page
 * GET ?action=list    — JSON list of public groups
 */

set_include_path(dirname(__DIR__) . "/include");
require_once("header.inc.php");

// Standalone page — disable API read-only snapshot set by header.inc.php
Zotero_DB::commitReadSnapshot();
Zotero_DB::readOnly(false);

require_once("selfhosted.inc.php");

// ── JSON: list public groups ─────────────────────────────────────────
if (($_GET['action'] ?? '') === 'list') {
	// Browsing is open to anyone. If the request carries a valid API key we
	// also flag which of these groups the viewer already belongs to, so the
	// UI can show "Join" vs "Joined / View library".
	$viewerUserID = 0;
	$apiKey = $_SERVER['HTTP_ZOTERO_API_KEY'] ?? '';
	if ($apiKey) {
		$vid = Zotero_DB::valueQuery("SELECT userID FROM zotero_master.`keys` WHERE `key`=?", $apiKey);
		if ($vid) $viewerUserID = (int)$vid;
	}
	$rows = Zotero_DB::query(
		"SELECT g.groupID, g.name, g.slug, g.type, g.libraryReading, g.description,
		        g.dateAdded,
		        (SELECT COUNT(*) FROM zotero_master.groupUsers gu WHERE gu.groupID = g.groupID) AS members,
		        EXISTS(SELECT 1 FROM zotero_master.groupUsers gum WHERE gum.groupID = g.groupID AND gum.userID = ?) AS isMember,
		        u.username AS owner
		 FROM zotero_master.groups g
		 LEFT JOIN zotero_master.groupUsers gu2 ON g.groupID = gu2.groupID AND gu2.role = 'owner'
		 LEFT JOIN zotero_master.users u ON gu2.userID = u.userID
		 WHERE g.type != 'Private'
		 ORDER BY g.name",
		[$viewerUserID]
	);
	jsonResponse($rows ?: []);
}

// ── HTML ─────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Group Search — Zotero</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: #f5f6fa; color: #333; min-height: 100vh; display: flex; flex-direction: column; }


/* ── Page ────────────────────────────────────────────────────────── */
.wrap { flex: 1; max-width: 820px; width: 100%; margin: 0 auto; padding: 32px 24px 64px; }
.page-head h1 { font-size: 26px; font-weight: 300; margin-bottom: 6px; }
.page-head p { color: #666; font-size: 14px; margin-bottom: 20px; }
.search-box { width: 100%; padding: 12px 16px; font-size: 15px; border: 1px solid #d1d5da; border-radius: 8px; margin-bottom: 8px; }
.search-box:focus { outline: none; border-color: #c1302b; box-shadow: 0 0 0 3px rgba(193,48,43,.1); }
.count { font-size: 13px; color: #999; margin-bottom: 16px; }

.group-card { background: #fff; border: 1px solid #e1e4e8; border-radius: 10px; padding: 18px 20px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.group-card .gc-top { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
.group-card .gc-name { font-size: 17px; font-weight: 600; color: #24292e; }
.group-card .gc-meta { font-size: 12px; color: #999; white-space: nowrap; }
.group-card .gc-desc { font-size: 13.5px; color: #555; margin: 8px 0 10px; line-height: 1.55; }
.group-card .gc-foot { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.badge { display: inline-block; padding: 2px 9px; border-radius: 11px; font-size: 11px; font-weight: 600; }
.badge-open { background: #d4edda; color: #155724; }
.badge-closed { background: #fff3cd; color: #856404; }
.badge-read-all { background: #e7f0ff; color: #0356b6; }
.badge-read-members { background: #eceef0; color: #555; }
.badge-member { background: #dfe9ff; color: #1b3fae; }
.gc-actions { margin-left: auto; display: flex; align-items: center; gap: 12px; }
.gc-view { font-size: 13px; font-weight: 500; color: #c1302b; text-decoration: none; white-space: nowrap; }
.gc-view:hover { text-decoration: underline; }
.gc-view.disabled { color: #aaa; pointer-events: none; }
.gc-signin { font-size: 13px; font-weight: 500; color: #c1302b; text-decoration: none; white-space: nowrap; }
.gc-signin:hover { text-decoration: underline; }
.gc-join { background: #c1302b; color: #fff; border: none; padding: 6px 15px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background .15s; }
.gc-join:hover { background: #a82824; }
.gc-join:disabled { opacity: .6; cursor: default; }
.empty { text-align: center; color: #999; padding: 48px 20px; background: #fff; border: 1px dashed #d0d7de; border-radius: 10px; }

.footer { background: #404040; color: #999; padding: 16px 0; text-align: center; font-size: 12px; }
.footer a { color: #cfd2d8; text-decoration: none; border-bottom: 1px solid rgba(207,210,216,.35); }
.footer a:hover { color: #fff; border-bottom-color: #fff; }
</style>
</head>
<body>
<nav id="zotero-nav" data-active="groups"></nav>

<div class="wrap">
	<div class="page-head">
		<h1>Group Search</h1>
		<p>Browse public groups on this server. Sign in to join open-membership groups.</p>
	</div>
	<input type="text" id="search" class="search-box" placeholder="Search groups by name or description…" autocomplete="off">
	<div class="count" id="count"></div>
	<div id="results"></div>
</div>
<div class="footer">Zotero Self-Hosted Server &nbsp;&middot;&nbsp; Need help? <a href="mailto:sungh20.kim@samsung.com">Contact the administrator</a></div>

<script>
let allGroups = [];
const loggedIn = !!localStorage.getItem('zotero_api_key');

function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

function typeBadge(type) {
	if (type === 'PublicOpen') return '<span class="badge badge-open">Open membership</span>';
	if (type === 'PublicClosed') return '<span class="badge badge-closed">Closed membership</span>';
	return '';
}

function readBadge(reading) {
	return reading === 'all'
		? '<span class="badge badge-read-all">Anyone can read</span>'
		: '<span class="badge badge-read-members">Members only</span>';
}

function render(list) {
	const results = document.getElementById('results');
	const count = document.getElementById('count');
	if (!list.length) {
		results.innerHTML = '<div class="empty">No public groups found.</div>';
		count.textContent = '';
		return;
	}
	count.textContent = `${list.length} public group${list.length === 1 ? '' : 's'}`;
	results.innerHTML = list.map(g => {
		const isMember = g.isMember == 1;
		const canRead = isMember || g.libraryReading === 'all';

		// Right-hand actions: Join (open groups you haven't joined) and/or
		// a library link (members, or anyone when reading is public).
		let actions = '';
		if (g.type === 'PublicOpen' && !isMember) {
			actions += loggedIn
				? `<button class="gc-join" data-id="${g.groupID}">Join group</button>`
				: `<a class="gc-signin" href="/library/">Sign in to join</a>`;
		}
		if (canRead) {
			actions += `<a class="gc-view" href="/library/?group=${g.groupID}&name=${encodeURIComponent(g.name)}">View library →</a>`;
		} else if (!actions) {
			actions += `<span class="gc-view disabled">Members only</span>`;
		}

		return `<div class="group-card">
			<div class="gc-top">
				<span class="gc-name">${esc(g.name)}</span>
				<span class="gc-meta">${g.members} member${g.members == 1 ? '' : 's'}${g.owner ? ' · ' + esc(g.owner) : ''}</span>
			</div>
			${g.description ? `<div class="gc-desc">${esc(g.description)}</div>` : ''}
			<div class="gc-foot">
				${typeBadge(g.type)}
				${readBadge(g.libraryReading)}
				${isMember ? '<span class="badge badge-member">Joined</span>' : ''}
				<span class="gc-actions">${actions}</span>
			</div>
		</div>`;
	}).join('');
}

function filter() {
	const q = document.getElementById('search').value.trim().toLowerCase();
	if (!q) return render(allGroups);
	render(allGroups.filter(g =>
		(g.name || '').toLowerCase().includes(q) ||
		(g.description || '').toLowerCase().includes(q)
	));
}

document.getElementById('search').addEventListener('input', filter);

function loadGroups() {
	const headers = {};
	const key = localStorage.getItem('zotero_api_key');
	if (key) headers['Zotero-API-Key'] = key;   // lets the server flag joined groups
	return fetch('groups.php?action=list', { headers })
		.then(r => r.json())
		.then(data => { allGroups = Array.isArray(data) ? data : []; filter(); })
		.catch(() => { document.getElementById('results').innerHTML = '<div class="empty">Could not load groups.</div>'; });
}

// Join an open-membership group (delegated; cards are re-rendered on filter)
document.getElementById('results').addEventListener('click', async (e) => {
	const btn = e.target.closest('.gc-join');
	if (!btn) return;
	const id = btn.dataset.id;
	btn.disabled = true;
	btn.textContent = 'Joining…';
	try {
		const r = await fetch('account.php?action=group.join&groupID=' + encodeURIComponent(id), {
			method: 'POST',
			headers: {
				'X-Requested-With': 'XMLHttpRequest',
				'Zotero-API-Key': localStorage.getItem('zotero_api_key') || ''
			}
		});
		const data = await r.json().catch(() => ({}));
		if (!r.ok) throw new Error(data.error || 'Could not join the group.');
		await loadGroups();   // reflect new membership (Joined badge + library link)
	} catch (err) {
		alert(err.message || 'Could not join the group.');
		btn.disabled = false;
		btn.textContent = 'Join group';
	}
});

loadGroups();
</script>
<script src="/nav.js"></script>
</body>
</html>
