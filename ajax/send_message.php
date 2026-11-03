<?php
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/push.php';
require_once __DIR__ . '/../includes/klipy.php';
require_once __DIR__ . '/../includes/gamify.php';
require_approved();
api_require_post();

// A POST bigger than post_max_size reaches PHP with $_POST and $_FILES emptied,
// which would otherwise surface as "your security token expired" — a confusing
// way to say that a 50 MB archive did not fit through the server's front door.
if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // PHP has already written its own "POST Content-Length … exceeds the limit"
    // warning into the output buffer on any host with display_errors on. Left
    // there it precedes the JSON, the client cannot parse the body at all, and
    // the sentence below — the whole point of this guard — never reaches the
    // person whose upload was refused. Dropping the buffer first is what makes
    // the friendly message the one they actually see.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    json_response([
        'error' => 'That attachment is larger than this server accepts (limit: '
                 . ini_get('post_max_size') . ').',
    ], 413);
}

api_require_csrf();

$uid         = current_uid();
$receiver_id = api_int('receiver_id');
$group_id    = api_int('group_id');
// A pasted spreadsheet arrives as markup: keep the table, throw the rest of
// the source page away.
['message' => $message, 'format' => $format] = normalize_message_body(
    (string)($_POST['message'] ?? ''),
    api_str('format') === 'html' ? 'html' : 'plain'
);

// ── Who is this going to? ─────────────────────────────────────────────────────
// Exactly one of the two: a person, or a group the sender belongs to. Every
// INSERT below fills in whichever column matches, and leaves the other NULL.
if ($group_id > 0) {
    if (!is_group_member($group_id, $uid)) {
        // Same answer for "no such group" as for "you are not in it".
        json_response(['error' => 'That group is not available.'], 404);
    }
    $receiver_id = null;
} else {
    if ($receiver_id <= 0) {
        json_response(['error' => 'Missing receiver.'], 400);
    }
    if ($receiver_id === $uid) {
        json_response(['error' => 'You cannot message yourself.'], 400);
    }

    $receiver = fetch_one(
        'SELECT id, full_name FROM users WHERE id = ? AND status = \'approved\'',
        [$receiver_id]
    );
    if (!$receiver) {
        json_response(['error' => 'This user is no longer available.'], 404);
    }
    $group_id = null;
}

// More than MAX_MESSAGE_LEN visible characters is not an error any more: the
// text is sent as a Markdown attachment instead (below), so nothing anybody
// writes is ever refused for its length. Exactly MAX_MESSAGE_LEN stays a normal
// text message. The markup cap still applies to anything sent as text.
$longText = $message !== '' && message_is_long($message, $format);
if (!$longText) {
    $tooLong = message_length_error($message, $format);
    if ($tooLong !== '') {
        json_response(['error' => $tooLong], 400);
    }
}

// ── Replying to something ─────────────────────────────────────────────────────
// The parent has to be one the sender may already see *and* one that belongs to
// this same conversation: without the second half, a reply could carry a quoted
// line out of a private chat and into a group.
//
// Only the first row of a multi-part send carries the quote. Attaching three
// files as one reply should read as one answer, not as the same quote stamped
// on three bubbles.
$reply_to = api_int('reply_to');
if ($reply_to > 0) {
    if (!messages_have('reply_to')) {
        json_response([
            'error' => 'This database has not been updated for replies yet. Run setup/install.php once.',
        ], 500);
    }
    if (!reply_target_is_valid($reply_to, $uid, $receiver_id, $group_id)) {
        json_response(['error' => 'That message cannot be replied to.'], 400);
    }
} else {
    $reply_to = 0;
}

// Remembered now, because $store() spends $reply_to on the first row it writes
// and the XP award below runs long after that.
$wasReply = $reply_to > 0;

// ── A GIF, sticker or meme from the picker ────────────────────────────────────
// Stored as its KLIPY CDN address rather than as a local file: re-hosting
// somebody else's animation costs disk and bandwidth this app does not have,
// and the API's terms expect their CDN to serve it. The kind doubles as the
// message type, so a sticker is never rendered as a GIF at the other end.
$klipy_url  = api_str('klipy_url');
$klipy_kind = api_str('klipy_kind');
$hasKlipy   = $klipy_url !== '';

