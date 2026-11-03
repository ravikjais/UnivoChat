<?php
/**
 * The media picker's one endpoint, proxied.
 *
 * Answers all three tabs — GIFs, stickers, memes — and all four views KLIPY
 * offers behind them: trending, search, categories and this user's recents.
 *
 * A GET read like ajax/get_messages.php, so no CSRF token: it changes nothing,
 * and it answers only to a signed-in, approved account — which is also what
 * keeps the KLIPY key from being usable by anybody who finds this URL.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/klipy.php';
require_approved();

$kind = api_str('kind', 'gif');
$path = klipy_path($kind);

if ($path === '') {
    json_response(['error' => 'Unknown media kind.'], 400);
}

$view  = api_str('view');
$query = mb_substr(api_str('q'), 0, 100);
// Bounded rather than trusted: KLIPY stops paging out long before this, and a
// client asking for page 9 999 should get an empty grid, not an API error.
$page  = max(1, min(api_int('page', 1), 200));

// ── The category strip ────────────────────────────────────────────────────────
// Fetched once per kind and then cached in the page, so the chips are one
// request for the life of the tab rather than one per open.
if ($view === 'categories') {
    $result = klipy_get($path . '/categories');

    if (!$result['ok']) {
        json_response(['error' => $result['error']], 502);
    }

    $categories = [];
    foreach ((array)($result['data']['categories'] ?? []) as $row) {
        $label = trim((string)($row['category'] ?? ''));
        $term  = trim((string)($row['query'] ?? $label));

        if ($label === '' || $term === '') continue;

        $categories[] = ['label' => mb_substr($label, 0, 40), 'query' => mb_substr($term, 0, 100)];
        if (count($categories) >= 20) break;   // a strip, not a directory
    }

    json_response(['kind' => $kind, 'categories' => $categories]);
}

// ── A page of content ─────────────────────────────────────────────────────────
if ($view === 'recent') {
    // Per-user, and filled by the share ping ajax/send_message.php fires after a
    // send — so "recent" here means recently *sent*, which is what somebody
    // reaching for this row is looking for.
    $result = klipy_get(
        $path . '/recent/' . klipy_customer_id(current_uid()),
        ['page' => $page, 'per_page' => min(KLIPY_LIMIT, 32)]
    );
} elseif ($query !== '') {
    $result = klipy_get($path . '/search', [
        'q'           => $query,
        'page'        => $page,
        'per_page'    => KLIPY_LIMIT,
        'customer_id' => klipy_customer_id(current_uid()),
    ]);
} else {
    $result = klipy_get($path . '/trending', [
        'page'        => $page,
        'per_page'    => KLIPY_LIMIT,
        'customer_id' => klipy_customer_id(current_uid()),
    ]);
}

if (!$result['ok']) {
    json_response(['error' => $result['error']], 502);
}

$rows = (array)($result['data']['data'] ?? []);

// array_values: the filter leaves holes, and a PHP array with holes encodes as a
// JSON object, which the client would iterate as nothing at all.
$items = array_values(array_filter(array_map(
    static fn(array $row): ?array => is_array($row) ? klipy_slim($row, $kind) : null,
    array_filter($rows, 'is_array')
)));

json_response([
    'kind'  => $kind,
    'view'  => $view === 'recent' ? 'recent' : ($query !== '' ? 'search' : 'trending'),
    'items' => $items,
    'page'  => $page,
    // KLIPY's own answer to "is there another page", not a guess from the count:
    // the ad and rendition filtering above can shorten a page that was full.
    'more'  => (bool)($result['data']['has_next'] ?? false),
]);
