<?php
/**
 * Everything a call needs from the server, by `action`:
 *
 *   ice_config  GET   the iceServers list (STUN, and TURN with a fresh credential)
 *   start       POST  to, media      → ring somebody; answers `busy` when they are on a call
 *   accept      POST  call           → the callee picks up
 *   reject      POST  call           → the callee declines
 *   hangup      POST  call[, reason] → either side; also what sendBeacon sends on pagehide
 *   signal      POST  call, kind, payload → hand an offer/answer/candidates to the other side
 *   poll        GET   call, after    → new signals since `after`, plus the call's state;
 *                                     doubles as the side's heartbeat
 *
 * See includes/calls.php for the state machine.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/calls.php';
require_approved();

$uid    = current_uid();
$action = api_str('action');

if (!calls_available()) {
    json_response(['error' => 'Calls are turned off.'], 403);
}
if (!calls_installed()) {
    json_response(['error' => 'Calls are not set up yet. Run setup/install.php.'], 503);
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost) {
    api_require_csrf();
}

// Every request settles abandoned calls first, so "busy" and "this call has
// ended" are answered from the truth rather than from a tab that closed.
calls_sweep();

/** The call named by `call`, for one of its participants — or a 404. */
$requireCall = static function () use ($uid): array {
    $call = call_for_participant(api_int('call'), $uid);
    if (!$call) {
        json_response(['error' => 'That call is not available.'], 404);
    }
    return $call;
};

