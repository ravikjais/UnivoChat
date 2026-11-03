<?php
/**
 * Levels, streaks and badges.
 *
 * The design has one rule behind it: **nothing here may slow down sending a
 * message.** So the work is split in two.
 *
 *   Hot path  — gamify_award(). One UPDATE against one row, on the same
 *               request that stored the message. No reads, no badge logic, no
 *               joins. It moves counters and XP and returns.
 *
 *   Cold path — gamify_sync(). Runs on the gamification poll and when the
 *               profile page is opened, which is orders of magnitude less
 *               often. This is where derived figures are recomputed, badges are
 *               evaluated and awarded, and level-ups are noticed.
 *
 * That split is also why a badge appears a few seconds after the message that
 * earned it rather than in the same instant: the alternative is eighteen
 * predicate checks on the send path, which is the one path that has to stay
 * quick.
 */
// Both are real dependencies, not conveniences: gamify_backfill() asks
// messages_have() whether this database knows about replies yet, and
// gamify_leaderboard() renders each row's picture through avatar_url(). Every
// web caller happens to load them first, which is exactly why they are named
// here — an implicit dependency works until something calls this file on its
// own, and then it fatals a long way from the cause.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

// ─── The economy ──────────────────────────────────────────────────────────────
/**
 * What each action is worth.
 *
 * Weighted by effort rather than by volume: a voice note or a shared file took
 * more from the sender than a one-word reply, and pays accordingly. Reacting is
 * worth a point because reading other people's messages is participation too.
 */
const XP_ACTIONS = [
    'message'        => 2,
    'reply'          => 3,
    'media'          => 2,   // a GIF, sticker or meme from the picker
    'image'          => 4,
    'file'           => 5,
    'voice'          => 6,
    'react_given'    => 1,
    'react_received' => 2,
    'group_created'  => 25,
];

/**
 * Ceiling on what a day's activity can pay, so the leaderboard measures taking
 * part rather than who is willing to type "ok" four hundred times. The daily
 * and streak bonuses below sit outside it — those are for showing up, and
 * showing up is exactly what the cap must not punish.
 */
const XP_DAILY_CAP = 220;

const XP_DAILY_BONUS  = 15;   // first activity of the day
const XP_STREAK_STEP  = 3;    // × consecutive days, on top of the daily bonus
const XP_STREAK_MAX   = 45;   // where the streak bonus stops growing

/**
 * The level curve: level L begins at 20·L·(L−1) XP.
 *
 * Quadratic, so each level costs a little more than the last (40 XP for level
 * 2, 400 for level 10, 1 160 for level 30) without the wall an exponential
 * curve puts up around level 20.
 */
function xp_for_level(int $level): int {
    $level = max(1, $level);
    return 20 * $level * ($level - 1);
}

/** The inverse of the curve above. */
function level_for_xp(int $xp): int {
    return max(1, (int)floor((1 + sqrt(1 + max(0, $xp) / 5)) / 2));
}

/** Everything the client needs to draw a level ring, from the XP alone. */
function level_progress(int $xp): array {
    $level = level_for_xp($xp);
    $floor = xp_for_level($level);
    $next  = xp_for_level($level + 1);
    $span  = max(1, $next - $floor);

    return [
        'level'     => $level,
        'xp'        => $xp,
        'level_xp'  => $xp - $floor,          // earned inside the current level
        'level_span'=> $span,                 // what the whole level costs
        'to_next'   => max(0, $next - $xp),
        'percent'   => (int)round(min(100, max(0, ($xp - $floor) / $span * 100))),
    ];
}

// ─── Badges ───────────────────────────────────────────────────────────────────
/**
 * Every badge, in the order they are displayed.
 *
 * `test` is handed the stats row and answers whether it has been earned. They
 * are deliberately all monotonic — nothing here can become false again — so a
 * badge is never taken away, and the evaluation can be a plain re-check rather
 * than an event log.
 */
