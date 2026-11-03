<?php
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/gamify.php';
require_once __DIR__ . '/../includes/calls.php';
require_approved();

$uid = current_uid();
// `focus` separates "the tab is still polling in the background" from "the user
// is actually here"; only the latter suppresses a push notification.
update_online_status($uid, api_bool('focus'));
set_offline_stale();

// The streak lives on the heartbeat rather than on sending, so a day spent
// reading still counts as a day you were here. All but the first call of the
// day exits on one indexed read.
$newDay = gamify_touch_day($uid);

// Occasional housekeeping: drop typing rows nobody will ever read again.
if (random_int(1, 20) === 1) {
    query('DELETE FROM typing_status WHERE updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
}

json_response([
    'ok'     => true,
    'unread' => get_total_unread($uid),
    // Prompts the client to refresh its badge immediately rather than waiting
    // for the next gamification poll — the streak has just moved.
    'newDay' => $newDay,
    'incoming_call' => incoming_call_for($uid),
]);
