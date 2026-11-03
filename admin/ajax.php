<?php
require_once __DIR__ . '/../includes/api.php';
require_admin();
api_require_post();
api_require_csrf();

$meId   = current_uid();
$target = api_int('uid');
$action = api_str('action');

if ($target <= 0 || $action === '') {
    json_response(['success' => false, 'message' => 'Missing parameters.'], 400);
}
if ($target === $meId) {
    json_response(['success' => false, 'message' => 'You cannot modify your own account.'], 400);
}

$user = fetch_one('SELECT id, role, status FROM users WHERE id = ?', [$target]);
if (!$user) {
    json_response(['success' => false, 'message' => 'User not found.'], 404);
}

// Only a superadmin may change roles.
if (in_array($action, ['promote', 'demote'], true) && !is_superadmin()) {
    json_response(['success' => false, 'message' => 'Insufficient permissions.'], 403);
}

// A superadmin account is off-limits to everybody, including other superadmins.
if ($user['role'] === 'superadmin') {
    json_response(['success' => false, 'message' => 'The superadmin account cannot be modified.'], 403);
}

// Plain admins may not act on other admins.
if ($user['role'] === 'admin' && !is_superadmin()) {
    json_response(['success' => false, 'message' => 'Only a superadmin can manage other admins.'], 403);
}

// `last_active = last_active` stops the column's ON UPDATE clause from
// rewriting the user's "last seen" time on an unrelated admin action.
switch ($action) {
    case 'approve':
        query("UPDATE users SET status = 'approved', last_active = last_active WHERE id = ?", [$target]);
        // Approving somebody is the moment they become part of the team, so it
        // is the moment they join the room the whole team is in.
        default_group_sync_user($target, (string)$user['role'], 'approved');
        $msg = 'User approved.';
        break;

    case 'reject':
        query("UPDATE users SET status = 'rejected', is_online = 0, last_active = last_active WHERE id = ?", [$target]);
        $msg = 'User rejected.';
        break;

    case 'disable':
        query("UPDATE users SET status = 'disabled', is_online = 0, last_active = last_active WHERE id = ?", [$target]);
        $msg = 'User disabled.';
        break;

    // A role change follows the account into the default group: admins run that
    // room, everybody else is a member of it. Only that group — an admin badge
    // on the account says nothing about the other rooms they are in.
    case 'promote':
        query("UPDATE users SET role = 'admin', last_active = last_active WHERE id = ?", [$target]);
        default_group_sync_user($target, 'admin', (string)$user['status']);
        $msg = 'User promoted to admin.';
        break;

    case 'demote':
        query("UPDATE users SET role = 'user', last_active = last_active WHERE id = ?", [$target]);
        default_group_sync_user($target, 'user', (string)$user['status']);
        $msg = 'User demoted to regular user.';
        break;

    default:
        json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}

json_response(['success' => true, 'message' => $msg]);
