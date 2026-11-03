<?php
// ─── App Configuration ────────────────────────────────────────────────────────
define('APP_NAME',    'UnivoChat');
// Also part of the cache-buster on style.css and app.js — see asset_url(),
// which adds each file's modification time so an edit reaches browsers even
// when this is not bumped.
define('APP_VERSION', '2.6.1');
define('TIMEZONE',    'Asia/Kolkata');

date_default_timezone_set(TIMEZONE);

/**
 * The app's timezone as a MySQL offset ("+05:30").
 *
 * MySQL is told this on every connection (see config/database.php) so NOW(),
 * CURRENT_TIMESTAMP and every TIMESTAMP column read back in the *same* zone PHP
 * formats them in. A named zone is deliberately not used: `SET time_zone =
 * 'Asia/Kolkata'` only works when the mysql.time_zone_* tables are populated,
 * which they are not on XAMPP or on most shared hosts.
 */
function tz_offset(?int $at = null): string {
    $seconds = (new DateTimeZone(TIMEZONE))->getOffset(new DateTimeImmutable('@' . ($at ?? time())));
    $sign    = $seconds < 0 ? '-' : '+';
    $seconds = abs($seconds);

    return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}

// ─── Paths ────────────────────────────────────────────────────────────────────
define('APP_ROOT',   realpath(__DIR__ . '/..'));
define('UPLOAD_DIR', APP_ROOT . '/uploads/');

// ─── Environment detection ────────────────────────────────────────────────────
// Anything served from localhost / 127.0.0.1 / *.local / *.test is treated as a
// development box; everything else is production.
$__host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))[0]);
$__isLocal = $__host === ''
    || in_array($__host, ['localhost', '127.0.0.1', '::1'], true)
    || str_ends_with($__host, '.local')
    || str_ends_with($__host, '.test')
    || str_starts_with($__host, '192.168.');

define('APP_ENV',   $__isLocal ? 'local' : 'production');
define('APP_DEBUG', APP_ENV === 'local');
unset($__host, $__isLocal);

// ─── Error reporting ──────────────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// ─── Database ─────────────────────────────────────────────────────────────────
// Optional machine-specific overrides. Anything defined in config.local.php
// wins, because the defaults below are only applied when still undefined.
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

if (APP_ENV === 'local') {
    defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
    defined('DB_NAME') || define('DB_NAME', 'univochat');
    defined('DB_USER') || define('DB_USER', 'root');
    defined('DB_PASS') || define('DB_PASS', '');
} else {
    defined('DB_HOST') || define('DB_HOST', 'localhost');
    defined('DB_NAME') || define('DB_NAME', '');
    defined('DB_USER') || define('DB_USER', '');
    defined('DB_PASS') || define('DB_PASS', '');
}
defined('DB_PORT') || define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

// ─── Uploads ──────────────────────────────────────────────────────────────────
/**
 * The ceiling for anything the composer attaches: 50 MB, whatever it is.
 *
 * These two are what every validator measures against (validate_image and
 * validate_any_file), and what dashboard.php hands the client so a file that is
 * too big is refused instantly instead of after a 50 MB round trip.
 *
 * They are *not* what the server will actually accept on their own. PHP refuses
 * an over-sized request before a single line of this application runs, so
 * `upload_max_filesize` and `post_max_size` have to be raised alongside them —
 * see the "Upload limits" block in .htaccess and .user.ini, which cover
 * mod_php and FastCGI/LiteSpeed hosts respectively. Raising the numbers here
 * and nowhere else buys a limit the server still rejects at 40 MB.
 */
define('MAX_FILE_SIZE',   50 * 1024 * 1024);          // 50 MB – images
define('MAX_DOC_SIZE',    50 * 1024 * 1024);          // 50 MB – everything else

/**
 * The only extensions rendered as a picture inside a bubble.
 *
 * Kept narrow on purpose. An .svg is markup, not a raster image — it can carry
 * script and external references — so it is treated as a code file: named,
 * offered for download, and never drawn into the conversation. Everything a
 * browser would have to *interpret* to display belongs on the download path.
 */
