<?php
/**
 * Web Push (RFC 8030 / 8291 / 8292) in plain PHP.
 *
 * Everything a browser needs to show a notification while UnivoChat is closed:
 * a VAPID identity for the server, and an aes128gcm-encrypted payload only the
 * subscribed browser can read. Implemented directly on ext/openssl so the app
 * keeps its "no composer, no vendor/" property.
 *
 * Requires: openssl (with prime256v1), curl, hash_hkdf().
 */

require_once __DIR__ . '/../config/database.php';

// ─── Base64url ────────────────────────────────────────────────────────────────
function b64url_encode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function b64url_decode(string $data): string {
    $data = strtr(trim($data), '-_', '+/');
    return (string)base64_decode(str_pad($data, (int)(ceil(strlen($data) / 4) * 4), '=', STR_PAD_RIGHT), true);
}

// ─── Environment ──────────────────────────────────────────────────────────────
/**
 * OpenSSL needs its config file to generate a key. Linux hosts find it on their
 * own; XAMPP on Windows ships it somewhere PHP does not look, and without it
 * openssl_pkey_new() fails with a bare "no such file".
 */
function openssl_conf_options(): array {
    static $conf = false;

    if ($conf === false) {
        $conf = null;
        $candidates = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            'D:/xampp/php/extras/ssl/openssl.cnf',
            'D:/xampp/apache/conf/openssl.cnf',
            'C:/xampp/php/extras/ssl/openssl.cnf',
            'C:/xampp/apache/conf/openssl.cnf',
        ]);
        foreach ($candidates as $path) {
            if (is_file($path)) { $conf = $path; break; }
        }
    }

    return $conf ? ['config' => $conf] : [];
}

function push_available(): bool {
    return extension_loaded('openssl')
        && extension_loaded('curl')
        && function_exists('hash_hkdf')
        && in_array('prime256v1', openssl_get_curve_names() ?: [], true);
}

/** A fresh P-256 key pair as ['key' => OpenSSLAsymmetricKey, 'public' => 65 raw bytes]. */
function ec_generate_key(): ?array {
    $key = openssl_pkey_new(array_merge(openssl_conf_options(), [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]));
    if (!$key) return null;

    $details = openssl_pkey_get_details($key);
    if (!$details || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
        return null;
    }

    return [
        'key'     => $key,
        'public'  => "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
                            . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT),
        'private' => str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT),
    ];
}

/**
 * Rebuild a usable private key object from the 32-byte scalar and 65-byte point
 * we stored, by hand-assembling the DER of an RFC 5915 ECPrivateKey. Saves
 * keeping a PEM blob (and its newlines) in the settings table.
 */
function ec_private_key_from_raw(string $privateRaw, string $publicRaw): ?OpenSSLAsymmetricKey {
    if (strlen($privateRaw) !== 32 || strlen($publicRaw) !== 65) return null;

    $der = "\x30\x77"                                       // SEQUENCE, 0x77 bytes
         . "\x02\x01\x01"                                   // version 1
         . "\x04\x20" . $privateRaw                         // privateKey OCTET STRING (32)
         . "\xa0\x0a" . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"   // [0] OID prime256v1
         . "\xa1\x44" . "\x03\x42\x00" . $publicRaw;        // [1] BIT STRING public point

    $pem = "-----BEGIN EC PRIVATE KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END EC PRIVATE KEY-----\n";

    $key = openssl_pkey_get_private($pem);
    return $key ?: null;
}

/** A peer's raw 65-byte public point as a key object openssl_pkey_derive() accepts. */
function ec_public_key_from_raw(string $publicRaw): ?OpenSSLAsymmetricKey {
    if (strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") return null;

    // SubjectPublicKeyInfo for id-ecPublicKey / prime256v1 — fixed prefix, then
    // the point itself.
    $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
         . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $publicRaw;

    $pem = "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";

    $key = openssl_pkey_get_public($pem);
    return $key ?: null;
}

// The settings store setting_get()/setting_set() used to live here moved to
// includes/functions.php when the default group started using it too: a
// key-value table shared by push keys and by which group is the default one is
// not a push concern. Every file that loads this one loads functions.php first.

// ─── VAPID identity ───────────────────────────────────────────────────────────
/**
 * The server's permanent push identity, generated on first use.
 *
 * Regenerating it would silently invalidate every subscription already handed
 * out, so it is written once and then only ever read.
 *
 * @return array{public:string,private:string}|null base64url encoded
 */
function vapid_keys(bool $create = true): ?array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $stored = setting_get('vapid_keys');
    if ($stored) {
        $keys = json_decode($stored, true);
        if (is_array($keys) && !empty($keys['public']) && !empty($keys['private'])) {
            return $cached = $keys;
        }
    }

    if (!$create || !push_available()) return null;

    $pair = ec_generate_key();
    if (!$pair) {
        error_log('[UnivoChat] Could not generate a VAPID key pair (OpenSSL EC unavailable).');
        return null;
    }

    $keys = [
        'public'  => b64url_encode($pair['public']),
        'private' => b64url_encode($pair['private']),
    ];

    // A key pair that cannot be stored must not be handed out: the browser would
    // subscribe against it and every later push would be signed by a different
    // one, which the push service rejects.
    if (!setting_set('vapid_keys', (string)json_encode($keys))) {
        error_log('[UnivoChat] Could not persist the VAPID key pair — push disabled.');
        return null;
    }

    return $cached = $keys;
}

