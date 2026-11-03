/* ═══════════════════════════════════════════════
   UnivoChat Service Worker
   ═══════════════════════════════════════════════ */

// Bumping this drops every cache that is not this one (see activate()).
// style.css and app.js no longer need it: pages request them as ?v=<build>,
// and a new build is simply a URL the cache has not seen yet.
const CACHE_NAME = 'univochat-v21';

// Precached so the icons and manifest are there on a flaky line. The CSS and
// JS are deliberately absent: pages ask for them by versioned URL, so a copy
// fetched here under the bare name would never be served — only downloaded,
// in full, on top of the one the page loads.
//
// PHP pages are deliberately NOT pre-cached either: serving a cached login.php
// would hand the visitor a stale CSRF token and every sign-in attempt would be
// rejected.
const STATIC_URLS = [
  './assets/icons/icon.svg',
  './assets/icons/icon-192.png',
  './manifest.json',
];

const isStaticAsset = pathname => /\.(css|js|svg|png|jpg|jpeg|gif|webp|woff2?|ico)$/i.test(pathname);

/** A specific build of a file (asset_url() in config/config.php); it never changes. */
const isVersioned = url => url.searchParams.has('v');

// ── Install: cache static assets ──────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      // addAll() rejects the whole batch if a single request fails; cache the
      // files individually so one missing icon cannot break installation.
      .then(cache => Promise.all(STATIC_URLS.map(url => cache.add(url).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

// ── Activate: clean old caches ────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// ── Fetch ─────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const { request } = event;

  // Never touch anything but plain GETs from our own origin: caching a POST
  // throws, and cross-origin requests are none of our business.
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Dynamic content (PHP pages, AJAX, uploads) always goes to the network.
  if (!isStaticAsset(url.pathname)) {
    event.respondWith(
      fetch(request).catch(() => {
        if (request.mode === 'navigate') {
          return new Response(
            '<!doctype html><meta charset="utf-8"><title>Offline</title>'
            + '<div style="font:16px/1.6 system-ui,sans-serif;text-align:center;margin:20vh auto;max-width:420px">'
            + '<h1 style="color:#2450DC;font-size:1.4rem">You are offline</h1>'
            + '<p style="color:#54656F">UnivoChat needs a connection. This page will work again once you are back online.</p>'
            + '<button onclick="location.reload()" style="margin-top:1rem;padding:.7rem 1.4rem;border:0;border-radius:10px;'
            + 'background:#2450DC;color:#fff;font-size:1rem;cursor:pointer">Retry</button></div>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
          );
        }
        // The header is how api() in app.js tells this apart from a real 503:
        // it is the network having failed, and the page backs off accordingly.
        return new Response(JSON.stringify({ error: 'You appear to be offline.' }), {
          headers: { 'Content-Type': 'application/json', 'X-Uni-Offline': '1' },
          status: 503,
        });
      })
    );
    return;
  }

  // A versioned asset is immutable: once cached it is served without touching
  // the network at all, which on a slow line is most of the page weight.
  if (isVersioned(url)) {
    event.respondWith(
      caches.match(request).then(cached => cached || fetch(request).then(response => {
        if (response && response.status === 200 && response.type === 'basic') {
          event.waitUntil(storeBuild(request, url, response.clone()));
        }
        return response;
      }))
    );
    return;
  }

  // Unversioned static assets: serve from cache, refresh in the background.
  event.respondWith(
    caches.match(request).then(cached => {
      const network = fetch(request).then(response => {
        if (response && response.status === 200 && response.type === 'basic') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(request, clone)).catch(() => {});
        }
        return response;
      });
      // A failed refresh must not surface as an unhandled rejection once the
      // cached copy has already answered.
      if (cached) network.catch(() => {});
      return cached || network;
    })
  );
});

/**
 * Cache one build of a file and forget the builds before it, so a year of
 * releases does not leave a year of app.js copies in the visitor's storage.
 */
async function storeBuild(request, url, response) {
  try {
    const cache = await caches.open(CACHE_NAME);
    for (const old of await cache.keys()) {
      const oldUrl = new URL(old.url);
      if (oldUrl.pathname === url.pathname && oldUrl.search !== url.search) {
        await cache.delete(old);
      }
    }
    await cache.put(request, response);
  } catch (_) {
    /* storage full or unavailable — the network copy was served regardless */
  }
}

