<?php
/**
 * One-to-one WebRTC calls — the server half.
 *
 * The audio and video never touch this server: they go browser to browser
 * (or through a TURN relay). What lives here is everything around that:
 *
 *   • the `calls` row — ringing → accepted → ended (or rejected, missed,
 *     cancelled, busy, failed) — which is both the state machine and the log;
 *   • `call_signals`, the mailbox the offer, answer and ICE candidates travel
 *     through, because shared hosting has no WebSocket server to carry them;
 *   • ringing: a web push to the callee, plus `incoming_call` riding along on
 *     the polls the page already makes;
 *   • the ICE server list, with short-lived TURN credentials.
 *
 * Every transition out of a live state goes through call_finish(), whose
 * conditional UPDATE is what makes "both sides hung up at once" or "the sweep
 * and the hangup raced" settle on exactly one outcome and one log message.
 */

require_once __DIR__ . '/push.php';

const CALL_LIVE_STATES = ['ringing', 'accepted'];
const CALL_MEDIA       = ['audio', 'video'];
const CALL_SIGNAL_KINDS = ['offer', 'answer', 'ice', 'renegotiate'];

function calls_available(): bool {
    return CALLS_ENABLED;
}

/** Has setup/install.php created the tables yet? Asked once per request. */
function calls_installed(): bool {
    static $installed = null;
    if ($installed === null) {
        $installed = (bool)fetch_one("SHOW TABLES LIKE 'calls'")
            && (bool)fetch_one("SHOW TABLES LIKE 'call_signals'");
    }
    return $installed;
}

function call_get(int $id): ?array {
    return $id > 0 ? fetch_one('SELECT * FROM calls WHERE id = ?', [$id]) : null;
}

/** The call, but only for one of its two participants. */
function call_for_participant(int $id, int $uid): ?array {
    $call = call_get($id);
    return $call && call_role($call, $uid) !== null ? $call : null;
}

/** 'caller', 'callee', or null for anybody else. */
function call_role(array $call, int $uid): ?string {
    if ((int)$call['caller_id'] === $uid) return 'caller';
    if ((int)$call['callee_id'] === $uid) return 'callee';
    return null;
}

function call_peer_id(array $call, int $uid): int {
    return (int)$call['caller_id'] === $uid ? (int)$call['callee_id'] : (int)$call['caller_id'];
}

function call_is_live(array $call): bool {
    return in_array($call['status'], CALL_LIVE_STATES, true);
}

/** The ringing or connected call $uid is part of, if any. */
function active_call_for(int $uid): ?array {
    return fetch_one(
        "SELECT * FROM calls
          WHERE status IN ('ringing','accepted')
            AND (caller_id = ? OR callee_id = ?)
          ORDER BY id DESC LIMIT 1",
        [$uid, $uid]
    );
}

/** Seconds between answering and hanging up; 0 for a call never answered. */
function call_duration(array $call): int {
    if (empty($call['answered_at'])) return 0;
    $from = strtotime((string)$call['answered_at']);
    $to   = !empty($call['ended_at']) ? strtotime((string)$call['ended_at']) : time();
    return max(0, $to - $from);
}

function call_user_card(int $id): array {
    $user = get_user($id);
    $name = $user ? ($user['full_name'] ?: $user['username']) : 'Unknown';
    return [
        'id'     => $id,
        'name'   => $name,
        'avatar' => avatar_url($user['avatar'] ?? null, $name),
    ];
}

/** What the client is told about a call, from $uid's side of it. */
function call_payload(array $call, int $uid): array {
    return [
        'id'       => (int)$call['id'],
        'status'   => $call['status'],
        'media'    => $call['media'],
        'role'     => call_role($call, $uid),
        'peer'     => call_user_card(call_peer_id($call, $uid)),
        'live'     => call_is_live($call),
        'duration' => call_duration($call),
    ];
}

/**
 * The call ringing for $uid right now, shaped for the incoming-call screen —
 * or null. Piggybacked onto the polls the page already makes, so a ring costs
 * no request of its own.
 *
 * Never throws: it runs inside endpoints whose real job is something else, and
 * an un-migrated install (no `calls` table yet) must not break those.
 */
