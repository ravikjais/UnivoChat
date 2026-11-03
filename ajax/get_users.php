<?php
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/calls.php';
require_approved();

$uid = current_uid();
update_online_status($uid);
set_offline_stale();

$q = api_str('q');

if ($q !== '') {
    $like = '%' . like_escape($q) . '%';
    $rows = fetch_all(
        'SELECT id, username, full_name, avatar, is_online, last_active
           FROM users
          WHERE status = \'approved\' AND id != ?
            AND (username LIKE ? ESCAPE \'\\\\\' OR full_name LIKE ? ESCAPE \'\\\\\')
          ORDER BY is_online DESC, full_name ASC
          LIMIT 50',
        [$uid, $like, $like]
    );
} else {
    $rows = fetch_all(
        'SELECT id, username, full_name, avatar, is_online, last_active
           FROM users
          WHERE status = \'approved\' AND id != ?
          LIMIT 200',
        [$uid]
    );
}

// Two aggregate queries replace the previous two-queries-per-contact loop.
$lastMessages = get_last_messages_map($uid);
$unreadMap    = get_unread_map($uid);

$users = [];
foreach ($rows as $row) {
    $id     = (int)$row['id'];
    $last   = $lastMessages[$id] ?? null;
    $unread = $unreadMap[$id] ?? 0;

    // message_preview() knows how to summarise a pasted table as "📊 Table"
    // instead of dumping its markup into the contact list.
    $lastText = message_preview($last);

    $users[] = [
        'id'              => $id,
        'username'        => $row['username'],
        'full_name'       => $row['full_name'],
        'avatar'          => avatar_url($row['avatar'], $row['full_name'] ?: $row['username']),
        'is_online'       => (bool)$row['is_online'],
        'last_active'     => $row['last_active'],
        'last_active_fmt' => time_ago($row['last_active']),
        'last_message'    => $lastText,
        'last_msg_id'     => $last ? (int)$last['id'] : 0,
        'last_msg_type'   => $last['type'] ?? null,
        'last_time'       => $last ? format_chat_time($last['created_at']) : '',
        'last_ts'         => $last ? (int)strtotime($last['created_at']) : 0,
        'unread'          => $unread,
        'is_mine'         => $last ? ((int)$last['sender_id'] === $uid) : false,
        // The ticks beside my own last message, read off the same is_read flag
        // the bubble's ticks are. Meaningless for a message they sent me, whose
        // state is the unread badge instead.
        'last_is_read'    => $last && (int)$last['sender_id'] === $uid && !empty($last['is_read']),
        // A deleted bubble shows no ticks, so neither does its row.
        'last_deleted'    => $last && !empty($last['deleted_at']),
    ];
}

// Most recent conversation first, then online users, then alphabetically.
usort($users, static function (array $a, array $b): int {
    if ($a['last_ts'] !== $b['last_ts']) return $b['last_ts'] <=> $a['last_ts'];
    if ($a['is_online'] !== $b['is_online']) return $b['is_online'] <=> $a['is_online'];
    return strcasecmp((string)$a['full_name'], (string)$b['full_name']);
});

// Groups ride along in the same poll and are merged into one list by the
// client: a second request every ten seconds to say "no new group messages"
// would be pure overhead.
$groups = sidebar_groups($uid);

json_response([
    'users'  => $users,
    'groups' => $groups,
    'unread' => array_sum($unreadMap) + array_sum(array_column($groups, 'unread')),
    // A call ringing for me. This poll runs whether or not a chat is open,
    // so it is what rings a page with nothing selected.
    'incoming_call' => incoming_call_for($uid),
]);