/** The applicationServerKey the browser must subscribe with, or '' if unavailable. */
function vapid_public_key(): string {
    $keys = vapid_keys();
    return $keys['public'] ?? '';
}

/**
 * A signed VAPID JWT for one push origin ("https://fcm.googleapis.com").
 * Cached per origin because a single send often fans out to many endpoints on
 * the same service.
 */
function vapid_authorization(string $audience): ?string {
    static $tokens = [];
    if (isset($tokens[$audience]) && $tokens[$audience]['exp'] > time() + 300) {
        return $tokens[$audience]['header'];
    }

    $keys = vapid_keys();
    if (!$keys) return null;

    $private = ec_private_key_from_raw(b64url_decode($keys['private']), b64url_decode($keys['public']));
    if (!$private) return null;

    $exp = time() + 12 * 3600;   // spec caps this at 24 hours
    $jwt = b64url_encode((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
         . '.' . b64url_encode((string)json_encode([
               'aud' => $audience,
               'exp' => $exp,
               'sub' => PUSH_CONTACT,
           ]));

    $der = '';
    if (!openssl_sign($jwt, $der, $private, OPENSSL_ALGO_SHA256)) return null;

    $raw = ecdsa_der_to_raw($der);
    if ($raw === null) return null;

    $header = 'vapid t=' . $jwt . '.' . b64url_encode($raw) . ', k=' . $keys['public'];
    $tokens[$audience] = ['header' => $header, 'exp' => $exp];

    return $header;
}

/**
 * openssl_sign() emits a DER SEQUENCE of two INTEGERs; JWS ES256 wants the two
 * values raw, left-padded to 32 bytes each.
 */
function ecdsa_der_to_raw(string $der): ?string {
    $offset = 0;
    $read = static function (int $length) use ($der, &$offset): ?string {
        if ($offset + $length > strlen($der)) return null;
        $slice = substr($der, $offset, $length);
        $offset += $length;
        return $slice;
    };

    if ($read(1) !== "\x30") return null;

    $len = $read(1);
    if ($len === null) return null;
    if (ord($len) & 0x80) {                       // long-form length
        $bytes = ord($len) & 0x7f;
        if ($read($bytes) === null) return null;
    }

    $part = static function () use ($read): ?string {
        if ($read(1) !== "\x02") return null;
        $len = $read(1);
        if ($len === null) return null;

        $value = $read(ord($len));
        if ($value === null) return null;

        $value = ltrim($value, "\x00");           // DER sign padding
        return str_pad($value, 32, "\0", STR_PAD_LEFT);
    };

    $r = $part();
    $s = $part();

    return ($r === null || $s === null) ? null : $r . $s;
}

// ─── Payload encryption (RFC 8291, aes128gcm) ─────────────────────────────────
/**
 * @return string|null The complete request body: salt ‖ rs ‖ idlen ‖ server key ‖ ciphertext
 */
function webpush_encrypt(string $payload, string $clientPublicB64, string $authSecretB64): ?string {
    $clientPublic = b64url_decode($clientPublicB64);
    $authSecret   = b64url_decode($authSecretB64);

    if (strlen($clientPublic) !== 65 || strlen($authSecret) < 16) return null;

    $server = ec_generate_key();
    $peer   = ec_public_key_from_raw($clientPublic);
    if (!$server || !$peer) return null;

    $shared = openssl_pkey_derive($peer, $server['key']);
    if (!$shared) return null;

    // Combine the ECDH secret with the subscription's auth secret, bound to
    // both public keys so the result cannot be replayed against another one.
    $keyInfo = "WebPush: info\x00" . $clientPublic . $server['public'];
    $ikm     = hash_hkdf('sha256', $shared, 32, $keyInfo, $authSecret);

    $salt  = random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00",     $salt);

    // Single record, so the padding delimiter is 0x02 ("last record").
    $tag = '';
    $ciphertext = openssl_encrypt(
        $payload . "\x02",
        'aes-128-gcm',
        $cek,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if ($ciphertext === false) return null;

    return $salt
         . pack('N', 4096)                  // record size
         . chr(strlen($server['public']))   // key id length (65)
         . $server['public']
         . $ciphertext . $tag;
}

// ─── Delivery ─────────────────────────────────────────────────────────────────
/**
 * Build the cURL handle for one push. Shared by the single and the parallel
 * paths, so a request can never be assembled two different ways.
 */
function webpush_request(string $endpoint, string $body, string $authorization): CurlHandle {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => PUSH_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => PUSH_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $authorization,
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . PUSH_TTL,
            'Urgency: high',
        ],
    ]);
    return $ch;
}

