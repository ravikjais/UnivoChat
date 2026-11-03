<?php
/**
 * The polling endpoint for whichever conversation is open — a person (`with`)
 * or a group (`group`). Both live in the same `messages` table, so everything
 * below the target check is shared: new messages, edits, deletions, reactions,
 * typing.
 *
 * It answers three different questions depending on which bound it is given:
 *
 *   before_id  → one page of *older* history, for scrolling upwards. Nothing
 *                else is computed: the client already has the rest.
 *   after_id   → the same downwards, for climbing back out of a conversation
 *                that was opened in the middle.
 *   around_id  → a window centred on one message, which is how a search result
 *                opens the conversation it was found in.
 *   last_id    → whatever has arrived since, plus edits, deletions, reactions
 *                and read receipts. The steady-state poll.
 *   neither    → the newest page, for a conversation that has just been opened.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/calls.php';
require_approved();

$uid       = current_uid();
$with_id   = api_int('with');
$group_id  = api_int('group');
$last_id   = api_int('last_id');
$from_id   = api_int('from_id');    // oldest message the client still has on screen
$before_id = api_int('before_id');  // asking for the page above that one
$after_id  = api_int('after_id');   // …and for the page below it
$around_id = api_int('around_id');  // open centred on this message (a search hit)
$since     = api_int('since');      // server clock of the client's previous poll
$active    = api_bool('active');    // chat is open AND the tab is visible

$isGroup = $group_id > 0;
// A paging request in either direction needs nothing but the messages: the
// client already has the target, the typing state and the unread count.
$history = $before_id > 0 || $after_id > 0;

if (!$isGroup && $with_id <= 0) {
    json_response(['error' => 'Missing user.'], 400);
}

if ($isGroup) {
    // Membership is the only key to a group. A non-member gets the same answer
    // as somebody asking about a group that does not exist.
    $group = group_get($group_id);
    if (!$group || !is_group_member($group_id, $uid)) {
        json_response(['error' => 'That group is not available.'], 404);
    }
} else {
    if ($with_id === $uid) {
        json_response(['error' => 'You cannot chat with yourself.'], 400);
    }

    $target = fetch_one(
        'SELECT id, username, full_name, avatar, is_online, last_active
           FROM users WHERE id = ? AND status = \'approved\'',
        [$with_id]
    );
    if (!$target) {
        json_response(['error' => 'This user is no longer available.'], 404);
    }
}

// Only mark as read while the conversation is actually on screen — and never
// while the reader is simply paging back through old messages.
if ($active && !$history) {
    $isGroup ? group_mark_read($group_id, $uid) : mark_messages_read($with_id, $uid);
}

// A group message carries its sender's name and picture: in a room of eight
// people the bubble alignment alone does not say who is talking.
//
// The LEFT JOIN onto `messages` again is what a quoted reply is built from, so
// a bubble that answers another one costs no extra query. It is only added when
// the database has been migrated for replies.
$hasReplies = messages_have('reply_to');

$columns = 'm.id, m.sender_id, m.receiver_id, m.group_id, m.message, m.format, m.type,
            m.file_path, m.file_name, m.is_read, m.edited_at, m.created_at, '
         . deleted_at_column() . ', ' . reply_to_column() . ', ' . forwarded_column() . ',
            u.full_name AS sender_name, u.username AS sender_username, u.avatar AS sender_avatar';

$from = 'FROM messages m JOIN users u ON u.id = m.sender_id';

if ($hasReplies) {
    $columns .= ',
            r.id         AS reply_id,
            r.sender_id  AS reply_sender_id,
            r.message    AS reply_message,
            r.format     AS reply_format,
            r.type       AS reply_type,
            r.file_name  AS reply_file_name,
            r.deleted_at AS reply_deleted_at,
            ru.full_name AS reply_sender_name';
    $from .= ' LEFT JOIN messages r ON r.id = m.reply_to
               LEFT JOIN users    ru ON ru.id = r.sender_id';
}

$scope  = $isGroup
    ? 'm.group_id = ?'
    : '((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?))';
$scopeArgs = $isGroup ? [$group_id] : [$uid, $with_id, $with_id, $uid];

// ── Which slice of the conversation ───────────────────────────────────────────
// One row beyond a page is always fetched: it answers "is there more that way?"
// without a second COUNT over the whole conversation.
$hasMore      = false;   // …above the returned slice
$hasMoreAfter = false;   // …below it

if ($before_id > 0) {
    // One page older than what the client already has. Fetched newest-first and
    // reversed, so the ids come back in the order they are prepended in.
    $page = max(1, min(200, MSG_HISTORY_PAGE));
    $rows = fetch_all(
        "SELECT $columns $from WHERE $scope AND m.id < ? ORDER BY m.id DESC LIMIT " . ($page + 1),
        [...$scopeArgs, $before_id]
    );
    $hasMore = count($rows) > $page;
    $msgs    = array_reverse(array_slice($rows, 0, $page));
} elseif ($after_id > 0) {
    // The mirror image: one page *newer*, for a reader climbing back down
    // towards the present after opening the conversation on an old message.
    $page = max(1, min(200, MSG_HISTORY_PAGE));
    $rows = fetch_all(
        "SELECT $columns $from WHERE $scope AND m.id > ? ORDER BY m.id ASC LIMIT " . ($page + 1),
        [...$scopeArgs, $after_id]
    );
    $hasMoreAfter = count($rows) > $page;
    $msgs         = array_slice($rows, 0, $page);
} elseif ($around_id > 0) {
    // A window centred on one message: half a page of what came before it, half
    // of what came after. Two bounded queries rather than one page walked
    // backwards from the present, which for a message from March would be
    // thousands of rows the reader never asked for.
    $page = max(2, min(200, MSG_PAGE_SIZE));
    $half = max(1, intdiv($page, 2));

    // Inclusive of the target itself, which is the row the reader is here for.
    $above = fetch_all(
        "SELECT $columns $from WHERE $scope AND m.id <= ? ORDER BY m.id DESC LIMIT " . ($half + 1),
        [...$scopeArgs, $around_id]
    );
    $below = fetch_all(
        "SELECT $columns $from WHERE $scope AND m.id > ? ORDER BY m.id ASC LIMIT " . ($half + 1),
        [...$scopeArgs, $around_id]
    );

    $hasMore      = count($above) > $half;
    $hasMoreAfter = count($below) > $half;
    $msgs         = array_merge(
        array_reverse(array_slice($above, 0, $half)),
        array_slice($below, 0, $half)
    );
} elseif ($last_id > 0) {
    $msgs = fetch_all(
        "SELECT $columns $from WHERE $scope AND m.id > ? ORDER BY m.id ASC LIMIT 200",
        [...$scopeArgs, $last_id]
    );
} else {
    // Initial load: the newest page, returned oldest-first.
    $page = max(1, min(200, MSG_PAGE_SIZE));
    $rows = fetch_all(
        "SELECT $columns $from WHERE $scope ORDER BY m.id DESC LIMIT " . ($page + 1),
        $scopeArgs
    );
    $hasMore = count($rows) > $page;
    $msgs    = array_reverse(array_slice($rows, 0, $page));
}

/** Shape one row the way the client renders it. */
$present = static function (array $m) use ($uid, $isGroup): array {
    $deleted = !empty($m['deleted_at']);

    $isImage = !$deleted && $m['type'] === 'image' && !empty($m['file_path']);
    $isDoc   = !$deleted && $m['type'] === 'file'  && !empty($m['file_path']);
    $isAudio = !$deleted && $m['type'] === 'audio' && !empty($m['file_path']);
    // A GIF, sticker or meme already holds an absolute KLIPY address; only an
    // uploaded file needs the uploads/ prefix bolted on.
    $isKlipy = !$deleted && in_array($m['type'], ['gif', 'sticker', 'meme'], true) && !empty($m['file_path']);
    $local   = $isImage || $isDoc || $isAudio;

    // An uploaded file is addressed by an encrypted token, never by its name on
    // disk: ajax/file.php decrypts it and checks the asker may see the message
    // before a single byte leaves uploads/media.
    $row = [
        'id'         => (int)$m['id'],
        'sender_id'  => (int)$m['sender_id'],
        'is_mine'    => (int)$m['sender_id'] === $uid,
        // A tombstone carries nothing it used to: message_delete() cleared the
        // text, the file name and the bytes, and 'deleted' is what tells the
        // client to draw the placeholder instead of an empty bubble.
        'deleted'    => $deleted,
        'message'    => $deleted ? '' : $m['message'],
        'format'     => $deleted ? 'plain' : ($m['format'] ?? 'plain'),
        'type'       => $deleted ? 'text' : $m['type'],
        'file_path'  => $local ? attachment_url((int)$m['id'])
                      : ($isKlipy ? (string)$m['file_path'] : null),
        'file_name'  => $deleted ? null : $m['file_name'],
        // Read off disk rather than stored: attachments predate any size column,
        // and stat() on a local file is cheaper than the migration would be.
        'file_size'  => $isImage || $isDoc
                      ? format_bytes(upload_size((string)$m['file_path']))
                      : '',
        'is_read'    => (bool)$m['is_read'],
        'edited'     => !$deleted && !empty($m['edited_at']),
        // Labelled on both sides, and never editable: a forward says somebody
        // else's words, so rewriting them under the label would misquote them.
        'forwarded'  => !$deleted && !empty($m['forwarded']),
        'can_edit'   => (int)$m['sender_id'] === $uid && $m['type'] === 'text'
                      && empty($m['forwarded']) && message_is_editable($m),
        'can_delete' => (int)$m['sender_id'] === $uid && message_is_deletable($m),
        'reply'      => $deleted ? null : reply_payload($m, $uid),
        'time'       => format_msg_time($m['created_at']),
        'date'       => format_date_separator($m['created_at']),
        'created_at' => $m['created_at'],
    ];

    if ($isGroup) {
        $row['sender_name']   = $m['sender_name'];
        $row['sender_avatar'] = avatar_url($m['sender_avatar'], $m['sender_name'] ?: $m['sender_username']);
    }

    return $row;
};

