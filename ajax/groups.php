<?php
/**
 * Everything about a group except its messages, which go through
 * get_messages.php / send_message.php like any other conversation.
 *
 * Actions (POST unless noted):
 *   list    GET   groups I am in
 *   info    GET   one group, with its members — members only
 *   create        make a group; the creator becomes its admin
 *   add           add members — group admin only
 *   remove        remove a member — group admin only
 *   leave         leave a group; the last one out deletes it
 *   rename        change name/description — group admin only
 *   avatar        set the group display picture — group admin only
 *   avatar_clear  drop it and go back to the initials tile — admin only
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/gamify.php';
require_approved();

$uid    = current_uid();
$action = api_str('action', 'list');

/** Every action but list/create needs a group the caller can actually see. */
$require_membership = static function () use ($uid): array {
    $group_id = api_int('group_id');
    $group    = group_get($group_id);

    // A non-member is told the same thing whether or not the group exists:
    // "you are not in it" and "there is no such group" must not be
    // distinguishable, or the endpoint becomes a directory of every group.
    if (!$group || !is_group_member($group_id, $uid)) {
        json_response(['error' => 'That group is not available.'], 404);
    }
    return $group;
};

/** One group as the client renders it. */
$present_group = static function (array $group, int $uid): array {
    $members = group_members((int)$group['id']);

    return [
        'id'           => (int)$group['id'],
        'name'         => $group['name'],
        'description'  => $group['description'],
        // Always a usable image: the uploaded picture, or the generated
        // initials tile. has_photo is what tells the two apart, so the info
        // panel knows whether to offer "Remove photo".
        'avatar'       => group_avatar_url($group['avatar'] ?? null, (string)$group['name']),
        'has_photo'    => group_has_photo($group['avatar'] ?? null),
        'can_photo'    => groups_have_avatar(),
        'created_by'   => (int)$group['created_by'],
        // The one room nobody leaves. The client hides "Leave group" and the
        // per-member remove buttons on it; the endpoints below refuse both
        // anyway, because a hidden button is a courtesy, not a check.
        'is_default'   => is_default_group((int)$group['id']),
        'my_role'      => group_member_role((int)$group['id'], $uid),
        'member_count' => count($members),
        'members'      => array_map(static fn(array $m): array => [
            'id'        => (int)$m['id'],
            'full_name' => $m['full_name'],
            'username'  => $m['username'],
            'avatar'    => avatar_url($m['avatar'], $m['full_name'] ?: $m['username']),
            'role'      => $m['role'],
            'is_online' => (bool)$m['is_online'],
        ], $members),
    ];
};

// ── Read-only actions ─────────────────────────────────────────────────────────
if ($action === 'list') {
    json_response(['groups' => sidebar_groups($uid)]);
}

if ($action === 'info') {
    json_response(['group' => $present_group($require_membership(), $uid)]);
}

// ── Everything below changes something ────────────────────────────────────────
api_require_post();

// A POST over post_max_size reaches PHP with $_POST and $_FILES emptied, which
// would otherwise surface as "your security token expired" — a confusing way to
// say the group picture did not fit through the server's front door.
if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_response([
        'error' => 'That picture is larger than this server accepts (limit: '
                 . ini_get('post_max_size') . ').',
    ], 413);
}

api_require_csrf();

if ($action === 'create') {
    // Any approved user may start a group — no admin role required.
    $members = $_POST['members'] ?? [];
    $result  = group_create(
        $uid,
        api_str('name'),
        api_str('description'),
        is_array($members) ? $members : []
    );

    if (!$result['ok']) {
        json_response(['error' => $result['msg']], 400);
    }

    // A picture chosen on the way in is optional, and a group that exists
    // without one is not a failure: the name is what was asked for, so a
    // rejected image is reported as a warning rather than losing the group.
    $warning = '';
    if (!empty($_FILES['avatar']['name'])) {
        $saved = group_set_avatar($result['group_id'], $_FILES['avatar']);
        if (!$saved['ok']) $warning = $saved['msg'];
    }

    // Starting a room where a team can talk is worth more than any single
    // message in it, which is what the outsized award says.
    gamify_award($uid, 'group_created');

    $group = group_get($result['group_id']);
    json_response(array_filter([
        'ok'      => true,
        'group'   => $present_group($group, $uid),
        'warning' => $warning,
    ]));
}

$group    = $require_membership();
$group_id = (int)$group['id'];

if ($action === 'leave') {
    if (is_default_group($group_id)) {
        json_response([
            'error' => 'This is the default group for everyone at ' . APP_NAME . ', so it cannot be left.',
        ], 400);
    }
    group_remove_member($group_id, $uid);
    json_response(['ok' => true, 'left' => true]);
}

// The rest is the group admin's business only.
if (!is_group_admin($group_id, $uid)) {
    json_response(['error' => 'Only a group admin can do that.'], 403);
}

switch ($action) {
    case 'add':
        $members = $_POST['members'] ?? [];
        $added   = group_add_members($group_id, is_array($members) ? $members : []);
        json_response([
            'ok'    => true,
            'added' => $added,
            'group' => $present_group(group_get($group_id), $uid),
        ]);

    case 'remove':
        $target = api_int('user_id');
        if (is_default_group($group_id)) {
            json_response([
                'error' => 'Everyone at ' . APP_NAME . ' is in this group, so nobody can be removed from it. '
                         . 'Disable the account from the admin panel instead.',
            ], 400);
        }
        if ($target === $uid) {
            json_response(['error' => 'Use “Leave group” to remove yourself.'], 400);
        }
        if (!group_remove_member($group_id, $target)) {
            json_response(['error' => 'That person is not in this group.'], 404);
        }
        json_response([
            'ok'    => true,
            'group' => $present_group(group_get($group_id), $uid),
        ]);

    case 'rename':
        $name = api_str('name');
        if ($name === '' || mb_strlen($name) > 80) {
            json_response(['error' => 'Give the group a name of 80 characters or fewer.'], 400);
        }
        query('UPDATE chat_groups SET name = ?, description = ? WHERE id = ?',
              [$name, mb_substr(api_str('description'), 0, 255) ?: null, $group_id]);
        json_response([
            'ok'    => true,
            'group' => $present_group(group_get($group_id), $uid),
        ]);

    // The display picture. Stored beside the profile pictures in
    // uploads/avatars, under a random name, and served directly — a group photo
    // is shown to everyone in the group and needs none of the per-message
    // access checking ajax/file.php does for a conversation's attachments.
    case 'avatar':
        if (empty($_FILES['avatar']['name'])) {
            json_response(['error' => 'No picture was uploaded.'], 400);
        }
        $saved = group_set_avatar($group_id, $_FILES['avatar']);
        if (!$saved['ok']) {
            json_response(['error' => $saved['msg']], 400);
        }
        json_response([
            'ok'    => true,
            'group' => $present_group(group_get($group_id), $uid),
        ]);

    case 'avatar_clear':
        $cleared = group_clear_avatar($group_id);
        if (!$cleared['ok']) {
            json_response(['error' => $cleared['msg']], 400);
        }
        json_response([
            'ok'    => true,
            'group' => $present_group(group_get($group_id), $uid),
        ]);

    default:
        json_response(['error' => 'Unknown action.'], 400);
}