define('ALLOWED_IMAGES',  ['jpg','jpeg','png','gif','webp']);

/**
 * Attachments: everything except the handful of extensions below.
 *
 * The app used to run an allow-list of ten document formats, which meant a
 * colleague could not send a .sql dump, a stylesheet or a source file. The
 * policy is now inverted — anything may be attached — which is safe because of
 * where those bytes end up and how they come back out:
 *
 *   • uploads/media carries a Deny-all .htaccess, so Apache never serves it;
 *   • ajax/file.php is the only route out, and it checks the requester is in
 *     the conversation before a single byte moves;
 *   • it answers with `Content-Disposition: attachment`, `nosniff` and
 *     `Content-Security-Policy: default-src 'none'; sandbox`, so a file is
 *     saved, never rendered, whatever it claims to contain.
 *
 * What stays blocked is what nothing above can make safe *if the deny rule ever
 * fails*: anything Apache or PHP might execute in place, plus the executable
 * formats a person could be talked into double-clicking after downloading. Both
 * groups are what every other messenger refuses too.
 */
define('BLOCKED_EXTS', [
    // Executed by the web server if it ever reaches them directly
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'pht', 'phar',
    'shtml', 'shtm', 'asp', 'aspx', 'ascx', 'ashx', 'asmx', 'cer', 'jsp', 'jspx',
    'cgi', 'fcgi', 'htaccess', 'htpasswd', 'ini',
    // Executed by the operating system after a download
    'exe', 'msi', 'msix', 'com', 'scr', 'pif', 'bat', 'cmd', 'vbs', 'vbe', 'wsf',
    'wsh', 'hta', 'cpl', 'scf', 'inf', 'lnk', 'reg', 'ps1', 'ps1xml', 'psc1',
    'jar', 'apk', 'dll', 'sys', 'drv', 'gadget', 'app',
]);

/**
 * Content types ajax/file.php may announce for a downloaded attachment.
 *
 * Anything not listed is served as `application/octet-stream`. The point is not
 * to be exhaustive — it is that a .html, .svg or .xml is never handed back
 * under a type a browser would try to *interpret*, however it got uploaded.
 */
define('DOWNLOAD_MIMES', [
    'pdf'  => 'application/pdf',
    'zip'  => 'application/zip',
    'rar'  => 'application/vnd.rar',
    '7z'   => 'application/x-7z-compressed',
    'gz'   => 'application/gzip',
    'tar'  => 'application/x-tar',
    'csv'  => 'text/csv',
    'txt'  => 'text/plain',
    'rtf'  => 'application/rtf',
    'doc'  => 'application/msword',
    'xls'  => 'application/vnd.ms-excel',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'odt'  => 'application/vnd.oasis.opendocument.text',
    'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
    'odp'  => 'application/vnd.oasis.opendocument.presentation',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
]);

/**
 * Extensions shown with a code badge and never previewed inline.
 *
 * Only used for the label on the card — the download path is the same for every
 * non-image attachment. Listed so a .css reads as "CSS" instead of falling back
 * to the generic file badge.
 */
define('CODE_EXTS', [
    'html', 'htm', 'xhtml', 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'cjs',
    'ts', 'tsx', 'jsx', 'vue', 'svelte', 'json', 'jsonc', 'xml', 'yml', 'yaml',
    'toml', 'md', 'markdown', 'sql', 'sh', 'bash', 'zsh', 'py', 'rb', 'go',
    'rs', 'java', 'kt', 'swift', 'c', 'h', 'cpp', 'hpp', 'cc', 'cs', 'm',
    'lua', 'r', 'pl', 'dart', 'ex', 'exs', 'svg', 'env', 'lock', 'diff', 'patch',
]);

/**
 * Extensions the shared-files panel files under "Archives" rather than
 * "Documents". Everything that is not an image and not an archive — including
 * every code file — lands in "Documents".
 */
define('ARCHIVE_EXTS', ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'zst', 'lz', 'lzma', 'cab']);

