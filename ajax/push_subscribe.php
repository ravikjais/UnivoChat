<?php
/**
 * Store (or drop, or replace) the push subscription a browser handed us.
 *
 * A subscription belongs to a browser, not to an account: if somebody else
 * signs in on the same machine the endpoint is simply reassigned to them, which
 * is why the insert updates user_id on a duplicate key.
 *
 * Four actions:
 *   subscribe   — the page registering what the browser gave it (the usual one)
 *   unsubscribe — the bell switched off
 *   renew       — the *service worker* handing over a subscription the push
 *                 service retired and replaced behind the app's back
 *   test        — send one notification to this browser, right now, so somebody
 *                 who "isn't getting notifications" finds out immediately
 *                 whether the fault is here or in their operating system
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/push.php';
require_approved();
api_require_post();

$action = api_str('action', 'subscribe');

// A service worker has no page to read a CSRF token from, so `renew` proves
// itself differently: it must name the old endpoint, and that endpoint must
// already be on record against the signed-in account. Knowing it is the
// capability — nobody can forge one without having been sent the pushes.
if ($action !== 'renew') {
    api_require_csrf();
}

$uid      = current_uid();
$endpoint = api_str('endpoint');

/** Reject anything that is not a plausible push endpoint before it is stored. */
$validEndpoint = static function (string $url): bool {
    return $url !== ''
        && filter_var($url, FILTER_VALIDATE_URL) !== false
        && str_starts_with($url, 'https://');
};

// ── Turn it off ───────────────────────────────────────────────────────────────
if ($action === 'unsubscribe') {
    if (!$validEndpoint($endpoint)) {
        json_response(['error' => 'Invalid push endpoint.'], 400);
    }
    query(
        'DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?',
        [hash('sha256', $endpoint), $uid]
    );
    json_response(['ok' => true, 'subscribed' => false]);
}

// ── Send one to this browser now ──────────────────────────────────────────────
// The single most useful thing to be able to do when somebody reports that
// notifications "do not work": it separates a missing subscription from a
// rejecting push service from an operating system that is swallowing them.
if ($action === 'test') {
    if (!push_available()) {
        json_response(['ok' => false, 'sent' => 0, 'reason' => 'This server cannot send push notifications.']);
    }

    $sent = push_to_user($uid, [
        'title' => 'UnivoChat',
        'body'  => 'Notifications are working on this device. 🎉',
        'icon'  => full_url('assets/icons/icon-192.png'),
        'badge' => full_url('assets/icons/icon-192.png'),
        'tag'   => 'univochat-test',
        'url'   => full_url('dashboard.php'),
        'test'  => true,
    ]);

    json_response([
        'ok'     => $sent > 0,
        'sent'   => $sent,
        'reason' => $sent > 0 ? '' : 'No device accepted the notification.',
    ]);
}

// ── Register, or replace ──────────────────────────────────────────────────────
if (!$validEndpoint($endpoint)) {
    json_response(['error' => 'Invalid push endpoint.'], 400);
}

$p256dh = api_str('p256dh');
$auth   = api_str('auth');

// The keys are base64url; decoding is the cheapest way to reject a malformed
// subscription now instead of at send time, when nobody is watching.
if (strlen(b64url_decode($p256dh)) !== 65 || strlen(b64url_decode($auth)) < 16) {
    json_response(['error' => 'Invalid push keys.'], 400);
}

$hash      = hash('sha256', $endpoint);
$userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

if ($action === 'renew') {
    $old = api_str('old_endpoint');
    if (!$validEndpoint($old)) {
        json_response(['error' => 'Invalid push endpoint.'], 400);
    }

    // The row has to exist and be ours. Without this a renewal would be an
    // unauthenticated way to point somebody else's notifications anywhere.
    $row = fetch_one(
        'SELECT id FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?',
        [hash('sha256', $old), $uid]
    );
    if (!$row) {
        json_response(['error' => 'That subscription is not on record.'], 404);
    }

    // The replacement may already be stored under another row (the browser can
    // renew twice); clear it first so the unique key cannot refuse the update.
    query(
        'DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND id <> ?',
        [$hash, (int)$row['id']]
    );
    query(
        'UPDATE push_subscriptions
            SET endpoint_hash = ?, endpoint = ?, p256dh = ?, auth = ?, user_agent = ?, last_used_at = NULL
          WHERE id = ?',
        [$hash, $endpoint, $p256dh, $auth, $userAgent, (int)$row['id']]
    );

    json_response(['ok' => true, 'subscribed' => true, 'renewed' => true]);
}

query(
    'INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, user_agent)
     VALUES (?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
        user_id      = VALUES(user_id),
        p256dh       = VALUES(p256dh),
        auth         = VALUES(auth),
        user_agent   = VALUES(user_agent),
        last_used_at = NULL',
    [$uid, $hash, $endpoint, $p256dh, $auth, $userAgent]
);

json_response(['ok' => true, 'subscribed' => true]);
