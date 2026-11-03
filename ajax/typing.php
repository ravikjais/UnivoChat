<?php
require_once __DIR__ . '/../includes/api.php';
require_approved();

$uid      = current_uid();
$to_id    = api_int('to');
$group_id = api_int('group');

if ($group_id > 0) {
    if (!is_group_member($group_id, $uid)) {
        json_response(['error' => 'That group is not available.'], 404);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        api_require_csrf();

        query(
            'INSERT INTO group_typing (group_id, user_id, updated_at) VALUES (?,?, NOW())
             ON DUPLICATE KEY UPDATE updated_at = NOW()',
            [$group_id, $uid]
        );
        json_response(['ok' => true]);
    }

    $typists = group_typists($group_id, $uid);
    json_response(['typing' => (bool)$typists, 'typists' => $typists]);
}

if ($to_id <= 0 || $to_id === $uid) {
    json_response(['error' => 'Missing or invalid recipient.'], 400);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_require_csrf();

    query(
        'INSERT INTO typing_status (user_id, to_user_id, updated_at) VALUES (?,?, NOW())
         ON DUPLICATE KEY UPDATE updated_at = NOW()',
        [$uid, $to_id]
    );
    json_response(['ok' => true]);
}

// GET: is the other person typing to me right now?
$row = fetch_one(
    'SELECT updated_at FROM typing_status WHERE user_id = ? AND to_user_id = ?',
    [$to_id, $uid]
);
json_response(['typing' => $row && (time() - strtotime($row['updated_at'])) < TYPING_TTL]);
