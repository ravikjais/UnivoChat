<?php
require_once __DIR__ . '/../config/database.php';

// ─── Time helpers ─────────────────────────────────────────────────────────────
function time_ago(?string $datetime): string {
    if (!$datetime) return '';
    $ts = strtotime($datetime);
    if ($ts === false) return '';

    $diff = time() - $ts;
    if ($diff < 0)      return 'Just now';
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60)    . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600)  . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M', $ts);
}

/** Calendar-aware day difference: 0 = today, 1 = yesterday, … */
function days_apart(int $ts): int {
    $a = new DateTimeImmutable('today');
    $b = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(TIMEZONE))->setTime(0, 0);
    return (int)$a->diff($b)->format('%r%a') * -1;
}

function format_chat_time(?string $datetime): string {
    if (!$datetime) return '';
    $ts = strtotime($datetime);
    if ($ts === false) return '';

    $days = days_apart($ts);
    if ($days <= 0) return date('h:i A', $ts);
    if ($days === 1) return 'Yesterday';
    return date('d/m/y', $ts);
}

function format_msg_time(?string $datetime): string {
    $ts = $datetime ? strtotime($datetime) : false;
    return $ts === false ? '' : date('h:i A', $ts);
}

function format_date_separator(?string $datetime): string {
    if (!$datetime) return '';
    $ts = strtotime($datetime);
    if ($ts === false) return '';

    $days = days_apart($ts);
    if ($days <= 0)  return 'Today';
    if ($days === 1) return 'Yesterday';
    return date('d F Y', $ts);
}

// ─── Rich message sanitising ──────────────────────────────────────────────────
/**
 * Tags kept when a message is stored as HTML, mapped to the attributes each one
 * may keep. Everything needed to represent a spreadsheet paste plus the basic
 * inline formatting that comes with it; nothing that can execute, load a remote
 * resource, or position itself outside the bubble.
 */
const RICH_ALLOWED_TAGS = [
    'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'caption' => [],
    'tr'    => [], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
    'p'     => [], 'div' => [], 'br' => [], 'hr' => [],
    'b'     => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [],
    // A pasted document keeps its outline: Teams, ChatGPT and Word all emit real
    // headings, and flattening them is what turns a briefing into a wall of text.
    'h1'    => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
    'ul'    => [], 'ol' => [], 'li' => [],
    'code'  => [], 'pre' => [], 'blockquote' => [],
    'a'     => ['href'],
];

/** Dropped with their contents — unwrapping these would leak script/style text. */
const RICH_DROP_TAGS = [
    'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
    'link', 'meta', 'base', 'title', 'head', 'noscript', 'template', 'svg', 'math',
    'form', 'input', 'button', 'select', 'option', 'textarea', 'label',
    'img', 'picture', 'source', 'audio', 'video', 'canvas', 'map', 'area',
];

/**
 * Reduce a pasted HTML fragment to the safe subset above.
 *
 * Unknown tags are unwrapped rather than deleted so the text a spreadsheet
 * wraps in <font>/<span style> survives; only the tags in RICH_DROP_TAGS lose
 * their contents. Returns '' when nothing renderable is left.
 */
function sanitize_message_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    // The meta tag is what makes DOMDocument read the fragment as UTF-8; the
    // wrapper div gives us a stable node to serialise the children of.
    $loaded = $doc->loadHTML(
        '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
        . '<div id="uni-rich-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$loaded) return '';

    $root = (new DOMXPath($doc))->query('//div[@id="uni-rich-root"]')->item(0);
    if (!$root) return '';

    rich_clean_children($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }

    return rich_has_markup($out) ? rich_collapse_whitespace($out) : '';
}

/**
 * Collapse the newlines a source document sprinkles between its tags.
 *
 * Whitespace is insignificant in HTML everywhere except inside <pre>, which is
 * the one place a line break *is* the formatting, so those blocks are stepped
 * over rather than flattened into a single line.
 */
function rich_collapse_whitespace(string $html): string {
    $parts = preg_split('#(<pre\b[^>]*>.*?</pre>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return trim(preg_replace('/\s*\R\s*/u', ' ', $html) ?? $html);
    }

    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) continue;                       // the captured <pre> blocks
        $parts[$i] = preg_replace('/\s*\R\s*/u', ' ', $part) ?? $part;
    }

    return trim(implode('', $parts));
}

/** Recursively strip a node's children down to the allow-list. */
function rich_clean_children(DOMNode $parent): void {
    // Snapshot first: the loop rewrites the child list as it goes.
    foreach (iterator_to_array($parent->childNodes) as $node) {
        if ($node instanceof DOMText) continue;

        if (!$node instanceof DOMElement) {   // comments, CDATA, processing instructions
            $parent->removeChild($node);
            continue;
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, RICH_DROP_TAGS, true)) {
            $parent->removeChild($node);
            continue;
        }

        rich_clean_children($node);

        if (!array_key_exists($tag, RICH_ALLOWED_TAGS)) {
            // Unwrap: the tag goes, its (already cleaned) contents stay.
            while ($node->firstChild) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);
            continue;
        }

        rich_clean_attributes($node, $tag);
    }
}

function rich_clean_attributes(DOMElement $node, string $tag): void {
    $allowed = RICH_ALLOWED_TAGS[$tag];
    $href    = $tag === 'a' ? trim($node->getAttribute('href')) : '';

    foreach (iterator_to_array($node->attributes) as $attr) {
        $name = strtolower($attr->nodeName);

        if (!in_array($name, $allowed, true)) {
            $node->removeAttribute($attr->nodeName);
            continue;
        }

        if ($name === 'colspan' || $name === 'rowspan') {
            $span = (int)trim($attr->nodeValue ?? '');
            if ($span > 1 && $span <= 100) {
                $node->setAttribute($name, (string)$span);
            } else {
                $node->removeAttribute($name);
            }
        }
    }

    // After the loop, never inside it: a source that wrote href before target
    // would otherwise have the pair set and then stripped again on the next turn,
    // leaving a pasted link to navigate the conversation away in the same tab.
    if ($tag === 'a') {
        if (preg_match('#^(https?://|mailto:)#i', $href)) {
            $node->setAttribute('href', $href);
            $node->setAttribute('target', '_blank');
            $node->setAttribute('rel', 'noopener noreferrer');
        } else {
            $node->removeAttribute('href');
        }
    }
}

/** Does the fragment still contain a tag, i.e. is it worth storing as HTML? */
function rich_has_markup(string $html): bool {
    return $html !== '' && preg_match('/<[a-z]/i', $html) === 1;
}

/**
 * Turn a submitted body into the pair actually stored.
 *
 * A rich submission that sanitises down to nothing keeps the words the person
 * typed and is stored as plain text — never discarded, which would leave them
 * looking at "Nothing to send".
 *
 * @return array{message: string, format: string}
 */
function normalize_message_body(string $body, string $format): array {
    if ($format === 'html') {
        $clean = sanitize_message_html($body);
        if ($clean !== '') {
            return ['message' => $clean, 'format' => 'html'];
        }
        $body = html_to_text($body);
    }

    // Strip control characters (keeping tabs and newlines) without discarding
    // the message if the regex engine trips over unusual input.
    $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);

    return ['message' => trim($cleaned ?? $body), 'format' => 'plain'];
}

/**
 * Size check for a stored body, measured the way the reader experiences it.
 *
 * @return string Empty when acceptable, otherwise the reason to send back.
 */
function message_length_error(string $message, string $format): string {
    $visible = mb_strlen($format === 'html' ? html_to_text($message) : $message);
    if ($visible > MAX_MESSAGE_LEN) {
        return 'Message is too long (max ' . MAX_MESSAGE_LEN . ' characters).';
    }
    // Second cap on the markup itself, so no client can post a megabyte of tags
    // wrapped around ten visible characters.
    if ($format === 'html' && strlen($message) > MAX_MESSAGE_HTML) {
        return 'That table is too large to send. Try splitting it up.';
    }
    return '';
}

/** Plain-text rendering of a stored message — used for lengths and previews. */
function html_to_text(string $html): string {
    // Turn the structural tags into spaces first so "A</td><td>B" is not "AB".
    $text = preg_replace(
        '#<(br|hr|/p|/div|/tr|/td|/th|/li|/ul|/ol|/blockquote|/h[1-6])[^>]*>#i', ' ', $html
    ) ?? $html;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
}

/** What a deleted message reads as everywhere it is still referred to. */
const DELETED_PREVIEW = '🚫 This message was deleted';

/** One-line summary of a message row for the contact list and notifications. */
function message_preview(?array $msg, int $limit = 60): string {
    if (!$msg) return '';
    // Before the type check: a deleted photo is not "📷 Image" any more.
    if (!empty($msg['deleted_at']))             return DELETED_PREVIEW;
    if (($msg['type'] ?? 'text') === 'gif')     return '🎞️ GIF';
    if (($msg['type'] ?? 'text') === 'sticker') return '🩷 Sticker';
    if (($msg['type'] ?? 'text') === 'meme')    return '😂 Meme';
    if (($msg['type'] ?? 'text') === 'call')    return call_log_label((string)($msg['message'] ?? ''));

    // An attachment sent with words says those words: "📷 Floor plan, level 2"
    // tells the contact list more than "📷 Image" ever could.
    $caption = message_caption_text($msg, $limit);
    if (($msg['type'] ?? 'text') === 'image')   return '📷 ' . ($caption !== '' ? $caption : 'Image');
    if (($msg['type'] ?? 'text') === 'audio')   return '🎤 ' . ($caption !== '' ? $caption : 'Voice message');
    if (($msg['type'] ?? 'text') === 'file') {
        if ($caption !== '') return '📎 ' . $caption;
        $name = trim((string)($msg['file_name'] ?? ''));
        return '📎 ' . ($name !== '' ? mb_substr($name, 0, $limit) : 'File');
    }

    $body = (string)($msg['message'] ?? '');
    if (($msg['format'] ?? 'plain') === 'html') {
        $text = html_to_text($body);
        // A pasted spreadsheet reads as noise in a one-line preview.
        return str_contains(strtolower($body), '<table') ? '📊 Table' : mb_substr($text, 0, $limit);
    }

    return mb_substr($body, 0, $limit);
}

/**
 * The caption an attachment row carries, flattened to one plain line.
 *
 * An image, document or voice note sent with text keeps that text in its own
 * `message` column rather than as a second row, so this is the one place the
 * previews read it from. Empty for a bare attachment and for any text row.
 */
/**
 * One line for a call-log row: "📹 Video call · 3:12", "📞 Missed voice call".
 *
 * Read from the row's JSON (written by call_finish()) rather than from the
 * calls table, so a preview never costs a second query. Cancelled and busy
 * read as missed: to the person who was called, that is what they were.
 */
