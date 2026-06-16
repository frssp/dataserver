/*
 * Shared top navigation for all self-hosted web pages.
 *
 * One source of truth so every page shows the same bar for the same login
 * state. Usage: put `<nav id="zotero-nav" data-active="groups"></nav>` where
 * the bar should go and load this script. Pages with server-known auth
 * (account.php via Basic auth, admin.php as super-user) can set
 * `window.ZOTERO_NAV` before this script to force state / wire handlers:
 *
 *   window.ZOTERO_NAV = {
 *     active: 'web-library' | 'groups' | 'account' | 'guide' | 'admin',
 *     username: 'alice',                 // force logged-in + show this name
 *     mode: 'admin',                     // admin super-user variant
 *     onLogout: function () { ... },     // override default logout
 *     onChangePassword: function () {}   // admin only
 *   };
 *
 * Login state otherwise comes from localStorage 'zotero_user_info' (set by
 * the web library on sign-in), so a signed-in user sees the logged-in bar on
 * every page and an anonymous visitor sees the public bar.
 */
(function () {
  var cfg = window.ZOTERO_NAV || {};
  var navEl = document.getElementById('zotero-nav');
  if (!navEl) return;

  var active = cfg.active || navEl.getAttribute('data-active') || '';

  function storedUsername() {
    try {
      var raw = localStorage.getItem('zotero_user_info');
      if (raw) {
        var u = JSON.parse(raw);
        if (u && u.username) return u.username;
      }
    } catch (e) {}
    return null;
  }

  var isAdmin = cfg.mode === 'admin';
  var username = cfg.username || storedUsername();
  var loggedIn = isAdmin || !!username;

  // ---- inject shared CSS once (scoped with .site-nav so it always wins) ----
  if (!document.getElementById('zotero-nav-css')) {
    var style = document.createElement('style');
    style.id = 'zotero-nav-css';
    style.textContent =
      '.site-nav{background:#fff;border-bottom:1px solid #ddd;padding:0 24px;display:flex;align-items:center;height:56px;position:sticky;top:0;z-index:40}' +
      '.site-nav .logo{font-family:"Helvetica Neue",Helvetica,Arial,sans-serif;font-size:26px;font-weight:300;letter-spacing:-.5px;text-decoration:none;margin-right:28px}' +
      '.site-nav .logo .z{color:#c1302b}.site-nav .logo .rest{color:#333}' +
      '.site-nav .nav-links{display:flex;align-items:center;gap:0;flex:1}' +
      '.site-nav .nav-link,.site-nav .nav-trigger{display:flex;align-items:center;gap:6px;height:56px;margin:0;width:auto;padding:0 16px;font-family:inherit;font-size:14px;font-weight:500;color:#444;text-decoration:none;background:none;border:0;border-bottom:3px solid transparent;cursor:pointer;transition:color .15s,border-color .15s}' +
      '.site-nav .nav-link:hover,.site-nav .nav-trigger:hover{color:#111;border-bottom-color:#c1302b}' +
      '.site-nav .nav-link.active,.site-nav .nav-trigger.active{color:#c1302b;border-bottom-color:#c1302b}' +
      '.site-nav .caret{width:11px;height:11px;color:#aaa;transition:transform .18s ease,color .15s}' +
      '.site-nav .nav-item{position:relative}' +
      '.site-nav .nav-item:hover .caret,.site-nav .nav-item:focus-within .caret{transform:rotate(180deg);color:#c1302b}' +
      '.site-nav .dropdown{position:absolute;top:100%;left:0;min-width:190px;background:#fff;border:1px solid #ddd;border-top:0;border-radius:0 0 8px 8px;box-shadow:0 6px 18px rgba(0,0,0,.10);padding:6px 0;display:none;flex-direction:column;z-index:50}' +
      '.site-nav .nav-item:hover .dropdown,.site-nav .nav-item:focus-within .dropdown{display:flex}' +
      '.site-nav .dropdown a{display:block;padding:9px 16px;height:auto;font-size:13px;color:#444;text-decoration:none;white-space:nowrap;border:0}' +
      '.site-nav .dropdown a .d-desc{display:block;font-size:11px;color:#999;margin-top:1px}' +
      '.site-nav .dropdown a:hover{background:#f6f8fa;color:#c1302b}' +
      '.site-nav .nav-right{margin-left:auto;display:flex;align-items:center;gap:12px}' +
      '.site-nav .nav-admin{font-size:12px;font-weight:500;color:#aaa;text-decoration:none;padding:5px 12px;border:1px solid #e1e4e8;border-radius:6px}' +
      '.site-nav .nav-admin:hover{color:#666;border-color:#bbb}' +
      '.site-nav .nav-user{font-size:14px;font-weight:500;color:#333}' +
      '.site-nav .nav-logout{font-size:12px;color:#999;cursor:pointer;background:none;border:1px solid #ddd;padding:4px 12px;border-radius:4px}' +
      '.site-nav .nav-logout:hover{color:#333;border-color:#999}';
    document.head.appendChild(style);
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
  }

  var caret = '<svg class="caret" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 4.5 6 7.5 9 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  function link(href, label, key) {
    return '<a href="' + href + '" class="nav-link' + (active === key ? ' active' : '') + '">' + label + '</a>';
  }

  var groupsMenu = link('/groups.php', 'Groups', 'groups');

  var left, right;

  if (loggedIn) {
    left =
      link('/library/', 'Web Library', 'web-library') +
      groupsMenu +
      link('/account.php', 'Account', 'account') +
      link('/manual.html', 'User Guide', 'guide');

    if (isAdmin) {
      right =
        '<button type="button" class="nav-logout" id="znav-changepw">Change Password</button>' +
        '<button type="button" class="nav-logout" id="znav-logout">Log Out</button>';
    } else {
      right =
        '<a href="/admin.php" class="nav-admin">Admin</a>' +
        '<span class="nav-user">' + esc(username) + '</span>' +
        '<button type="button" class="nav-logout" id="znav-logout">Log Out</button>';
    }
  } else {
    var loginMenu =
      '<div class="nav-item">' +
        '<button type="button" class="nav-trigger' + (active === 'login' ? ' active' : '') + '">Log In ' + caret + '</button>' +
        '<div class="dropdown">' +
          '<a href="/library/">Sign In<span class="d-desc">Access your library</span></a>' +
          '<a href="/register.php">Sign Up<span class="d-desc">Create a new account</span></a>' +
        '</div>' +
      '</div>';
    left = groupsMenu + loginMenu + link('/manual.html', 'User Guide', 'guide');
    right = '<a href="/admin.php" class="nav-admin">Admin</a>';
  }

  navEl.className = 'site-nav';
  navEl.innerHTML =
    '<a href="/" class="logo"><span class="z">z</span><span class="rest">otero</span></a>' +
    '<div class="nav-links">' + left + '</div>' +
    '<div class="nav-right">' + right + '</div>';

  var logoutBtn = document.getElementById('znav-logout');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', function () {
      if (typeof cfg.onLogout === 'function') { cfg.onLogout(); return; }
      try {
        localStorage.removeItem('zotero_api_key');
        localStorage.removeItem('zotero_user_info');
      } catch (e) {}
      window.location.href = '/';
    });
  }
  var cpwBtn = document.getElementById('znav-changepw');
  if (cpwBtn && typeof cfg.onChangePassword === 'function') {
    cpwBtn.addEventListener('click', cfg.onChangePassword);
  }
})();