// ── Push notifications ────────────────────────────────────────
// This is what runs when UnivoChat is not open: the browser wakes the worker,
// hands it the encrypted payload the server sent, and expects a notification.

/**
 * Is this a browser that lets a push pass without showing anything?
 *
 * `userVisibleOnly: true` is a promise that every push produces a visible
 * notification. Chromium and Gecko forgive a silent one while a window of the
 * origin is on screen — the page announces the message itself, and a system
 * notification on top of that is just noise. WebKit does not forgive it at all:
 * a handful of silent pushes and Safari **revokes the subscription**, which is
 * why macOS users kept going quiet after a day or two of using the app with a
 * tab open. There, the notification is shown regardless.
 */
const FORGIVES_SILENT_PUSH = !/^((?!chrome|android|crios|fxios|edg).)*safari/i.test(
  (self.navigator && self.navigator.userAgent) || ''
);

self.addEventListener('push', event => {
  let data = {};
  if (event.data) {
    try { data = event.data.json(); } catch (_) { data = { body: event.data.text() }; }
  }

  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    // "focused" has to mean the window is genuinely in front. A visible client
    // is not the same thing: a browser window sitting behind another
    // application still reports `visible`, and treating that as "they can see
    // it" is how a message became an in-page toast nobody was looking at.
    const focused = clients.some(
      client => client.focused && client.visibilityState === 'visible'
    );

    // A system notification is going up unless the app is in front of the user
    // on a browser that permits staying quiet.
    const silent = focused && FORGIVES_SILENT_PUSH;

    // Whoever is open gets told either way, so an in-page badge and an open
    // conversation stay honest. "push" is the flavour that also raises a toast;
    // once a notification is being shown, the toast would be the same message
    // twice, so those clients get the background flavour instead.
    clients.forEach(client =>
      client.postMessage({ type: silent ? 'push' : 'push-background', ...data })
    );

    if (silent) return;

    // A ring stays up until it is answered, declined or replaced by the
    // "Missed call" push that shares its tag; a message may fade on its own.
    const ringing = data.type === 'call';

    await self.registration.showNotification(data.title || 'UnivoChat', {
      body:      data.body  || 'New message',
      icon:      data.icon  || './assets/icons/icon-192.png',
      badge:     data.badge || './assets/icons/icon-192.png',
      tag:       data.tag   || 'univochat',
      renotify:  true,
      timestamp: Date.now(),
      requireInteraction: ringing,
      vibrate:   ringing ? [400, 200, 400, 200, 400] : undefined,
      data,
    });
  })());
});

self.addEventListener('notificationclick', event => {
  event.notification.close();

  const data   = event.notification.data || {};
  const target = data.url || './dashboard.php';

  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    // Re-use an open window rather than piling up tabs, and tell it which
    // conversation to show.
    for (const client of clients) {
      if ('focus' in client) {
        client.postMessage({ type: 'notification-click', ...data });
        return client.focus();
      }
    }
    return self.clients.openWindow(target);
  })());
});

/**
 * A push service may retire a subscription and issue a replacement.
 *
 * Renewing it locally is only half the job: until the server is told, every
 * notification is being posted to an endpoint that no longer exists — and the
 * app may not be opened for days. So the worker registers the replacement
 * itself, naming the old endpoint as proof that it is the browser those
 * notifications were already going to (ajax/push_subscribe.php, action=renew).
 * It cannot read a CSRF token, but it carries the session cookie, and knowing
 * the retired endpoint is a capability nobody else has.
 */
self.addEventListener('pushsubscriptionchange', event => {
  event.waitUntil((async () => {
    const oldSub = event.oldSubscription;
    let   fresh  = event.newSubscription;

    if (!fresh) {
      const key = oldSub && oldSub.options && oldSub.options.applicationServerKey;
      if (!key) return;
      fresh = await self.registration.pushManager
        .subscribe({ userVisibleOnly: true, applicationServerKey: key })
        .catch(() => null);
    }
    if (!fresh || !oldSub || !oldSub.endpoint) return;

    const json = fresh.toJSON();
    const body = new URLSearchParams({
      action:       'renew',
      old_endpoint: oldSub.endpoint,
      endpoint:     fresh.endpoint,
      p256dh:       (json.keys && json.keys.p256dh) || '',
      auth:         (json.keys && json.keys.auth) || '',
    });

    await fetch(new URL('./ajax/push_subscribe.php', self.location.href), {
      method:      'POST',
      credentials: 'include',
      headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    }).catch(() => {});
  })());
});