function call_log_label(string $json): string {
    $data  = json_decode($json, true) ?: [];
    $video = ($data['media'] ?? 'audio') === 'video';
    $icon  = $video ? '📹' : '📞';
    $kind  = $video ? 'video call' : 'voice call';

    switch ($data['status'] ?? '') {
        case 'ended':
            $secs = max(0, (int)($data['duration'] ?? 0));
            $time = $secs >= 3600
                ? sprintf('%d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60)
                : sprintf('%d:%02d', intdiv($secs, 60), $secs % 60);
            return $icon . ' ' . ucfirst($kind) . ' · ' . $time;
        case 'rejected':
            return $icon . ' Declined ' . $kind;
        case 'failed':
            return $icon . ' ' . ucfirst($kind) . ' failed';
        default:
            return $icon . ' Missed ' . $kind;
    }
}

function message_caption_text(array $msg, int $limit = 60): string {
    if (($msg['type'] ?? 'text') === 'text') return '';

    $body = trim((string)($msg['message'] ?? ''));
    if ($body === '') return '';

    $text = ($msg['format'] ?? 'plain') === 'html' ? html_to_text($body) : $body;
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

    return mb_substr($text, 0, $limit);
}

/**
 * A window of a message around the word somebody searched for.
 *
 * message_preview() answers "what is this message about", which is the wrong
 * question for a search result: the first sixty characters of a long message
 * routinely do not contain the thing that made it a hit. This centres on the
 * match instead, and returns plain text — the caller escapes it and paints the
 * highlight, so no markup is ever assembled here.
 */
function search_snippet(array $msg, string $needle, int $window = 120): string {
    $type = (string)($msg['type'] ?? 'text');

    // An attachment matched on its caption or on its name. The caption is the
    // body when it is what matched; otherwise the name is all there is to quote.
    $caption = message_caption_text($msg, 100000);
    if ($type !== 'text' && $caption !== '' && ($needle === '' || mb_stripos($caption, $needle) !== false)) {
        $msg  = ['type' => 'text', 'format' => 'plain', 'message' => $caption];
        $type = 'text';
    }
    if ($type !== 'text') {
        $name = trim((string)($msg['file_name'] ?? ''));
        return $name !== '' ? $name : message_preview($msg, $window);
    }

    $text = ($msg['format'] ?? 'plain') === 'html'
        ? html_to_text((string)($msg['message'] ?? ''))
        : (string)($msg['message'] ?? '');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

    if ($text === '' || mb_strlen($text) <= $window) return $text;

    $at = $needle === '' ? false : mb_stripos($text, $needle);
    if ($at === false) return mb_substr($text, 0, $window) . '…';

    // A third of the window ahead of the match, the rest after it: the words
    // *before* a hit are usually what makes it recognisable.
    $start = max(0, $at - (int)($window / 3));
    $out   = mb_substr($text, $start, $window);

    return ($start > 0 ? '…' : '') . $out . ($start + $window < mb_strlen($text) ? '…' : '');
}

// ─── Groups ───────────────────────────────────────────────────────────────────
/**
 * Group chat lives in the same `messages` table as everything else: a row with
 * `group_id` set is a group message, a row with `receiver_id` set is a direct
 * one. Membership in `group_members` is the entire access-control story — every
 * read and every write goes through is_group_member(), and there is no other
 * way to reach a group's messages or even learn that it exists.
 */
function group_get(int $id): ?array {
    return $id > 0
        ? fetch_one('SELECT id, name, description, ' . group_avatar_column()
                  . ', created_by, created_at FROM chat_groups WHERE id = ?', [$id])
        : null;
}

/**
 * Has this database been migrated for group display pictures yet?
 *
 * Asked for the same reason as messages_support_type(): setup/install.php adds
 * `chat_groups.avatar`, and until somebody runs it, selecting the column would
 * fail every query that touches a group — emptying the whole sidebar rather
 * than merely leaving the groups without photographs. One metadata read per
 * request buys a graceful degradation instead.
 */
function groups_have_avatar(): bool {
    static $supported = null;
    if ($supported !== null) return $supported;

    return $supported = (bool)fetch_one("SHOW COLUMNS FROM `chat_groups` LIKE 'avatar'");
}

/** `g.avatar`, or a NULL stand-in on an installation that has not migrated. */
function group_avatar_column(string $prefix = ''): string {
    return groups_have_avatar() ? $prefix . 'avatar' : 'NULL AS avatar';
}

/**
 * What the client shows for a group: the uploaded picture when there is one,
 * otherwise the same generated initials image a person without a photo gets.
 *
 * Generated server-side rather than drawn in JavaScript so a group looks
 * identical in the sidebar, the header, the info panel and a notification —
 * one implementation of the colour and the initials, not two that can drift.
 */
function group_avatar_url(?string $avatar, string $name): string {
    if ($avatar) {
        $file = basename($avatar);
        if ($file !== '' && is_file(UPLOAD_DIR . 'avatars/' . $file)) {
            return base_url('uploads/avatars/' . rawurlencode($file));
        }
    }
    return initials_avatar($name !== '' ? $name : 'Group');
}

/** Is there a real photograph behind the URL above, or just the initials tile? */
function group_has_photo(?string $avatar): bool {
    $file = basename((string)$avatar);
    return $file !== '' && is_file(UPLOAD_DIR . 'avatars/' . $file);
}

/** Store a new group picture and delete the file it replaces. */
function group_set_avatar(int $group_id, array $file): array {
    if (!groups_have_avatar()) {
        return ['ok' => false, 'msg' => 'This database has not been updated for group photos yet. Run setup/install.php once.'];
    }
    // The same ceiling a profile picture gets: it is displayed at 128 px and
    // there is no reason for one to weigh as much as a shared photo.
    if ((int)($file['error'] ?? 0) === UPLOAD_ERR_OK && (int)($file['size'] ?? 0) > MAX_AVATAR_SIZE) {
        return ['ok' => false, 'msg' => 'Group picture must be ' . (MAX_AVATAR_SIZE / 1024 / 1024) . ' MB or smaller.'];
    }

    $saved = save_upload($file, 'avatars');
    if (!$saved['ok']) {
        return ['ok' => false, 'msg' => $saved['msg']];
    }

    $previous = fetch_one('SELECT avatar FROM chat_groups WHERE id = ?', [$group_id]);
    query('UPDATE chat_groups SET avatar = ? WHERE id = ?', [$saved['file'], $group_id]);

    // basename() so a crafted value in the column can never delete something
    // outside the avatars folder.
    $old = basename((string)($previous['avatar'] ?? ''));
    if ($old !== '' && $old !== $saved['file'] && is_file(UPLOAD_DIR . 'avatars/' . $old)) {
        @unlink(UPLOAD_DIR . 'avatars/' . $old);
    }

    return ['ok' => true, 'file' => $saved['file']];
}

/** Drop the uploaded picture; the group falls back to its initials tile. */
function group_clear_avatar(int $group_id): array {
    if (!groups_have_avatar()) {
        return ['ok' => false, 'msg' => 'This group has no picture to remove.'];
    }

    $current = fetch_one('SELECT avatar FROM chat_groups WHERE id = ?', [$group_id]);
    $file    = basename((string)($current['avatar'] ?? ''));
    if ($file === '') {
        return ['ok' => false, 'msg' => 'This group has no picture to remove.'];
    }

    query('UPDATE chat_groups SET avatar = NULL WHERE id = ?', [$group_id]);
    if (is_file(UPLOAD_DIR . 'avatars/' . $file)) {
        @unlink(UPLOAD_DIR . 'avatars/' . $file);
    }

    return ['ok' => true];
}

/** 'admin', 'member', or null when the user is not in the group at all. */
function group_member_role(int $group_id, int $user_id): ?string {
    if ($group_id <= 0 || $user_id <= 0) return null;

    $row = fetch_one(
        'SELECT role FROM group_members WHERE group_id = ? AND user_id = ?',
        [$group_id, $user_id]
    );
    return $row ? (string)$row['role'] : null;
}

function is_group_member(int $group_id, int $user_id): bool {
    return group_member_role($group_id, $user_id) !== null;
}

function is_group_admin(int $group_id, int $user_id): bool {
    return group_member_role($group_id, $user_id) === 'admin';
}

/** Everyone in the group, admins first, each with the fields the UI shows. */
function group_members(int $group_id): array {
    return fetch_all(
        'SELECT u.id, u.username, u.full_name, u.avatar, u.is_online, u.last_active,
                gm.role, gm.joined_at
           FROM group_members gm
           JOIN users u ON u.id = gm.user_id
          WHERE gm.group_id = ?
          ORDER BY (gm.role = \'admin\') DESC, u.full_name ASC',
        [$group_id]
    );
}

function group_member_ids(int $group_id): array {
    return array_map(
        static fn(array $r): int => (int)$r['user_id'],
        fetch_all('SELECT user_id FROM group_members WHERE group_id = ?', [$group_id])
    );
}

// ─── Settings store ───────────────────────────────────────────────────────────
// A small key-value table: the server's push identity, and which group is the
// default one. Both swallow database errors on purpose — on an installation
// that has not run setup/install.php since the upgrade the table simply does
// not exist yet, and a missing settings row must degrade to "that feature is
// unavailable", never to a chat window that refuses to load.
function setting_get(string $name): ?string {
    try {
        $row = fetch_one('SELECT value FROM app_settings WHERE name = ?', [$name]);
    } catch (PDOException $e) {
        return null;
    }
    return $row ? (string)$row['value'] : null;
}

function setting_set(string $name, string $value): bool {
    try {
        query(
            'INSERT INTO app_settings (name, value) VALUES (?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$name, $value]
        );
    } catch (PDOException $e) {
        return false;
    }
    return true;
}

// ─── The default group ────────────────────────────────────────────────────────
// The one room everybody is in. See the block comment in config/config.php for
// what it is and why it cannot be left.

/**
 * Which group is the default one, or 0 if there is not one yet.
 *
 * Read from app_settings rather than matched by name, so renaming the group in
 * the UI does not quietly turn it back into an ordinary one. The id is checked
 * against chat_groups on the way out: a stale setting left behind by a group
 * that was deleted directly in the database must read as "no default group",
 * not as a group id that no longer resolves.
 *
 * Cached for the request — this is asked on the sidebar, on the info panel and
 * on every membership change, and the answer cannot change underneath us. The
 * one moment it can is just after the group is created, which is why
 * default_group_ensure() re-reads with $refresh.
 */
function default_group_id(bool $refresh = false): int {
    static $cached = null;
    if ($refresh) $cached = null;
    if ($cached !== null) return $cached;

    if (!FEATURE_DEFAULT_GROUP) return $cached = 0;

    $id = (int)(setting_get(DEFAULT_GROUP_SETTING) ?? 0);
    if ($id <= 0) return $cached = 0;

    // A setting left pointing at a group that was deleted directly in the
    // database must read as "no default group", not as a dangling id.
    try {
        $row = fetch_one('SELECT id FROM chat_groups WHERE id = ?', [$id]);
    } catch (PDOException $e) {
        return $cached = 0;   // schema not migrated yet
    }
    return $cached = $row ? $id : 0;
}

function is_default_group(int $group_id): bool {
    return $group_id > 0 && $group_id === default_group_id();
}

/**
 * The account that owns the default group.
 *
 * chat_groups.created_by is a foreign key, so the group needs a real user to
 * belong to. The superadmin is the obvious choice; an installation without one
 * falls back to the longest-standing admin and then to the longest-standing
 * approved account, because a default group owned by somebody is worth more
 * than no default group at all.
 */
function default_group_owner(): int {
    $row = fetch_one(
        "SELECT id FROM users
          WHERE status = 'approved'
          ORDER BY FIELD(role, 'superadmin', 'admin', 'user') ASC, id ASC
          LIMIT 1"
    );
    return (int)($row['id'] ?? 0);
}

/** The group role an account's own role earns it: admins run the default room. */
function default_group_role(string $user_role): string {
    return in_array($user_role, ['superadmin', 'admin'], true) ? 'admin' : 'member';
}

/**
 * Make sure the default group exists, and hand back its id.
 *
 * Idempotent and safe to call from anywhere: if the setting already points at a
 * live group this is a single indexed read. Returns 0 when the feature is off,
 * when the schema has not been migrated yet, or when there is no approved
 * account to own the group — all three degrade to "no default group", never to
 * an error the user sees.
 */
function default_group_ensure(): int {
    if (!FEATURE_DEFAULT_GROUP) return 0;

    $existing = default_group_id();
    if ($existing > 0) return $existing;

    $owner = default_group_owner();
    if ($owner <= 0) return 0;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        query('INSERT INTO chat_groups (name, description, created_by) VALUES (?,?,?)',
              [DEFAULT_GROUP_NAME, DEFAULT_GROUP_DESC, $owner]);
        $id = (int)$pdo->lastInsertId();

        // The owner joins first and as an admin, so the group is never briefly
        // one that nobody can administer.
        query("INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?,?,'admin')",
              [$id, $owner]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[UnivoChat] default_group_ensure failed: ' . $e->getMessage());
        return 0;
    }

    setting_set(DEFAULT_GROUP_SETTING, (string)$id);
    default_group_id(true);       // the cached 0 from the check above is now wrong

    default_group_sync_all();
    return $id;
}

/**
 * Put one account in the default group, with the role their account role earns.
 *
 * Called when somebody registers, when they are approved, and when they are
 * promoted or demoted. Cheap enough for all three: one read of their own
 * membership row, and a write only when it is actually wrong.
 *
 * Pending, rejected and disabled accounts are left out — every other part of
 * the app treats "member of a group" as something only an approved account can
 * be, and a pending registration showing up in the member list before an admin
 * has looked at it would be a small privacy leak of its own.
 */
function default_group_sync_user(int $user_id, ?string $user_role = null, ?string $status = null): bool {
    if ($user_id <= 0) return false;

    $group_id = default_group_id();
    if ($group_id <= 0) return false;

    if ($user_role === null || $status === null) {
        $row = fetch_one('SELECT role, status FROM users WHERE id = ?', [$user_id]);
        if (!$row) return false;
        $user_role = (string)$row['role'];
        $status    = (string)$row['status'];
    }

    $current = group_member_role($group_id, $user_id);

    // Not somebody who should be in it. Anyone already in stays in: losing
    // access is what `disabled` is for, and stripping the membership would
    // delete their place in the room rather than suspend it.
    if ($status !== 'approved') return false;

    $wanted = default_group_role($user_role);

    if ($current === null) {
        query('INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?,?,?)',
              [$group_id, $user_id, $wanted]);
        return true;
    }

    if ($current !== $wanted) {
        query('UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?',
              [$wanted, $group_id, $user_id]);
        return true;
    }

    return false;
}

/**
 * Bring the whole default group in line in two statements.
 *
 * Used by the installer's backfill and once more the moment the group is
 * created. Set-based rather than a loop over users: the same work as calling
 * default_group_sync_user() for everybody, without the round trip per account.
 *
 * @return array{added:int, promoted:int}
 */
function default_group_sync_all(): array {
    $group_id = default_group_id();
    if ($group_id <= 0) return ['added' => 0, 'promoted' => 0];

    // Everyone approved and not already in. INSERT IGNORE rather than a read
    // followed by a loop: the primary key on (group_id, user_id) is what makes
    // this safe to run as often as we like.
    $added = query(
        "INSERT IGNORE INTO group_members (group_id, user_id, role)
         SELECT ?, u.id, IF(u.role IN ('superadmin','admin'), 'admin', 'member')
           FROM users u
          WHERE u.status = 'approved'",
        [$group_id]
    )->rowCount();

    // And anyone whose account role has moved since they joined. Restricted to
    // this group: an admin is not automatically an admin of every room they
    // happen to be in, only of this one.
    $promoted = query(
        "UPDATE group_members gm
           JOIN users u ON u.id = gm.user_id
            SET gm.role = IF(u.role IN ('superadmin','admin'), 'admin', 'member')
          WHERE gm.group_id = ?
            AND gm.role <> IF(u.role IN ('superadmin','admin'), 'admin', 'member')",
        [$group_id]
    )->rowCount();

    return ['added' => max(0, $added), 'promoted' => max(0, $promoted)];
}

/**
 * Create a group and put the creator in it as its admin.
 *
 * Wrapped in a transaction: a group with no admin — or worse, one whose creator
 * is not a member — would be unreachable by everybody, including the person who
 * just made it.
 */
function group_create(int $creator_id, string $name, string $description, array $member_ids): array {
    $name        = trim($name);
    $description = trim($description);

    if ($name === '')            return ['ok' => false, 'msg' => 'Give the group a name.'];
    if (mb_strlen($name) > 80)   return ['ok' => false, 'msg' => 'That name is too long (80 characters max).'];

    // Only approved users, never the creator twice, never somebody made up.
    $others = array_values(array_unique(array_filter(
        array_map('intval', $member_ids),
        static fn(int $id): bool => $id > 0 && $id !== $creator_id
    )));

    if ($others) {
        $marks = implode(',', array_fill(0, count($others), '?'));
        $others = array_map(
            static fn(array $r): int => (int)$r['id'],
            fetch_all("SELECT id FROM users WHERE status = 'approved' AND id IN ($marks)", $others)
        );
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        query('INSERT INTO chat_groups (name, description, created_by) VALUES (?,?,?)',
              [$name, $description !== '' ? mb_substr($description, 0, 255) : null, $creator_id]);
        $group_id = (int)$pdo->lastInsertId();

        query('INSERT INTO group_members (group_id, user_id, role) VALUES (?,?,\'admin\')',
              [$group_id, $creator_id]);

        foreach ($others as $id) {
            query('INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?,?,\'member\')',
                  [$group_id, $id]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[UnivoChat] group_create failed: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'The group could not be created.'];
    }

    return ['ok' => true, 'group_id' => $group_id, 'added' => count($others)];
}

/** @return int How many of the given users were actually new to the group. */
function group_add_members(int $group_id, array $user_ids): int {
    $added = 0;
    foreach (array_unique(array_map('intval', $user_ids)) as $id) {
        if ($id <= 0 || is_group_member($group_id, $id)) continue;

        $user = fetch_one('SELECT id FROM users WHERE id = ? AND status = \'approved\'', [$id]);
        if (!$user) continue;

        query('INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?,?,\'member\')',
              [$group_id, $id]);
        $added++;
    }
    return $added;
}

/**
 * Remove somebody from a group — used both by an admin removing a member and by
 * a member leaving.
 *
 * The last admin cannot walk out and strand the group: the longest-standing
 * remaining member is promoted first. An empty group is deleted outright, which
 * takes its messages with it through the foreign key.
 */
function group_remove_member(int $group_id, int $user_id): bool {
    // Nobody leaves the default group and nobody is removed from it. The
    // endpoints check this first so they can say why; this is the invariant
    // itself, so no later caller can take the last member out of the one room
    // the whole team is supposed to share.
    if (is_default_group($group_id)) return false;

    $role = group_member_role($group_id, $user_id);
    if ($role === null) return false;

    query('DELETE FROM group_members WHERE group_id = ? AND user_id = ?', [$group_id, $user_id]);
    query('DELETE FROM group_typing  WHERE group_id = ? AND user_id = ?', [$group_id, $user_id]);

    $remaining = fetch_one('SELECT COUNT(*) AS cnt FROM group_members WHERE group_id = ?', [$group_id]);
    if ((int)($remaining['cnt'] ?? 0) === 0) {
        query('DELETE FROM chat_groups WHERE id = ?', [$group_id]);
        return true;
    }

    if ($role === 'admin') {
        $admins = fetch_one(
            'SELECT COUNT(*) AS cnt FROM group_members WHERE group_id = ? AND role = \'admin\'',
            [$group_id]
        );
        if ((int)($admins['cnt'] ?? 0) === 0) {
            query(
                'UPDATE group_members SET role = \'admin\'
                  WHERE group_id = ?
                  ORDER BY joined_at ASC, user_id ASC
                  LIMIT 1',
                [$group_id]
            );
        }
    }

    return true;
}

/** Groups $uid belongs to, newest conversation first. */
function get_user_groups(int $uid): array {
    return fetch_all(
        'SELECT g.id, g.name, g.description, ' . group_avatar_column('g.') . ', g.created_by,
                gm.role, gm.last_read_id,
                (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id) AS member_count
           FROM group_members gm
           JOIN chat_groups g ON g.id = gm.group_id
          WHERE gm.user_id = ?
          ORDER BY g.name ASC',
        [$uid]
    );
}

/** Newest message of every group $uid is in, keyed by group id. One query. */
function get_group_last_messages_map(int $uid): array {
    $rows = fetch_all(
        'SELECT t.gid, m.id, m.sender_id, m.message, m.format, m.type, m.file_name, m.created_at,
                u.full_name AS sender_name, ' . deleted_at_column() . '
           FROM (
                SELECT m2.group_id AS gid, MAX(m2.id) AS mid
                  FROM messages m2
                  JOIN group_members gm ON gm.group_id = m2.group_id AND gm.user_id = ?
                 WHERE m2.group_id IS NOT NULL
                 GROUP BY m2.group_id
           ) t
           JOIN messages m ON m.id = t.mid
           JOIN users    u ON u.id = m.sender_id',
        [$uid]
    );

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['gid']] = $r;
    }
    return $map;
}

/** Unread count per group for $uid: anything newer than their last_read_id. */
function get_group_unread_map(int $uid): array {
    $rows = fetch_all(
        'SELECT gm.group_id, COUNT(m.id) AS cnt
           FROM group_members gm
           LEFT JOIN messages m
                  ON m.group_id = gm.group_id
                 AND m.id > gm.last_read_id
                 AND m.sender_id <> gm.user_id
          WHERE gm.user_id = ?
          GROUP BY gm.group_id',
        [$uid]
    );

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['group_id']] = (int)$r['cnt'];
    }
    return $map;
}