// How far back the shared-files panel looks. A conversation with more
// attachments than this shows the newest ones; nothing is lost, and the panel
// never turns into an unbounded query.
define('MEDIA_PANEL_LIMIT', 300);

// ─── Feature flags ────────────────────────────────────────────────────────────
/**
 * Camera capture and voice notes.
 *
 * Live as of 2.0.0. Two things have to agree for either to work, and both are
 * done: the flag below renders the button, the modal and the recording bar, and
 * the root .htaccess grants `camera=(self), microphone=(self)` in its
 * Permissions-Policy — with the header still set to `camera=()` the buttons
 * appear and then fail with "access was blocked", which looks like a
 * permissions bug and is not one.
 *
 * Setting either back to false removes the markup entirely, so nothing in the
 * app can reach getUserMedia and no browser ever shows a prompt. The server
 * declines a hand-made voice upload too, so the switch means one thing only.
 *
 * Both APIs additionally need a secure context: https://, or localhost. Over
 * plain http:// to a LAN address the browser hides the API and app.js hides the
 * buttons rather than shipping ones that cannot work.
 */
define('FEATURE_CAMERA', true);
define('FEATURE_VOICE',  true);

/**
 * Voice notes. Recorded in the browser by MediaRecorder, which picks its own
 * container: Chrome and Firefox produce WebM/Opus, Safari produces MP4/AAC.
 *
 * libmagic looks at the container, not at whether there is a video track in it,
 * so a WebM with only audio in it is still reported as `video/webm` — hence the
 * video/* entries against audio-only extensions.
 */
define('ALLOWED_AUDIO', [
    'webm' => ['audio/webm', 'video/webm'],
    'ogg'  => ['audio/ogg', 'application/ogg', 'video/ogg'],
    'm4a'  => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
    'mp3'  => ['audio/mpeg'],
    'wav'  => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
]);
define('MAX_AUDIO_SIZE',   15 * 1024 * 1024);   // 15 MB
define('MAX_VOICE_SECONDS', 300);               // 5 minutes per recording

/**
 * How many attachments one message may carry.
 *
 * Deliberately larger than PHP's own `max_file_uploads` (20 by default, and not
 * settable at runtime): the client sends them in batches of UPLOAD_BATCH_SIZE
 * rather than as one enormous multipart body, so neither that limit nor
 * post_max_size is what decides how many pictures a person may send. Every file
 * becomes its own row either way, so a batched send and a single one produce the
 * same conversation.
 */
define('MAX_FILES_PER_MESSAGE', 40);

// Files per upload request, and the byte budget one request may carry. Both are
// well inside a stock php.ini, which is the point — a batch that fits never
// meets the "larger than this server accepts" wall.
define('UPLOAD_BATCH_SIZE',  8);
// Per-request byte budget for a *multi-file* batch. A single file larger than
// this still travels on its own (batchFiles cannot split one file), which is
// why post_max_size has to clear MAX_DOC_SIZE rather than merely this.
define('UPLOAD_BATCH_BYTES', 24 * 1024 * 1024);

// Secret behind attachment URLs (see app_key()). Deliberately left undefined:
// one is generated into config/.appkey on first use. Define it in
// config.local.php only to pin the same key across several servers.
// defined('APP_KEY') || define('APP_KEY', '…');
define('MAX_MESSAGE_LEN', 4000);

// Text longer than MAX_MESSAGE_LEN is not refused: it travels as a Markdown
// attachment instead (see long_text_attachment()). This is the ceiling on that
// file, in bytes — far beyond anything typed, but a bound on what one paste can
// write to disk.
define('MAX_TEXT_FILE_BYTES', 2 * 1024 * 1024);

// How many conversations one Forward may reach at once.
define('FORWARD_MAX_TARGETS', 5);

// How many message hits the sidebar search offers at once. Deliberately modest:
// the list is a way *into* a conversation, not a second reading surface, and one
// row past this is fetched only to say "there are more".
define('SEARCH_RESULT_LIMIT', 25);
define('MAX_AVATAR_SIZE', 3 * 1024 * 1024);           // 3 MB

