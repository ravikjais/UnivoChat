<?php
/**
 * Forward one message into up to FORWARD_MAX_TARGETS other conversations.
 *
 * Anything the forwarder can see may be forwarded — text, a pasted table, a
 * photo, a document, an archive, a voice note, a GIF — with its caption, into
 * any person they could message or any group they belong to. The copy is a new
 * message from the forwarder, flagged `forwarded`, and stands on its own: an
 * attachment gets bytes of its own, so it survives the original being deleted.
 *
 * Every destination is attempted and answered separately. One failing never
 * takes the others down with it, and the client retries only what failed —
 * with the same `key`, which forward_message_to() uses to hand back the message
 * already written instead of a duplicate when a response was merely lost.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/push.php';
require_approved();
api_require_post();
api_require_csrf();

$uid    = current_uid();
$msg_id = api_int('id');
$key    = strtolower(api_str('key'));
$targets = forward_targets(api_str('targets'));

if (!messages_have('forwarded')) {
    json_response([
        'error' => 'This database has not been updated for forwarding yet. Run setup/install.php once.',
    ], 500);
}
if ($msg_id <= 0) {
    json_response(['error' => 'Missing message.'], 400);
}
if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
    json_response(['error' => 'Missing forward key.'], 400);
}
if (!$targets) {
    json_response(['error' => 'Choose a conversation to forward to.'], 400);
}
if (count($targets) > FORWARD_MAX_TARGETS) {
    json_response(['error' => 'You can forward to at most ' . FORWARD_MAX_TARGETS . ' chats at once.'], 400);
}

$src = fetch_one(
    'SELECT id, sender_id, receiver_id, group_id, message, format, type, file_path, file_name, '
  . deleted_at_column('') . '
     FROM messages WHERE id = ?',
    [$msg_id]
);

// One answer for "no such message" and "not yours to see".
if (!$src || !can_see_message($src, $uid)) {
    json_response(['error' => 'That message cannot be forwarded.'], 404);
}
if (!empty($src['deleted_at'])) {
    json_response(['error' => 'A deleted message cannot be forwarded.'], 400);
}
if ($src['type'] !== 'text' && empty($src['file_path'])) {
    json_response(['error' => 'That attachment is no longer available.'], 400);
}
if ($src['type'] === 'text' && trim((string)$src['message']) === '') {
    json_response(['error' => 'There is nothing in that message to forward.'], 400);
}

$results  = [];
$notify   = [];   // destinations that got a brand-new message

foreach ($targets as [$kind, $target]) {
    $label = ($kind === 'group' ? 'g' : 'u') . $target;

    // The same checks sending applies: a person who is approved and is not me,
    // or a group I am a member of. Forwarding is not a way round either.
    if ($kind === 'group') {
        $ok = group_get($target) && is_group_member($target, $uid);
    } else {
        $ok = $target !== $uid
           && fetch_one("SELECT id FROM users WHERE id = ? AND status = 'approved'", [$target]);
    }
    if (!$ok) {
        $results[] = ['target' => $label, 'ok' => false, 'error' => 'That chat is not available.'];
        continue;
    }

    $done = forward_message_to($src, $uid, $kind, $target, $key);
    if (!$done['ok']) {
        $results[] = ['target' => $label, 'ok' => false, 'error' => $done['msg']];
        continue;
    }

    $results[] = ['target' => $label, 'ok' => true, 'id' => $done['id'], 'duplicate' => $done['duplicate']];

    if (!$done['duplicate']) {
        // My own message is read by definition, in a group as anywhere.
        if ($kind === 'group') group_mark_read($target, $uid);
        $notify[] = [$kind, $target];
    }
}

$failed = array_values(array_filter($results, static fn(array $r): bool => !$r['ok']));

api_respond_and_continue([
    'success' => !$failed,
    'results' => $results,
    'failed'  => count($failed),
]);

// ── After the response ────────────────────────────────────────────────────────
if ($notify) {
    $preview = '↪️ ' . message_preview($src, 120);
    foreach ($notify as [$kind, $target]) {
        notify_new_message(
            $uid,
            $kind === 'user'  ? $target : null,
            $kind === 'group' ? $target : null,
            $preview
        );
    }
}
