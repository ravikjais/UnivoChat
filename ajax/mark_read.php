<?php
require_once __DIR__ . '/../includes/api.php';
require_approved();
api_require_post();
api_require_csrf();

$uid      = current_uid();
$from_id  = api_int('from');
$group_id = api_int('group');

// A group tracks one high-water mark per member instead of a flag per message.
if ($group_id > 0) {
    if (!is_group_member($group_id, $uid)) {
        json_response(['error' => 'That group is not available.'], 404);
    }

    group_mark_read($group_id, $uid);
    json_response(['ok' => true, 'marked' => 0, 'unread' => get_total_unread($uid)]);
}

if ($from_id <= 0) {
    json_response(['error' => 'Missing sender.'], 400);
}

$marked = mark_messages_read($from_id, $uid);

json_response([
    'ok'     => true,
    'marked' => $marked,
    'unread' => get_total_unread($uid),
]);