// ─── GIFs, stickers and memes (KLIPY) ─────────────────────────────────────────
/**
 * One provider behind all three tabs of the media picker. KLIPY addresses them
 * as /gifs, /stickers and /static-memes under a single key, which is why the
 * picker can offer three libraries without three integrations.
 *
 * The key stays on the server: ajax/klipy.php proxies every call, so it is never
 * written into the page. Set it to '' (or in config.local.php) to switch the
 * feature off — the button then disappears rather than failing on click.
 */
defined('KLIPY_API_KEY') || define('KLIPY_API_KEY', '');
define('KLIPY_BASE', 'https://api.klipy.com/api/v1/');

// The kinds the picker offers, in tab order: the name this app stores in
// `messages.type` => the path segment KLIPY serves it under.
define('KLIPY_KINDS', [
    'gif'     => 'gifs',
    'sticker' => 'stickers',
    'meme'    => 'static-memes',
]);

// off | low | medium | high — how hard KLIPY filters before it answers. 'high'
// is the strictest and the only one worth offering a workplace: a search that
// returns 50 results under 'medium' comes back with 30 under 'high'.
define('KLIPY_CONTENT_FILTER', 'high');

// xx_YY. Steers trending and search towards what this audience recognises.
define('KLIPY_LOCALE',  'en_IN');
define('KLIPY_LIMIT',   24);          // results per page (KLIPY's own cap is 50)
define('KLIPY_TIMEOUT', 6);           // seconds per API call
define('KLIPY_SHARE_TIMEOUT', 3);     // seconds for the fire-and-forget share ping

// ─── Rich messages ────────────────────────────────────────────────────────────
// A pasted spreadsheet is stored as a sanitised HTML fragment, which needs far
// more room than its plain-text length: 4 000 characters of table text easily
// becomes 40 000 characters of <tr>/<td> markup.
define('MAX_MESSAGE_HTML', 200 * 1024);

// How long a sender may still edit a text message they sent (0 = forever).
define('MSG_EDIT_WINDOW', 900);       // 15 minutes

/**
 * How long a sender may delete a message for everybody. 0 = no limit.
 *
 * Open-ended, unlike the edit window, because the two answer different worries.
 * An edit rewrites what somebody is recorded as having said, so it closes
 * quickly. A deletion only ever removes — and "I posted that in the wrong
 * group" is as often noticed a week later as a minute later, which is why
 * Slack and Teams put no clock on it either.
 *
 * Set it to a number of seconds (172800 for 48 hours, the WhatsApp rule) to
 * enforce a window instead; the button then disappears from older bubbles and
 * the endpoint refuses them.
 */
define('MSG_DELETE_WINDOW', 0);

/**
 * How much of a conversation is loaded at a time.
 *
 * The first page is what a reader sees on opening a chat; scrolling to the top
 * asks for the next MSG_HISTORY_PAGE older messages, and keeps doing so until
 * the beginning. Nothing is unreachable — the pages simply arrive as they are
 * needed rather than in one query that grows with the conversation.
 */
define('MSG_PAGE_SIZE',    50);
define('MSG_HISTORY_PAGE', 50);

// The reactions offered by the picker. Anything else is rejected server-side.
define('REACTION_EMOJIS', ['👍', '❤️', '😂', '😮', '😢', '🙏', '🎉', '🔥']);
define('MAX_REACTIONS_PER_USER', 3);  // per message

/**
 * ─── The default group ────────────────────────────────────────────────────────
 *
 * One room everybody is in. Every approved account joins it automatically — the
 * ones that already existed when the feature landed, and every account approved
 * afterwards — so there is always somewhere to reach the whole team without
 * anybody having to be invited first.
 *
 * Superadmins and admins are its group admins, kept in step with their account
 * role: promote somebody to admin and they become an admin here too; demote
 * them and they go back to being a member.
 *
 * Nobody can leave it and nobody can be removed from it — that is what "the
 * default group" means, and it is the same rule Slack applies to #general. It
 * also spares the app the loop it would otherwise be in, re-adding on the next
 * login the people it had just let out. Every other group is unaffected: they
 * are created, joined and left exactly as before.
 *
 * Which group it is lives in app_settings under `default_group_id`, so renaming
 * it changes nothing. The name below is only used when creating it.
 */