switch ($action) {

    case 'ice_config':
        json_response(['iceServers' => call_ice_servers($uid)]);

    case 'start':
        api_require_post();

        $to    = api_int('to');
        $media = api_str('media', 'audio');

        if (!in_array($media, CALL_MEDIA, true)) {
            json_response(['error' => 'Unknown call type.'], 400);
        }
        if ($to <= 0 || $to === $uid) {
            json_response(['error' => 'Missing or invalid recipient.'], 400);
        }

        $callee = fetch_one("SELECT id FROM users WHERE id = ? AND status = 'approved'", [$to]);
        if (!$callee) {
            json_response(['error' => 'This user is no longer available.'], 404);
        }

        $recent = (int)(fetch_one(
            'SELECT COUNT(*) AS c FROM calls WHERE caller_id = ? AND created_at >= NOW() - INTERVAL 1 MINUTE',
            [$uid]
        )['c'] ?? 0);
        if ($recent >= CALL_START_PER_MIN) {
            json_response(['error' => 'Too many calls in a row. Try again in a minute.'], 429);
        }

        // Check-then-insert under a lock: two people calling each other in the
        // same instant must not both get a ringing call (the glare case) —
        // one wins, the other is told the line is busy.
        $locked = (int)(fetch_one("SELECT GET_LOCK('univochat_calls', 5) AS l")['l'] ?? 0) === 1;
        if (!$locked) {
            json_response(['error' => 'Could not place the call. Please try again.'], 503);
        }

        // No json_response() inside the try: exit skips `finally`, and the
        // lock would then be held until the connection closed.
        $mine = $call = null;
        try {
            $mine = active_call_for($uid);
            if (!$mine) {
                $calleeBusy = (bool)active_call_for($to);
                query(
                    "INSERT INTO calls (caller_id, callee_id, media, status, last_seen_caller)
                     VALUES (?, ?, ?, 'ringing', NOW())",
                    [$uid, $to, $media]
                );
                $call = call_get((int)db()->lastInsertId());
                if ($calleeBusy) {
                    // Logged, so the person who was busy can see who tried them.
                    call_finish($call, 'busy', $to);
                    $call = call_get((int)$call['id']);
                }
            }
        } finally {
            query("SELECT RELEASE_LOCK('univochat_calls')");
        }

        if ($mine) {
            json_response(['error' => 'You are already on a call.', 'call' => call_payload($mine, $uid)], 409);
        }
        if ($call['status'] === 'busy') {
            json_response(['busy' => true, 'call' => call_payload($call, $uid)]);
        }

        api_respond_and_continue([
            'ok'   => true,
            'call' => call_payload($call, $uid),
            'ring' => CALL_RING_TIMEOUT,
        ]);
        notify_incoming_call($call);
        exit;

    case 'accept':
        api_require_post();
        $call = $requireCall();

        if (call_role($call, $uid) !== 'callee') {
            json_response(['error' => 'Only the person being called can answer.'], 403);
        }

        $answered = query(
            "UPDATE calls SET status = 'accepted', answered_at = NOW(), last_seen_callee = NOW()
              WHERE id = ? AND callee_id = ? AND status = 'ringing'",
            [(int)$call['id'], $uid]
        )->rowCount() === 1;

        $call = call_get((int)$call['id']);
        if (!$answered) {
            json_response(['error' => 'This call has already ended.', 'call' => call_payload($call, $uid)], 409);
        }
        json_response(['ok' => true, 'call' => call_payload($call, $uid)]);

    case 'reject':
        api_require_post();
        $call = $requireCall();

        if (call_role($call, $uid) !== 'callee' || $call['status'] !== 'ringing') {
            json_response(['ok' => true, 'call' => call_payload($call, $uid)]);
        }
        call_finish($call, 'rejected', $uid);
        json_response(['ok' => true, 'call' => call_payload(call_get((int)$call['id']), $uid)]);

    case 'hangup':
        api_require_post();
        $call = $requireCall();

        // Already over: hanging up twice (button, then pagehide beacon) is fine.
        if (!call_is_live($call)) {
            json_response(['ok' => true, 'call' => call_payload($call, $uid)]);
        }

        $role = call_role($call, $uid);
        if (api_str('reason') === 'failed') {
            // The connection never came up, or dropped and could not recover.
            $status = 'failed';
        } elseif ($call['status'] === 'accepted') {
            $status = 'ended';
        } elseif ($role === 'callee') {
            $status = 'rejected';
        } else {
            // The caller's own ring timer ending the call reads as missed;
            // putting the phone down early reads as cancelled. A couple of
            // seconds' grace covers the two clocks not quite agreeing.
            $age    = time() - strtotime((string)$call['created_at']);
            $status = $age >= CALL_RING_TIMEOUT - 3 ? 'missed' : 'cancelled';
        }

        $wasRinging = $call['status'] === 'ringing';
        $finished   = call_finish($call, $status, $uid);
        $call       = call_get((int)$call['id']);

        if ($finished && $wasRinging && $role === 'caller') {
            // The callee's phone may still be showing the ring.
            api_respond_and_continue(['ok' => true, 'call' => call_payload($call, $uid)]);
            notify_call_missed($call);
            exit;
        }
        json_response(['ok' => true, 'call' => call_payload($call, $uid)]);

    case 'signal':
        api_require_post();
        $call = $requireCall();

        if (!call_is_live($call)) {
            json_response(['error' => 'This call has ended.', 'call' => call_payload($call, $uid)], 409);
        }
        // The caller's offer goes out while it rings; the callee has nothing
        // to say until they have picked up.
        if ($call['status'] === 'ringing' && call_role($call, $uid) !== 'caller') {
            json_response(['error' => 'Answer the call first.'], 409);
        }

        $kind    = api_str('kind');
        $payload = (string)($_POST['payload'] ?? '');

        if (!in_array($kind, CALL_SIGNAL_KINDS, true)) {
            json_response(['error' => 'Unknown signal.'], 400);
        }
        if ($payload === '' || strlen($payload) > CALL_SIGNAL_MAX_BYTES) {
            json_response(['error' => 'Signal is empty or too large.'], 413);
        }
        json_decode($payload);
        if (json_last_error() !== JSON_ERROR_NONE) {
            json_response(['error' => 'Signal must be JSON.'], 400);
        }

        query(
            'INSERT INTO call_signals (call_id, from_id, to_id, kind, payload) VALUES (?, ?, ?, ?, ?)',
            [(int)$call['id'], $uid, call_peer_id($call, $uid), $kind, $payload]
        );
        $signalId = (int)db()->lastInsertId();   // before the heartbeat's UPDATE resets it
        call_touch($call, $uid);
        json_response(['ok' => true, 'id' => $signalId]);

    case 'poll':
        $call  = $requireCall();
        $after = max(0, api_int('after'));

        $signals = [];
        if (call_is_live($call)) {
            call_touch($call, $uid);

            $rows = fetch_all(
                'SELECT id, kind, payload FROM call_signals
                  WHERE call_id = ? AND to_id = ? AND id > ?
                  ORDER BY id ASC LIMIT 50',
                [(int)$call['id'], $uid, $after]
            );
            foreach ($rows as $row) {
                $signals[] = [
                    'id'      => (int)$row['id'],
                    'kind'    => $row['kind'],
                    'payload' => json_decode((string)$row['payload'], true),
                ];
            }
        }

        json_response([
            'call'    => call_payload($call, $uid),
            'signals' => $signals,
            'now'     => time(),
        ]);

    default:
        json_response(['error' => 'Unknown action.'], 400);
}