function incoming_call_for(int $uid): ?array {
    if (!calls_available()) return null;

    try {
        // Housekeeping on a fraction of polls: enough to settle abandoned
        // calls within seconds across a team, without a write on every poll.
        if (random_int(1, 10) === 1) calls_sweep();

        $call = fetch_one(
            "SELECT * FROM calls
              WHERE callee_id = ? AND status = 'ringing'
                AND created_at >= NOW() - INTERVAL ? SECOND
              ORDER BY id DESC LIMIT 1",
            [$uid, CALL_RING_TIMEOUT]
        );
    } catch (Throwable $e) {
        return null;
    }

    if (!$call) return null;

    return [
        'id'    => (int)$call['id'],
        'media' => $call['media'],
        'from'  => call_user_card((int)$call['caller_id']),
        // How long it has left to ring, so a page that learns of the call late
        // does not ring for a full timeout after the caller has given up.
        'ttl'   => max(0, CALL_RING_TIMEOUT - (time() - strtotime((string)$call['created_at']))),
    ];
}

/** The side's heartbeat. Called on every call poll. */
function call_touch(array $call, int $uid): void {
    $column = call_role($call, $uid) === 'caller' ? 'last_seen_caller' : 'last_seen_callee';
    query("UPDATE calls SET $column = NOW() WHERE id = ?", [(int)$call['id']]);
}

/**
 * Move a live call to a final state. Returns false when it was already over —
 * somebody else got there first, and their outcome stands.
 *
 * $stale: the call is being ended because a side stopped polling, so it is
 * dated from the last heartbeat rather than from now, or a laptop shut mid-call
 * would be logged as a call that went on talking to nobody for half a minute.
 */
function call_finish(array $call, string $status, ?int $by = null, bool $stale = false): bool {
    $ended = query(
        "UPDATE calls
            SET status   = ?,
                ended_by = ?,
                ended_at = IF(?, LEAST(COALESCE(last_seen_caller, NOW()), COALESCE(last_seen_callee, NOW())), NOW())
          WHERE id = ? AND status IN ('ringing','accepted')",
        [$status, $by, $stale ? 1 : 0, (int)$call['id']]
    )->rowCount() === 1;

    if (!$ended) return false;

    query('DELETE FROM call_signals WHERE call_id = ?', [(int)$call['id']]);

    $call = call_get((int)$call['id']) ?? $call;
    call_log_message($call);

    return true;
}

/**
 * The row a finished call leaves in the conversation, from caller to callee.
 *
 * A call nobody picked up is left unread, so it counts in the callee's badge
 * the way a missed call should; anything they answered or declined they have
 * already seen.
 */
function call_log_message(array $call): void {
    if (!messages_support_type('call')) return;   // migration not run yet

    $unseen = in_array($call['status'], ['missed', 'cancelled', 'busy'], true);

    try {
        query(
            "INSERT INTO messages (sender_id, receiver_id, message, format, type, is_read)
             VALUES (?, ?, ?, 'plain', 'call', ?)",
            [
                (int)$call['caller_id'],
                (int)$call['callee_id'],
                json_encode([
                    'call'     => (int)$call['id'],
                    'media'    => $call['media'],
                    'status'   => $call['status'],
                    'duration' => call_duration($call),
                ]),
                $unseen ? 0 : 1,
            ]
        );
    } catch (Throwable $e) {
        // The call itself is settled either way; a missing log line is not
        // worth turning a hangup into an error.
        error_log('[UnivoChat] Call log message failed: ' . $e->getMessage());
    }
}

/**
 * Settle calls nobody is going to settle: rings that ran out, callers who left
 * while ringing, and connected calls one side walked away from.
 */
