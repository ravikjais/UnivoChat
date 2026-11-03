<?php
/**
 * Everything shared in one conversation, for the files panel.
 *
 * Answers the same two addresses the message poll does — `with=<user>` for a
 * direct chat, `group=<id>` for a group — and applies exactly the same access
 * rules, because this hands back the very attachments those messages carry.
 *
 * The whole list comes back in one response and the client sorts it into
 * drawers: an attachment moves between "Images", "Documents" and "Archives"
 * only by what it is, so a request per tab would be three trips for one answer.
 */
require_once __DIR__ . '/../includes/api.php';
require_approved();

$uid      = current_uid();
$with_id  = api_int('with');
$group_id = api_int('group');
$isGroup  = $group_id > 0;

if ($isGroup) {
    // Membership is the only key to a group; a non-member is told the same
    // thing as somebody asking about a group that does not exist.
    if (!group_get($group_id) || !is_group_member($group_id, $uid)) {
        json_response(['error' => 'That group is not available.'], 404);
    }
} else {
    if ($with_id <= 0 || $with_id === $uid) {
        json_response(['error' => 'Missing user.'], 400);
    }
    $peer = fetch_one("SELECT id FROM users WHERE id = ? AND status = 'approved'", [$with_id]);
    if (!$peer) {
        json_response(['error' => 'This user is no longer available.'], 404);
    }
}

$rows   = conversation_attachments($uid, $isGroup ? null : $with_id, $isGroup ? $group_id : null);
$items  = [];
$counts = ['images' => 0, 'documents' => 0, 'archives' => 0];

foreach ($rows as $r) {
    $id       = (int)$r['id'];
    $name     = (string)($r['file_name'] ?: basename((string)$r['file_path']));
    $category = attachment_category((string)$r['type'], $r['file_name'], (string)$r['file_path']);
    $counts[$category]++;

    $items[] = [
        'id'       => $id,
        'category' => $category,
        // The same encrypted address the bubble uses, so an image the reader
        // already has on screen is served from cache rather than fetched twice.
        'url'      => attachment_url($id),
        'name'     => $name,
        'ext'      => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
        'size'     => format_bytes(upload_size((string)$r['file_path'])),
        'is_mine'  => (int)$r['sender_id'] === $uid,
        // First name only: the row has one line for "who and when".
        'sender'   => (int)$r['sender_id'] === $uid
                        ? 'You'
                        : explode(' ', (string)$r['sender_name'])[0],
        'date'     => format_chat_time($r['created_at']),
        'time'     => format_msg_time($r['created_at']),
    ];
}

// ── Links ─────────────────────────────────────────────────────────────────────
// Every message that carried a web address, captions included, with the words
// it was sent with — a bare URL rarely says why somebody shared it.
$linkRows = conversation_links($uid, $isGroup ? null : $with_id, $isGroup ? $group_id : null);
$links    = [];

foreach ($linkRows as $r) {
    $urls = message_links($r['message'], (string)($r['format'] ?? 'plain'));
    if (!$urls) continue;

    $text = ($r['format'] ?? 'plain') === 'html' ? html_to_text((string)$r['message']) : (string)$r['message'];

    $links[] = [
        'id'      => (int)$r['id'],
        'urls'    => $urls,
        // The message as plain text, line breaks kept; the client shortens it.
        'text'    => mb_substr(trim($text), 0, 600),
        // An attachment's caption is labelled as one, so "see the drawing"
        // makes sense next to a link with no drawing in sight.
        'kind'    => (string)$r['type'],
        'file'    => $r['type'] !== 'text' ? (string)($r['file_name'] ?? '') : '',
        'is_mine' => (int)$r['sender_id'] === $uid,
        'sender'  => (int)$r['sender_id'] === $uid
                        ? 'You'
                        : explode(' ', (string)$r['sender_name'])[0],
        'date'    => format_chat_time($r['created_at']),
        'time'    => format_msg_time($r['created_at']),
    ];
}
$counts['links'] = count($links);

json_response([
    'items'  => $items,
    'links'  => $links,
    'counts' => $counts,
    'total'  => count($items),
    // True when the conversation has more attachments than one panel shows, so
    // the client can say so instead of quietly presenting a partial list.
    'capped' => count($rows) >= MEDIA_PANEL_LIMIT || count($linkRows) >= MEDIA_PANEL_LIMIT,
]);