$result = array_map($present, $msgs);

// ── A page of history needs nothing else ──────────────────────────────────────
// The client already has the target, the typing state and the unread count from
// its steady-state poll; recomputing them here would be four queries spent on
// answers it is about to throw away.
if ($history) {
    json_response([
        'messages'       => $result,
        'history'        => true,
        'has_more'       => $hasMore,
        'has_more_after' => $hasMoreAfter,
        'now'            => time(),
    ]);
}

// ── Changes to messages the client already rendered ───────────────────────────
// Polling only asks for ids above last_id, so an edit or a deletion further up
// would never arrive. `since` is the server clock from the previous response; a
// small overlap is deliberate, since re-applying an unchanged edit costs
// nothing. The bound is the whole window on screen, not just the newest page —
// somebody can delete a message from an hour ago.
$edits   = [];
$deleted = [];

if ($since > 0 && $last_id > 0) {
    $changedSince = max(0, $since - 5);

    $edited = fetch_all(
        "SELECT $columns $from
          WHERE $scope
            AND m.edited_at IS NOT NULL
            AND m.edited_at >= FROM_UNIXTIME(?)
            AND m.id <= ?
          ORDER BY m.id ASC
          LIMIT 200",
        [...$scopeArgs, $changedSince, $last_id]
    );
    $edits = array_map($present, $edited);

    if (messages_have('deleted_at')) {
        // Ids only: there is nothing left of these rows worth sending, and the
        // client turns each one into the same placeholder.
        $deleted = array_map('intval', fetch_col(
            "SELECT m.id FROM messages m
              WHERE $scope
                AND m.deleted_at IS NOT NULL
                AND m.deleted_at >= FROM_UNIXTIME(?)
                AND m.id <= ?
              ORDER BY m.id ASC
              LIMIT 500",
            [...$scopeArgs, $changedSince, $last_id]
        ));
    }
}

