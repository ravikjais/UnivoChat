<?php
/**
 * Attachment gate.
 *
 * Everything under uploads/media is served from here instead of by Apache, for
 * two reasons: the URL carries an encrypted message id rather than the name of
 * the file on disk, and the bytes only go out to the two people the message is
 * between. uploads/media/.htaccess closes the direct route, so this is the only
 * way in.
 *
 * Deliberately not built on includes/api.php: the answer is a file, not JSON,
 * and it wants to be cached rather than marked no-store.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

/** A refusal an <img> or an <a> can cope with — plain text, never JSON. */
function file_gate_fail(int $code, string $why): never {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    exit($why);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    file_gate_fail(405, 'Method not allowed.');
}

refresh_session_user();
if (!is_logged_in() || ($_SESSION['user_status'] ?? '') !== 'approved') {
    file_gate_fail(403, 'Not signed in.');
}

$uid = current_uid();
$id  = attachment_id_from_token((string)($_GET['f'] ?? ''));
if ($id <= 0) {
    file_gate_fail(404, 'Not found.');
}

$msg = fetch_one(
    'SELECT sender_id, receiver_id, group_id, type, file_path, file_name FROM messages WHERE id = ?',
    [$id]
);

// One answer covers "no such message", "not an attachment" and "not yours".
// Telling them apart would turn this endpoint into a way of asking which
// message ids exist and who they belong to. For a group message, "yours" means
// a row in group_members — which is also how a removed member stops being able
// to open attachments they could reach yesterday.
if (!$msg
    || !can_see_message($msg, $uid)
    || !in_array($msg['type'], ['image', 'file', 'audio'], true)
    || empty($msg['file_path'])) {
    file_gate_fail(404, 'Not found.');
}

// The column is written by save_upload() and nothing else, but a path that is
// about to be opened gets flattened to a bare name on principle.
$stored = basename((string)$msg['file_path']);
$path   = UPLOAD_DIR . 'media/' . $stored;
if ($stored === '' || !is_file($path)) {
    file_gate_fail(404, 'Not found.');
}

$ext  = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
$size = (int)filesize($path);
$etag = '"' . md5($stored . '|' . $size . '|' . (int)filemtime($path)) . '"';

// An image is shown in place; a document is handed over as a download. Either
// way it travels under the name the sender chose, which the random name on disk
// has thrown away: filename* carries it in full (RFC 5987) and the plain
// filename= is the ASCII fallback for anything that cannot read filename*.
$sent  = trim(str_replace(['"', '\\', "\r", "\n"], '', (string)($msg['file_name'] ?: $stored)));
$sent  = $sent !== '' ? $sent : $stored;
$ascii = preg_replace('/[^\x20-\x7E]/', '_', $sent);

// Compressing a JPEG or a ZIP wastes CPU to no effect, and a compressed body
// makes the Content-Length below a lie the browser would wait forever on.
@ini_set('zlib.output_compression', '0');

// The message type leads: a non-image is only ever announced as one of the
// types in DOWNLOAD_MIMES, and as application/octet-stream otherwise. That is
// what lets any file be attached — a .html or .svg comes back as bytes to save,
// never as something this origin asks the browser to render.
header('Content-Type: ' . attachment_mime($ext, (string)$msg['type']));
if (!ini_get('zlib.output_compression')) {
    header('Content-Length: ' . $size);
}
header('Content-Disposition: ' . ($msg['type'] === 'file' ? 'attachment' : 'inline')
     . '; filename="' . $ascii . '"'
     . "; filename*=UTF-8''" . rawurlencode($sent));
header('X-Content-Type-Options: nosniff');
// Nothing stored here has any business fetching anything else.
header("Content-Security-Policy: default-src 'none'; sandbox");
// Private: the response depends on who is signed in, so no shared cache may
// keep it. Immutable is honest here — a token addresses one row, and a row's
// file never changes.
header('Cache-Control: private, max-age=604800, immutable');
header('ETag: ' . $etag);
header('Accept-Ranges: none');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
if ($method === 'HEAD') {
    exit;
}

// Buffers go first so a 20 MB download streams instead of being assembled in
// memory, and so nothing already buffered corrupts the file.
while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
