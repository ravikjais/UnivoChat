<?php
/**
 * GIPHY — the small slice of the API the GIF picker needs.
 *
 * Every call leaves from the server, so GIPHY_API_KEY never reaches the browser,
 * and the enormous rendition list GIPHY answers with is cut down to the handful
 * of fields the picker uses before any of it crosses the wire.
 *
 * Nothing here is cached: trending changes constantly and a search is typed one
 * keystroke at a time, so the browser's own request coalescing (one in-flight
 * request, aborted on the next keystroke) is where that problem is solved.
 */

/**
 * Renditions tried in order, best first.
 *
 * `fixed_height` is the family GIPHY sizes to a uniform 200px tall, which is
 * exactly what a chat bubble wants; `downsized` variants are the fallback for
 * the occasional GIF that has no fixed_height rendition at all.
 */
const GIPHY_SEND_RENDITIONS    = ['fixed_height', 'downsized_medium', 'downsized', 'original'];
const GIPHY_PREVIEW_RENDITIONS = ['fixed_height_downsampled', 'fixed_height_small', 'fixed_height', 'downsized'];
const GIPHY_STILL_RENDITIONS   = ['fixed_height_still', 'fixed_width_still', '480w_still'];

function giphy_enabled(): bool {
    return GIPHY_API_KEY !== '' && function_exists('curl_init');
}

/**
 * GET one GIPHY endpoint and hand back its `data` array.
 *
 * @return array{ok: bool, data: array, error: string}
 */
function giphy_get(string $endpoint, array $params): array {
    if (!giphy_enabled()) {
        return ['ok' => false, 'data' => [], 'error' => 'GIFs are not configured on this server.'];
    }

    // Key and rating first: with PHP's + the left-hand side wins, so no caller
    // can pass a `rating` of its own and quietly switch the content filter off.
    $url = 'https://api.giphy.com/v1/gifs/' . $endpoint . '?' . http_build_query(
        ['api_key' => GIPHY_API_KEY, 'rating' => GIPHY_RATING] + $params
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => GIPHY_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => GIPHY_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => APP_NAME . '/' . APP_VERSION,
    ]);

    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($ch);

    if ($body === false) {
        error_log('[UnivoChat] GIPHY transport error: ' . $error);
        return ['ok' => false, 'data' => [], 'error' => 'Could not reach GIPHY. Check your connection.'];
    }

    $json = json_decode((string)$body, true);

    if ($status !== 200 || !is_array($json)) {
        // The key and the query are in $url, so only the status is logged.
        error_log('[UnivoChat] GIPHY ' . $endpoint . ' failed (' . $status . ')');
        return [
            'ok'    => false,
            'data'  => [],
            'error' => $status === 401 || $status === 403
                ? 'The GIPHY key was rejected.'
                : 'GIPHY is not responding right now.',
        ];
    }

    return ['ok' => true, 'data' => is_array($json['data'] ?? null) ? $json['data'] : [], 'error' => ''];
}

/**
 * One result, cut down to what the picker draws and the message row stores.
 *
 * Returns null for a GIF whose renditions are all missing or point somewhere
 * unexpected — better one gap in the grid than a broken image in a bubble.
 */
function giphy_slim(array $gif): ?array {
    $images  = is_array($gif['images'] ?? null) ? $gif['images'] : [];
    $send    = giphy_rendition($images, GIPHY_SEND_RENDITIONS);
    $preview = giphy_rendition($images, GIPHY_PREVIEW_RENDITIONS);

    if ($send === null || $preview === null) {
        return null;
    }
    $still = giphy_rendition($images, GIPHY_STILL_RENDITIONS);

    return [
        'id'      => (string)($gif['id'] ?? ''),
        'title'   => giphy_title($gif),
        'url'     => $send['url'],
        'preview' => $preview['url'],
        // The grid sizes each tile from this before the image arrives, so the
        // results do not jump around as they load in.
        'width'   => $preview['width'],
        'height'  => $preview['height'],
        'still'   => $still['url'] ?? '',
    ];
}

/** First rendition in $names that exists and points at GIPHY. */
function giphy_rendition(array $images, array $names): ?array {
    foreach ($names as $name) {
        $url = $images[$name]['url'] ?? null;

        if (is_string($url) && is_giphy_media_url($url)) {
            return [
                'url'    => $url,
                'width'  => max(1, (int)($images[$name]['width']  ?? 0)),
                'height' => max(1, (int)($images[$name]['height'] ?? 0)),
            ];
        }
    }
    return null;
}

/** GIPHY titles carry a trailing " GIF" / " GIF by …" that reads badly as alt text. */
function giphy_title(array $gif): string {
    $title = trim((string)($gif['title'] ?? ''));
    $title = (string)preg_replace('/\s+GIF(\s+by\s+.*)?$/i', '', $title);

    return mb_substr($title !== '' ? $title : 'GIF', 0, 120);
}

/**
 * Is this a URL the app is willing to store and render?
 *
 * The picker is the only thing that should ever produce one, but it arrives from
 * the browser like any other form field, so the host is pinned to GIPHY's own
 * domain here. Without this the composer would be an arbitrary-image embedder:
 * anyone could post a URL that silently reports every reader's IP address to a
 * server of their choosing.
 */
function is_giphy_media_url(string $url): bool {
    if ($url === '' || strlen($url) > 500) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
        return false;
    }
    if (!preg_match('#^([a-z0-9-]+\.)*giphy\.com$#i', (string)$parts['host'])) {
        return false;
    }

    $path = strtolower((string)($parts['path'] ?? ''));

    return str_ends_with($path, '.gif') || str_ends_with($path, '.webp');
}
