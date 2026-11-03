<?php
require_once __DIR__ . '/../config/database.php';
// Declared rather than assumed. update_avatar() has always called save_upload()
// from functions.php and register_user() now calls default_group_sync_user();
// both worked only because the pages that reach them happened to load
// functions.php first. login.php and register.php do not.
require_once __DIR__ . '/functions.php';

// ─── Session bootstrap ────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // Scope the cookie to this application so other projects sharing the same
    // host (a typical XAMPP htdocs) cannot clobber the session.
    $cookiePath = base_url();
    if ($cookiePath === '' ) { $cookiePath = '/'; }

    session_name(SESSION_NAME);
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => $cookiePath,
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ─── Guards ───────────────────────────────────────────────────────────────────
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Pull the current role/status back out of the database every few seconds so
 * that an admin approving, disabling or promoting somebody takes effect on the
 * next request instead of only after a re-login.
 */
function refresh_session_user(bool $force = false): void {
    if (empty($_SESSION['user_id'])) return;

    $last = (int)($_SESSION['user_refreshed_at'] ?? 0);
    if (!$force && (time() - $last) < SESSION_REFRESH) return;

    $_SESSION['user_refreshed_at'] = time();

    $u = fetch_one('SELECT username, role, status FROM users WHERE id = ?', [(int)$_SESSION['user_id']]);
    if (!$u) {                       // account deleted while logged in
        $_SESSION = [];
        session_destroy();
        return;
    }

    $_SESSION['username']    = $u['username'];
    $_SESSION['user_role']   = $u['role'];
    $_SESSION['user_status'] = $u['status'];
}

function require_login(): void {
    refresh_session_user();
    if (!is_logged_in()) {
        if (is_api_request()) {
            json_response(['error' => 'Your session has expired. Please sign in again.', 'auth' => false], 401);
        }
        redirect('login.php');
    }
}

function require_approved(): void {
    require_login();
    if (($_SESSION['user_status'] ?? '') !== 'approved') {
        if (is_api_request()) {
            json_response([
                'error'  => 'Your account is not approved for chat access.',
                'auth'   => false,
                'status' => $_SESSION['user_status'] ?? 'pending',
            ], 403);
        }
        redirect('pending.php');
    }
}

function require_admin(): void {
    require_login();
    if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'superadmin'], true)) {
        if (is_api_request()) {
            json_response(['error' => 'Administrator access required.'], 403);
        }
        redirect('dashboard.php');
    }
}

function is_superadmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'superadmin';
}

function is_admin(): bool {
    return in_array($_SESSION['user_role'] ?? '', ['admin', 'superadmin'], true);
}

// ─── Current user ─────────────────────────────────────────────────────────────
function current_user(): ?array {
    if (!is_logged_in()) return null;
    return fetch_one(
        'SELECT id, username, email, full_name, avatar, role, status FROM users WHERE id = ?',
        [current_uid()]
    );
}