/**
 * Is this status the service asking us back later, or its final answer?
 *
 * Status 0 — the request timed out or never connected — is deliberately *not*
 * on the list. It is the one failure that has already spent the whole time
 * budget, and retrying it doubles the worst case for every recipient in the
 * send. The message is not lost either way: the push service holds it for
 * PUSH_TTL, and a client that opens the app re-reads the conversation anyway.
 * What is on the list are the answers that come back fast and mean "not now".
 */
function push_status_is_transient(int $status): bool {
    return $status === 429          // slow down
        || $status === 500
        || $status === 502
        || $status === 503
        || $status === 504;
}

/**
 * POST one encrypted payload to one endpoint.
 *
 * @return int HTTP status, or 0 when the request never left the building.
 */
function webpush_deliver(string $endpoint, string $body, string $authorization): int {
    $ch = webpush_request($endpoint, $body, $authorization);

    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($response === false) {
        error_log('[UnivoChat] Push transport error: ' . curl_error($ch));
    } elseif ($status >= 400 && $status !== 404 && $status !== 410) {
        error_log('[UnivoChat] Push rejected (' . $status . '): ' . substr((string)$response, 0, 300));
    }

    return $status;
}

/**
 * Deliver many pushes at once.
 *
 * The serial loop this replaces cost up to PUSH_TIMEOUT *per endpoint*, which
 * is what put the last member of a large group minutes behind the first — and
 * on a host with a modest max_execution_time, what stopped them being notified
 * at all. A cURL multi handle sends PUSH_CONCURRENCY of them side by side
 * instead, so thirty recipients cost about what one costs.
 *
 * A transient answer (429, 5xx, or a request that never left) is tried again up
 * to PUSH_RETRIES times: those are the service asking us to come back, and
 * treating them as failures is how a perfectly good subscription ends up
 * looking unreliable.
 *
 * @param array<int,array{endpoint:string,body:string,auth:string}> $jobs
 * @return array<int,int> the same keys, each mapped to an HTTP status (0 = never sent)
 */
function webpush_deliver_many(array $jobs): array {
    if (!$jobs) return [];

    $statuses = [];
    $pending  = $jobs;

    for ($attempt = 0; $attempt <= PUSH_RETRIES && $pending; $attempt++) {
        // A retry is a courtesy, not a hammer: the second round pauses so a
        // service that answered 429 is not asked again in the same breath.
        if ($attempt > 0) usleep(400000);

        $retry = [];

        foreach (array_chunk($pending, PUSH_CONCURRENCY, true) as $chunk) {
            $multi   = curl_multi_init();
            $handles = [];

            foreach ($chunk as $key => $job) {
                $ch = webpush_request($job['endpoint'], $job['body'], $job['auth']);
                curl_multi_add_handle($multi, $ch);
                $handles[$key] = $ch;
            }

            do {
                $code = curl_multi_exec($multi, $running);
                if ($running) curl_multi_select($multi, 1.0);
            } while ($running && $code === CURLM_OK);

            foreach ($handles as $key => $ch) {
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $error  = curl_error($ch);
                $body   = (string)curl_multi_getcontent($ch);

                if ($status === 0 && $error !== '') {
                    error_log('[UnivoChat] Push transport error: ' . $error);
                } elseif ($status >= 400 && $status !== 404 && $status !== 410) {
                    error_log('[UnivoChat] Push rejected (' . $status . '): ' . substr($body, 0, 300));
                }

                $statuses[$key] = $status;
                if (push_status_is_transient($status)) $retry[$key] = $jobs[$key];

                curl_multi_remove_handle($multi, $ch);
            }

            curl_multi_close($multi);
        }

        $pending = $retry;
    }

    return $statuses;
}

/**
 * Send a notification to every browser $userId has subscribed.
 *
 * @param array $payload {title, body, url, icon, tag, from}
 * @return int Number of endpoints that accepted the message.
 */
function push_to_user(int $userId, array $payload): int {
    return push_to_users([$userId], $payload);
}

/**
 * The same, for everybody who should hear about one message.
 *
 * One call rather than one per member: the encryption is per subscription, but
 * the *sending* is not — and a group announcement was exactly the case where
 * doing them one after another was the whole problem. Every endpoint across
 * every recipient goes out in one parallel sweep.
 *
 * Subscriptions the push service reports as gone (404/410) are deleted, which
 * is the only supported way to learn that somebody cleared their site data or
 * uninstalled the PWA.
 *
 * @param int[] $userIds
 * @return int Number of endpoints that accepted the message.
 */
