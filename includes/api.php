<?php
/**
 * Shared bootstrap for every JSON endpoint.
 *
 * Defining API_REQUEST before the guards load makes require_login() and friends
 * answer with a JSON 401/403 instead of issuing an HTML redirect that a fetch()
 * call would try (and fail) to parse as JSON.
 */
define('API_REQUEST', true);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

function api_require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
}

function api_require_csrf(): void {
    if (!verify_csrf()) {
        json_response([
            'error' => 'Your security token expired. Please reload the page.',
            'csrf'  => false,
        ], 403);
    }
}

/**
 * Send the JSON response, let the browser go, and keep the script running.
 *
 * Used by endpoints with slow after-work — currently web push, which talks to
 * Google's and Mozilla's servers and has no business sitting inside the round
 * trip the sender is waiting on. Every SAPI that cannot detach simply finishes
 * the work before the connection closes, which is slower but still correct.
 */
function api_respond_and_continue(array $data): void {
    ignore_user_abort(true);

    // A declared length is what tells mod_php's client the body is complete;
    // send_json() always declares one unless PHP's own compression is on.
    send_json(json_encode_body($data), 200, true);

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();                 // PHP-FPM
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();               // LiteSpeed / Hostinger
    }
}

function api_int(string $key, int $default = 0): int {
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_scalar($value) ? (int)$value : $default;
}

function api_str(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

function api_bool(string $key): bool {
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    return in_array($value, ['1', 1, true, 'true', 'yes'], true);
}