function current_uid(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

// ─── Avatar ───────────────────────────────────────────────────────────────────
/**
 * Uploaded avatar when present, otherwise a self-contained initials avatar.
 * Deliberately local: the app must render correctly offline and inside the PWA
 * without calling out to a third-party avatar service.
 */
function avatar_url(?string $avatar, string $username = '?'): string {
    if ($avatar) {
        $file = basename($avatar);
        if ($file !== '' && is_file(UPLOAD_DIR . 'avatars/' . $file)) {
            return base_url('uploads/avatars/' . rawurlencode($file));
        }
    }
    return initials_avatar($username);
}

function initials_avatar(string $name): string {
    $name  = trim($name) !== '' ? trim($name) : '?';
    $parts = preg_split('/\s+/u', $name) ?: [$name];

    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8'), 'UTF-8');
    if (count($parts) > 1) {
        $initials .= mb_strtoupper(mb_substr((string)end($parts), 0, 1, 'UTF-8'), 'UTF-8');
    }

    $palette = ['#2F5BE8','#25D366','#FFA726','#29B6F6','#EC407A','#26A69A','#7E57C2','#EF5350'];
    $color   = $palette[abs(crc32(mb_strtolower($name, 'UTF-8'))) % count($palette)];
    $label   = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">'
         . '<rect width="128" height="128" rx="64" fill="' . $color . '"/>'
         . '<text x="64" y="70" text-anchor="middle" dominant-baseline="middle" fill="#fff"'
         . ' font-family="Segoe UI,system-ui,sans-serif" font-size="54" font-weight="600">' . $label . '</text>'
         . '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

// ─── Login ────────────────────────────────────────────────────────────────────
function login_user(string $email, string $password): array {
    $email = trim($email);
    if ($email === '' || $password === '') {
        return ['success' => false, 'message' => 'Email and password are required.'];
    }

    $user = fetch_one('SELECT * FROM users WHERE email = ?', [$email]);

    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    $statusMessages = [
        'pending'  => 'Your account is still awaiting admin approval.',
        'rejected' => 'Your account registration was rejected. Please contact the administrator.',
        'disabled' => 'Your account has been disabled. Please contact the administrator.',
    ];

    if (isset($statusMessages[$user['status']])) {
        return [
            'success' => false,
            'message' => $statusMessages[$user['status']],
            'status'  => $user['status'],
        ];
    }

    // Regenerate session ID to prevent fixation, then rebuild the session.
    session_regenerate_id(true);

    $_SESSION['user_id']           = (int)$user['id'];
    $_SESSION['user_role']         = $user['role'];
    $_SESSION['user_status']       = $user['status'];
    $_SESSION['username']          = $user['username'];
    $_SESSION['user_refreshed_at'] = time();
    unset($_SESSION['csrf_token']);   // issue a fresh token for the new session

    query('UPDATE users SET is_online = 1, last_active = NOW() WHERE id = ?', [(int)$user['id']]);

    return ['success' => true];
}

// ─── Profile ──────────────────────────────────────────────────────────────────
/**
 * Change the signed-in user's display name.
 *
 * `last_active = last_active` keeps the update from disturbing the "last seen"
 * value, the same guard the admin actions use.
 */
function update_display_name(int $uid, string $full_name): array {
    $full_name = trim($full_name);

    if ($full_name === '') {
        return ['success' => false, 'message' => 'Your name cannot be empty.'];
    }
    if (mb_strlen($full_name) > 100) {
        return ['success' => false, 'message' => 'Name is too long (max 100 characters).'];
    }

    query('UPDATE users SET full_name = ?, last_active = last_active WHERE id = ?', [$full_name, $uid]);

    return ['success' => true, 'message' => 'Your name has been updated.'];
}

/** Change the password after re-checking the current one. */
function change_password(int $uid, string $current, string $new, string $confirm): array {
    if ($current === '' || $new === '') {
        return ['success' => false, 'message' => 'Please fill in every password field.'];
    }
    if ($new !== $confirm) {
        return ['success' => false, 'message' => 'The new passwords do not match.'];
    }
    if (strlen($new) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    }
    if (strlen($new) > 72) {   // bcrypt silently truncates beyond 72 bytes
        return ['success' => false, 'message' => 'Password must be 72 characters or fewer.'];
    }

    $user = fetch_one('SELECT password FROM users WHERE id = ?', [$uid]);
    if (!$user || !password_verify($current, $user['password'])) {
        return ['success' => false, 'message' => 'Your current password is not correct.'];
    }
    if (password_verify($new, $user['password'])) {
        return ['success' => false, 'message' => 'The new password must be different from the current one.'];
    }

    query(
        'UPDATE users SET password = ?, last_active = last_active WHERE id = ?',
        [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $uid]
    );

    // The password just changed: a stolen session cookie should not survive it.
    session_regenerate_id(true);

    return ['success' => true, 'message' => 'Your password has been changed.'];
}

/** Store a new profile picture and delete the file it replaces. */
function update_avatar(int $uid, array $file): array {
    // Tighter than MAX_FILE_SIZE: an avatar is displayed at 128 px and there is
    // no reason for one to weigh as much as a shared photo.
    if ((int)($file['error'] ?? 0) === UPLOAD_ERR_OK && (int)($file['size'] ?? 0) > MAX_AVATAR_SIZE) {
        return [
            'success' => false,
            'message' => 'Profile picture must be ' . (MAX_AVATAR_SIZE / 1024 / 1024) . ' MB or smaller.',
        ];
    }

    $saved = save_upload($file, 'avatars');
    if (!$saved['ok']) {
        return ['success' => false, 'message' => $saved['msg']];
    }

    $previous = fetch_one('SELECT avatar FROM users WHERE id = ?', [$uid]);
    query('UPDATE users SET avatar = ?, last_active = last_active WHERE id = ?', [$saved['file'], $uid]);

    // basename() so a crafted value in the column can never point outside the
    // avatars folder.
    $old = basename((string)($previous['avatar'] ?? ''));
    if ($old !== '' && $old !== $saved['file'] && is_file(UPLOAD_DIR . 'avatars/' . $old)) {
        @unlink(UPLOAD_DIR . 'avatars/' . $old);
    }

    return ['success' => true, 'message' => 'Your profile picture has been updated.'];
}

/** Drop the uploaded picture and fall back to the generated initials avatar. */
function remove_avatar(int $uid): array {
    $current = fetch_one('SELECT avatar FROM users WHERE id = ?', [$uid]);
    $file    = basename((string)($current['avatar'] ?? ''));

    if ($file === '') {
        return ['success' => false, 'message' => 'You do not have a profile picture to remove.'];
    }

    query('UPDATE users SET avatar = NULL, last_active = last_active WHERE id = ?', [$uid]);
    if (is_file(UPLOAD_DIR . 'avatars/' . $file)) {
        @unlink(UPLOAD_DIR . 'avatars/' . $file);
    }

    return ['success' => true, 'message' => 'Your profile picture has been removed.'];
}

// ─── Logout ───────────────────────────────────────────────────────────────────
function logout_user(): never {
    if (is_logged_in()) {
        query('UPDATE users SET is_online = 0, last_active = NOW() WHERE id = ?', [current_uid()]);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();

    // JSON polls are kept in the browser's HTTP cache for revalidation (see
    // send_json()); none of it should outlive the session on a shared machine.
    header('Clear-Site-Data: "cache"');

    redirect('login.php');
}

// ─── Password reset ───────────────────────────────────────────────────────────
/**
 * Only the SHA-256 of a reset token is ever stored.
 *
 * The same reasoning as hashing a password: somebody who walks off with a copy
 * of `password_resets` — a leaked backup, a shared-host neighbour reading the
 * dump — still cannot mint a working link out of it. A plain SHA-256 rather
 * than bcrypt is right here because the input is 32 bytes of CSPRNG output, not
 * something a person chose; there is nothing to brute-force.
 */
function reset_token_hash(string $token): string {
    return hash('sha256', $token);
}

function reset_client_ip(): string {
    return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * How many links have been asked for recently, so the form cannot be turned
 * into a way of filling somebody else's inbox.
 *
 * Counted two ways because they stop different things: per account, so one
 * mailbox cannot be flooded however many machines ask; and per requesting
 * address, so one machine cannot walk the user list.
 */
function reset_rate_exceeded(int $user_id): bool {
    $mine = fetch_one(
        'SELECT COUNT(*) AS cnt FROM password_resets
          WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
        [$user_id]
    );
    if ((int)($mine['cnt'] ?? 0) >= RESET_MAX_PER_HOUR) return true;

    $ip = reset_client_ip();
    if ($ip === '') return false;

    $theirs = fetch_one(
        'SELECT COUNT(*) AS cnt FROM password_resets
          WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
        [$ip]
    );
    return (int)($theirs['cnt'] ?? 0) >= RESET_MAX_PER_IP;
}

/**
 * Mint a reset token for an account and record its hash.
 *
 * Any earlier unused token for the same account is spent first: asking for a
 * second link has to invalidate the first, or a forwarded email from last week
 * would still open the door.
 *
 * @return string The raw token — the only time it exists in this form. It goes
 *                straight into the email and is never written down anywhere.
 */
function create_password_reset(int $user_id): string {
    query(
        'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
        [$user_id]
    );

    $token = bin2hex(random_bytes(32));

    query(
        'INSERT INTO password_resets (user_id, token_hash, ip, expires_at)
         VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
        [$user_id, reset_token_hash($token), reset_client_ip(), RESET_TOKEN_TTL]
    );

    return $token;
}

/**
 * The account a token belongs to, or null when it is unknown, spent, expired,
 * or attached to an account that may not sign in anyway.
 */
function password_reset_user(string $token): ?array {
    // A 64-character hex string is the only shape a real token has; anything
    // else is refused before it reaches the database.
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return null;

    $row = fetch_one(
        'SELECT r.id, r.user_id, u.email, u.full_name, u.username, u.status
           FROM password_resets r
           JOIN users u ON u.id = r.user_id
          WHERE r.token_hash = ?
            AND r.used_at IS NULL
            AND r.expires_at > NOW()',
        [reset_token_hash($token)]
    );

    // A rejected or disabled account is not helped by a new password, and an
    // account still awaiting approval has nothing to sign in to yet.
    return $row && in_array($row['status'], ['approved', 'pending'], true) ? $row : null;
}

/**
 * Spend a token and set the new password.
 *
 * The two happen together, in a transaction: a password changed without the
 * token being marked used would leave a working link behind, and a token marked
 * used without the password changing would lock the person out of their own
 * reset.
 *
 * @return array{success: bool, message: string}
 */
function complete_password_reset(string $token, string $new, string $confirm): array {
    if ($new === '' || $confirm === '') {
        return ['success' => false, 'message' => 'Please fill in both password fields.'];
    }
    if ($new !== $confirm) {
        return ['success' => false, 'message' => 'The two passwords do not match.'];
    }
    if (strlen($new) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    }
    if (strlen($new) > 72) {   // bcrypt silently truncates beyond 72 bytes
        return ['success' => false, 'message' => 'Password must be 72 characters or fewer.'];
    }

    // Re-checked inside the transaction: between loading the page and
    // submitting it, the link may have been used in another tab.
    $row = password_reset_user($token);
    if (!$row) {
        return ['success' => false, 'message' => 'This reset link is no longer valid. Please request a new one.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        query(
            'UPDATE users SET password = ?, last_active = last_active WHERE id = ?',
            [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), (int)$row['user_id']]
        );
        query('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [(int)$row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[UnivoChat] Password reset failed for user ' . $row['user_id'] . ': ' . $e->getMessage());
        return ['success' => false, 'message' => 'The password could not be changed. Please try again.'];
    }

    return ['success' => true, 'message' => 'Your password has been changed. You can sign in with it now.'];
}

// ─── Register ─────────────────────────────────────────────────────────────────
function register_user(array $data): array {
    $username  = trim($data['username']  ?? '');
    $email     = trim($data['email']     ?? '');
    $password  = (string)($data['password'] ?? '');
    $full_name = trim($data['full_name'] ?? '');

    if ($username === '' || $email === '' || $password === '' || $full_name === '') {
        return ['success' => false, 'message' => 'All fields are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }
    if (mb_strlen($email) > 100) {
        return ['success' => false, 'message' => 'Email address is too long (max 100 characters).'];
    }
    if (mb_strlen($full_name) > 100) {
        return ['success' => false, 'message' => 'Full name is too long (max 100 characters).'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    }
    if (strlen($password) > 72) {   // bcrypt silently truncates beyond 72 bytes
        return ['success' => false, 'message' => 'Password must be 72 characters or fewer.'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        return ['success' => false, 'message' => 'Username: 3-30 characters, letters, numbers and underscores only.'];
    }

    $exists = fetch_one('SELECT id FROM users WHERE email = ? OR username = ?', [$email, $username]);
    if ($exists) {
        return ['success' => false, 'message' => 'That email or username is already in use.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        query(
            'INSERT INTO users (username, email, password, full_name, role, status) VALUES (?,?,?,?,?,?)',
            [$username, $email, $hash, $full_name, 'user', 'pending']
        );
        $new_id = (int)db()->lastInsertId();
    } catch (PDOException $e) {
        // Unique-key race between the check above and the insert.
        return ['success' => false, 'message' => 'That email or username is already in use.'];
    }

    // Joining the default group is really the approval's job — a registration
    // starts out pending and default_group_sync_user() leaves those alone. It
    // is called here anyway so that an installation which one day approves on
    // sign-up gets the membership without needing to remember this, and so
    // there is a single place that decides who belongs in that room.
    default_group_sync_user($new_id, 'user', 'pending');

    return ['success' => true, 'message' => 'Account created! An admin will review your registration shortly.'];
}
