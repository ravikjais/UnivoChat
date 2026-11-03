<?php
/**
 * Levels, streaks, badges and the ranking.
 *
 * The cold path of the whole feature — see includes/gamify.php. Everything
 * expensive lives here rather than on the send path: recomputing derived
 * figures, evaluating eighteen badge predicates, and reading the leaderboard.
 *
 * Actions:
 *   me           my stats, my badges, and anything not yet celebrated
 *   leaderboard  the ranking — `scope=month` (default) or `scope=all`
 *
 * Both are GETs; nothing here changes anything the user asked to change, so
 * there is no CSRF gate. The awards themselves happen on the endpoints that do
 * the real work.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/gamify.php';
require_approved();

$uid    = current_uid();
$action = api_str('action') ?: 'me';

// Two ways to be switched off, with the same answer either way: the database
// has not been migrated for it, or FEATURE_GAMIFY_UI is holding the display
// back for a later release. Answered rather than refused — the client hides
// the level chip, the leaderboard and the profile sections, and everything
// else in the app carries on exactly as before.
//
// Note this gate is on *reading* only. Awards keep landing on the endpoints
// that do the real work, so the scores are accumulating the whole time.
if (!gamify_available() || !FEATURE_GAMIFY_UI) {
    json_response(['enabled' => false]);
}

if ($action === 'leaderboard') {
    $scope = api_str('scope') === 'all' ? 'all' : 'month';

    json_response([
        'enabled' => true,
        'scope'   => $scope,
        'board'   => gamify_leaderboard($uid, $scope),
        'month'   => date('F Y'),
    ]);
}

// ── me ────────────────────────────────────────────────────────────────────────
$sync = gamify_sync($uid);

json_response([
    'enabled'    => true,
    'stats'      => $sync['stats'],
    'badges'     => $sync['badges'],
    // Shown once and once only: gamify_sync() has already marked these as
    // notified, so a second tab will not celebrate them again.
    'new_badges' => $sync['new_badges'],
    'level_up'   => $sync['level_up'],
    'xp_rules'   => XP_ACTIONS,
]);
