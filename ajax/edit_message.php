<?php
require_once __DIR__ . '/../includes/api.php';
require_approved();
api_require_post();
api_require_csrf();

$uid     = current_uid();
$msg_id  = api_int('id');
$body    = (string)($_POST['message'] ?? '');
$format  = api_str('format') === 'html' ? 'html' : 'plain';

if ($msg_id <= 0) {
    json_response(['error' => 'Missing message.'], 400);
}

$msg = fetch_one(
    'SELECT id, sender_id, receiver_id, type, message, format, edited_at, created_at, '
  . deleted_at_column('') . ', ' . forwarded_column('') . '
       FROM messages WHERE id = ?',
    [$msg_id]
);

// Same answer whether the message does not exist or belongs to somebody else:
// there is no reason to help anybody map out other people's conversations.
if (!$msg || (int)$msg['sender_id'] !== $uid) {
    json_response(['error' => 'That message cannot be edited.'], 404);
}
if ($msg['type'] !== 'text') {
    json_response(['error' => 'Only text messages can be edited.'], 400);
}
if (!empty($msg['forwarded'])) {
    json_response(['error' => 'Forwarded messages cannot be edited.'], 400);
}
if (!message_is_editable($msg)) {
    json_response([
        'error' => 'This message is too old to edit (' . round(MSG_EDIT_WINDOW / 60) . ' minute limit).',
    ], 403);
}

['message' => $body, 'format' => $format] = normalize_message_body($body, $format);

if ($body === '') {
    json_response(['error' => 'An edited message cannot be empty.'], 400);
}

$tooLong = message_length_error($body, $format);
if ($tooLong !== '') {
    json_response(['error' => $tooLong], 400);
}

// Nothing changed – succeed quietly rather than stamping a pointless edit mark.
if ($body === (string)$msg['message'] && $format === ($msg['format'] ?? 'plain')) {
    json_response([
        'ok'      => true,
        'id'      => $msg_id,
        'message' => $body,
        'format'  => $format,
        'edited'  => !empty($msg['edited_at']),
    ]);
}

query(
    'UPDATE messages SET message = ?, format = ?, edited_at = NOW() WHERE id = ? AND sender_id = ?',
    [$body, $format, $msg_id, $uid]
);

json_response([
    'ok'      => true,
    'id'      => $msg_id,
    'message' => $body,
    'format'  => $format,
    'edited'  => true,
]);