define('FEATURE_DEFAULT_GROUP', true);
define('DEFAULT_GROUP_NAME', 'Univo Team');
// Kept short: it is shown in the chat header beside the member count, where a
// long one is truncated anyway. The rule about joining and leaving is stated by
// the info panel, which an admin cannot edit away.
define('DEFAULT_GROUP_DESC', 'Everyone at Univo.');
define('DEFAULT_GROUP_SETTING', 'default_group_id');

// ─── Polling ──────────────────────────────────────────────────────────────────
define('MSG_POLL_INTERVAL', 3000);    // ms – active conversation
// The heartbeat also decides whether a message needs a push notification, so
// it has to be more frequent than the window the server suppresses them for
// (PUSH_ACTIVE_TTL). The two used to be 30s against 45s, which meant somebody
// who walked away could go three quarters of a minute without being notified.
define('STATUS_INTERVAL',   25000);   // ms – online heartbeat
define('USERS_INTERVAL',    10000);   // ms – contact list refresh
// Levels and badges are the coldest thing the app polls: the endpoint
// re-evaluates eighteen predicates and recomputes derived figures, and nobody
// is waiting on the answer. A minute is often enough for a reward to feel
// immediate without putting that work on the message loop.
define('GAMIFY_POLL_INTERVAL', 60000);

/**
 * Show levels, streaks, badges and the leaderboard? Held back for a later
 * release.
 *
 * This switch is **display only**. Everything carries on being recorded with
 * it off: gamify_award() still banks XP on every message, reaction and upload,
 * gamify_touch_day() still extends streaks on the heartbeat, and the counters
 * behind all eighteen badges keep climbing. What stops is the polling, the
 * level ring, the streak chip, the leaderboard and the profile sections — and
 * ajax/gamify.php answers `enabled: false` so none of it leaks early.
 *
 * The badge predicates are all monotonic and evaluated from those stored
 * counters, so the day this is flipped to true the first sync awards every
 * badge already earned, retroactively and in one go. Nothing is lost by
 * waiting; the history is being written either way.
 */
define('FEATURE_GAMIFY_UI', false);
define('TYPING_TTL',        5);       // seconds
define('ONLINE_TTL',        90);      // seconds before a user is marked offline

// ─── Calls (WebRTC) ───────────────────────────────────────────────────────────
/**
 * One-to-one voice and video calls. The media goes browser to browser; the
 * server only rings, relays the offer/answer through `call_signals`, and keeps
 * the `calls` log — see includes/calls.php.
 *
 * Setting this to false makes ajax/call.php refuse everything and drops the
 * incoming-call field from the polls, so nothing can ring.
 */
define('CALLS_ENABLED', true);
define('CALL_RING_TIMEOUT',  40);     // seconds a call rings before it is missed
// A live call whose side has not polled for this long is treated as gone (tab
// closed, laptop shut). The client polls every few seconds during a call, and
// a tab holding a live RTCPeerConnection is exempt from timer throttling.
define('CALL_HEARTBEAT_TTL', 30);     // seconds
define('CALL_START_PER_MIN', 6);      // calls one person may place per minute
define('CALL_SIGNAL_MAX_BYTES', 65536); // an SDP with every candidate is ~5–15 KB

/**
 * ICE servers. STUN is enough for most networks; TURN relays the media when
 * both sides sit behind strict NAT (mobile data, campus and office networks),
 * and without it those calls connect and then carry no sound or picture.
 *
 * TURN takes one of two forms, both overridable from config.local.php:
 *   • CALL_TURN_SECRET — coturn's `use-auth-secret` (the TURN REST scheme):
 *     each user gets a credential that expires after CALL_TURN_TTL seconds,
 *     so nothing long-lived ever reaches the browser. Preferred.
 *   • CALL_TURN_USERNAME / CALL_TURN_CREDENTIAL — a provider's fixed pair.
 */