/** Everything in the group up to $upto counts as seen by $uid. */
function group_mark_read(int $group_id, int $uid, int $upto = 0): void {
    if ($upto <= 0) {
        $row  = fetch_one('SELECT MAX(id) AS mid FROM messages WHERE group_id = ?', [$group_id]);
        $upto = (int)($row['mid'] ?? 0);
    }

    // GREATEST keeps a late-arriving poll from dragging the marker backwards.
    query(
        'UPDATE group_members SET last_read_id = GREATEST(last_read_id, ?)
          WHERE group_id = ? AND user_id = ?',
        [$upto, $group_id, $uid]
    );
}

/**
 * Every group $uid is in, shaped exactly like the contact rows beside them in
 * the sidebar so the client can merge the two lists and sort by recency.
 */
function sidebar_groups(int $uid): array {
    $groups = get_user_groups($uid);
    $last   = get_group_last_messages_map($uid);
    $unread = get_group_unread_map($uid);

    $out = [];
    foreach ($groups as $g) {
        $id  = (int)$g['id'];
        $msg = $last[$id] ?? null;

        // "Ravi: 📷 Image" — in a group, who said it matters as much as what.
        $preview = $msg ? message_preview($msg) : '';
        if ($msg && $preview !== '') {
            $who     = (int)$msg['sender_id'] === $uid ? 'You' : explode(' ', (string)$msg['sender_name'])[0];
            $preview = $who . ': ' . $preview;
        }

        $out[] = [
            'id'           => $id,
            'is_group'     => true,
            'name'         => $g['name'],
            'full_name'    => $g['name'],
            'description'  => $g['description'],
            'avatar'       => group_avatar_url($g['avatar'] ?? null, (string)$g['name']),
            'has_photo'    => group_has_photo($g['avatar'] ?? null),
            'is_default'   => is_default_group($id),
            'role'         => $g['role'],
            'member_count' => (int)$g['member_count'],
            'last_message' => $preview,
            'last_msg_id'  => $msg ? (int)$msg['id'] : 0,
            'last_time'    => $msg ? format_chat_time($msg['created_at']) : '',
            'last_ts'      => $msg ? (int)strtotime($msg['created_at']) : 0,
            'unread'       => $unread[$id] ?? 0,
            'is_mine'      => $msg ? ((int)$msg['sender_id'] === $uid) : false,
            // A group has no per-member receipts, so its bubbles only ever show
            // the single "sent" tick — and so does its row.
            'last_is_read' => false,
            'last_deleted' => $msg && !empty($msg['deleted_at']),
        ];
    }

    usort($out, static fn(array $a, array $b): int =>
        $b['last_ts'] <=> $a['last_ts'] ?: strcasecmp((string)$a['name'], (string)$b['name']));

    return $out;
}

