<?php
/**
 * UnivoChat Installer
 *
 * Creates the database, tables and the initial superadmin account using the
 * credentials from config/config.php (which picks local vs production
 * automatically). Safe to run more than once: it only adds what is missing.
 *
 * DELETE the whole /setup folder once the app is running.
 */

require_once __DIR__ . '/../config/config.php';
// app_key() and format_bytes() below live here. Loading it does not open a
// database connection — the installer makes its own PDO with the credentials
// config.php picked, because the target database may not exist yet.
require_once __DIR__ . '/../includes/functions.php';

$adminEmail = 'admin@univochat.local';
$adminPass  = 'Admin@123';        // change immediately after the first sign-in

$error = '';
$done  = [];
$created = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ── Connect without selecting a database ──────────────────────────
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("SET time_zone = '" . tz_offset() . "'");   // same IST clock as the app

        // Shared hosts often pre-create the database and forbid CREATE DATABASE.
        try {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $done[] = 'Database <code>' . e(DB_NAME) . '</code> is ready.';
        } catch (PDOException $e) {
            $done[] = 'Using the existing database <code>' . e(DB_NAME) . '</code>.';
        }
        $pdo->exec('USE `' . DB_NAME . '`');

        // ── Users ─────────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `username`    VARCHAR(30)  NOT NULL,
                `email`       VARCHAR(100) NOT NULL,
                `password`    VARCHAR(255) NOT NULL,
                `full_name`   VARCHAR(100) NOT NULL,
                `avatar`      VARCHAR(255) DEFAULT NULL,
                `role`        ENUM('user','admin','superadmin') NOT NULL DEFAULT 'user',
                `status`      ENUM('pending','approved','rejected','disabled') NOT NULL DEFAULT 'pending',
                `is_online`   TINYINT(1) NOT NULL DEFAULT 0,
                `last_active` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_focus_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_username` (`username`),
                UNIQUE KEY `uq_email` (`email`),
                KEY `idx_status`  (`status`),
                KEY `idx_online`  (`is_online`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>users</code> is ready.';

        // ── Messages ──────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `messages` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sender_id`   INT UNSIGNED NOT NULL,
                `receiver_id` INT UNSIGNED NOT NULL,
                `message`     MEDIUMTEXT   DEFAULT NULL,
                `format`      ENUM('plain','html') NOT NULL DEFAULT 'plain',
                `type`        ENUM('text','image','file','gif','audio','sticker','meme') NOT NULL DEFAULT 'text',
                `file_path`   VARCHAR(500) DEFAULT NULL,
                `file_name`   VARCHAR(255) DEFAULT NULL,
                `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
                `edited_at`   TIMESTAMP    NULL DEFAULT NULL,
                `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_conversation` (`sender_id`,`receiver_id`,`id`),
                KEY `idx_receiver_unread` (`receiver_id`,`is_read`),
                KEY `idx_created` (`created_at`),
                KEY `idx_edited` (`edited_at`),
                CONSTRAINT `fk_msg_sender`   FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_msg_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>messages</code> is ready.';

        // ── Reactions ─────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `message_reactions` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `message_id` INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `emoji`      VARCHAR(16)  NOT NULL,
                `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_reaction` (`message_id`,`user_id`,`emoji`),
                KEY `idx_react_user` (`user_id`),
                CONSTRAINT `fk_react_msg`  FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_react_user` FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>message_reactions</code> is ready.';

        // ── Push subscriptions ────────────────────────────────────────────
        // endpoint_hash carries the unique key: endpoints are far too long to
        // index directly, but their SHA-256 is a fixed 64 characters.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `push_subscriptions` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`       INT UNSIGNED NOT NULL,
                `endpoint_hash` CHAR(64)     NOT NULL,
                `endpoint`      TEXT         NOT NULL,
                `p256dh`        VARCHAR(255) NOT NULL,
                `auth`          VARCHAR(64)  NOT NULL,
                `user_agent`    VARCHAR(255) DEFAULT NULL,
                `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_used_at`  TIMESTAMP    NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_endpoint` (`endpoint_hash`),
                KEY `idx_push_user` (`user_id`),
                CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>push_subscriptions</code> is ready.';

        // ── Settings (holds the generated VAPID key pair) ──────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `app_settings` (
                `name`       VARCHAR(64) NOT NULL,
                `value`      TEXT        NOT NULL,
                `updated_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>app_settings</code> is ready.';

        // ── Typing status ─────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `typing_status` (
                `user_id`    INT UNSIGNED NOT NULL,
                `to_user_id` INT UNSIGNED NOT NULL,
                `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`,`to_user_id`),
                CONSTRAINT `fk_typing_user` FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_typing_to`   FOREIGN KEY (`to_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>typing_status</code> is ready.';

        // ── Groups ────────────────────────────────────────────────────────
        // `groups` is a reserved word in MySQL 8, so the table is chat_groups.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `chat_groups` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`        VARCHAR(80)  NOT NULL,
                `description` VARCHAR(255) DEFAULT NULL,
                `avatar`      VARCHAR(255) DEFAULT NULL,
                `created_by`  INT UNSIGNED NOT NULL,
                `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_group_creator` (`created_by`),
                CONSTRAINT `fk_group_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>chat_groups</code> is ready.';

        // Membership is the whole access-control story for a group: every read
        // and every write checks for a row here. `last_read_id` replaces the
        // per-message is_read flag a one-to-one chat uses — one row per member
        // instead of one row per member per message.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `group_members` (
                `group_id`     INT UNSIGNED NOT NULL,
                `user_id`      INT UNSIGNED NOT NULL,
                `role`         ENUM('admin','member') NOT NULL DEFAULT 'member',
                `last_read_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `joined_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`group_id`,`user_id`),
                KEY `idx_member_user` (`user_id`),
                CONSTRAINT `fk_member_group` FOREIGN KEY (`group_id`) REFERENCES `chat_groups`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_member_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)       ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>group_members</code> is ready.';

        // Separate from typing_status, whose primary key is (user, to_user) and
        // has no room for a group.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `group_typing` (
                `group_id`   INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`group_id`,`user_id`),
                CONSTRAINT `fk_gtyping_group` FOREIGN KEY (`group_id`) REFERENCES `chat_groups`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_gtyping_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)       ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>group_typing</code> is ready.';

        // ── Upgrade an existing installation ──────────────────────────────
        // `last_active ON UPDATE CURRENT_TIMESTAMP` made every status change
        // reset the user's "last seen" time, so drop the clause if present.
        $col = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'last_active'")->fetch();
        if ($col && stripos((string)$col['Extra'], 'on update') !== false) {
            $pdo->exec("ALTER TABLE `users` MODIFY `last_active` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $done[] = 'Fixed the <code>last_active</code> column (no longer reset by unrelated updates).';
        }

        $idx = $pdo->query("SHOW INDEX FROM `messages` WHERE Key_name = 'idx_conversation'")->fetchAll();
        if (count($idx) === 2) {   // old two-column version
            $pdo->exec("ALTER TABLE `messages` DROP INDEX `idx_conversation`,
                        ADD INDEX `idx_conversation` (`sender_id`,`receiver_id`,`id`)");
            $done[] = 'Improved the conversation index on <code>messages</code>.';
        }

        // Columns added after 1.1.0 — rich (table) messages, edited messages
        // and the focus heartbeat that decides whether a push is needed.
        $hasColumn = static function (string $table, string $column) use ($pdo): bool {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $st->execute([$table, $column]);
            return (bool)$st->fetchColumn();
        };

        $added = [];
        if (!$hasColumn('messages', 'format')) {
            $pdo->exec("ALTER TABLE `messages`
                        ADD `format` ENUM('plain','html') NOT NULL DEFAULT 'plain' AFTER `message`");
            $added[] = 'messages.format';
        }
        if (!$hasColumn('messages', 'edited_at')) {
            $pdo->exec("ALTER TABLE `messages`
                        ADD `edited_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_read`,
                        ADD INDEX `idx_edited` (`edited_at`)");
            $added[] = 'messages.edited_at';
        }
        if (!$hasColumn('users', 'last_focus_at')) {
            $pdo->exec("ALTER TABLE `users`
                        ADD `last_focus_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_active`");
            $added[] = 'users.last_focus_at';
        }
        if ($added) {
            $done[] = 'Added new columns: <code>'
                    . implode('</code>, <code>', array_map('e', $added)) . '</code>.';
        }

        // A GIF is a message type of its own: it holds a KLIPY address rather
        // than a file in uploads/, and reads as "GIF" wherever it is summarised.
        $col = $pdo->query("SHOW COLUMNS FROM `messages` LIKE 'type'")->fetch();
        if ($col && !str_contains(strtolower((string)$col['Type']), "'gif'")) {
            $pdo->exec("ALTER TABLE `messages`
                        MODIFY `type` ENUM('text','image','file','gif') NOT NULL DEFAULT 'text'");
            $done[] = 'Added the <code>gif</code> message type.';
        }

        // ── Group messages live in `messages` ─────────────────────────────
        // One table, so replies, reactions, edits, attachments and the polling
        // loop work in a group without a second copy of any of it. A row is a
        // group message when group_id is set, and a direct message when
        // receiver_id is — which is why receiver_id has to give up NOT NULL.
        if (!$hasColumn('messages', 'group_id')) {
            $pdo->exec("ALTER TABLE `messages`
                        ADD `group_id` INT UNSIGNED NULL DEFAULT NULL AFTER `receiver_id`,
                        ADD KEY `idx_group_msgs` (`group_id`,`id`),
                        ADD CONSTRAINT `fk_msg_group` FOREIGN KEY (`group_id`)
                            REFERENCES `chat_groups`(`id`) ON DELETE CASCADE");
            $done[] = 'Added <code>messages.group_id</code> for group conversations.';
        }

        // A group gets a display picture of its own, stored beside the profile
        // pictures in uploads/avatars. Until this column exists the app falls
        // back to the generated initials tile rather than failing.
        if (!$hasColumn('chat_groups', 'avatar')) {
            $pdo->exec("ALTER TABLE `chat_groups`
                        ADD `avatar` VARCHAR(255) DEFAULT NULL AFTER `description`");
            $done[] = 'Added <code>chat_groups.avatar</code> for group display pictures.';
        }

        $col = $pdo->query("SHOW COLUMNS FROM `messages` LIKE 'receiver_id'")->fetch();
        if ($col && strtoupper((string)$col['Null']) === 'NO') {
            $pdo->exec("ALTER TABLE `messages` MODIFY `receiver_id` INT UNSIGNED NULL DEFAULT NULL");
            $done[] = 'Made <code>messages.receiver_id</code> optional (group messages have none).';
        }

        // Voice notes are their own message type: they render as a player
        // rather than as a download, and read as "Voice message" in a preview.
        $col = $pdo->query("SHOW COLUMNS FROM `messages` LIKE 'type'")->fetch();
        if ($col && !str_contains(strtolower((string)$col['Type']), "'audio'")) {
            $pdo->exec("ALTER TABLE `messages`
                        MODIFY `type` ENUM('text','image','file','gif','audio') NOT NULL DEFAULT 'text'");
            $done[] = 'Added the <code>audio</code> message type for voice notes.';
        }

        // Stickers and memes come from the same picker as GIFs and are stored
        // the same way — a KLIPY address, not a file — but each is a type of its
        // own because each renders differently: a sticker has no bubble behind
        // it, a meme is a still picture, and both read as themselves in a
        // notification rather than as "GIF".
        $col = $pdo->query("SHOW COLUMNS FROM `messages` LIKE 'type'")->fetch();
        if ($col && !str_contains(strtolower((string)$col['Type']), "'sticker'")) {
            $pdo->exec("ALTER TABLE `messages`
                        MODIFY `type` ENUM('text','image','file','gif','audio','sticker','meme')
                        NOT NULL DEFAULT 'text'");
            $done[] = 'Added the <code>sticker</code> and <code>meme</code> message types.';
        }

        // A finished call leaves one row in the conversation ("📹 Video call ·
        // 3:12", "📞 Missed voice call"); its details are JSON in `message`.
        $col = $pdo->query("SHOW COLUMNS FROM `messages` LIKE 'type'")->fetch();
        if ($col && !str_contains(strtolower((string)$col['Type']), "'call'")) {
            $pdo->exec("ALTER TABLE `messages`
                        MODIFY `type` ENUM('text','image','file','gif','audio','sticker','meme','call')
                        NOT NULL DEFAULT 'text'");
            $done[] = 'Added the <code>call</code> message type for the call log.';
        }

        // A pasted spreadsheet outgrows TEXT's 64 KB quickly.
        $col = $pdo->query("SHOW COLUMNS FROM `messages` LIKE 'message'")->fetch();
        if ($col && strcasecmp((string)$col['Type'], 'text') === 0) {
            $pdo->exec("ALTER TABLE `messages` MODIFY `message` MEDIUMTEXT DEFAULT NULL");
            $done[] = 'Widened <code>messages.message</code> to MEDIUMTEXT for pasted tables.';
        }

        // ── Replies ───────────────────────────────────────────────────────
        // A message may answer another one in the same conversation. Self-
        // referential, and ON DELETE SET NULL so a reply survives its parent
        // being removed outright — it simply stops quoting.
        if (!$hasColumn('messages', 'reply_to')) {
            $pdo->exec("ALTER TABLE `messages`
                        ADD `reply_to` INT UNSIGNED NULL DEFAULT NULL AFTER `group_id`,
                        ADD KEY `idx_reply_to` (`reply_to`),
                        ADD CONSTRAINT `fk_msg_reply` FOREIGN KEY (`reply_to`)
                            REFERENCES `messages`(`id`) ON DELETE SET NULL");
            $done[] = 'Added <code>messages.reply_to</code> for replying to a message.';
        }

        // ── Deleted messages ──────────────────────────────────────────────
        // A tombstone, not a DELETE: the polling loop only ever asks for ids
        // above what it already has, so a row that simply vanished would stay
        // on every other screen until a reload. The text, the file name and the
        // bytes on disk are cleared at delete time; this column is what is left.
        if (!$hasColumn('messages', 'deleted_at')) {
            $pdo->exec("ALTER TABLE `messages`
                        ADD `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `edited_at`,
                        ADD INDEX `idx_deleted` (`deleted_at`)");
            $done[] = 'Added <code>messages.deleted_at</code> for deleting messages.';
        }

        // ── Forwarded messages ────────────────────────────────────────────
        // A flag rather than a pointer to the original: the copy has to stand
        // on its own once the original is deleted, and "Forwarded" is all the
        // reader is ever told about where it came from.
        if (!$hasColumn('messages', 'forwarded')) {
            $pdo->exec("ALTER TABLE `messages`
                        ADD `forwarded` TINYINT(1) NOT NULL DEFAULT 0 AFTER `reply_to`");
            $done[] = 'Added <code>messages.forwarded</code> for forwarding messages.';
        }

        // One row per (forward action, destination). The client mints a key for
        // each forward and re-sends it on a retry, and the unique index is what
        // turns "the response was lost, try again" into the message that was
        // already written instead of a second copy of it.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `message_forwards` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`     INT UNSIGNED NOT NULL,
                `fwd_key`     CHAR(32)     NOT NULL,
                `target_kind` ENUM('user','group') NOT NULL,
                `target_id`   INT UNSIGNED NOT NULL,
                `source_id`   INT UNSIGNED NOT NULL,
                `message_id`  INT UNSIGNED NOT NULL,
                `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_forward` (`user_id`,`fwd_key`,`target_kind`,`target_id`),
                KEY `idx_forward_msg` (`message_id`),
                CONSTRAINT `fk_fwd_user` FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE,
                CONSTRAINT `fk_fwd_msg`  FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>message_forwards</code> is ready.';

        // ── Password resets ───────────────────────────────────────────────
        // Only the SHA-256 of a token is stored, so a stolen copy of this table
        // cannot be used to take over an account — the same reasoning as
        // hashing a password. A row is spent by `used_at`, and expires anyway.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `password_resets` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`    INT UNSIGNED NOT NULL,
                `token_hash` CHAR(64)     NOT NULL,
                `ip`         VARCHAR(45)  DEFAULT NULL,
                `expires_at` DATETIME     NOT NULL,
                `used_at`    DATETIME     NULL DEFAULT NULL,
                `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_reset_token` (`token_hash`),
                KEY `idx_reset_user` (`user_id`,`created_at`),
                KEY `idx_reset_ip` (`ip`,`created_at`),
                CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>password_resets</code> is ready.';

        // ── Calls ─────────────────────────────────────────────────────────
        // One row per call attempt, kept as the call log. `last_seen_*` is
        // each side's heartbeat: a call whose side stops polling is ended by
        // calls_sweep() instead of staying "live" and making both users busy.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `calls` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `caller_id`        INT UNSIGNED NOT NULL,
                `callee_id`        INT UNSIGNED NOT NULL,
                `media`            ENUM('audio','video') NOT NULL DEFAULT 'audio',
                `status`           ENUM('ringing','accepted','rejected','missed',
                                        'cancelled','busy','ended','failed') NOT NULL DEFAULT 'ringing',
                `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `answered_at`      TIMESTAMP    NULL DEFAULT NULL,
                `ended_at`         TIMESTAMP    NULL DEFAULT NULL,
                `ended_by`         INT UNSIGNED NULL DEFAULT NULL,
                `last_seen_caller` TIMESTAMP    NULL DEFAULT NULL,
                `last_seen_callee` TIMESTAMP    NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_call_status`  (`status`),
                KEY `idx_call_callee`  (`callee_id`,`status`),
                KEY `idx_call_caller`  (`caller_id`,`created_at`),
                CONSTRAINT `fk_call_caller` FOREIGN KEY (`caller_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_call_callee` FOREIGN KEY (`callee_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>calls</code> is ready.';

        // The signalling mailbox: offer, answer and ICE candidates on their way
        // from one browser to the other. Transient — a call's rows are deleted
        // the moment it ends.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `call_signals` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `call_id`    INT UNSIGNED NOT NULL,
                `from_id`    INT UNSIGNED NOT NULL,
                `to_id`      INT UNSIGNED NOT NULL,
                `kind`       ENUM('offer','answer','ice','renegotiate') NOT NULL,
                `payload`    MEDIUMTEXT   NOT NULL,
                `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_signal_inbox` (`call_id`,`to_id`,`id`),
                KEY `idx_signal_created` (`created_at`),
                CONSTRAINT `fk_signal_call` FOREIGN KEY (`call_id`) REFERENCES `calls`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>call_signals</code> is ready.';

        // ── Gamification ──────────────────────────────────────────────────
        // One row per person, holding both the economy (xp, level, streak) and
        // the counters the badges are evaluated against. Denormalised on
        // purpose: the send path updates this row and nothing else, and the
        // leaderboard reads it without touching `messages` at all.
        //
        // day_xp/day_date enforce the daily XP cap; month_xp/month_key are what
        // makes a monthly ranking possible without a per-event ledger.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_stats` (
                `user_id`            INT UNSIGNED NOT NULL,
                `xp`                 INT UNSIGNED NOT NULL DEFAULT 0,
                `level`              SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `notified_level`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `streak_days`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `best_streak`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `last_active_day`    DATE     NULL DEFAULT NULL,
                `day_xp`             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `day_date`           DATE     NULL DEFAULT NULL,
                `month_xp`           INT UNSIGNED NOT NULL DEFAULT 0,
                `month_key`          CHAR(7)  NULL DEFAULT NULL,
                `messages_sent`      INT UNSIGNED NOT NULL DEFAULT 0,
                `replies_sent`       INT UNSIGNED NOT NULL DEFAULT 0,
                `images_sent`        INT UNSIGNED NOT NULL DEFAULT 0,
                `files_sent`         INT UNSIGNED NOT NULL DEFAULT 0,
                `voice_sent`         INT UNSIGNED NOT NULL DEFAULT 0,
                `reactions_given`    INT UNSIGNED NOT NULL DEFAULT 0,
                `reactions_received` INT UNSIGNED NOT NULL DEFAULT 0,
                `groups_created`     INT UNSIGNED NOT NULL DEFAULT 0,
                `peers_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `night_owl`          TINYINT(1) NOT NULL DEFAULT 0,
                `early_bird`         TINYINT(1) NOT NULL DEFAULT 0,
                `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`),
                KEY `idx_xp` (`xp`),
                KEY `idx_month_xp` (`month_key`,`month_xp`),
                CONSTRAINT `fk_stats_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>user_stats</code> is ready.';

        // `notified` is what stops a badge being celebrated twice — including
        // across two open tabs, which is why it lives here and not in a session.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_badges` (
                `user_id`   INT UNSIGNED NOT NULL,
                `badge`     VARCHAR(32)  NOT NULL,
                `notified`  TINYINT(1)   NOT NULL DEFAULT 0,
                `earned_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`,`badge`),
                KEY `idx_badge_earned` (`user_id`,`earned_at`),
                CONSTRAINT `fk_badge_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'Table <code>user_badges</code> is ready.';

        // Everybody who was already using the app gets credited for what they
        // have already done. Without this the first thing a leaderboard would
        // say is that a five-month regular and a brand-new account are equal.
        require_once APP_ROOT . '/includes/gamify.php';
        $seeded = 0;
        foreach ($pdo->query("SELECT id FROM users WHERE status = 'approved'") as $row) {
            $has = $pdo->prepare('SELECT user_id FROM user_stats WHERE user_id = ?');
            $has->execute([(int)$row['id']]);
            if ($has->fetch()) continue;

            gamify_ensure_row((int)$row['id']);
            gamify_sync((int)$row['id']);
            $seeded++;
        }
        $done[] = $seeded
            ? 'Scored ' . $seeded . ' existing account' . ($seeded === 1 ? '' : 's') . ' from their history.'
            : 'Gamification stats are already in place.';

        // ── Upload folders ────────────────────────────────────────────────
        foreach (['media', 'avatars'] as $sub) {
            $dir = UPLOAD_DIR . $sub;
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create upload folder: ' . $dir);
            }
        }
        $done[] = 'Upload folders are ready.';

        // Chat attachments are private: only ajax/file.php may read them, and it
        // checks the requester is in the conversation first. Written here as
        // well as shipped in the repo, so a freshly created (or restored)
        // uploads/media is never briefly world-readable.
        $guard = UPLOAD_DIR . 'media/.htaccess';
        if (!is_file($guard)) {
            file_put_contents($guard, implode("\n", [
                '# Chat attachments are served by ajax/file.php, never directly.',
                '<IfModule mod_authz_core.c>',
                '    Require all denied',
                '</IfModule>',
                '<IfModule !mod_authz_core.c>',
                '    Order allow,deny',
                '    Deny from all',
                '</IfModule>',
                '',
            ]));
            $done[] = 'Locked <code>uploads/media</code> down to the attachment gate.';
        }

        // Minting the key now means the first person to send a picture is not
        // also the one who finds out that config/ is read-only.
        app_key();
        if (!defined('APP_KEY') && !is_file(APP_ROOT . '/config/.appkey')) {
            $done[] = '<strong style="color:#b26a00">Warning:</strong> could not write <code>config/.appkey</code>'
                    . ' — attachment links will use a derived key instead. Make <code>config/</code> writable once,'
                    . ' or set <code>APP_KEY</code> in <code>config/config.local.php</code>.';
        }

        if (!is_writable(UPLOAD_DIR)) {
            $done[] = '<strong style="color:#b26a00">Warning:</strong> <code>' . e(UPLOAD_DIR)
                    . '</code> is not writable — sending attachments will fail.';
        }

        // ── Outgoing mail ─────────────────────────────────────────────────
        // Only the "Forgot password" flow needs it; chat itself does not, so a
        // missing mailer is reported rather than treated as a failed install.
        require_once APP_ROOT . '/includes/mailer.php';
        if (!is_file(APP_ROOT . '/vendor/autoload.php')) {
            $done[] = '<strong style="color:#b26a00">Warning:</strong> PHPMailer is not installed — run'
                    . ' <code>composer install</code> in <code>' . e(APP_ROOT) . '</code>.'
                    . ' Password-reset emails cannot be sent until it is.';
        } elseif (!mail_configured()) {
            $done[] = '<strong style="color:#b26a00">Warning:</strong> SMTP is not configured —'
                    . ' password-reset emails will not be sent. Set <code>SMTP_ENABLED</code>,'
                    . ' <code>SMTP_HOST</code>, <code>SMTP_USER</code>, <code>SMTP_PASS</code> and'
                    . ' <code>SMTP_FROM</code> in <code>config/config.local.php</code>'
                    . ' (see the SMTP block in <code>config/config.php</code> for a worked example).';
        } else {
            $done[] = 'Outgoing mail is configured (<code>' . e(SMTP_HOST) . ':' . (int)SMTP_PORT . '</code>).';
        }

        // PHP's own ceilings win over MAX_DOC_SIZE, and a POST over post_max_size
        // is discarded before any of our code runs — worth saying out loud here
        // rather than leaving somebody to discover it with a 20 MB archive.
        $toBytes = static function (string $value): int {
            $value = trim($value);
            $unit  = strtolower(substr($value, -1));
            $num   = (int)$value;
            return match ($unit) {
                'g'     => $num * 1024 * 1024 * 1024,
                'm'     => $num * 1024 * 1024,
                'k'     => $num * 1024,
                default => $num,
            };
        };

        $need  = max(MAX_FILE_SIZE, MAX_DOC_SIZE);
        $limit = min($toBytes((string)ini_get('upload_max_filesize')), $toBytes((string)ini_get('post_max_size')));
        if ($limit > 0 && $limit < $need) {
            $done[] = '<strong style="color:#b26a00">Warning:</strong> PHP accepts uploads of at most '
                    . e(format_bytes($limit)) . ' (<code>upload_max_filesize</code> / <code>post_max_size</code>), '
                    . 'but attachments are configured up to ' . e(format_bytes($need))
                    . '. Raise them in <code>php.ini</code> or lower <code>MAX_DOC_SIZE</code>.';
        }

        // ── Superadmin ────────────────────────────────────────────────────
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? OR role = "superadmin" LIMIT 1');
        $exists->execute([$adminEmail]);

        if ($exists->fetch()) {
            $done[] = 'A superadmin account already exists — left untouched.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, email, password, full_name, role, status)
                 VALUES ('superadmin', ?, ?, 'Super Admin', 'superadmin', 'approved')"
            );
            $stmt->execute([$adminEmail, password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12])]);
            $created = true;
            $done[] = 'Superadmin account created.';
        }

        // ── The default group ─────────────────────────────────────────────
        // Last, because it needs an approved account to own it and the
        // superadmin above may have been created moments ago.
        //
        // Idempotent in both halves: default_group_ensure() is a no-op once the
        // setting points at a live group, and the sync is an INSERT IGNORE plus
        // an UPDATE that only touches rows whose role is actually wrong. Run it
        // twice and the second run reports nothing to do.
        if (FEATURE_DEFAULT_GROUP) {
            $group_id = default_group_ensure();

            if ($group_id <= 0) {
                $done[] = '<strong style="color:#b26a00">Warning:</strong> the default group could not be '
                        . 'created. Chat still works; every group has to be made by hand.';
            } else {
                $sync  = default_group_sync_all();
                $count = (int)$pdo->query(
                    'SELECT COUNT(*) FROM group_members WHERE group_id = ' . $group_id
                )->fetchColumn();

                $done[] = 'Default group <code>' . e(DEFAULT_GROUP_NAME) . '</code> is ready — '
                        . $count . ' member' . ($count === 1 ? '' : 's')
                        . ($sync['added']    ? ', ' . $sync['added'] . ' added' : '')
                        . ($sync['promoted'] ? ', ' . $sync['promoted'] . ' role' . ($sync['promoted'] === 1 ? '' : 's') . ' updated' : '')
                        . '.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Environment checks shown before installing.
$checks = [
    'PHP 8.1 or newer'   => PHP_VERSION_ID >= 80100,
    'PDO MySQL driver'   => extension_loaded('pdo_mysql'),
    'fileinfo extension' => extension_loaded('fileinfo'),
    'mbstring extension' => extension_loaded('mbstring'),
    'uploads/ writable'  => is_dir(UPLOAD_DIR) ? is_writable(UPLOAD_DIR) : is_writable(APP_ROOT),
];
$ready = !in_array(false, $checks, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>UnivoChat Installer</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem 1rem}
  .card{background:#fff;border-radius:16px;padding:40px;width:100%;max-width:520px;box-shadow:0 4px 24px rgba(0,0,0,.1)}
  h1{font-size:1.6rem;color:#2450DC;margin-bottom:8px}
  p{color:#666;margin-bottom:20px;font-size:.95rem}
  .info{background:#EDE9FF;border-radius:8px;padding:14px;margin-bottom:16px;font-size:.9rem;color:#333;line-height:1.9}
  code{background:#d5cfff;padding:2px 6px;border-radius:4px;font-size:.85em}
  ul{list-style:none;font-size:.9rem;line-height:2;margin-bottom:20px}
  li.ok::before{content:"✔ ";color:#2e7d32;font-weight:700}
  li.no::before{content:"✘ ";color:#c62828;font-weight:700}
  .btn{width:100%;padding:14px;background:#2450DC;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;transition:.2s}
  .btn:hover{background:#1B3CAE}
  .btn[disabled]{background:#bbb;cursor:not-allowed}
  .success{background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:14px;border-radius:8px;margin-top:16px;font-size:.9rem;line-height:1.9}
  .error{background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:14px;border-radius:8px;margin-top:16px;font-size:.9rem;word-break:break-word}
  .warn{background:#fff3cd;border:1px solid #ffe69c;color:#664d03;padding:14px;border-radius:8px;margin-top:16px;font-size:.9rem}
  a{color:#2450DC}
</style>
</head>
<body>
<div class="card">
  <h1>⚡ UnivoChat Installer</h1>
  <p>Creates the database, tables, upload folders and the first superadmin account.</p>

  <div class="info">
    <strong>Environment:</strong> <code><?= e(APP_ENV) ?></code><br>
    <strong>Host:</strong> <code><?= e(DB_HOST) ?></code><br>
    <strong>Database:</strong> <code><?= e(DB_NAME) ?></code><br>
    <strong>User:</strong> <code><?= e(DB_USER) ?></code>
  </div>

  <ul>
    <?php foreach ($checks as $label => $pass): ?>
    <li class="<?= $pass ? 'ok' : 'no' ?>"><?= e($label) ?></li>
    <?php endforeach; ?>
  </ul>

  <form method="POST">
    <button class="btn" type="submit" <?= $ready ? '' : 'disabled' ?>>
      <?= $ready ? 'Run Installation' : 'Fix the failing checks first' ?>
    </button>
  </form>

  <?php if ($done): ?>
    <div class="success">
      <?php foreach ($done as $line): ?>• <?= $line ?><br><?php endforeach; ?>
    </div>
    <?php if ($created): ?>
    <div class="warn">
      Sign in with <code><?= e($adminEmail) ?></code> / <code><?= e($adminPass) ?></code>
      and change the password straight away.
    </div>
    <?php endif; ?>
    <div class="warn">
      <strong>Now delete the <code>setup/</code> folder.</strong><br>
      <a href="<?= e(base_url('login.php')) ?>">Go to UnivoChat →</a>
    </div>
  <?php elseif ($error): ?>
    <div class="error"><strong>Installation failed:</strong><br><?= e($error) ?></div>
  <?php endif; ?>
</div>
</body>
</html>