function push_to_users(array $userIds, array $payload): int {
    if (!push_available()) return 0;

    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return 0;

    try {
        $in   = implode(',', array_fill(0, count($userIds), '?'));
        $subs = fetch_all(
            "SELECT id, user_id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id IN ($in)",
            $userIds
        );
    } catch (PDOException $e) {
        return 0;                                   // table not created yet
    }
    if (!$subs || !vapid_keys()) return 0;

    $json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $jobs = [];
    $dead = [];

    foreach ($subs as $sub) {
        $id       = (int)$sub['id'];
        $endpoint = (string)$sub['endpoint'];
        $origin   = push_origin($endpoint);
        if ($origin === '') { $dead[] = $id; continue; }

        $authorization = vapid_authorization($origin);
        if (!$authorization) {
            // Signing is broken, so nothing can be delivered — and dropping
            // subscriptions over it would be destroying working ones.
            error_log('[UnivoChat] Push aborted: no VAPID token could be signed for ' . $origin);
            return 0;
        }

        $body = webpush_encrypt($json, (string)$sub['p256dh'], (string)$sub['auth']);
        if ($body === null) { $dead[] = $id; continue; }

        $jobs[$id] = ['endpoint' => $endpoint, 'body' => $body, 'auth' => $authorization];
    }

    $sent    = 0;
    $touched = [];

    foreach (webpush_deliver_many($jobs) as $id => $status) {
        if ($status === 404 || $status === 410) {
            $dead[] = (int)$id;
        } elseif ($status >= 200 && $status < 300) {
            $sent++;
            $touched[] = (int)$id;
        }
    }

    if ($dead) {
        $in = implode(',', array_fill(0, count($dead), '?'));
        query("DELETE FROM push_subscriptions WHERE id IN ($in)", $dead);
    }
    if ($touched) {
        $in = implode(',', array_fill(0, count($touched), '?'));
        query("UPDATE push_subscriptions SET last_used_at = NOW() WHERE id IN ($in)", $touched);
    }

    return $sent;
}

/** "https://fcm.googleapis.com" from a full endpoint URL. */
function push_origin(string $endpoint): string {
    $parts = parse_url($endpoint);
    if (empty($parts['scheme']) || empty($parts['host']) || $parts['scheme'] !== 'https') {
        return '';
    }
    return $parts['scheme'] . '://' . $parts['host'];
}

/**
 * Tell everybody a new message is for that it arrived — unless they are at the
 * keyboard already, in which case the page's own poll shows it.
 *
 * Shared by sending and forwarding, so a forwarded photo reaches a lock screen
 * exactly the way a freshly sent one does.
 *
 * @param string $preview One line of what was said ("📷 Image", "Hi there").
 */
function notify_new_message(int $uid, ?int $receiver_id, ?int $group_id, string $preview): void {
    $me     = get_user($uid);
    $name   = $me['full_name'] ?: ($me['username'] ?? 'UnivoChat');
    $avatar = avatar_url($me['avatar'] ?? null, $name);

    // A data: URI avatar (the generated initials image) is skipped: it can
    // outweigh the whole payload, and push services cap those at 4 KB.
    $icon = str_starts_with($avatar, 'data:')
        ? full_url('assets/icons/icon-192.png')
        : absolute_url($avatar);

    if ($group_id) {
        $group = group_get($group_id);
        if (!$group) return;

        // In a group the title is the group and the sender moves into the body,
        // which is the only way to tell who said what from the lock screen.
        $recipients = array_filter(
            group_member_ids($group_id),
            static fn(int $id): bool => $id !== $uid && !user_is_at_keyboard($id)
        );

        // One call for the whole room: every member's endpoints go out in the
        // same parallel sweep, so the twentieth person is notified at the same
        // moment as the first rather than a minute of timeouts later.
        push_to_users($recipients, [
            'title' => (string)$group['name'],
            'body'  => $name . ': ' . $preview,
            'icon'  => $icon,
            'badge' => full_url('assets/icons/icon-192.png'),
            'tag'   => 'group-' . $group_id,
            'url'   => full_url('dashboard.php?group=' . $group_id),
            'from'  => $uid,
            'group' => $group_id,
        ]);
    } elseif ($receiver_id && !user_is_at_keyboard($receiver_id)) {
        push_to_user($receiver_id, [
            'title' => $name,
            'body'  => $preview,
            'icon'  => $icon,
            'badge' => full_url('assets/icons/icon-192.png'),
            'tag'   => 'chat-' . $uid,
            'url'   => full_url('dashboard.php?chat=' . $uid),
            'from'  => $uid,
        ]);
    }
}
