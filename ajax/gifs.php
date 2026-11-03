<?php
/**
 * GIF search, proxied.
 *
 * A GET read like ajax/get_messages.php, so no CSRF token: it changes nothing,
 * and it answers only to a signed-in, approved account — which is also what
 * keeps the GIPHY key from being usable by anybody who finds this URL.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/giphy.php';
require_approved();

$query  = mb_substr(api_str('q'), 0, 100);
// GIPHY stops paging out at 4 999; the picker never gets near it, and a client
// asking for a silly offset should get an empty page rather than an API error.
$offset = max(0, min(api_int('offset'), 4999 - GIPHY_LIMIT));

$result = $query !== ''
    ? giphy_get('search', ['q' => $query, 'offset' => $offset, 'limit' => GIPHY_LIMIT, 'lang' => 'en'])
    : giphy_get('trending', ['offset' => $offset, 'limit' => GIPHY_LIMIT]);

if (!$result['ok']) {
    json_response(['error' => $result['error']], 502);
}

// array_values: the filter leaves holes, and a PHP array with holes encodes as a
// JSON object, which the client would iterate as nothing at all.
$gifs = array_values(array_filter(array_map('giphy_slim', $result['data'])));

json_response([
    'gifs'   => $gifs,
    'offset' => $offset + count($result['data']),
    // A short page is GIPHY saying there is nothing after it.
    'more'   => count($result['data']) >= GIPHY_LIMIT,
]);