defined('CALL_STUN_URLS')       || define('CALL_STUN_URLS', [
    'stun:stun.l.google.com:19302',
    'stun:stun1.l.google.com:19302',
]);
defined('CALL_TURN_URLS')       || define('CALL_TURN_URLS', []);   // e.g. ['turn:turn.example.com:3478', 'turns:turn.example.com:5349']
defined('CALL_TURN_SECRET')     || define('CALL_TURN_SECRET', '');
defined('CALL_TURN_USERNAME')   || define('CALL_TURN_USERNAME', '');
defined('CALL_TURN_CREDENTIAL') || define('CALL_TURN_CREDENTIAL', '');
define('CALL_TURN_TTL', 3600);        // seconds a REST-scheme credential lasts

// ─── Web push ─────────────────────────────────────────────────────────────────
// Contact address sent to the push service in the VAPID token; push providers
// use it to reach the site owner when a subscription misbehaves.
define('PUSH_CONTACT',     'mailto:ravijaiswal8960@gmail.com');
define('PUSH_TTL',          86400);   // seconds a push service may hold a message
/**
 * A tab whose user was demonstrably *at* it this recently gets no push — the
 * app announces the message in the page instead.
 *
 * Kept just above the heartbeat interval and no further. It used to be 45s
 * against a 30s heartbeat, which meant up to three quarters of a minute of
 * pushes were suppressed for somebody who had already walked away. The client
 * now also sends an un-focused heartbeat the instant the window loses focus, so
 * in practice this only covers the case where that one beacon was lost — but
 * the margin over STATUS_INTERVAL still has to be wide enough that a slow
 * request cannot make somebody sitting right there look absent.
 */
define('PUSH_ACTIVE_TTL',   40);
define('PUSH_TIMEOUT',      6);       // seconds per push request
// Endpoints contacted at once. Delivery used to be a serial loop, which put a
// group of twenty members several minutes behind a group of two — the last
// member's notification arrived long after the conversation had moved on.
// Wide enough that an ordinary group is one sweep, so the whole send costs
// about what its slowest single endpoint costs.
define('PUSH_CONCURRENCY',  24);
// A push service answering 429/500/502/503 is asking us to come back, not
// telling us the subscription is dead. One retry costs a second and recovers
// the overwhelming majority of those.
define('PUSH_RETRIES',      1);

// ─── Outgoing mail (SMTP, via PHPMailer) ──────────────────────────────────────
/**
 * Credentials for the mailbox the app sends from.
 *
 * Every value here is a `defined() ||` default, so config/config.local.php —
 * which is loaded above and is not in version control — is where the real ones
 * belong. Leave SMTP_ENABLED false, or SMTP_HOST empty, and the password-reset
 * flow reports honestly that mail is not configured instead of pretending to
 * have sent something.
 *
 * Gmail example (config/config.local.php):
 *
 *     define('SMTP_ENABLED',   true);
 *     define('SMTP_HOST',      'smtp.gmail.com');
 *     define('SMTP_PORT',      587);
 *     define('SMTP_SECURE',    'tls');
 *     define('SMTP_USER',      'you@gmail.com');
 *     define('SMTP_PASS',      'abcd efgh ijkl mnop');   // an App Password
 *     define('SMTP_FROM',      'you@gmail.com');
 *     define('SMTP_FROM_NAME', 'UnivoChat');
 *
 * Gmail rejects an account password here: 2-Step Verification has to be on and
 * the value must be a 16-character App Password. Port 465 with SMTP_SECURE
 * 'ssl' works too, and is worth trying where a host blocks 587.
 */