function gamify_badges(): array {
    static $badges = null;
    if ($badges !== null) return $badges;

    return $badges = [
        'first_word'    => ['💬', 'First Word',     'Send your first message',
                            static fn(array $s): bool => (int)$s['messages_sent'] >= 1],
        'chatterbox'    => ['🗣️', 'Chatterbox',     'Send 100 messages',
                            static fn(array $s): bool => (int)$s['messages_sent'] >= 100],
        'megaphone'     => ['📢', 'Megaphone',      'Send 1,000 messages',
                            static fn(array $s): bool => (int)$s['messages_sent'] >= 1000],
        'good_listener' => ['↩️', 'Good Listener',  'Reply to 25 messages',
                            static fn(array $s): bool => (int)$s['replies_sent'] >= 25],
        'shutterbug'    => ['📷', 'Shutterbug',     'Share 10 photos',
                            static fn(array $s): bool => (int)$s['images_sent'] >= 10],
        'courier'       => ['📎', 'Courier',        'Share 25 files',
                            static fn(array $s): bool => (int)$s['files_sent'] >= 25],
        'on_the_record' => ['🎤', 'On the Record',  'Send a voice note',
                            static fn(array $s): bool => (int)$s['voice_sent'] >= 1],
        'cheerleader'   => ['🎯', 'Cheerleader',    'Give 50 reactions',
                            static fn(array $s): bool => (int)$s['reactions_given'] >= 50],
        'crowd_pleaser' => ['❤️', 'Crowd Pleaser',  'Receive 50 reactions',
                            static fn(array $s): bool => (int)$s['reactions_received'] >= 50],
        'spark'         => ['🔥', 'Spark',          'Keep a 3-day streak',
                            static fn(array $s): bool => (int)$s['best_streak'] >= 3],
        'consistent'    => ['⚡', 'Consistent',     'Keep a 7-day streak',
                            static fn(array $s): bool => (int)$s['best_streak'] >= 7],
        'unstoppable'   => ['🏆', 'Unstoppable',    'Keep a 30-day streak',
                            static fn(array $s): bool => (int)$s['best_streak'] >= 30],
        'night_owl'     => ['🌙', 'Night Owl',      'Send a message after midnight',
                            static fn(array $s): bool => !empty($s['night_owl'])],
        'early_bird'    => ['🌅', 'Early Bird',     'Send a message before 8am',
                            static fn(array $s): bool => !empty($s['early_bird'])],
        'convener'      => ['👥', 'Convener',       'Create a group',
                            static fn(array $s): bool => (int)$s['groups_created'] >= 1],
        'connector'     => ['🤝', 'Connector',      'Talk with 5 different people',
                            static fn(array $s): bool => (int)$s['peers_count'] >= 5],
        'rising_star'   => ['⭐', 'Rising Star',    'Reach level 5',
                            static fn(array $s): bool => (int)$s['level'] >= 5],
        'veteran'       => ['🎖️', 'Veteran',        'Reach level 15',
                            static fn(array $s): bool => (int)$s['level'] >= 15],
    ];
}

/** One badge as the client draws it, locked or not. */
function badge_payload(string $key, bool $earned, ?string $at = null): array {
    $def = gamify_badges()[$key] ?? ['🏅', ucfirst($key), '', null];

    return [
        'key'    => $key,
        'icon'   => $def[0],
        'name'   => $def[1],
        'how'    => $def[2],
        'earned' => $earned,
        'at'     => $at,
    ];
}

// ─── Availability ─────────────────────────────────────────────────────────────
/**
 * Has this database been migrated for gamification yet?
 *
 * Same reasoning as messages_have(): setup/install.php creates `user_stats` and
 * `user_badges`, and until somebody runs it every award would throw. Answering
 * "not yet" turns the whole feature off quietly instead of breaking sending a
 * message, which is what it must never do.
 */
function gamify_available(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;

    try {
        return $ready = (bool)fetch_one("SHOW TABLES LIKE 'user_stats'");
    } catch (Throwable $e) {
        return $ready = false;
    }
}