/** Members other than $uid who have typed in the last TYPING_TTL seconds. */
function group_typists(int $group_id, int $uid): array {
    $rows = fetch_all(
        'SELECT u.full_name
           FROM group_typing gt
           JOIN users u ON u.id = gt.user_id
          WHERE gt.group_id = ?
            AND gt.user_id <> ?
            AND gt.updated_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
          ORDER BY gt.updated_at DESC
          LIMIT 3',
        [$group_id, $uid, TYPING_TTL]
    );

    // First names only: "Ana, Ravi" reads better above a composer than two
    // full names do, and it is all the indicator has room for.
    return array_map(
        static fn(array $r): string => explode(' ', (string)$r['full_name'])[0],
        $rows
    );
}

/**
 * The same reaction map as get_reactions_map(), scoped to a group.
 *
 * Separate rather than parameterised because the join that proves the caller
 * may see these messages is different: membership, not "one of the two ids".
 */
function get_group_reactions_map(int $uid, int $group_id, int $from_id): array {
    $rows = fetch_all(
        'SELECT r.message_id, r.emoji, r.user_id, u.full_name
           FROM message_reactions r
           JOIN messages m ON m.id = r.message_id
           JOIN users    u ON u.id = r.user_id
          WHERE m.id >= ? AND m.group_id = ?
          ORDER BY r.id ASC',
        [$from_id, $group_id]
    );

    return reactions_rows_to_map($rows, $uid);
}

/**
 * May this user see this message at all?
 *
 * The single question behind the attachment gate and the reaction endpoint: a
 * direct message belongs to its two people, a group message to the group.
 */
function can_see_message(array $msg, int $uid): bool {
    $group_id = (int)($msg['group_id'] ?? 0);
    if ($group_id > 0) {
        return is_group_member($group_id, $uid);
    }

    return (int)($msg['sender_id'] ?? 0) === $uid || (int)($msg['receiver_id'] ?? 0) === $uid;
}

// ─── Query helpers ────────────────────────────────────────────────────────────
/** Escape LIKE wildcards so a search for "100%" does not match everything. */
function like_escape(string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

// ─── User helpers ─────────────────────────────────────────────────────────────
function get_approved_users(int $exclude_id): array {
    return fetch_all(
        'SELECT id, username, full_name, avatar, is_online, last_active
           FROM users
          WHERE status = \'approved\' AND id != ?
          ORDER BY is_online DESC, full_name ASC',
        [$exclude_id]
    );
}

function get_user(int $id): ?array {
    return fetch_one(
        'SELECT id, username, full_name, avatar, is_online, last_active, role, status
           FROM users WHERE id = ?',
        [$id]
    );
}

// ─── Message helpers ──────────────────────────────────────────────────────────
/**
 * Does the messages table know about this type yet?
 *
 * Worth asking, because of how an ENUM fails. Without STRICT_TRANS_TABLES — not
 * set on a stock XAMPP — MySQL answers an unknown ENUM value by silently storing
 * an empty string instead of refusing it. A sticker sent against an un-migrated
 * schema therefore lands as a typeless, textless row: an empty bubble in the
 * conversation, a valid-looking URL in the column, and nothing in the log to say
 * why. One metadata read on the picker's path turns that into a sentence
 * somebody can act on.
 */
function messages_support_type(string $type): bool {
    static $definition = null;

    if ($definition === null) {
        $column     = fetch_one("SHOW COLUMNS FROM `messages` LIKE 'type'");
        $definition = strtolower((string)($column['Type'] ?? ''));
    }

    return str_contains($definition, "'" . strtolower($type) . "'");
}

function get_last_message(int $uid_a, int $uid_b): ?array {
    return fetch_one(
        'SELECT id, message, format, type, file_name, created_at, sender_id, '
      . deleted_at_column('') . ' FROM messages
          WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
          ORDER BY id DESC LIMIT 1',
        [$uid_a, $uid_b, $uid_b, $uid_a]
    );
}

/**
 * Newest message of every conversation $uid takes part in, keyed by the other
 * participant's id. One query instead of one per contact.
 */
function get_last_messages_map(int $uid): array {
    $rows = fetch_all(
        'SELECT t.peer, m.id, m.sender_id, m.message, m.format, m.type, m.file_name, m.is_read, m.created_at, '
      . deleted_at_column() . '
           FROM (
                SELECT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS peer,
                       MAX(id) AS mid
                  FROM messages
                 WHERE sender_id = ? OR receiver_id = ?
                 GROUP BY peer
           ) t
           JOIN messages m ON m.id = t.mid',
        [$uid, $uid, $uid]
    );

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['peer']] = $r;
    }
    return $map;
}

/** Unread counts for $uid keyed by sender id. One query for every contact. */
function get_unread_map(int $uid): array {
    $rows = fetch_all(
        'SELECT sender_id, COUNT(*) AS cnt
           FROM messages
          WHERE receiver_id = ? AND is_read = 0
          GROUP BY sender_id',
        [$uid]
    );

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['sender_id']] = (int)$r['cnt'];
    }
    return $map;
}

/**
 * May this message still be edited by its sender? Images are never editable —
 * there is nothing to type — and MSG_EDIT_WINDOW closes the door on old text.
 */
function message_is_editable(array $msg): bool {
    if (!empty($msg['deleted_at']))          return false;
    if (!empty($msg['forwarded']))           return false;
    if (($msg['type'] ?? 'text') !== 'text') return false;
    if (MSG_EDIT_WINDOW <= 0)                return true;

    $ts = strtotime((string)($msg['created_at'] ?? ''));
    return $ts !== false && (time() - $ts) <= MSG_EDIT_WINDOW;
}

/**
 * May the sender still take this message back? Any type — a photo sent to the
 * wrong group is the case that matters most — but only inside the window, and
 * only once.
 */
function message_is_deletable(array $msg): bool {
    if (!empty($msg['deleted_at'])) return false;
    if (MSG_DELETE_WINDOW <= 0)     return true;

    $ts = strtotime((string)($msg['created_at'] ?? ''));
    return $ts !== false && (time() - $ts) <= MSG_DELETE_WINDOW;
}

/**
 * Take a message back, for everybody.
 *
 * A tombstone rather than a DELETE, for three reasons: the polling loop only
 * ever asks for ids *above* what it already has, so a vanished row would stay
 * on every other screen until a reload; a reply quoting it would lose its
 * parent; and "This message was deleted" is what a reader needs to see, rather
 * than a conversation that quietly does not add up.
 *
 * Everything the message actually carried does go, though — the text, the name
 * of the file, its bytes on disk and the reactions people left on it. What
 * remains is a row saying that something was here and is not any more.
 *
 * @return array{ok: bool, msg: string}
 */
