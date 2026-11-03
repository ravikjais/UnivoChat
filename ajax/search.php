<?php
/**
 * Search the conversations themselves, not just the people in them.
 *
 * The sidebar's search box already asked ajax/get_users.php which *people* match
 * a word. This answers the other half — which *messages* contain it — so that
 * looking for "invoice" finds the message about the invoice and not merely
 * everyone whose name happens to contain those letters.
 *
 * What is searchable is exactly what the asker may already read: their own
 * direct messages, and the groups they belong to. Nothing here widens that.
 */
require_once __DIR__ . '/../includes/api.php';
require_approved();

$uid = current_uid();
$q   = api_str('q');

// One character matches most of the database and none of it usefully.
if (mb_strlen($q) < 2) {
    json_response(['messages' => [], 'query' => $q, 'truncated' => false]);
}

$limit = max(1, min(60, api_int('limit', SEARCH_RESULT_LIMIT)));
$like  = '%' . like_escape($q) . '%';

$hasGroups  = messages_have('group_id');
$myGroups   = $hasGroups ? array_column(get_user_groups($uid), 'id') : [];
$myGroups   = array_map('intval', $myGroups);

// ── Where may this person read? ───────────────────────────────────────────────
// A direct message is mine if I am either end of it; a group message is mine if
// I am in the group. Everything else is invisible, and the WHERE clause — not a
// filter after the fact — is what makes that true.
$scopes = [];
$args   = [];

$directScope = $hasGroups
    ? '(m.group_id IS NULL AND (m.sender_id = ? OR m.receiver_id = ?))'
    : '(m.sender_id = ? OR m.receiver_id = ?)';
$scopes[] = $directScope;
$args[]   = $uid;
$args[]   = $uid;

if ($myGroups) {
    $in       = implode(',', array_fill(0, count($myGroups), '?'));
    $scopes[] = "m.group_id IN ($in)";
    $args     = array_merge($args, $myGroups);
}

// ── What counts as a match ────────────────────────────────────────────────────
// The text of a message, and the name of an attachment: "the PDF Ravi sent" is
// exactly as much a thing people search for as a sentence someone typed.
$match  = '(m.message LIKE ? ESCAPE \'\\\\\' OR m.file_name LIKE ? ESCAPE \'\\\\\')';
$args[] = $like;
$args[] = $like;

$where = '(' . implode(' OR ', $scopes) . ') AND ' . $match;

// A call-log row's text is JSON ("media":"video"…) that nobody typed.
$where .= " AND m.type <> 'call'";

// A deleted message has no text left to match, but an un-migrated install has
// no column to say so either.
if (messages_have('deleted_at')) {
    $where .= ' AND m.deleted_at IS NULL';
}

$columns = 'm.id, m.sender_id, m.receiver_id, m.message, m.format, m.type,
            m.file_name, m.created_at,
            u.full_name AS sender_name, u.username AS sender_username, u.avatar AS sender_avatar'
         . ($hasGroups ? ', m.group_id' : ', NULL AS group_id');

// One row past the limit answers "is there more than this?" without a COUNT
// across every message the asker can see.
$rows = fetch_all(
    "SELECT $columns
       FROM messages m
       JOIN users u ON u.id = m.sender_id
      WHERE $where
      ORDER BY m.id DESC
      LIMIT " . ($limit + 1),
    $args
);

$truncated = count($rows) > $limit;
$rows      = array_slice($rows, 0, $limit);

// ── Who each hit belongs to ───────────────────────────────────────────────────
// Resolved in two queries rather than two per row: a search that matched forty
// messages across a dozen conversations would otherwise be eighty round trips.
$peerIds  = [];
$groupIds = [];
foreach ($rows as $row) {
    if (!empty($row['group_id'])) {
        $groupIds[(int)$row['group_id']] = true;
        continue;
    }
    $peer = (int)$row['sender_id'] === $uid ? (int)$row['receiver_id'] : (int)$row['sender_id'];
    if ($peer > 0) $peerIds[$peer] = true;
}

$peers = [];
if ($peerIds) {
    $ids = array_keys($peerIds);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    foreach (fetch_all("SELECT id, username, full_name, avatar FROM users WHERE id IN ($in)", $ids) as $u) {
        $peers[(int)$u['id']] = $u;
    }
}

$groups = [];
if ($groupIds) {
    $ids = array_keys($groupIds);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    foreach (fetch_all(
        'SELECT id, name, ' . group_avatar_column() . " FROM chat_groups WHERE id IN ($in)",
        $ids
    ) as $g) {
        $groups[(int)$g['id']] = $g;
    }
}

// ── Shape it ──────────────────────────────────────────────────────────────────
$out = [];
foreach ($rows as $row) {
    $gid = (int)($row['group_id'] ?? 0);

    if ($gid > 0) {
        $group = $groups[$gid] ?? null;
        if (!$group) continue;                       // left the group mid-request
        $where_name = (string)$group['name'];
        $avatar     = group_avatar_url($group['avatar'] ?? null, $where_name);
        $target     = ['group_id' => $gid];
    } else {
        $peerId = (int)$row['sender_id'] === $uid ? (int)$row['receiver_id'] : (int)$row['sender_id'];
        $peer   = $peers[$peerId] ?? null;
        if (!$peer) continue;                        // the account was removed
        $where_name = (string)($peer['full_name'] ?: $peer['username']);
        $avatar     = avatar_url($peer['avatar'], $where_name);
        $target     = ['user_id' => $peerId];
    }

    $mine = (int)$row['sender_id'] === $uid;

    $out[] = $target + [
        'id'        => (int)$row['id'],
        'is_group'  => $gid > 0,
        // The conversation the hit is in — the line the result is filed under.
        'where'     => $where_name,
        'avatar'    => $avatar,
        // Who said it. In a group this is the half that says whether to keep
        // reading; in a one-to-one it is "You" or the person you are looking at.
        'who'       => $mine ? 'You' : (string)($row['sender_name'] ?: $row['sender_username']),
        'is_mine'   => $mine,
        // A window of the message around the word that matched, rather than its
        // first sixty characters — the match is the whole point of the row.
        'snippet'   => search_snippet($row, $q),
        'time'      => format_chat_time($row['created_at']),
        'date'      => format_date_separator($row['created_at']),
    ];
}

json_response([
    'messages'  => $out,
    'query'     => $q,
    'truncated' => $truncated,
]);