defined('SMTP_ENABLED')   || define('SMTP_ENABLED',   true);
defined('SMTP_HOST')      || define('SMTP_HOST',      '');
defined('SMTP_PORT')      || define('SMTP_PORT',      587);
defined('SMTP_SECURE')    || define('SMTP_SECURE',    'tls');   // 'tls' | 'ssl' | ''
defined('SMTP_USER')      || define('SMTP_USER',      '');
defined('SMTP_PASS')      || define('SMTP_PASS',      '');
// SMTP_FROM is an *address*, not a display name — PHPMailer validates it and
// refuses to send otherwise. The name shown to the recipient is SMTP_FROM_NAME,
// and the two together arrive as: Univo Chat <mail@example.com>.
//
// It should also match SMTP_USER. Hostinger, like most providers, only lets an
// authenticated mailbox send as itself; a mismatched From is rejected at the
// server even when PHPMailer is happy with it.
defined('SMTP_FROM')      || define('SMTP_FROM',      '');
defined('SMTP_FROM_NAME') || define('SMTP_FROM_NAME', 'Univo Chat');
defined('SMTP_REPLY_TO')  || define('SMTP_REPLY_TO',  '');
defined('SMTP_TIMEOUT')   || define('SMTP_TIMEOUT',   15);      // seconds
// Writes the whole SMTP conversation to the error log. Never to the page — a
// debug trace in a response would carry the password with it.
defined('SMTP_DEBUG')     || define('SMTP_DEBUG',     false);

// ─── Password reset ───────────────────────────────────────────────────────────
// A link is good for this long, is single-use, and is invalidated the moment a
// newer one is requested for the same account.
define('RESET_TOKEN_TTL',    3600);   // 1 hour
// Ceilings on how often a link may be asked for, so the form cannot be turned
// into a way of mailbombing somebody else's inbox.
define('RESET_MAX_PER_HOUR', 4);      // per account
define('RESET_MAX_PER_IP',   12);     // per requesting address, per hour

// ─── Session ──────────────────────────────────────────────────────────────────
define('SESSION_LIFETIME', 86400);    // 24 hours
define('SESSION_NAME',     'UNIVOCHAT_SESSID');
define('SESSION_REFRESH',  15);       // seconds between role/status re-checks

// ─── URL helper ───────────────────────────────────────────────────────────────
// Returns the web-root-relative path of the app, e.g. "/UnivoChat/login.php".
function base_url(string $path = ''): string {
    static $base = null;

    if ($base === null) {
        $appRoot = rtrim(str_replace('\\', '/', (string)APP_ROOT), '/');
        $base    = '';

        // Preferred: derive the prefix by comparing the running script's real
        // path with the URL it was requested under. Immune to symlinks,
        // Windows drive-letter casing and unusual DOCUMENT_ROOT values.
        $scriptFile = str_replace('\\', '/', (string)(realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ''));
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($scriptFile !== '' && $scriptName !== '' && stripos($scriptFile, $appRoot . '/') === 0) {
            $rel = substr($scriptFile, strlen($appRoot));           // /ajax/get_users.php
            if (str_ends_with(strtolower($scriptName), strtolower($rel))) {
                $base = substr($scriptName, 0, strlen($scriptName) - strlen($rel));
            }
        }

        // Fallback: subtract DOCUMENT_ROOT from the application root.
        if ($base === '') {
            $docRoot = str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
            $docRoot = rtrim($docRoot !== '' ? (realpath($docRoot) ?: $docRoot) : '', '/');
            $docRoot = str_replace('\\', '/', $docRoot);
            if ($docRoot !== '' && stripos($appRoot, $docRoot) === 0) {
                $base = substr($appRoot, strlen($docRoot));
            }
        }

        $base = rtrim($base, '/');
    }

    return $base . '/' . ltrim($path, '/');
}

// Scheme + host of the running request, e.g. "https://ravijaiswal.in".
function url_origin(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https://' : 'http://') . $host;
}

/**
 * base_url() for a static file, with a version that changes whenever the file
 * does. The root .htaccess caches anything carrying `?v=` for a year as
 * immutable, and the service worker serves it without asking the network — so
 * a returning visitor on a slow line downloads app.js once per release, not
 * once per visit. The modification time is part of it so an edit that forgot
 * to bump APP_VERSION still reaches everybody.
 */
function asset_url(string $path): string {
    $mtime = @filemtime(APP_ROOT . '/' . ltrim($path, '/'));
    return base_url($path) . '?v=' . APP_VERSION . ($mtime ? '.' . $mtime : '');
}