function message_delete(array $msg): array {
    if (!messages_have('deleted_at')) {
        return ['ok' => false, 'msg' => 'This database has not been updated for deleting messages yet. Run setup/install.php once.'];
    }

    $id = (int)$msg['id'];

    query(
        "UPDATE messages
            SET deleted_at = NOW(), message = NULL, file_path = NULL, file_name = NULL, format = 'plain'
          WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    // Reactions on a message nobody can read any more are noise; the foreign key
    // does not fire, because the row is still there.
    query('DELETE FROM message_reactions WHERE message_id = ?', [$id]);

    // The bytes go too. Only for a stored upload — a GIF, sticker or meme holds
    // a KLIPY address, and there is nothing of ours to unlink. basename() so a
    // crafted column value can never reach outside uploads/media.
    if (in_array((string)$msg['type'], ['image', 'file', 'audio'], true)) {
        $stored = basename((string)($msg['file_path'] ?? ''));
        $path   = UPLOAD_DIR . 'media/' . $stored;
        if ($stored !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    return ['ok' => true, 'msg' => 'Message deleted.'];
}

// ─── Schema feature detection ─────────────────────────────────────────────────
/**
 * Has this database been migrated far enough to have `messages.$column`?
 *
 * Asked for the same reason as groups_have_avatar(): setup/install.php adds
 * `reply_to` and `deleted_at`, and until somebody runs it, naming a missing
 * column in a SELECT would fail every message poll — emptying whole
 * conversations rather than merely leaving replies unquoted. One metadata read
 * per request buys a graceful degradation instead.
 */
function messages_have(string $column): bool {
    static $known = [];
    if (isset($known[$column])) return $known[$column];

    // The value is interpolated (MySQL takes no placeholder in SHOW … LIKE with
    // native prepares), so it is checked rather than trusted. Every caller
    // passes a literal, but the guard costs nothing.
    if (!preg_match('/^[a-z_]+$/', $column)) return $known[$column] = false;

    return $known[$column] = (bool)fetch_one("SHOW COLUMNS FROM `messages` LIKE '$column'");
}

/** `m.deleted_at`, or a NULL stand-in on an installation that has not migrated. */
function deleted_at_column(string $prefix = 'm.'): string {
    return messages_have('deleted_at') ? $prefix . 'deleted_at' : 'NULL AS deleted_at';
}

/** `m.reply_to`, or a NULL stand-in on an installation that has not migrated. */
function reply_to_column(string $prefix = 'm.'): string {
    return messages_have('reply_to') ? $prefix . 'reply_to' : 'NULL AS reply_to';
}

/** `m.forwarded`, or a 0 stand-in on an installation that has not migrated. */
function forwarded_column(string $prefix = 'm.'): string {
    return messages_have('forwarded') ? $prefix . 'forwarded' : '0 AS forwarded';
}

// ─── Replies ──────────────────────────────────────────────────────────────────
/**
 * The quoted strip above a reply: who was answered, and a line of what they
 * said.
 *
 * Built from the columns get_messages.php pulls through its LEFT JOIN onto the
 * parent message, so quoting costs no extra query per bubble. Returns null when
 * the row is not a reply, or when the message it answers has since been deleted
 * outright by an admin — a quote of nothing is worse than no quote.
 */
function reply_payload(array $m, int $uid): ?array {
    $id = (int)($m['reply_id'] ?? 0);
    if ($id <= 0) return null;

    $parent = [
        'message'    => $m['reply_message']    ?? '',
        'format'     => $m['reply_format']     ?? 'plain',
        'type'       => $m['reply_type']       ?? 'text',
        'file_name'  => $m['reply_file_name']  ?? null,
        'deleted_at' => $m['reply_deleted_at'] ?? null,
    ];

    return [
        'id'      => $id,
        'is_mine' => (int)($m['reply_sender_id'] ?? 0) === $uid,
        'who'     => (int)($m['reply_sender_id'] ?? 0) === $uid
                        ? 'You'
                        : (string)($m['reply_sender_name'] ?? 'Someone'),
        'preview' => message_preview($parent, 120),
        'type'    => (string)$parent['type'],
    ];
}

/**
 * May $uid reply to message $id, and is it in the conversation they are
 * writing into?
 *
 * Both halves matter. The first stops somebody quoting a message they were
 * never shown; the second stops a reply carrying a quote from a *different*
 * conversation into this one, which would leak a line of it to everybody here.
 *
 * @return bool True when the id may be stored as this message's parent.
 */
function reply_target_is_valid(int $id, int $uid, ?int $receiver_id, ?int $group_id): bool {
    if ($id <= 0) return false;

    $parent = fetch_one(
        'SELECT sender_id, receiver_id, group_id FROM messages WHERE id = ?',
        [$id]
    );
    if (!$parent || !can_see_message($parent, $uid)) return false;

    if ($group_id) {
        return (int)($parent['group_id'] ?? 0) === $group_id;
    }

    // A direct message: the parent must be between the same two people, in
    // either direction, and must not belong to a group at all.
    if (!empty($parent['group_id'])) return false;

    $pair = [(int)$parent['sender_id'], (int)$parent['receiver_id']];
    sort($pair);
    $mine = [$uid, (int)$receiver_id];
    sort($mine);

    return $pair === $mine;
}

function get_unread_count(int $from_id, int $to_id): int {
    $row = fetch_one(
        'SELECT COUNT(*) AS cnt FROM messages WHERE sender_id = ? AND receiver_id = ? AND is_read = 0',
        [$from_id, $to_id]
    );
    return (int)($row['cnt'] ?? 0);
}

/** Everything waiting for this user: direct messages plus every group. */
function get_total_unread(int $user_id): int {
    $direct = fetch_one(
        'SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0',
        [$user_id]
    );

    $group = fetch_one(
        'SELECT COUNT(m.id) AS cnt
           FROM group_members gm
           JOIN messages m ON m.group_id = gm.group_id
                          AND m.id > gm.last_read_id
                          AND m.sender_id <> gm.user_id
          WHERE gm.user_id = ?',
        [$user_id]
    );

    return (int)($direct['cnt'] ?? 0) + (int)($group['cnt'] ?? 0);
}

function mark_messages_read(int $from_id, int $to_id): int {
    return query(
        'UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0',
        [$from_id, $to_id]
    )->rowCount();
}

// ─── Reactions ────────────────────────────────────────────────────────────────
/**
 * Reactions on a conversation, keyed by message id and grouped per emoji:
 *
 *   [ 42 => [ ['emoji' => '👍', 'count' => 2, 'mine' => true, 'who' => 'Ravi, Ana'] ] ]
 *
 * $from_id bounds the work to the window the client actually has on screen.
 */
function get_reactions_map(int $uid, int $peer, int $from_id): array {
    $rows = fetch_all(
        'SELECT r.message_id, r.emoji, r.user_id, u.full_name
           FROM message_reactions r
           JOIN messages m ON m.id = r.message_id
           JOIN users    u ON u.id = r.user_id
          WHERE m.id >= ?
            AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
          ORDER BY r.id ASC',
        [$from_id, $uid, $peer, $peer, $uid]
    );

    return reactions_rows_to_map($rows, $uid);
}

/**
 * Group raw reaction rows into the per-message shape above.
 *
 * Shared by the direct and group variants, which differ only in the WHERE
 * clause that decides which messages the caller is allowed to count.
 */
function reactions_rows_to_map(array $rows, int $uid): array {
    $map = [];
    foreach ($rows as $r) {
        $mid   = (int)$r['message_id'];
        $emoji = (string)$r['emoji'];

        if (!isset($map[$mid][$emoji])) {
            $map[$mid][$emoji] = ['emoji' => $emoji, 'count' => 0, 'mine' => false, 'who' => []];
        }
        $map[$mid][$emoji]['count']++;
        $map[$mid][$emoji]['who'][] = (int)$r['user_id'] === $uid ? 'You' : (string)$r['full_name'];
        if ((int)$r['user_id'] === $uid) {
            $map[$mid][$emoji]['mine'] = true;
        }
    }

    // JSON needs lists, not emoji-keyed objects, so the client can just map().
    $out = [];
    foreach ($map as $mid => $groups) {
        $out[(string)$mid] = array_map(static function (array $g): array {
            $g['who'] = implode(', ', $g['who']);
            return $g;
        }, array_values($groups));
    }
    return $out;
}

/** Reactions on one message, in the same shape as get_reactions_map()'s values. */
function get_message_reactions(int $message_id, int $uid): array {
    $rows = fetch_all(
        'SELECT r.emoji, r.user_id, u.full_name
           FROM message_reactions r
           JOIN users u ON u.id = r.user_id
          WHERE r.message_id = ?
          ORDER BY r.id ASC',
        [$message_id]
    );

    $groups = [];
    foreach ($rows as $r) {
        $emoji = (string)$r['emoji'];
        if (!isset($groups[$emoji])) {
            $groups[$emoji] = ['emoji' => $emoji, 'count' => 0, 'mine' => false, 'who' => []];
        }
        $groups[$emoji]['count']++;
        $groups[$emoji]['who'][] = (int)$r['user_id'] === $uid ? 'You' : (string)$r['full_name'];
        if ((int)$r['user_id'] === $uid) {
            $groups[$emoji]['mine'] = true;
        }
    }

    return array_map(static function (array $g): array {
        $g['who'] = implode(', ', $g['who']);
        return $g;
    }, array_values($groups));
}

/** Highest id of my messages to $peer that the peer has already read. */
function last_read_message_id(int $me, int $peer): int {
    $row = fetch_one(
        'SELECT MAX(id) AS mid FROM messages WHERE sender_id = ? AND receiver_id = ? AND is_read = 1',
        [$me, $peer]
    );
    return (int)($row['mid'] ?? 0);
}

// ─── File / upload helpers ────────────────────────────────────────────────────
function upload_error_message(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is larger than the server upload limit.',
        UPLOAD_ERR_PARTIAL                        => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE                        => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR                     => 'Server is missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE                     => 'Server could not write the file to disk.',
        UPLOAD_ERR_EXTENSION                      => 'A PHP extension blocked the upload.',
        default                                   => 'Upload failed (error ' . $code . ').',
    };
}

/** The extension of an uploaded file, lower-cased and without the dot. */
function upload_ext(array $file): string {
    return strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
}

/**
 * The checks every upload shares: the transfer finished, the bytes really came
 * through PHP's upload handler (and are not an arbitrary path a caller made up),
 * and the file is neither empty nor over $maxSize.
 */
function validate_upload_basics(array $file, int $maxSize): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => upload_error_message((int)($file['error'] ?? UPLOAD_ERR_NO_FILE))];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'msg' => 'Invalid upload.'];
    }
    if ((int)$file['size'] <= 0) {
        return ['ok' => false, 'msg' => 'The file is empty.'];
    }
    if ((int)$file['size'] > $maxSize) {
        return ['ok' => false, 'msg' => 'File too large (max ' . round($maxSize / 1024 / 1024) . ' MB).'];
    }

    return ['ok' => true];
}

function validate_image(array $file): array {
    $basics = validate_upload_basics($file, MAX_FILE_SIZE);
    if (!$basics['ok']) return $basics;

    $ext = upload_ext($file);
    if (!in_array($ext, ALLOWED_IMAGES, true)) {
        return ['ok' => false, 'msg' => 'Pictures must be a JPG, PNG, GIF or WebP.'];
    }

    // Trust the file's contents, not its name.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed_mimes = [
        'image/jpeg' => ['jpg','jpeg'],
        'image/png'  => ['png'],
        'image/gif'  => ['gif'],
        'image/webp' => ['webp'],
    ];
    if (!isset($allowed_mimes[$mime])) {
        return ['ok' => false, 'msg' => 'That file is named like a picture but does not contain one.'];
    }
    // Normalise the extension to match the real type (a .png that is really a
    // JPEG gets stored as .jpg so browsers and downloads behave).
    $ext = in_array($ext, $allowed_mimes[$mime], true) ? $ext : $allowed_mimes[$mime][0];

    return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'kind' => 'image'];
}