if ($hasKlipy && (klipy_path($klipy_kind) === '' || !is_klipy_media_url($klipy_url))) {
    json_response(['error' => 'That ' . klipy_label($klipy_kind) . ' could not be sent.'], 400);
}
if ($hasKlipy && !messages_support_type($klipy_kind)) {
    json_response([
        'error' => 'This database has not been updated for GIFs, stickers and memes yet. '
                 . 'Run setup/install.php once.',
    ], 500);
}

$hasFiles = !empty($_FILES['files']['name'][0]);
// Held back for a later release: with the flag off there is no button, and the
// server declines a hand-made upload too, so the switch means one thing only.
$hasVoice = FEATURE_VOICE && !empty($_FILES['voice']['name']);
if (!$hasFiles && !$hasVoice && !$hasKlipy && $message === '') {
    json_response(['error' => 'Nothing to send.'], 400);
}

$inserted = [];
$errors   = [];
$kinds    = [];   // 'image' / 'file' / 'audio', in the order they were stored
$docName  = '';   // first document's name, for the notification preview

/**
 * One row, addressed at whichever of the two targets this send is for.
 *
 * The quote is spent on the first row and never repeated: `$reply_to` is read
 * through a reference so the closure can clear it after using it once.
 *
 * @return int The new message id.
 */
$store = static function (
    ?string $body, string $type, ?string $path = null, ?string $name = null, string $format = 'plain'
) use ($uid, $receiver_id, $group_id, &$reply_to): int {
    $columns = 'sender_id, receiver_id, group_id, message, format, type, file_path, file_name';
    $marks   = '?,?,?,?,?,?,?,?';
    $args    = [$uid, $receiver_id, $group_id, $body, $format, $type, $path, $name];

    // Attachments are stored before the text, so the first row is the top of
    // the group on screen — which is where a quote belongs.
    if ($reply_to > 0) {
        $columns .= ', reply_to';
        $marks   .= ',?';
        $args[]   = $reply_to;
        $reply_to = 0;
    }

    query("INSERT INTO messages ($columns) VALUES ($marks)", $args);
    return (int)db()->lastInsertId();
};

if ($hasFiles) {
    $files = $_FILES['files'];
    $count = count($files['name']);

    if ($count > MAX_FILES_PER_MESSAGE) {
        json_response(['error' => 'You can send at most ' . MAX_FILES_PER_MESSAGE . ' files at once.'], 400);
    }

    for ($i = 0; $i < $count; $i++) {
        $file = [
            'name'     => (string)$files['name'][$i],
            'type'     => (string)$files['type'][$i],
            'tmp_name' => (string)$files['tmp_name'][$i],
            'error'    => (int)$files['error'][$i],
            'size'     => (int)$files['size'][$i],
        ];

        // 'any': images stay images, and documents/archives land as type 'file'.
        $saved = save_upload($file, 'media', 'any');
        if (!$saved['ok']) {
            $errors[] = $file['name'] . ': ' . $saved['msg'];
            continue;
        }

        $kind     = $saved['kind'] === 'file' ? 'file' : 'image';
        $sentName = mb_substr($file['name'], 0, 255);

        $inserted[] = $store(null, $kind, $saved['file'], $sentName);
        $kinds[]    = $kind;
        if ($kind === 'file' && $docName === '') $docName = $sentName;
    }

    // Every attachment failed and there is no accompanying text – tell the user.
    if (!$inserted && $message === '') {
        json_response(['error' => implode(' ', $errors) ?: 'Could not send the attachment.'], 400);
    }
}

// ── Voice note ────────────────────────────────────────────────────────────────
// Its own field rather than another files[] entry: what arrives is whatever
// container MediaRecorder produced, and it is checked against the audio
// allow-list instead of the composer's.
if ($hasVoice) {
    $saved = save_upload($_FILES['voice'], 'media', 'audio');
    if (!$saved['ok']) {
        if (!$inserted && $message === '') {
            json_response(['error' => $saved['msg']], 400);
        }
        $errors[] = 'Voice message: ' . $saved['msg'];
    } else {
        // file_name carries the length in seconds, which the player shows before
        // the audio has loaded far enough to know its own duration.
        $seconds    = max(0, min(MAX_VOICE_SECONDS, api_int('voice_seconds')));
        $inserted[] = $store(null, 'audio', $saved['file'], 'voice-' . $seconds . 's');
        $kinds[]    = 'audio';
    }
}