// ─── The hot path ─────────────────────────────────────────────────────────────
/** Which counter column each action moves, if any. */
const XP_COUNTERS = [
    'message'        => 'messages_sent',
    'reply'          => 'replies_sent',
    'image'          => 'images_sent',
    'file'           => 'files_sent',
    'voice'          => 'voice_sent',
    'react_given'    => 'reactions_given',
    'react_received' => 'reactions_received',
    'group_created'  => 'groups_created',
];

/**
 * Record one thing somebody did.
 *
 * Deliberately forgiving: an award that fails must never take a sent message
 * down with it, so everything here is wrapped and swallowed. Losing two points
 * is not worth an error page.
 *
 * @param string $action One of XP_ACTIONS.
 * @param int    $times  For a send that carried several files at once.
 */
function gamify_award(int $uid, string $action, int $times = 1): void {
    if ($uid <= 0 || $times < 1 || !isset(XP_ACTIONS[$action]) || !gamify_available()) return;

    $points  = XP_ACTIONS[$action] * $times;
    $counter = XP_COUNTERS[$action] ?? null;

    // Two window flags earned by *when* somebody is writing rather than by how
    // much. Read off the app clock, which is the clock every timestamp uses.
    $hour  = (int)date('G');
    $isMsg = in_array($action, ['message', 'reply'], true);
    $night = $isMsg && $hour < 5            ? 1 : 0;
    $early = $isMsg && $hour >= 5 && $hour < 8 ? 1 : 0;

    try {
        gamify_ensure_row($uid);

        // The daily cap is applied inside SQL rather than by reading the row
        // first: two messages sent in the same second would otherwise both read
        // the same "spent so far" and both be allowed through it.
        //
        // `day_date = CURDATE()` is what resets the allowance after midnight,
        // in the same statement that spends it.
        //
        // THE ORDER OF THESE ASSIGNMENTS IS LOAD-BEARING. MySQL evaluates a
        // single-table UPDATE's SET list left to right, and a later assignment
        // sees the *new* value of an earlier column. So every expression that
        // reads `day_xp` has to come before `day_xp` is written, and everything
        // reading `day_date` before `day_date` is written — otherwise the grant
        // is computed against the allowance it has just spent and the last
        // award before the cap silently pays nothing.
        $grant = 'LEAST(?, GREATEST(0, ? - IF(day_date = CURDATE(), day_xp, 0)))';

        $sql = "UPDATE user_stats
                   SET xp        = xp + $grant,
                       month_xp  = IF(month_key = DATE_FORMAT(CURDATE(), '%Y-%m'), month_xp, 0) + $grant,
                       month_key = DATE_FORMAT(CURDATE(), '%Y-%m'),
                       day_xp    = IF(day_date = CURDATE(), day_xp, 0) + $grant,
                       day_date  = CURDATE(),
                       night_owl = GREATEST(night_owl, ?),
                       early_bird = GREATEST(early_bird, ?)";

        $args = [$points, XP_DAILY_CAP, $points, XP_DAILY_CAP, $points, XP_DAILY_CAP, $night, $early];

        if ($counter !== null) {
            // Never capped: the counters are a record of what happened, and the
            // badges that read them must not depend on the XP allowance.
            $sql .= ", `$counter` = `$counter` + ?";
            $args[] = $times;
        }

        $sql .= ' WHERE user_id = ?';
        $args[] = $uid;

        query($sql, $args);
    } catch (Throwable $e) {
        error_log('[UnivoChat] gamify_award(' . $action . ') failed for ' . $uid . ': ' . $e->getMessage());
    }
}

/**
 * Note that this user was here today, and extend or restart their streak.
 *
 * Called on the status heartbeat, so it runs on every open tab every thirty
 * seconds — hence the early exit on `last_active_day`, which makes all but the
 * first call of the day a single indexed read.
 *
 * @return bool True when this was the first activity of a new day.
 */