/**
 * Everything that is not a picture: a document, an archive, a source file, a
 * database dump, a font — whatever somebody needs to hand a colleague.
 *
 * The extension is kept exactly as sent and the sniffed type is *not* required
 * to agree with it. That is a deliberate reversal of the old document rule, and
 * it is safe because of where the bytes go: a non-image is stored as type
 * 'file', which ajax/file.php only ever returns with `Content-Disposition:
 * attachment`, `nosniff` and a `default-src 'none'; sandbox` policy. It is
 * saved to disk, never rendered, so what it claims to be cannot matter.
 *
 * What is still refused is the small set of extensions in BLOCKED_EXTS — the
 * ones a web server might execute in place, and the ones an operating system
 * runs on a double-click.
 */
function validate_any_file(array $file): array {
    $basics = validate_upload_basics($file, MAX_DOC_SIZE);
    if (!$basics['ok']) return $basics;

    $ext = upload_ext($file);
    if ($ext !== '' && in_array($ext, BLOCKED_EXTS, true)) {
        return ['ok' => false, 'msg' => 'A .' . $ext . ' file cannot be sent for security reasons.'];
    }

    // A name with no extension at all would be stored as a bare random hex
    // string, which nothing on the way back out could label; `.bin` keeps the
    // stored name well-formed without claiming anything about the contents.
    if ($ext === '') $ext = 'bin';

    // Read for the record only — the extension decides how the file is served.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']);

    return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'kind' => 'file'];
}

/**
 * A recorded voice note.
 *
 * Never reached from the file picker — the composer offers no audio extensions
 * — so in practice this validates what MediaRecorder just produced. It is still
 * a full check: the upload is a POST like any other and could be hand-made.
 */
function validate_audio(array $file): array {
    $basics = validate_upload_basics($file, MAX_AUDIO_SIZE);
    if (!$basics['ok']) return $basics;

    $ext      = upload_ext($file);
    $accepted = ALLOWED_AUDIO[$ext] ?? null;
    if ($accepted === null) {
        return ['ok' => false, 'msg' => 'That is not a supported audio format.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']);
    if (!in_array($mime, $accepted, true)) {
        return ['ok' => false, 'msg' => 'That recording could not be read.'];
    }

    return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'kind' => 'audio'];
}

/** What the composer refuses, for the one message that has to say so. */
function blocked_attachments_hint(): string {
    return 'Programs and scripts (.' . implode(', .', array_slice(BLOCKED_EXTS, 0, 6)) . ' and similar)';
}

/**
 * Route an attachment to the right validator by extension.
 *
 * Two paths only: the handful of raster formats that are drawn into a bubble,
 * and everything else, which becomes a named download.
 */
function validate_attachment(array $file): array {
    return in_array(upload_ext($file), ALLOWED_IMAGES, true)
        ? validate_image($file)
        : validate_any_file($file);
}

/**
 * Returns ['ok'=>true,'file'=>name,'kind'=>'image'|'file'|'audio'] or
 * ['ok'=>false,'msg'=>reason].
 *
 * @param string $accept 'image' (avatars and group pictures), 'audio' (voice
 *                       notes) or 'any' (whatever the composer may attach).
 */
function save_upload(array $file, string $subdir = 'media', string $accept = 'image'): array {
    $result = match ($accept) {
        'any'   => validate_attachment($file),
        'audio' => validate_audio($file),
        default => validate_image($file),
    };
    if (!$result['ok']) return $result;

    $dir = UPLOAD_DIR . trim($subdir, '/') . '/';
    if (!ensure_dir($dir) || !is_writable($dir)) {
        error_log('[UnivoChat] Upload directory not writable: ' . $dir);
        return ['ok' => false, 'msg' => 'Server cannot store uploads right now.'];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $result['ext'];
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        error_log('[UnivoChat] move_uploaded_file failed for ' . $dir . $filename);
        return ['ok' => false, 'msg' => 'Could not save the uploaded file.'];
    }

    return ['ok' => true, 'file' => $filename, 'kind' => $result['kind'] ?? 'image'];
}

// ─── Shared files ─────────────────────────────────────────────────────────────
/**
 * Which drawer of the shared-files panel an attachment belongs in: always one
 * of 'images', 'documents' or 'archives'.
 *
 * The stored type settles images; everything else is filed by extension, taken
 * from the name the sender used and falling back to the name on disk for the
 * older rows that recorded no file_name.
 */
function attachment_category(string $type, ?string $fileName, ?string $storedPath = null): string {
    if ($type === 'image') return 'images';

    $ext = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
    if ($ext === '' && $storedPath) {
        $ext = strtolower(pathinfo((string)$storedPath, PATHINFO_EXTENSION));
    }

    return in_array($ext, ARCHIVE_EXTS, true) ? 'archives' : 'documents';
}

/**
 * Every attachment in one conversation, newest first.
 *
 * Exactly one of $peer / $group_id is meaningful; the caller has already proved
 * the requester may read the conversation, which is what makes it safe to hand
 * back a whole conversation's files in one answer.
 *
 * GIFs, stickers and memes are left out: they are addresses on KLIPY's CDN
 * rather than files anybody shared, and a "Files" drawer that offers to
 * re-download somebody else's animation is not what the panel is for.
 */
function conversation_attachments(int $uid, ?int $peer, ?int $group_id, int $limit = MEDIA_PANEL_LIMIT): array {
    $scope = $group_id
        ? 'm.group_id = ?'
        : '((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))';
    $args  = $group_id ? [$group_id] : [$uid, $peer, $peer, $uid];

    return fetch_all(
        "SELECT m.id, m.sender_id, m.type, m.file_path, m.file_name, m.created_at,
                u.full_name AS sender_name
           FROM messages m
           JOIN users u ON u.id = m.sender_id
          WHERE $scope
            AND m.type IN ('image', 'file')
            AND m.file_path IS NOT NULL AND m.file_path <> ''
          ORDER BY m.id DESC
          LIMIT " . max(1, $limit),
        $args
    );
}

// ─── Shared links ─────────────────────────────────────────────────────────────
/**
 * The same shape linkify() in app.js turns into an anchor: an http(s) address,
 * or a bare www. one. Kept in step with it so the Links drawer lists exactly
 * what the conversation shows as clickable.
 */
const LINK_PATTERN = '~(?:https?://|www\.)[^\s<>"\']+~iu';

/**
 * Every web address in one message body, in the order they appear, each once.
 *
 * A link the sender typed at the end of a sentence carries the full stop with
 * it as far as the regex is concerned; trailing punctuation is trimmed, and a
 * closing bracket only survives when the address opened one itself (the
 * Wikipedia case). Only http(s) is returned — a mailto: is not a link anybody
 * means to revisit from a drawer.
 *
 * @return string[] Absolute URLs, ready to use as an href.
 */
