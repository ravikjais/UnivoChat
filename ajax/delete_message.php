<?php
/**
 * Take a message back, for everybody in the conversation.
 *
 * Only the sender may do it, and only inside MSG_DELETE_WINDOW. There is no
 * "delete for me": a conversation two people read differently is a support
 * ticket waiting to happen, and this app has one copy of each message rather
 * than a per-reader view of it.
 *
 * What actually happens lives in message_delete() — the text goes, the file on
 * disk goes, the reactions go, and a tombstone row stays behind so every other
 * open client learns about it on its next poll.
 */
require_once __DIR__ . '/../includes/api.php';
require_approved();
api_require_post();
api_require_csrf();

$uid    = current_uid();
$msg_id = api_int('id');

if ($msg_id <= 0) {
    json_response(['error' => 'Missing message.'], 400);
}

$msg = fetch_one(
    'SELECT id, sender_id, receiver_id, group_id, type, file_path, created_at, '
  . deleted_at_column('') . '
     FROM messages WHERE id = ?',
    [$msg_id]
);

// Same answer whether the message does not exist or belongs to somebody else:
// there is no reason to help anybody map out other people's conversations.
if (!$msg || (int)$msg['sender_id'] !== $uid) {
    json_response(['error' => 'That message cannot be deleted.'], 404);
}

// Already gone — succeed quietly. Two tabs, or a double tap on a slow
// connection, should not produce an error the second time round.
if (!empty($msg['deleted_at'])) {
    json_response(['ok' => true, 'id' => $msg_id, 'deleted' => true]);
}

if (!message_is_deletable($msg)) {
    json_response([
        'error' => 'This message is too old to delete ('
                 . round(MSG_DELETE_WINDOW / 3600) . ' hour limit).',
    ], 403);
}

$result = message_delete($msg);
if (!$result['ok']) {
    json_response(['error' => $result['msg']], 500);
}

json_response(['ok' => true, 'id' => $msg_id, 'deleted' => true]);
