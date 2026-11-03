<?php
/**
 * KLIPY — the slice of the API the media picker needs.
 *
 * One provider serves all three tabs. GIFs, stickers and static memes are the
 * same object under three path segments (see KLIPY_KINDS), so everything below
 * takes a *kind* — 'gif', 'sticker' or 'meme' — and the only place that knows
 * KLIPY's own names for them is klipy_path().
 *
 * Every call leaves from the server, so KLIPY_API_KEY never reaches the browser,
 * and the five-format × four-size rendition matrix KLIPY answers with is cut
 * down to the three URLs the picker uses before any of it crosses the wire.
 *
 * Nothing here is cached: trending changes through the day and a search is typed
 * one keystroke at a time, so the browser's own request coalescing (one
 * in-flight request, aborted on the next keystroke) is where that is solved.
 */

// app_key() lives there, and klipy_customer_id() is built on it.
require_once __DIR__ . '/functions.php';

/**
 * Renditions tried in order, best first, per kind.
 *
 * Sizes: `md` is the sweet spot for a chat bubble — `hd` is a full-screen asset
 * and `sm` starts to soften on a high-density screen. Formats: WebP first
 * because it is roughly half the bytes of the same animation as a GIF and keeps
 * a sticker's alpha channel intact, which a GIF's one-bit transparency does not.
 */
const KLIPY_RENDITIONS = [
    'gif' => [
        'send'    => [['md', 'hd', 'sm'], ['webp', 'gif']],
        'preview' => [['sm', 'xs', 'md'], ['webp', 'gif']],
        'still'   => [['sm', 'xs', 'md'], ['jpg']],
    ],
    'sticker' => [
        'send'    => [['md', 'hd', 'sm'], ['webp', 'gif', 'png']],
        'preview' => [['sm', 'xs', 'md'], ['webp', 'gif', 'png']],
        'still'   => [['sm', 'xs', 'md'], ['png']],
    ],
    // A static meme is a picture: there is no animation to hold back, so the
    // still row is the preview itself.
    'meme' => [
        'send'    => [['md', 'hd', 'sm'], ['webp', 'png', 'jpg']],
        'preview' => [['sm', 'xs', 'md'], ['webp', 'png', 'jpg']],
        'still'   => [['sm', 'xs', 'md'], ['webp', 'png', 'jpg']],
    ],
];

/** KLIPY's `type` field, per kind. Anything else in a page is not content. */
const KLIPY_ITEM_TYPES = [
    'gif'     => ['gif'],
    'sticker' => ['sticker'],
    'meme'    => ['static_meme', 'meme'],
];

/** What each kind is called in a sentence, a preview line and an error. */
const KLIPY_LABELS = ['gif' => 'GIF', 'sticker' => 'sticker', 'meme' => 'meme'];

function klipy_enabled(): bool {
    return KLIPY_API_KEY !== '' && function_exists('curl_init');
}

/** KLIPY's path segment for one of our kinds, or '' if it is not one of ours. */
function klipy_path(string $kind): string {
    return (string)(KLIPY_KINDS[$kind] ?? '');
}

function klipy_label(string $kind): string {
    return KLIPY_LABELS[$kind] ?? 'item';
}

/**
 * The stable, anonymous handle KLIPY personalises "recent" and trending with.
 *
 * A keyed hash of the user id rather than the id itself: KLIPY asks for a value
 * that is the same on every call for the same person, and explicitly not for
 * anything that identifies them. app_key() never leaves this server, so the
 * mapping cannot be reversed from the outside.
 */
function klipy_customer_id(int $uid): string {
    return substr(hash_hmac('sha256', 'klipy-customer:' . $uid, app_key()), 0, 32);
}

/**
 * GET one KLIPY endpoint and hand back its inner `data`.
 *
 * KLIPY nests twice: `{result: true, data: {data: [...], has_next: …}}` for a
 * page of content, `{result: true, data: {categories: [...]}}` for everything
 * else. The middle layer is what comes back here; the caller reads what it
 * expects out of it.
 *
 * @return array{ok: bool, data: array, error: string}
 */
function klipy_get(string $path, array $params = []): array {
    if (!klipy_enabled()) {
        return ['ok' => false, 'data' => [], 'error' => 'Media is not configured on this server.'];
    }

    // The safety filter and locale go on the left of + so no caller can pass a
    // content_filter of its own and quietly switch the filter off.
    $url = KLIPY_BASE . KLIPY_API_KEY . '/' . $path . '?' . http_build_query(
        ['content_filter' => KLIPY_CONTENT_FILTER, 'locale' => KLIPY_LOCALE] + $params
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => KLIPY_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => KLIPY_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => APP_NAME . '/' . APP_VERSION,
    ]);

    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($ch);

    if ($body === false) {
        error_log('[UnivoChat] KLIPY transport error: ' . $error);
        return ['ok' => false, 'data' => [], 'error' => 'Could not reach KLIPY. Check your connection.'];
    }

    $json = json_decode((string)$body, true);

    if ($status !== 200 || !is_array($json) || ($json['result'] ?? false) !== true) {
        // The key and the query are both in $url, so only the status is logged.
        error_log('[UnivoChat] KLIPY ' . $path . ' failed (' . $status . ')');
        return [
            'ok'    => false,
            'data'  => [],
            'error' => $status === 401 || $status === 403
                ? 'The KLIPY key was rejected.'
                : 'KLIPY is not responding right now.',
        ];
    }

    return ['ok' => true, 'data' => is_array($json['data'] ?? null) ? $json['data'] : [], 'error' => ''];
}