function message_links(?string $message, string $format = 'plain'): array {
    $body = (string)$message;
    if ($body === '' || !preg_match('~https?://|www\.~i', $body)) return [];

    $found = [];
    if ($format === 'html') {
        // Anchors first: a pasted link's visible text is often not its address.
        if (preg_match_all('~<a\b[^>]*\bhref="([^"]+)"~i', $body, $m)) {
            foreach ($m[1] as $href) {
                $found[] = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $body = html_to_text($body);
    }

    if (preg_match_all(LINK_PATTERN, $body, $m)) {
        foreach ($m[0] as $raw) {
            $url = rtrim($raw, '.,;:!?*');
            while (str_ends_with($url, ')') && substr_count($url, '(') < substr_count($url, ')')) {
                $url = rtrim(substr($url, 0, -1), '.,;:!?*');
            }
            $found[] = $url;
        }
    }

    $out = [];
    foreach ($found as $url) {
        if (stripos($url, 'www.') === 0) $url = 'https://' . $url;
        if (!preg_match('~^https?://[^\s/?#]+~i', $url)) continue;
        $out[$url] = true;
    }

    return array_keys($out);
}

/**
 * Every message in one conversation that carries at least one link, newest
 * first. Captions count — a photo sent with "details at https://…" shared a
 * link as surely as a text message did.
 *
 * The LIKE is only a coarse filter that keeps the scan off rows that cannot
 * match; message_links() decides what actually is one.
 */
function conversation_links(int $uid, ?int $peer, ?int $group_id, int $limit = MEDIA_PANEL_LIMIT): array {
    $scope = $group_id
        ? 'm.group_id = ?'
        : '((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))';
    $args  = $group_id ? [$group_id] : [$uid, $peer, $peer, $uid];
    $alive = messages_have('deleted_at') ? 'AND m.deleted_at IS NULL' : '';

    return fetch_all(
        "SELECT m.id, m.sender_id, m.message, m.format, m.type, m.file_name, m.created_at,
                u.full_name AS sender_name
           FROM messages m
           JOIN users u ON u.id = m.sender_id
          WHERE $scope $alive
            AND m.message IS NOT NULL
            AND (m.message LIKE '%http%' OR m.message LIKE '%www.%')
          ORDER BY m.id DESC
          LIMIT " . max(1, $limit),
        $args
    );
}

// ─── Long text as a Markdown attachment ───────────────────────────────────────
/**
 * Is this body too long to travel as a text message?
 *
 * Measured exactly the way message_length_error() measures it — the visible
 * characters, not the markup — so "4,000 characters" means the same thing on
 * both sides of the rule.
 */
function message_is_long(string $message, string $format): bool {
    return mb_strlen($format === 'html' ? html_to_text($message) : $message) > MAX_MESSAGE_LEN;
}

/**
 * Write bytes the server produced itself into an upload directory.
 *
 * The generated twin of save_upload(): same random naming, same directory, so
 * ajax/file.php serves the result exactly like a file somebody attached.
 *
 * @return array{ok: bool, file?: string, msg?: string}
 */
function save_generated_file(string $bytes, string $ext, string $subdir = 'media'): array {
    $dir = UPLOAD_DIR . trim($subdir, '/') . '/';
    if (!ensure_dir($dir) || !is_writable($dir)) {
        error_log('[UnivoChat] Upload directory not writable: ' . $dir);
        return ['ok' => false, 'msg' => 'Server cannot store files right now.'];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    $written  = @file_put_contents($dir . $filename, $bytes, LOCK_EX);
    if ($written !== strlen($bytes)) {
        @unlink($dir . $filename);
        error_log('[UnivoChat] Could not write generated file ' . $dir . $filename);
        return ['ok' => false, 'msg' => 'Could not save the file.'];
    }

    return ['ok' => true, 'file' => $filename];
}

/**
 * A second, independent copy of a stored upload, under a new random name.
 *
 * Forwarding copies rather than shares: message_delete() unlinks a message's
 * bytes, and a forward must not vanish from another conversation because the
 * original sender took theirs back.
 *
 * @return string|null The new stored name, or null when the copy failed.
 */
function copy_stored_upload(string $stored, string $subdir = 'media'): ?string {
    $stored = basename($stored);
    $dir    = UPLOAD_DIR . trim($subdir, '/') . '/';
    if ($stored === '' || !is_file($dir . $stored)) return null;

    $ext  = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
    $copy = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');

    return @copy($dir . $stored, $dir . $copy) ? $copy : null;
}

/** Remove a stored upload by name — the undo for a copy that was never used. */
function discard_stored_upload(?string $stored, string $subdir = 'media'): void {
    $stored = basename((string)$stored);
    $path   = UPLOAD_DIR . trim($subdir, '/') . '/' . $stored;
    if ($stored !== '' && is_file($path)) @unlink($path);
}

/**
 * A name for the .md file a long message becomes: its first meaningful line,
 * cut to a handful of words — "Q3-launch-plan-and-owners.md" — so the file is
 * recognisable in a Downloads folder. A body with no words in its first lines
 * at all falls back to a timestamp.
 */
function long_text_filename(string $text): string {
    $slug = '';
    foreach (array_slice(preg_split('/\R/u', $text) ?: [], 0, 20) as $line) {
        // Markdown furniture at the start of a line is not part of the title.
        $line = preg_replace('/^\s*(?:[#>*+\-=~`|_]+|\d{1,3}[.)])\s*/u', '', $line) ?? $line;
        $line = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $line) ?? '');
        if ($line === '') continue;

        $words = array_slice(preg_split('/\s+/u', $line) ?: [], 0, 8);
        $slug  = implode('-', $words);
        if (mb_strlen($slug) > 60) {
            $slug = rtrim(mb_substr($slug, 0, 60), '-');
        }
        break;
    }

    return ($slug !== '' ? $slug : 'Message-' . date('Y-m-d-His')) . '.md';
}

/**
 * Store a long message as a Markdown file.
 *
 * Plain text goes in exactly as it was typed — every line break and every
 * character — because a typed body is already the most faithful Markdown of
 * itself. A rich (pasted) body is converted, so its headings, lists, tables and
 * emphasis survive as Markdown rather than as a pile of tags.
 *
 * @return array{ok: bool, file?: string, name?: string, msg?: string}
 */
function long_text_attachment(string $message, string $format): array {
    $markdown = $format === 'html' ? html_to_markdown($message) : $message;
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    if (!str_ends_with($markdown, "\n")) $markdown .= "\n";

    if (strlen($markdown) > MAX_TEXT_FILE_BYTES) {
        return [
            'ok'  => false,
            'msg' => 'That message is too long to send, even as a file (limit: '
                   . format_bytes(MAX_TEXT_FILE_BYTES) . ').',
        ];
    }

    $saved = save_generated_file($markdown, 'md');
    if (!$saved['ok']) return $saved;

    return [
        'ok'   => true,
        'file' => $saved['file'],
        'name' => mb_substr(long_text_filename($markdown), 0, 255),
    ];
}

/**
 * Convert a sanitised rich message into Markdown.
 *
 * Only ever handed the output of sanitize_message_html(), so the tag set is the
 * small fixed one in RICH_ALLOWED_TAGS and every tag has a mapping below.
 */
function html_to_markdown(string $html): string {
    $doc  = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $loaded = $doc->loadHTML(
        '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
        . '<div id="uni-md-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $loaded ? (new DOMXPath($doc))->query('//div[@id="uni-md-root"]')->item(0) : null;
    if (!$root) return html_to_text($html);

    $md = md_children($root);

    // Blocks each pad themselves with blank lines; collapse the runs that
    // leaves behind, and the spaces stranded at the end of lines.
    $md = preg_replace('/[ \t]+\n/u', "\n", $md) ?? $md;
    $md = preg_replace('/\n{3,}/u', "\n\n", $md) ?? $md;

    return trim($md) . "\n";
}

/** Markdown for every child of a node, concatenated. */
function md_children(DOMNode $node, bool $inCell = false): string {
    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= md_node($child, $inCell);
    }
    return $out;
}

/** Characters that would otherwise turn literal text into formatting. */
function md_escape(string $text, bool $inCell = false): string {
    $text = preg_replace('/([\\\\`*_])/u', '\\\\$1', $text) ?? $text;
    return $inCell ? str_replace('|', '\\|', $text) : $text;
}

/** Wrap inline content in a marker, keeping the marker hard against the words. */
function md_wrap(string $inner, string $mark): string {
    $trimmed = trim($inner);
    if ($trimmed === '') return $inner;

    $lead  = preg_match('/^\s/u', $inner) ? ' ' : '';
    $trail = preg_match('/\s$/u', $inner) ? ' ' : '';
    return $lead . $mark . $trimmed . $mark . $trail;
}

function md_node(DOMNode $node, bool $inCell = false): string {
    if ($node instanceof DOMText) {
        $text = preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $node->nodeValue ?? '')) ?? '';
        return md_escape($text, $inCell);
    }
    if (!$node instanceof DOMElement) return '';

    $tag = strtolower($node->nodeName);

    switch ($tag) {
        case 'br':
            return $inCell ? ' ' : "\n";
        case 'hr':
            return "\n\n---\n\n";
        case 'p':
            return "\n\n" . trim(md_children($node, $inCell)) . "\n\n";
        case 'div':
            // The composer writes one <div> per typed line, so a div is a line,
            // not a paragraph: it only needs a break of its own in front when
            // it follows inline text, never when it follows another block.
            $prev   = $node->previousSibling;
            $inline = $prev instanceof DOMText
                ? trim((string)$prev->nodeValue) !== ''
                : $prev instanceof DOMElement && !in_array(strtolower($prev->nodeName), [
                    'div', 'p', 'br', 'hr', 'ul', 'ol', 'table', 'pre', 'blockquote',
                    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                ], true);
            return ($inline ? "\n" : '') . trim(md_children($node, $inCell)) . "\n";
        case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
            $text = trim(preg_replace('/\s+/u', ' ', md_children($node, true)) ?? '');
            return $text === '' ? '' : "\n\n" . str_repeat('#', (int)$tag[1]) . ' ' . $text . "\n\n";
        case 'b': case 'strong':
            return md_wrap(md_children($node, $inCell), '**');
        case 'i': case 'em':
            return md_wrap(md_children($node, $inCell), '*');
        case 's': case 'strike':
            return md_wrap(md_children($node, $inCell), '~~');
        case 'code':
            $code = (string)$node->textContent;
            if ($code === '') return '';
            // A backtick inside the code needs a longer fence around it.
            $fence = str_contains($code, '`') ? '``' : '`';
            return $fence . ($fence === '``' ? ' ' . $code . ' ' : $code) . $fence;
        case 'pre':
            $code  = rtrim((string)$node->textContent, "\n");
            $fence = str_contains($code, '```') ? '~~~' : '```';
            return "\n\n" . $fence . "\n" . $code . "\n" . $fence . "\n\n";
        case 'blockquote':
            $inner = trim(md_children($node));
            $lines = array_map(
                static fn(string $l): string => $l === '' ? '>' : '> ' . $l,
                explode("\n", $inner)
            );
            return "\n\n" . implode("\n", $lines) . "\n\n";
        case 'ul':
        case 'ol':
            return "\n\n" . md_list($node, $tag === 'ol') . "\n\n";
        case 'a':
            $text = trim(md_children($node, $inCell));
            $href = trim($node->getAttribute('href'));
            if ($href === '' || !preg_match('#^(https?://|mailto:)#i', $href)) return $text;
            if ($text === '' || $text === md_escape($href, $inCell)) return '<' . $href . '>';
            return '[' . $text . '](' . str_replace([' ', ')'], ['%20', '%29'], $href) . ')';
        case 'table':
            return "\n\n" . md_table($node) . "\n\n";
        default:
            // u, span, caption outside a table walk and anything unwrapped.
            return md_children($node, $inCell);
    }
}

/** A list, with any list nested in an item indented under it. */
function md_list(DOMElement $list, bool $ordered): string {
    $lines = [];
    $n     = 1;

    foreach ($list->childNodes as $item) {
        if (!$item instanceof DOMElement) continue;
        $tag = strtolower($item->nodeName);

        // A list placed straight inside a list, without an <li> around it.
        if ($tag === 'ul' || $tag === 'ol') {
            foreach (explode("\n", md_list($item, $tag === 'ol')) as $l) {
                $lines[] = '   ' . $l;
            }
            continue;
        }
        if ($tag !== 'li') continue;

        $marker = $ordered ? $n++ . '. ' : '- ';
        $body   = trim(preg_replace('/\n{3,}/u', "\n\n", md_children($item)) ?? '');
        // A list nested in the item sits directly under its text; a blank line
        // there would turn the whole list "loose" in most renderers.
        $body   = preg_replace('/\n{2,}(?=(?:[-*+]|\d+\.) )/u', "\n", $body) ?? $body;
        $parts  = explode("\n", $body);
        $pad    = str_repeat(' ', strlen($marker));

        $lines[] = $marker . array_shift($parts);
        foreach ($parts as $part) {
            $lines[] = $part === '' ? '' : $pad . $part;
        }
    }

    return implode("\n", $lines);
}

/** A GFM pipe table. The first row is the header, whether or not it was <th>. */
function md_table(DOMElement $table): string {
    $rows    = [];
    $caption = '';

    foreach ((new DOMXPath($table->ownerDocument))->query('.//tr|.//caption', $table) as $row) {
        if (strtolower($row->nodeName) === 'caption') {
            $caption = trim(md_children($row, true));
            continue;
        }

        $cells = [];
        foreach ($row->childNodes as $cell) {
            if (!$cell instanceof DOMElement) continue;
            $tag = strtolower($cell->nodeName);
            if ($tag !== 'td' && $tag !== 'th') continue;

            $text = trim(preg_replace('/\s+/u', ' ', md_children($cell, true)) ?? '');
            $cells[] = $text;
            // A spanned cell still takes up its columns.
            for ($i = 1, $span = (int)$cell->getAttribute('colspan'); $i < $span; $i++) {
                $cells[] = '';
            }
        }
        if ($cells) $rows[] = $cells;
    }

    if (!$rows) return $caption;

    $width = max(array_map('count', $rows));
    $line  = static fn(array $cells): string =>
        '| ' . implode(' | ', array_pad($cells, $width, '')) . ' |';

    $out   = [$line($rows[0]), '|' . str_repeat(' --- |', $width)];
    foreach (array_slice($rows, 1) as $row) {
        $out[] = $line($row);
    }

    return ($caption !== '' ? '**' . $caption . "**\n\n" : '') . implode("\n", $out);
}