function calls_sweep(): void {
    $stale = fetch_all(
        "SELECT * FROM calls
          WHERE status IN ('ringing','accepted')
            AND (
                  (status = 'ringing'  AND (created_at       < NOW() - INTERVAL ? SECOND
                                         OR last_seen_caller < NOW() - INTERVAL ? SECOND))
               OR (status = 'accepted' AND (last_seen_caller < NOW() - INTERVAL ? SECOND
                                         OR last_seen_callee < NOW() - INTERVAL ? SECOND))
            )
          LIMIT 50",
        [CALL_RING_TIMEOUT, CALL_HEARTBEAT_TTL, CALL_HEARTBEAT_TTL, CALL_HEARTBEAT_TTL]
    );

    foreach ($stale as $call) {
        if ($call['status'] === 'accepted') {
            call_finish($call, 'ended', null, true);
        } else {
            $timedOut = strtotime((string)$call['created_at']) <= time() - CALL_RING_TIMEOUT;
            call_finish($call, $timedOut ? 'missed' : 'cancelled');
        }
    }

    // Belt and braces: call_finish() already empties a call's mailbox.
    if (random_int(1, 20) === 1) {
        query('DELETE FROM call_signals WHERE created_at < NOW() - INTERVAL 1 DAY');
    }
}

/**
 * The RTCPeerConnection `iceServers` list.
 *
 * With CALL_TURN_SECRET the TURN credential follows coturn's REST scheme:
 * username "<expiry>:<uid>", password base64(HMAC-SHA1(secret, username)).
 * The TURN server checks the same HMAC and the expiry, so what reaches the
 * browser stops working after CALL_TURN_TTL and the secret never leaves here.
 */
function call_ice_servers(int $uid): array {
    $servers = [];

    if (CALL_STUN_URLS) {
        $servers[] = ['urls' => array_values(CALL_STUN_URLS)];
    }

    if (CALL_TURN_URLS) {
        if (CALL_TURN_SECRET !== '') {
            $username  = (time() + CALL_TURN_TTL) . ':' . $uid;
            $servers[] = [
                'urls'       => array_values(CALL_TURN_URLS),
                'username'   => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, CALL_TURN_SECRET, true)),
            ];
        } elseif (CALL_TURN_USERNAME !== '') {
            $servers[] = [
                'urls'       => array_values(CALL_TURN_URLS),
                'username'   => CALL_TURN_USERNAME,
                'credential' => CALL_TURN_CREDENTIAL,
            ];
        }
    }

    return $servers;
}

/**
 * Ring the callee's devices. Sent whether or not they are at the keyboard,
 * unlike a message: the service worker hands every push to open pages too, so
 * this is also the fastest way to ring a tab that is already open — faster
 * than waiting for its next poll.
 */
function notify_incoming_call(array $call): void {
    $from = call_user_card((int)$call['caller_id']);
    $icon = str_starts_with($from['avatar'], 'data:')
        ? full_url('assets/icons/icon-192.png')
        : absolute_url($from['avatar']);

    push_to_user((int)$call['callee_id'], [
        'type'  => 'call',
        'call'  => (int)$call['id'],
        'media' => $call['media'],
        'title' => $call['media'] === 'video' ? 'Incoming video call' : 'Incoming voice call',
        'body'  => $from['name'],
        'icon'  => $icon,
        'badge' => full_url('assets/icons/icon-192.png'),
        // Same tag as the follow-up below, so "Missed call" replaces the ring.
        'tag'   => 'call-' . (int)$call['id'],
        'url'   => full_url('dashboard.php?chat=' . (int)$call['caller_id'] . '&call=' . (int)$call['id']),
        'from'  => (int)$call['caller_id'],
    ]);
}

/** Replace a ringing notification once the caller has given up. */
function notify_call_missed(array $call): void {
    $from = call_user_card((int)$call['caller_id']);

    push_to_user((int)$call['callee_id'], [
        'type'  => 'call_missed',
        'call'  => (int)$call['id'],
        'title' => $call['media'] === 'video' ? 'Missed video call' : 'Missed voice call',
        'body'  => $from['name'],
        'icon'  => full_url('assets/icons/icon-192.png'),
        'badge' => full_url('assets/icons/icon-192.png'),
        'tag'   => 'call-' . (int)$call['id'],
        'url'   => full_url('dashboard.php?chat=' . (int)$call['caller_id']),
        'from'  => (int)$call['caller_id'],
    ]);
}
