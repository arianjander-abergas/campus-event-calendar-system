/**
 * Campus Event Hub — install the site on a phone (a home-screen shortcut
 * that opens like an app). Loaded on every page (app_head_tags()).
 *  - Registers sw.js (needed for Chrome / Edge / Samsung Internet to offer
 *    "Install app"; on a real site that needs HTTPS — localhost works too).
 *  - "Install app" buttons ([data-install-app], e.g. in the sidebar) and,
 *    once per phone, a small banner. Where the browser offers its own
 *    install prompt it's used: one tap, then the phone's own "Install"
 *    confirmation (no browser lets a site install itself without it).
 *  - Inside an app's built-in browser (Messenger, Facebook, Instagram,
 *    TikTok…), which can't install sites: on Android the site reopens itself
 *    in Chrome with ?install=1, and Chrome shows a one-tap Install sheet.
 *  - Otherwise (iPhone / iPad, or no prompt yet) a pop-up shows the taps
 *    that add the shortcut.
 */
(function () {
  var script = document.currentScript;
  var base = script ? script.src.replace(/\/assets\/js\/app\.js(\?.*)?$/, '') : '';

  if ('serviceWorker' in navigator && (location.protocol === 'https:' || /^(localhost|127\.0\.0\.1)$/.test(location.hostname))) {
    navigator.serviceWorker.register(base + '/sw.js').catch(function () { /* the shortcut still works without it */ });
  }

  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (standalone) return; // already opened from the home screen

  var ua = navigator.userAgent;
  var isIOS = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isPhone = isIOS || /android|mobile/i.test(ua) || window.matchMedia('(pointer: coarse) and (max-width: 900px)').matches;
  var isAndroid = /android/i.test(ua);
  // An app's built-in browser (they can't install sites): Messenger / Facebook, Instagram,
  // TikTok, LINE, WeChat, Snapchat, X — or any Android WebView ("; wv)").
  var inApp = /FBAN|FBAV|FB_IAB|FBIOS|Messenger|Instagram|musical_ly|TikTok|BytedanceWebview|Line\/|MicroMessenger|Snapchat|Twitter/i.test(ua)
    || (isAndroid && /; wv\)/.test(ua));
  // Arrived from "Open in Chrome" (?install=1): offer the install straight away, then tidy the address.
  var cameToInstall = /[?&]install=1(&|$)/.test(location.search);
  if (cameToInstall && window.history.replaceState) {
    window.history.replaceState(null, '', location.href.replace(/([?&])install=1(&|$)/, function (m, a, b) { return b ? a : ''; }).replace(/[?&]$/, ''));
  }
  var prompt = null; // the browser's own "Install app" prompt, when it offers one
  var DISMISSED = 'ceh-install-dismissed';

  function store(key, value) {
    try { if (value === undefined) return localStorage.getItem(key); localStorage.setItem(key, value); } catch (e) { return null; }
    return null;
  }

  function showButtons() {
    document.querySelectorAll('[data-install-app]').forEach(function (b) {
      var label = b.querySelector('[data-install-label]');
      if (label && !isPhone && b.getAttribute('data-desktop-label')) label.textContent = b.getAttribute('data-desktop-label');
      b.hidden = false;
    });
  }
  function hideAll() {
    document.querySelectorAll('[data-install-app]').forEach(function (b) { b.hidden = true; });
    var banner = document.querySelector('.install-banner');
    if (banner) banner.remove();
  }

  function help() {
    var steps = inApp
      ? (isIOS
        ? ['Tap <strong>⋯</strong> (or the compass) and choose <strong>Open in Safari</strong> / <strong>Open in browser</strong>.', 'In Safari, tap <strong>Share</strong> → <strong>Add to Home Screen</strong> → <strong>Add</strong>.']
        : ['Tap <strong>⋮</strong> / <strong>⋯</strong> at the top right and choose <strong>Open in Chrome</strong> (or <strong>Open in browser</strong>).', 'Chrome then shows an <strong>Install</strong> button — tap it.'])
      : isIOS
      ? ['Tap the <strong>Share</strong> button (the square with an arrow) in Safari.', 'Choose <strong>Add to Home Screen</strong>.', 'Tap <strong>Add</strong>. Campus Event Hub is now on your home screen.']
      : isPhone
        ? ['Open your browser\'s menu (<strong>⋮</strong> at the top right).', 'Choose <strong>Add to Home screen</strong> or <strong>Install app</strong>.', 'Tap <strong>Add</strong>. Campus Event Hub is now on your home screen.']
        : ['In Chrome or Edge, click the <strong>install</strong> icon at the right end of the address bar —', 'or open the menu (<strong>⋮</strong> / <strong>…</strong>) and choose <strong>Install Campus Event Hub</strong> (Edge: Apps › Install this site as an app).', 'On a phone: open this site and use <strong>Add to Home screen</strong>.'];
    var box = document.createElement('dialog');
    box.className = 'install-help';
    box.innerHTML = '<h2>' + (inApp ? 'Open in your browser to install' : 'Add Campus Event Hub to your ' + (isPhone ? 'phone' : 'device')) + '</h2>'
      + (inApp ? '<p>This app\'s built-in browser can\'t install websites.</p>' : '') + '<ol>'
      + steps.map(function (s) { return '<li>' + s + '</li>'; }).join('')
      + '</ol><p>It opens like an app, straight to the site — no app store needed.</p>'
      + '<div class="install-help-actions"><button type="button" class="btn btn-primary" data-close>Got it</button></div>';
    document.body.appendChild(box);
    box.querySelector('[data-close]').addEventListener('click', function () { box.close(); box.remove(); });
    box.addEventListener('close', function () { box.remove(); });
    if (typeof box.showModal === 'function') box.showModal(); else box.setAttribute('open', '');
  }

  function install() {
    if (prompt) {
      prompt.prompt(); // the phone's own "Install app?" — one tap
      prompt.userChoice.then(function (choice) {
        if (choice && choice.outcome === 'accepted') hideAll();
        prompt = null;
      });
    } else if (inApp && isAndroid) {
      openInChrome();
    } else {
      help();
    }
  }

  // Android, inside Messenger & co.: reopen this page in Chrome, marked ?install=1 so Chrome
  // offers the install at once. If Chrome can't be opened, the page stays here and the
  // steps show instead.
  function openInChrome() {
    var url = location.href.replace(/#.*$/, '');
    url += (url.indexOf('?') === -1 ? '?' : '&') + 'install=1';
    var rest = url.replace(/^https?:\/\//, '');
    var scheme = location.protocol.replace(':', '');
    location.href = 'intent://' + rest + '#Intent;scheme=' + scheme + ';package=com.android.chrome;end';
    setTimeout(function () { if (!document.hidden) help(); }, 1800);
  }

  // Opened by "Open in Chrome": a sheet with one Install button (the phone's confirmation follows).
  function installSheet() {
    if (document.querySelector('.install-help')) return;
    var box = document.createElement('dialog');
    box.className = 'install-help';
    box.innerHTML = '<h2>Install Campus Event Hub</h2>'
      + '<p>Tap <strong>Install</strong>, then confirm. It opens like an app from your home screen — no app store needed.</p>'
      + '<div class="install-help-actions"><button type="button" class="btn btn-outline" data-close>Not now</button>'
      + '<button type="button" class="btn btn-primary" data-install-now>Install</button></div>';
    document.body.appendChild(box);
    box.querySelector('[data-close]').addEventListener('click', function () { box.close(); });
    box.querySelector('[data-install-now]').addEventListener('click', function () { box.close(); install(); });
    box.addEventListener('close', function () { box.remove(); });
    if (typeof box.showModal === 'function') box.showModal(); else box.setAttribute('open', '');
  }

  function banner() {
    if (!isPhone || store(DISMISSED) || document.querySelector('.install-banner')) return;
    var bar = document.createElement('div');
    bar.className = 'install-banner';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Add to home screen');
    bar.innerHTML = '<img src="' + base + '/assets/images/app/icon-192.png" alt="" width="36" height="36">'
      + '<span>Add <strong>Campus Event Hub</strong> to your home screen</span>'
      + '<button type="button" class="install-banner-add">Add</button>'
      + '<button type="button" class="install-banner-close" aria-label="Not now">×</button>';
    document.body.appendChild(bar);
    bar.querySelector('.install-banner-add').addEventListener('click', function () { install(); });
    bar.querySelector('.install-banner-close').addEventListener('click', function () { store(DISMISSED, '1'); bar.remove(); });
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); // show our own button instead of the browser's mini bar
    prompt = e;
    showButtons();
    banner();
    if (cameToInstall) { cameToInstall = false; installSheet(); } // came from "Open in Chrome"
  });
  window.addEventListener('appinstalled', function () { store(DISMISSED, '1'); hideAll(); });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-install-app]');
    if (!btn) return;
    e.preventDefault();
    install();
  });

  document.addEventListener('DOMContentLoaded', function () {
    // Phones can always add a shortcut (with the browser's menu if there's no prompt).
    if (isPhone) { showButtons(); banner(); }
  });
  if (document.readyState !== 'loading' && isPhone) { showButtons(); banner(); }
  // Came to install but Chrome hasn't offered its prompt within a few seconds (e.g. already
  // installed, or not Chrome): show the sheet anyway — its button falls back to the steps.
  if (cameToInstall) setTimeout(function () { if (cameToInstall) { cameToInstall = false; if (!inApp) installSheet(); else help(); } }, 3000);
})();