// ─── Forwarding ───────────────────────────────────────────────────────────────
/**
 * Parse the destinations of one Forward: "u12,g3" → [['user', 12], ['group', 3]].
 *
 * @return array<int, array{0: string, 1: int}> Unique, in the order given.
 */
function forward_targets(string $raw): array {
    $out = [];
    foreach (explode(',', $raw) as $token) {
        if (!preg_match('/^\s*([ug])(\d{1,10})\s*$/', $token, $m)) continue;
        $kind = $m[1] === 'g' ? 'group' : 'user';
        $id   = (int)$m[2];
        if ($id > 0) $out[$kind . ':' . $id] = [$kind, $id];
    }
    return array_values($out);
}

/**
 * Forward one message into one conversation, at most once per key.
 *
 * The idempotency row and the message are written in one transaction, so either
 * both exist or neither does. A retry with the same key — after a timeout, a
 * dropped connection, a double tap — finds the row and gets the message that
 * was already written back instead of a second copy.
 *
 * @param array  $src  The source row (id, message, format, type, file_path, file_name).
 * @return array{ok: bool, id?: int, duplicate?: bool, msg?: string}
 */
function forward_message_to(array $src, int $uid, string $kind, int $target, string $key): array {
    $existing = fetch_one(
        'SELECT message_id FROM message_forwards
          WHERE user_id = ? AND fwd_key = ? AND target_kind = ? AND target_id = ?',
        [$uid, $key, $kind, $target]
    );
    if ($existing) {
        return ['ok' => true, 'id' => (int)$existing['message_id'], 'duplicate' => true];
    }

    // A stored upload gets bytes of its own; a KLIPY address is just copied.
    $type = (string)$src['type'];
    $path = $src['file_path'] !== null ? (string)$src['file_path'] : null;
    $copy = null;
    if (in_array($type, ['image', 'file', 'audio'], true)) {
        $copy = copy_stored_upload((string)$path);
        if ($copy === null) {
            return ['ok' => false, 'msg' => 'The attachment is no longer available.'];
        }
        $path = $copy;
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        query(
            'INSERT INTO messages
                    (sender_id, receiver_id, group_id, message, format, type, file_path, file_name, forwarded)
             VALUES (?,?,?,?,?,?,?,?,1)',
            [
                $uid,
                $kind === 'user'  ? $target : null,
                $kind === 'group' ? $target : null,
                $src['message'],
                $src['format'] ?: 'plain',
                $type,
                $path,
                $src['file_name'],
            ]
        );
        $id = (int)$pdo->lastInsertId();

        query(
            'INSERT INTO message_forwards (user_id, fwd_key, target_kind, target_id, source_id, message_id)
             VALUES (?,?,?,?,?,?)',
            [$uid, $key, $kind, $target, (int)$src['id'], $id]
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        discard_stored_upload($copy);

        // Two copies of the same retry raced each other, and the other one won:
        // its message is the answer to this one too.
        $won = fetch_one(
            'SELECT message_id FROM message_forwards
              WHERE user_id = ? AND fwd_key = ? AND target_kind = ? AND target_id = ?',
            [$uid, $key, $kind, $target]
        );
        if ($won) {
            return ['ok' => true, 'id' => (int)$won['message_id'], 'duplicate' => true];
        }

        error_log('[UnivoChat] forward failed: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Could not forward the message.'];
    }

    return ['ok' => true, 'id' => $id, 'duplicate' => false];
}

// ─── Attachment addressing ────────────────────────────────────────────────────
/**
 * The secret behind attachment tokens.
 *
 * Lives in config/.appkey, a directory Apache is told to refuse outright, and is
 * created on first use. Losing or rotating it costs nothing: every attachment
 * URL is minted from the database on each poll, so no token is expected to
 * outlive the page it was written into. A read-only config/ is survivable too —
 * the derivation at the bottom is stable for a given installation, which is
 * enough, because the token is an address and ajax/file.php is the lock.
 */
function app_key(): string {
    static $key = null;
    if ($key !== null) return $key;

    if (defined('APP_KEY') && APP_KEY !== '') {
        return $key = hash('sha256', (string)APP_KEY, true);
    }

    $file = APP_ROOT . '/config/.appkey';
    $hex  = is_file($file) ? trim((string)@file_get_contents($file)) : '';

    if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
        $hex = bin2hex(random_bytes(32));
        @file_put_contents($file, $hex, LOCK_EX);
        @chmod($file, 0600);

        if (trim((string)@file_get_contents($file)) !== $hex) {
            error_log('[UnivoChat] Could not store ' . $file . ' – falling back to a derived key.');
            $hex = hash('sha256', 'univochat|' . APP_ROOT . '|' . DB_NAME . '|' . DB_PASS);
        }
    }

    return $key = (string)hex2bin($hex);
}

/**
 * Opaque handle for one attachment row: AES-256-GCM over the message id.
 *
 * The stored file name never reaches the page, so uploads/media cannot be
 * walked by guessing names, and an edited token fails its authentication tag
 * instead of quietly addressing somebody else's file. It is only an address:
 * ajax/file.php still checks that whoever presents it is one of the two people
 * the message is between.
 *
 * The IV is derived from the id rather than drawn at random, which makes the
 * token — and therefore the URL — the same on every poll. That is what lets the
 * browser cache an image instead of re-downloading it each time a conversation
 * is opened. Deterministic encryption leaks only that two identical plaintexts
 * are identical, and message ids are unique, so there is nothing to leak.
 */
function attachment_token(int $messageId): string {
    if ($messageId <= 0) return '';

    $key = app_key();
    $iv  = substr(hash_hmac('sha256', 'attachment-iv|' . $messageId, $key, true), 0, 12);
    $tag = '';

    $cipher = openssl_encrypt((string)$messageId, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        error_log('[UnivoChat] attachment_token: openssl_encrypt failed');
        return '';
    }

    // base64url, unpadded: it travels in a query string.
    return rtrim(strtr(base64_encode($iv . $tag . $cipher), '+/', '-_'), '=');
}

/** The message id inside a token, or 0 if it is missing, edited or forged. */
function attachment_id_from_token(string $token): int {
    if ($token === '' || strlen($token) > 128) return 0;

    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($raw === false || strlen($raw) < 29) return 0;      // 12 IV + 16 tag + 1+

    $plain = openssl_decrypt(
        substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, 12, 16)
    );

    return $plain !== false && ctype_digit($plain) ? (int)$plain : 0;
}

/** Where the client asks for an attachment. Never the path on disk. */
function attachment_url(int $messageId): string {
    $token = attachment_token($messageId);
    return $token === '' ? '' : base_url('ajax/file.php?f=' . $token);
}

/**
 * Content-Type for a stored attachment, decided by what kind of message it is.
 *
 * The message type leads, not the extension, because the three kinds leave the
 * server under different rules:
 *
 *   • 'image' — one of four raster formats, already reconciled against the
 *     file's real contents by validate_image(), and shown in place;
 *   • 'audio' — a recording, announced as audio/* whatever container
 *     MediaRecorder chose, so <audio> plays it instead of offering a download;
 *   • 'file'  — anything at all. Only the types in DOWNLOAD_MIMES are named;
 *     everything else is `application/octet-stream`. A .html, .svg or .xml
 *     therefore never comes back under a type a browser would try to render,
 *     which — together with the `attachment` disposition ajax/file.php sets —
 *     is what makes "any file type" safe to accept.
 */
function attachment_mime(string $ext, string $type = 'file'): string {
    $ext = strtolower($ext);

    if ($type === 'image') {
        static $images = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        ];
        return $images[$ext] ?? 'application/octet-stream';
    }

    if ($type === 'audio') {
        static $audio = [
            'webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
            'mp3'  => 'audio/mpeg', 'wav' => 'audio/wav',
        ];
        return $audio[$ext] ?? 'application/octet-stream';
    }

    return DOWNLOAD_MIMES[$ext] ?? 'application/octet-stream';
}

/**
 * The badge on an attachment card: 'PDF', 'CSS', 'ZIP'.
 *
 * Server-side twin of docLook() in app.js, used where a file has to be labelled
 * without the client — currently the shared-files panel's rows.
 */
function attachment_label(string $ext): string {
    $ext = strtolower($ext);
    if ($ext === '') return 'FILE';

    static $named = [
        'docx' => 'DOC', 'xlsx' => 'XLS', 'pptx' => 'PPT',
        'jpeg' => 'JPG', 'markdown' => 'MD',
    ];

    return $named[$ext] ?? strtoupper(mb_substr($ext, 0, 4));
}

/** Size on disk of a stored upload, or null once it has gone missing. */
function upload_size(string $stored, string $subdir = 'media'): ?int {
    $path = UPLOAD_DIR . trim($subdir, '/') . '/' . basename($stored);
    if (!is_file($path)) return null;

    $bytes = filesize($path);
    return $bytes === false ? null : (int)$bytes;
}

/** "2.4 MB" – the size label under a file attachment. */
function format_bytes(?int $bytes): string {
    if ($bytes === null || $bytes < 0) return '';
    if ($bytes < 1024)                 return $bytes . ' B';

    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;
    $unit  = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    // One decimal below 10 ("9.6 MB"), none above it ("14 MB").
    return ($value < 10 ? round($value, 1) : round($value)) . ' ' . $units[$unit];
}

// ─── Online status ────────────────────────────────────────────────────────────
/**
 * @param bool $focused The heartbeat came from a tab the user is actually
 *                      looking at. Recorded separately from `last_active`
 *                      because a background tab keeps polling, and a background
 *                      tab is exactly the case that still needs a push.
 */
function update_online_status(int $uid, bool $focused = false): void {
    query(
        $focused
            ? 'UPDATE users SET is_online = 1, last_active = NOW(), last_focus_at = NOW() WHERE id = ?'
            : 'UPDATE users SET is_online = 1, last_active = NOW() WHERE id = ?',
        [$uid]
    );
}

/** Is $uid sitting in front of an open, focused UnivoChat tab right now? */
function user_is_at_keyboard(int $uid): bool {
    $row = fetch_one(
        'SELECT 1 AS ok FROM users
          WHERE id = ? AND last_focus_at IS NOT NULL
            AND last_focus_at > DATE_SUB(NOW(), INTERVAL ? SECOND)',
        [$uid, PUSH_ACTIVE_TTL]
    );
    return (bool)$row;
}

/**
 * Flip users who stopped polling to offline. `last_active = last_active` keeps
 * the column's ON UPDATE CURRENT_TIMESTAMP clause from resetting "last seen"
 * to now, which would make every offline user look like they just left.
 */
function set_offline_stale(int $seconds = ONLINE_TTL): void {
    query(
        'UPDATE users
            SET is_online = 0, last_active = last_active
          WHERE is_online = 1 AND last_active < DATE_SUB(NOW(), INTERVAL ? SECOND)',
        [$seconds]
    );
}
