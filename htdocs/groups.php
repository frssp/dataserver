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
	$rows = Zotero_DB::query(
		"SELECT g.groupID, g.name, g.slug, g.type, g.libraryReading, g.description,
		        g.dateAdded,
		        (SELECT COUNT(*) FROM zotero_master.groupUsers gu WHERE gu.groupID = g.groupID) AS members,
		        u.username AS owner
		 FROM zotero_master.groups g
		 LEFT JOIN zotero_master.groupUsers gu2 ON g.groupID = gu2.groupID AND gu2.role = 'owner'
		 LEFT JOIN zotero_master.users u ON gu2.userID = u.userID
		 WHERE g.type != 'Private'
		 ORDER BY g.name"
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

/* ── Top nav (shared structure) ──────────────────────────────────── */
.site-nav { background: #fff; border-bottom: 1px solid #ddd; padding: 0 24px; display: flex; align-items: center; height: 56px; position: relative; z-index: 40; }
.site-nav .logo { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 26px; font-weight: 300; letter-spacing: -0.5px; text-decoration: none; margin-right: 28px; }
.site-nav .logo .z { color: #c1302b; }
.site-nav .logo .rest { color: #333; }
.nav-links { display: flex; align-items: center; gap: 0; flex: 1; }
.nav-link, .nav-trigger { display: flex; align-items: center; gap: 5px; height: 56px; padding: 0 16px; font-family: inherit; font-size: 14px; font-weight: 500; color: #444; text-decoration: none; background: none; border: 0; border-bottom: 3px solid transparent; cursor: pointer; transition: color .15s, border-color .15s; }
.nav-link:hover, .nav-trigger:hover, .nav-trigger.active { color: #111; border-bottom-color: #c1302b; }
.caret { width: 11px; height: 11px; color: #aaa; transition: transform .18s ease, color .15s; }
.nav-item:hover .caret, .nav-item:focus-within .caret { transform: rotate(180deg); color: #c1302b; }
.nav-item { position: relative; }
.dropdown { position: absolute; top: 100%; left: 0; min-width: 190px; background: #fff; border: 1px solid #ddd; border-top: 0; border-radius: 0 0 8px 8px; box-shadow: 0 6px 18px rgba(0,0,0,.10); padding: 6px 0; display: none; flex-direction: column; }
.nav-item:hover .dropdown, .nav-item:focus-within .dropdown { display: flex; }
.dropdown a { display: block; padding: 9px 16px; font-size: 13px; color: #444; text-decoration: none; white-space: nowrap; }
.dropdown a .d-desc { display: block; font-size: 11px; color: #999; margin-top: 1px; }
.dropdown a:hover { background: #f6f8fa; color: #c1302b; }
.nav-right { margin-left: auto; display: flex; align-items: center; }
.nav-admin { font-size: 12px; font-weight: 500; color: #aaa; text-decoration: none; padding: 5px 12px; border: 1px solid #e1e4e8; border-radius: 6px; }
.nav-admin:hover { color: #666; border-color: #bbb; }

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
.gc-view { margin-left: auto; font-size: 13px; font-weight: 500; color: #c1302b; text-decoration: none; }
.gc-view:hover { text-decoration: underline; }
.gc-view.disabled { color: #aaa; pointer-events: none; }
.empty { text-align: center; color: #999; padding: 48px 20px; background: #fff; border: 1px dashed #d0d7de; border-radius: 10px; }

.footer { background: #404040; color: #999; padding: 16px 0; text-align: center; font-size: 12px; }
</style>
</head>
<body>
<nav id="zotero-nav" data-active="groups"></nav>

<div class="wrap">
	<div class="page-head">
		<h1>Group Search</h1>
		<p>Browse public groups on this server. No sign-in required.</p>
	</div>
	<input type="text" id="search" class="search-box" placeholder="Search groups by name or description…" autocomplete="off">
	<div class="count" id="count"></div>
	<div id="results"></div>
</div>
<div class="footer">Zotero Self-Hosted Server</div>

<script>
let allGroups = [];

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
		const canRead = g.libraryReading === 'all';
		const view = canRead
			? `<a class="gc-view" href="/library/?group=${g.groupID}&name=${encodeURIComponent(g.name)}">View library →</a>`
			: `<span class="gc-view disabled">Members only</span>`;
		return `<div class="group-card">
			<div class="gc-top">
				<span class="gc-name">${esc(g.name)}</span>
				<span class="gc-meta">${g.members} member${g.members == 1 ? '' : 's'}${g.owner ? ' · ' + esc(g.owner) : ''}</span>
			</div>
			${g.description ? `<div class="gc-desc">${esc(g.description)}</div>` : ''}
			<div class="gc-foot">
				${typeBadge(g.type)}
				${readBadge(g.libraryReading)}
				${view}
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

fetch('groups.php?action=list')
	.then(r => r.json())
	.then(data => { allGroups = Array.isArray(data) ? data : []; render(allGroups); })
	.catch(() => { document.getElementById('results').innerHTML = '<div class="empty">Could not load groups.</div>'; });
</script>
<script src="/nav.js"></script>
</body>
</html>