function gamify_touch_day(int $uid): bool {
    if ($uid <= 0 || !gamify_available()) return false;

    try {
        gamify_ensure_row($uid);

        $row = fetch_one(
            'SELECT last_active_day, streak_days, best_streak,
                    DATEDIFF(CURDATE(), last_active_day) AS gap
               FROM user_stats WHERE user_id = ?',
            [$uid]
        );
        if (!$row) return false;

        $gap = $row['last_active_day'] === null ? null : (int)$row['gap'];
        if ($gap === 0) return false;                    // already counted today

        // Yesterday continues the run; anything longer starts a new one. A
        // first-ever visit is day one either way.
        $streak = $gap === 1 ? (int)$row['streak_days'] + 1 : 1;
        $best   = max((int)$row['best_streak'], $streak);
        $bonus  = XP_DAILY_BONUS + min(XP_STREAK_MAX, XP_STREAK_STEP * $streak);

        query(
            'UPDATE user_stats
                SET last_active_day = CURDATE(),
                    streak_days     = ?,
                    best_streak     = ?,
                    xp              = xp + ?,
                    month_xp        = IF(month_key = DATE_FORMAT(CURDATE(), \'%Y-%m\'), month_xp, 0) + ?,
                    month_key       = DATE_FORMAT(CURDATE(), \'%Y-%m\')
              WHERE user_id = ? AND (last_active_day IS NULL OR last_active_day < CURDATE())',
            [$streak, $best, $bonus, $bonus, $uid]
        );

        return true;
    } catch (Throwable $e) {
        error_log('[UnivoChat] gamify_touch_day failed for ' . $uid . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Make sure this user has a stats row, seeding it from their history the first
 * time.
 *
 * Backfilling matters more than it looks: without it everybody who has been
 * using the app for months starts at zero next to somebody who joined today,
 * and the first thing a leaderboard would say is something untrue.
 */
function gamify_ensure_row(int $uid): void {
    static $seeded = [];
    if (isset($seeded[$uid])) return;
    $seeded[$uid] = true;

    $exists = fetch_one('SELECT user_id FROM user_stats WHERE user_id = ?', [$uid]);
    if ($exists) return;

    query('INSERT IGNORE INTO user_stats (user_id) VALUES (?)', [$uid]);
    gamify_backfill($uid);
}

/**
 * Count what somebody already did before any of this existed, and pay for it.
 *
 * Run once per user, from gamify_ensure_row(). Deleted messages still count —
 * they were written, and taking XP back for tidying up would be a strange
 * incentive to build.
 */
function gamify_backfill(int $uid): void {
    $hasReply = messages_have('reply_to');

    $m = fetch_one(
        'SELECT
            SUM(type = \'text\')                                   AS messages_sent,
            SUM(type = \'image\')                                  AS images_sent,
            SUM(type = \'file\')                                   AS files_sent,
            SUM(type = \'audio\')                                  AS voice_sent,
            SUM(type IN (\'gif\',\'sticker\',\'meme\'))            AS media_sent,'
          . ($hasReply ? 'SUM(reply_to IS NOT NULL)' : '0') . ' AS replies_sent
           FROM messages WHERE sender_id = ?',
        [$uid]
    ) ?: [];

    $given = fetch_one('SELECT COUNT(*) AS c FROM message_reactions WHERE user_id = ?', [$uid]);
    $recvd = fetch_one(
        'SELECT COUNT(*) AS c FROM message_reactions r
           JOIN messages m ON m.id = r.message_id
          WHERE m.sender_id = ? AND r.user_id <> ?',
        [$uid, $uid]
    );
    $groups = fetch_one('SELECT COUNT(*) AS c FROM chat_groups WHERE created_by = ?', [$uid]);

    $counts = [
        'message'        => (int)($m['messages_sent'] ?? 0),
        'reply'          => (int)($m['replies_sent'] ?? 0),
        'image'          => (int)($m['images_sent'] ?? 0),
        'file'           => (int)($m['files_sent'] ?? 0),
        'voice'          => (int)($m['voice_sent'] ?? 0),
        'media'          => (int)($m['media_sent'] ?? 0),
        'react_given'    => (int)($given['c'] ?? 0),
        'react_received' => (int)($recvd['c'] ?? 0),
        'group_created'  => (int)($groups['c'] ?? 0),
    ];

    // Plain text messages are counted separately from replies above, so a reply
    // is not paid for twice.
    $counts['message'] = max(0, $counts['message'] - $counts['reply']);

    $xp = 0;
    foreach ($counts as $action => $n) {
        $xp += XP_ACTIONS[$action] * $n;
    }

    query(
        'UPDATE user_stats
            SET xp = ?, messages_sent = ?, replies_sent = ?, images_sent = ?, files_sent = ?,
                voice_sent = ?, reactions_given = ?, reactions_received = ?, groups_created = ?,
                level = ?, notified_level = ?
          WHERE user_id = ?',
        [
            $xp,
            $counts['message'] + $counts['reply'] + $counts['media'],
            $counts['reply'], $counts['image'], $counts['file'], $counts['voice'],
            $counts['react_given'], $counts['react_received'], $counts['group_created'],
            level_for_xp($xp),
            // Seeded as already-notified: nobody wants fourteen level-up
            // animations the first time they open the app after an upgrade.
            level_for_xp($xp),
            $uid,
        ]
    );
}

// ─── The cold path ────────────────────────────────────────────────────────────
/**
 * Bring a user's derived figures up to date, award any badge now earned, and
 * hand back anything worth celebrating.
 *
 * @return array{stats: array, badges: array, new_badges: array, level_up: ?int}
 */
function gamify_sync(int $uid): array {
    $empty = ['stats' => [], 'badges' => [], 'new_badges' => [], 'level_up' => null];
    if ($uid <= 0 || !gamify_available()) return $empty;

    try {
        gamify_ensure_row($uid);

        // How many different people this user has actually exchanged messages
        // with. Recomputed here rather than tracked on the send path, where it
        // would cost a DISTINCT on every message.
        $peers = fetch_one(
            'SELECT COUNT(DISTINCT peer) AS c FROM (
                 SELECT receiver_id AS peer FROM messages WHERE sender_id = ? AND receiver_id IS NOT NULL
                 UNION
                 SELECT sender_id   AS peer FROM messages WHERE receiver_id = ?
             ) t',
            [$uid, $uid]
        );

        $stats = fetch_one('SELECT * FROM user_stats WHERE user_id = ?', [$uid]);
        if (!$stats) return $empty;

        // `level` is a stored copy of a value derived from `xp`, kept only so
        // the leaderboard can read it without recomputing per row. This is the
        // one place it is written, from the curve rather than from SQL.
        $stats['peers_count'] = (int)($peers['c'] ?? 0);
        $stats['level']       = level_for_xp((int)$stats['xp']);

        query(
            'UPDATE user_stats SET peers_count = ?, level = ? WHERE user_id = ?',
            [$stats['peers_count'], $stats['level'], $uid]
        );

        // ── Badges ────────────────────────────────────────────────────────
        $held = [];
        foreach (fetch_all('SELECT badge, earned_at FROM user_badges WHERE user_id = ?', [$uid]) as $row) {
            $held[(string)$row['badge']] = (string)$row['earned_at'];
        }

        foreach (gamify_badges() as $key => $def) {
            if (isset($held[$key]) || !$def[3]($stats)) continue;
            query('INSERT IGNORE INTO user_badges (user_id, badge) VALUES (?,?)', [$uid, $key]);
            $held[$key] = date('Y-m-d H:i:s');
        }

        // ── What has not been shown yet ───────────────────────────────────
        $fresh = fetch_all(
            'SELECT badge FROM user_badges WHERE user_id = ? AND notified = 0 ORDER BY earned_at ASC',
            [$uid]
        );
        $newBadges = array_map(
            static fn(array $r): array => badge_payload((string)$r['badge'], true, $held[(string)$r['badge']] ?? null),
            $fresh
        );
        if ($newBadges) {
            query('UPDATE user_badges SET notified = 1 WHERE user_id = ? AND notified = 0', [$uid]);
        }

        $level    = (int)$stats['level'];
        $levelUp  = $level > (int)$stats['notified_level'] ? $level : null;
        if ($levelUp !== null) {
            query('UPDATE user_stats SET notified_level = ? WHERE user_id = ?', [$level, $uid]);
        }

        $badges = [];
        foreach (gamify_badges() as $key => $_) {
            $badges[] = badge_payload($key, isset($held[$key]), $held[$key] ?? null);
        }

        return [
            'stats'      => gamify_stats_payload($stats),
            'badges'     => $badges,
            'new_badges' => $newBadges,
            'level_up'   => $levelUp,
        ];
    } catch (Throwable $e) {
        error_log('[UnivoChat] gamify_sync failed for ' . $uid . ': ' . $e->getMessage());
        return $empty;
    }
}

/** The stats row, shaped the way the client draws it. */
function gamify_stats_payload(array $s): array {
    $xp = (int)$s['xp'];

    return level_progress($xp) + [
        'streak'      => (int)$s['streak_days'],
        'best_streak' => (int)$s['best_streak'],
        'messages'    => (int)$s['messages_sent'],
        'replies'     => (int)$s['replies_sent'],
        'images'      => (int)$s['images_sent'],
        'files'       => (int)$s['files_sent'],
        'voice'       => (int)$s['voice_sent'],
        'given'       => (int)$s['reactions_given'],
        'received'    => (int)$s['reactions_received'],
        'groups'      => (int)$s['groups_created'],
        'peers'       => (int)$s['peers_count'],
        // Whether today has already been counted, so the client can say
        // "streak continues" rather than leaving somebody guessing.
        'today'       => $s['last_active_day'] === date('Y-m-d'),
        'month_xp'    => (int)$s['month_xp'],
    ];
}

/**
 * The ranking.
 *
 * Everybody approved appears, including people with no stats row yet — a
 * colleague missing from the list entirely reads as a bug, while a colleague on
 * zero reads as an invitation.
 *
 * @param string $scope 'month' or 'all'
 */
function gamify_leaderboard(int $uid, string $scope = 'month', int $limit = 25): array {
    if (!gamify_available()) return [];

    $column = $scope === 'all' ? 's.xp' : 's.month_xp';
    $month  = date('Y-m');

    $rows = fetch_all(
        "SELECT u.id, u.username, u.full_name, u.avatar,
                COALESCE(s.xp, 0) AS xp,
                COALESCE(IF(s.month_key = ?, s.month_xp, 0), 0) AS month_xp,
                COALESCE(s.level, 1) AS level,
                COALESCE(s.streak_days, 0) AS streak,
                (SELECT COUNT(*) FROM user_badges b WHERE b.user_id = u.id) AS badges
           FROM users u
           LEFT JOIN user_stats s ON s.user_id = u.id
          WHERE u.status = 'approved'
          ORDER BY " . ($scope === 'all' ? 'xp' : 'month_xp') . " DESC, u.full_name ASC
          LIMIT " . max(1, min(100, $limit)),
        [$month]
    );

    $out  = [];
    $rank = 0;
    foreach ($rows as $r) {
        $rank++;
        $out[] = [
            'rank'      => $rank,
            'id'        => (int)$r['id'],
            'name'      => (string)$r['full_name'],
            'avatar'    => avatar_url($r['avatar'], $r['full_name'] ?: $r['username']),
            'xp'        => $scope === 'all' ? (int)$r['xp'] : (int)$r['month_xp'],
            'level'     => (int)$r['level'],
            'streak'    => (int)$r['streak'],
            'badges'    => (int)$r['badges'],
            'is_me'     => (int)$r['id'] === $uid,
        ];
    }

    return $out;
}