/**
 * Tell KLIPY that something was sent.
 *
 * This is what fills the picker's "Recent" row and what steers later trending
 * and search results towards what this crowd actually uses, so it is worth the
 * call — but it is worth nothing to the person waiting on their message, which
 * is why the caller only ever runs it after the response has been flushed.
 * Failure is silent by design: a share that did not register is not a message
 * that did not send.
 */
function klipy_share(string $kind, string $slug, int $uid, string $query = ''): void {
    $path = klipy_path($kind);

    if (!klipy_enabled() || $path === '' || $slug === '') {
        return;
    }

    $ch = curl_init(KLIPY_BASE . KLIPY_API_KEY . '/' . $path . '/share/' . rawurlencode($slug));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => (string)json_encode([
            'customer_id' => klipy_customer_id($uid),
            // KLIPY asks for the search that led to a share, and for nothing at
            // all when the item came off the trending page.
            'q'           => mb_substr($query, 0, 100),
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => KLIPY_SHARE_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => KLIPY_SHARE_TIMEOUT,
        CURLOPT_USERAGENT      => APP_NAME . '/' . APP_VERSION,
    ]);

    curl_exec($ch);
}

/**
 * One result, cut down to what the picker draws and the message row stores.
 *
 * Returns null for anything whose renditions are missing or point somewhere
 * unexpected — which is also what keeps an advertisement out of the grid, since
 * an ad is not one of KLIPY_ITEM_TYPES and carries a different shape entirely.
 * Better one gap in the results than a broken image in a bubble.
 */
function klipy_slim(array $item, string $kind): ?array {
    if (!in_array((string)($item['type'] ?? ''), KLIPY_ITEM_TYPES[$kind] ?? [], true)) {
        return null;
    }

    $file    = is_array($item['file'] ?? null) ? $item['file'] : [];
    $rules   = KLIPY_RENDITIONS[$kind];
    $send    = klipy_rendition($file, ...$rules['send']);
    $preview = klipy_rendition($file, ...$rules['preview']);

    if ($send === null || $preview === null) {
        return null;
    }
    $still = klipy_rendition($file, ...$rules['still']);

    return [
        'slug'    => mb_substr((string)($item['slug'] ?? ''), 0, 190),
        'title'   => klipy_title($item, $kind),
        'url'     => $send['url'],
        'preview' => $preview['url'],
        // The grid sizes each tile from these before the image arrives, so the
        // results do not jump around as they load in.
        'width'   => $preview['width'],
        'height'  => $preview['height'],
        // Shown in place of the animation when the reader asked for less motion.
        'still'   => $still['url'] ?? '',
    ];
}

/**
 * First rendition that exists and points at KLIPY, walking sizes then formats.
 *
 * Size is the outer loop: a `sm` WebP is closer to what was asked for than an
 * `hd` GIF, and dropping a size costs less than dropping a format.
 */
function klipy_rendition(array $file, array $sizes, array $formats): ?array {
    foreach ($sizes as $size) {
        foreach ($formats as $format) {
            $url = $file[$size][$format]['url'] ?? null;

            if (is_string($url) && is_klipy_media_url($url)) {
                return [
                    'url'    => $url,
                    'width'  => max(1, (int)($file[$size][$format]['width']  ?? 0)),
                    'height' => max(1, (int)($file[$size][$format]['height'] ?? 0)),
                ];
            }
        }
    }
    return null;
}

/**
 * Alt text for the bubble.
 *
 * KLIPY titles repeat the kind at the end ("Screaming Excited Screaming Meme"),
 * which a screen reader would then read out twice — the bubble already says
 * what it is.
 */
function klipy_title(array $item, string $kind): string {
    $title = trim((string)($item['title'] ?? ''));
    $title = (string)preg_replace('/\s+(GIF|Sticker|Meme)$/i', '', $title);

    return mb_substr($title !== '' ? $title : klipy_label($kind), 0, 120);
}

/**
 * Is this a URL the app is willing to store and render?
 *
 * The picker is the only thing that should ever produce one, but it arrives from
 * the browser like any other form field, so the host is pinned to KLIPY's own
 * domain here. Without this the composer would be an arbitrary-image embedder:
 * anyone could post a URL that silently reports every reader's IP address to a
 * server of their choosing.
 *
 * The 500-character ceiling is `messages.file_path`: a URL that would not
 * survive the round trip is refused rather than stored truncated.
 */
function is_klipy_media_url(string $url): bool {
    if ($url === '' || strlen($url) > 500) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
        return false;
    }
    if (!preg_match('#^([a-z0-9-]+\.)*klipy\.com$#i', (string)$parts['host'])) {
        return false;
    }

    $path = strtolower((string)($parts['path'] ?? ''));

    foreach (['.gif', '.webp', '.png', '.jpg', '.jpeg'] as $ext) {
        if (str_ends_with($path, $ext)) return true;
    }
    return false;
}