// Reactions cover the whole window the client has on screen, not just the new
// messages: somebody can react to a message from an hour ago.
$window = $from_id > 0 ? $from_id : (int)($msgs[0]['id'] ?? 0);
$reactions = [];
if ($window > 0) {
    $reactions = $isGroup
        ? get_group_reactions_map($uid, $group_id, $window)
        : get_reactions_map($uid, $with_id, $window);
}

// ── Who is typing ─────────────────────────────────────────────────────────────
if ($isGroup) {
    $typists   = group_typists($group_id, $uid);
    $is_typing = (bool)$typists;
} else {
    $typing = fetch_one(
        'SELECT updated_at FROM typing_status WHERE user_id = ? AND to_user_id = ?',
        [$with_id, $uid]
    );
    $typists   = [];
    $is_typing = $typing && (time() - strtotime($typing['updated_at'])) < TYPING_TTL;
}

$targetPayload = $isGroup
    ? [
        'id'           => (int)$group['id'],
        'is_group'     => true,
        'name'         => $group['name'],
        'full_name'    => $group['name'],
        'description'  => $group['description'],
        'avatar'       => group_avatar_url($group['avatar'] ?? null, (string)$group['name']),
        'has_photo'    => group_has_photo($group['avatar'] ?? null),
        'member_count' => (int)(fetch_one(
            'SELECT COUNT(*) AS c FROM group_members WHERE group_id = ?', [$group_id]
        )['c'] ?? 0),
        'my_role'      => group_member_role($group_id, $uid),
    ]
    : [
        'id'          => (int)$target['id'],
        'is_group'    => false,
        'username'    => $target['username'],
        'full_name'   => $target['full_name'],
        'avatar'      => avatar_url($target['avatar'], $target['full_name'] ?: $target['username']),
        'is_online'   => (bool)$target['is_online'],
        'last_active' => time_ago($target['last_active']),
    ];

json_response([
    'messages'  => $result,
    'edits'     => $edits,
    // Ids the client should replace with "This message was deleted".
    'deleted'   => $deleted,
    'reactions' => (object)$reactions,
    'target'    => $targetPayload,
    // Only meaningful on the first load: whether anything sits above this page…
    'has_more'  => $hasMore,
    // …and, when the conversation was opened on an old message, below it. The
    // client stays anchored — and stops polling for new arrivals — until this
    // comes back false, otherwise every poll would drag in months of backlog.
    'has_more_after' => $hasMoreAfter,
    'is_typing' => $is_typing,
    // Names, so a group can say "Ana and Ravi are typing…" instead of "someone".
    'typists'   => $typists,
    // Highest id of my messages the other side has read – lets the client turn
    // single ticks into double ticks without reloading the conversation. Groups
    // have no per-member ticks, so it stays 0 there.
    'read_upto' => $isGroup ? 0 : last_read_message_id($uid, $with_id),
    'unread'    => get_total_unread($uid),
    // The 3-second poll, so with a chat open a ring shows up within seconds
    // even when the push is slow or the browser has none.
    'incoming_call' => incoming_call_for($uid),
    'now'       => time(),
]);