if ($hasKlipy) {
    // file_name carries the KLIPY title, which becomes the alt text a screen
    // reader announces in place of the animation.
    $inserted[] = $store(
        null,
        $klipy_kind,
        $klipy_url,
        mb_substr(api_str('klipy_title') ?: klipy_label($klipy_kind), 0, 255)
    );
}

// Ids of the attachment rows this request wrote, before any text is added —
// the last of them is where a caption belongs.
$attachmentIds = $inserted;

if ($longText) {
    // Too long for a bubble: the text becomes a .md file of its own, one more
    // attachment of this same send, rather than being refused or truncated.
    $doc = long_text_attachment($message, $format);
    if (!$doc['ok']) {
        if (!$inserted) {
            json_response(['error' => $doc['msg']], 400);
        }
        $errors[] = $doc['msg'];
    } else {
        $inserted[] = $store(null, 'file', $doc['file'], $doc['name']);
        $kinds[]    = 'file';
        if ($docName === '') $docName = $doc['name'];
    }
} elseif ($message !== '' && $attachmentIds) {
    // Text sent with an attachment is its caption, not a second message. It
    // rides on the last attachment of the send: the bottom of the run on
    // screen, which is where a caption is read — and, with the client sending
    // the text on its final upload batch, the last attachment of all of them.
    query(
        'UPDATE messages SET message = ?, format = ? WHERE id = ?',
        [$message, $format, end($attachmentIds)]
    );
} elseif ($message !== '') {
    $inserted[] = $store($message, 'text', null, null, $format);
}

// Sending always ends a typing session.
if ($group_id) {
    query('DELETE FROM group_typing WHERE group_id = ? AND user_id = ?', [$group_id, $uid]);
    // My own message is read by definition.
    group_mark_read($group_id, $uid);
} else {
    query('DELETE FROM typing_status WHERE user_id = ? AND to_user_id = ?', [$uid, $receiver_id]);
}

if ($longText) {
    $preview = message_preview(['type' => 'file', 'file_name' => $docName], 120);
} elseif ($message !== '' && !$attachmentIds) {
    $preview = message_preview(['message' => $message, 'format' => $format, 'type' => 'text'], 120);
} elseif ($hasKlipy) {
    $preview = message_preview(['type' => $klipy_kind], 120);
} else {
    // The attachment that leads, with its caption where it has one.
    $lead = in_array('image', $kinds, true) ? 'image' : (in_array('audio', $kinds, true) ? 'audio' : 'file');
    $preview = message_preview([
        'type'      => $lead,
        'file_name' => $docName,
        'message'   => $message,
        'format'    => $format,
    ], 120);
}

api_respond_and_continue([
    'success'  => true,
    'inserted' => $inserted,
    'id'       => $inserted ? end($inserted) : null,
    'warnings' => $errors,
    // The text went out as a .md attachment rather than as a bubble.
    'as_file'  => $longText && !empty($doc['ok']),
]);

// ── After the response ────────────────────────────────────────────────────────
// Nothing below this line is on the path of the message that was just sent.

// XP for what was just sent. After the response on purpose: a point total is
// never worth a millisecond of the round trip somebody is waiting on, and
// gamify_award() swallows its own failures so it can never take a sent message
// down with it.
if ($inserted) {
    $images = count(array_filter($kinds, static fn(string $k): bool => $k === 'image'));
    $docs   = count(array_filter($kinds, static fn(string $k): bool => $k === 'file'));
    $notes  = count(array_filter($kinds, static fn(string $k): bool => $k === 'audio'));

    if ($images) gamify_award($uid, 'image', $images);
    if ($docs)   gamify_award($uid, 'file',  $docs);
    if ($notes)  gamify_award($uid, 'voice', $notes);
    if ($hasKlipy) gamify_award($uid, 'media');

    // A reply is worth more than a loose message, and is never counted as both.
    if ($message !== '') {
        gamify_award($uid, $wasReply ? 'reply' : 'message');
    }
}

// Telling KLIPY what was sent is what fills the picker's "Recent" row and what
// steers later trending and search results — but it is nothing the sender is
// waiting on, so it happens here, after they have their response.
if ($hasKlipy && $inserted) {
    klipy_share($klipy_kind, api_str('klipy_slug'), $uid, api_str('klipy_q'));
}

// Notify anyone who is not looking at the app.
if ($inserted) {
    notify_new_message($uid, $receiver_id, $group_id, $preview);
}
