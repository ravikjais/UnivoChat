<?php
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/gamify.php';
require_approved();
api_require_post();
api_require_csrf();

$uid    = current_uid();
$msg_id = api_int('message_id');
$emoji  = api_str('emoji');

if ($msg_id <= 0 || $emoji === '') {
    json_response(['error' => 'Missing message or emoji.'], 400);
}

// Only the picker's own emoji are storable — that keeps arbitrary text out of
// the reaction row and the chip list predictable.
if (!in_array($emoji, REACTION_EMOJIS, true)) {
    json_response(['error' => 'That reaction is not available.'], 400);
}

// can_see_message() covers both shapes: the two people in a direct chat, or
// anybody in the group a message was posted to.
$msg = fetch_one(
    'SELECT id, sender_id, receiver_id, group_id, ' . deleted_at_column('') . '
       FROM messages WHERE id = ?',
    [$msg_id]
);
if (!$msg || !can_see_message($msg, $uid)) {
    json_response(['error' => 'That message is not available.'], 404);
}

// There is nothing left to react to, and message_delete() has already cleared
// whatever reactions the message had.
if (!empty($msg['deleted_at'])) {
    json_response(['error' => 'That message was deleted.'], 410);
}

$existing = fetch_one(
    'SELECT id FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?',
    [$msg_id, $uid, $emoji]
);

if ($existing) {
    query('DELETE FROM message_reactions WHERE id = ?', [(int)$existing['id']]);
    $added = false;
} else {
    $mine = fetch_one(
        'SELECT COUNT(*) AS cnt FROM message_reactions WHERE message_id = ? AND user_id = ?',
        [$msg_id, $uid]
    );
    if ((int)($mine['cnt'] ?? 0) >= MAX_REACTIONS_PER_USER) {
        json_response([
            'error' => 'You can add up to ' . MAX_REACTIONS_PER_USER . ' reactions per message.',
        ], 400);
    }

    try {
        query(
            'INSERT INTO message_reactions (message_id, user_id, emoji) VALUES (?,?,?)',
            [$msg_id, $uid, $emoji]
        );
    } catch (PDOException $e) {
        // Double tap: the unique key already holds this reaction, which is the
        // state the user asked for anyway.
    }
    $added = true;

    // Both sides of a reaction are worth something: giving one is taking part,
    // and getting one means somebody read what you wrote. Only on the way in —
    // removing a reaction does not claw the points back, or taking one off and
    // putting it back would be a way to farm them.
    gamify_award($uid, 'react_given');
    if ((int)$msg['sender_id'] !== $uid) {
        gamify_award((int)$msg['sender_id'], 'react_received');
    }
}

json_response([
    'ok'        => true,
    'added'     => $added,
    'id'        => $msg_id,
    'reactions' => get_message_reactions($msg_id, $uid),
]);