// Absolute URL (scheme + host + path) – used for redirects and manifests.
function full_url(string $path = ''): string {
    return url_origin() . base_url($path);
}

/**
 * Absolute URL for a link that is already root-relative — what base_url() and
 * avatar_url() return. Left untouched when it is already absolute, or a data:
 * URI (the generated initials avatars).
 */
function absolute_url(string $url): string {
    if ($url === '' || preg_match('#^(https?:)?//|^data:#i', $url)) {
        return $url;
    }
    return url_origin() . '/' . ltrim($url, '/');
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// ─── Request helpers ──────────────────────────────────────────────────────────
// AJAX endpoints define API_REQUEST so guards can answer with JSON instead of
// bouncing the browser to an HTML login page.
function is_api_request(): bool {
    return defined('API_REQUEST') && API_REQUEST;
}

function redirect(string $path): never {
    header('Location: ' . base_url($path));
    exit;
}

// ─── Security helpers ─────────────────────────────────────────────────────────
function e(?string $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): bool {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $token        = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    // An empty session token must never validate against an empty submission.
    if ($sessionToken === '' || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals($sessionToken, $token);
}

function json_response(array $data, int $code = 200): never {
    send_json(json_encode_body($data), $code);
    exit;
}

function json_encode_body(array $data): string {
    return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * Write a JSON body the way a slow connection wants it.
 *
 * Two things, both aimed at the polls that run every few seconds for as long
 * as the app is open:
 *
 *   • A successful GET carries an ETag and is marked `private, no-cache`
 *     rather than `no-store`, so the browser keeps the last answer and asks
 *     "has it changed?". The contact list is ~6 KB and usually identical ten
 *     seconds later; a 304 is a couple of hundred bytes of headers. fetch()
 *     hands the script the stored body as an ordinary 200, so no client code
 *     has to know. logout.php clears the stored copies (Clear-Site-Data).
 *   • Anything over a kilobyte is gzipped here rather than left to the web
 *     server, because XAMPP ships with mod_deflate off. Apache and LiteSpeed
 *     both leave a body alone once it has a Content-Encoding, so this never
 *     compresses twice — and the length declared is that of the bytes sent.
 *
 * @param bool $close  Ask the client to drop the connection once the body is in
 *                     — what api_respond_and_continue() needs to let go early.
 */
function send_json(string $body, int $code = 200, bool $close = false): void {
    if (headers_sent()) {
        echo $body;
        return;
    }

    $method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $cacheable = $code === 200 && ($method === 'GET' || $method === 'HEAD');

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Vary: Accept-Encoding');

    if ($cacheable) {
        $etag = 'W/"' . hash('xxh128', $body) . '"';
        header('Cache-Control: private, no-cache');
        header('ETag: ' . $etag);

        $sent = array_map('trim', explode(',', (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
        if (in_array($etag, $sent, true)) {
            http_response_code(304);
            return;
        }
    } else {
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    $phpCompresses = (bool)ini_get('zlib.output_compression');
    if (!$phpCompresses && strlen($body) > 1024 && function_exists('gzencode')
        && preg_match('/\bgzip\b/i', (string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''))) {
        $gz = gzencode($body, 5);
        if ($gz !== false) {
            $body = $gz;
            header('Content-Encoding: gzip');
        }
    }

    // With PHP's own output compression on, the length would describe the
    // wrong bytes and the browser would wait for ever.
    if (!$phpCompresses) {
        header('Content-Length: ' . strlen($body));
    }
    if ($close) {
        header('Connection: close');
        // A small body is left uncompressed above, and mod_deflate would then
        // re-chunk it and hold the end of the response until the script exits —
        // exactly the wait an early close exists to avoid.
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
            @apache_setenv('no-brotli', '1');
        }
    }
    if ($method !== 'HEAD') {
        echo $body;
    }
}

// ─── Filesystem helper ────────────────────────────────────────────────────────
function ensure_dir(string $dir): bool {
    return is_dir($dir) || mkdir($dir, 0775, true) || is_dir($dir);
}
