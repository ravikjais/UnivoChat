<?php
/**
 * "Forgot password" — step one: ask for the address, mail a single-use link.
 *
 * The answer is deliberately the same whether or not the address is one we
 * know. A form that says "no such account" is a way of asking which of a list
 * of addresses have accounts here, and that is worth more to somebody probing
 * than it is to the person who mistyped their own email.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

if (is_logged_in()) {
    refresh_session_user(true);
    redirect(($_SESSION['user_status'] ?? '') === 'approved' ? 'dashboard.php' : 'pending.php');
}

$error = $sent = '';
$email = '';
// Only ever set on a development box (see below): the link, shown on the page
// because there is no SMTP server to send it through.
$devLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!mail_available() && APP_ENV !== 'local') {
        // Honest rather than a silent no-op: without SMTP nothing can arrive,
        // and telling somebody to "check their inbox" would be a lie.
        $error = 'Password reset email is not configured on this server. Please contact the administrator.';
    } else {
        $user = fetch_one(
            'SELECT id, email, full_name, username, status FROM users WHERE email = ?',
            [$email]
        );

        // The success message is written before anything is decided, so every
        // path below takes exactly the same branch from the reader's side.
        $sent = 'If that address belongs to a ' . APP_NAME . ' account, a reset link is on its way. '
              . 'It expires in ' . round(RESET_TOKEN_TTL / 60) . ' minutes.';

        // A rejected or disabled account gets no link: a new password would not
        // let them in, so the mail would only be confusing.
        if ($user && in_array($user['status'], ['approved', 'pending'], true)) {
            if (reset_rate_exceeded((int)$user['id'])) {
                // Still the same message. Somebody hammering the form learns
                // nothing; the person who pressed twice simply uses their first
                // link, which is still the newest one issued.
                error_log('[UnivoChat] Reset rate limit hit for ' . $user['email']
                        . ' from ' . reset_client_ip());
            } else {
                $token = create_password_reset((int)$user['id']);
                $link  = full_url('reset_password.php') . '?token=' . urlencode($token);
                $name  = (string)($user['full_name'] ?: $user['username']);

                if (mail_available()) {
                    $result = send_mail(
                        (string)$user['email'],
                        $name,
                        'Reset your ' . APP_NAME . ' password',
                        password_reset_email($name, $link, (int)round(RESET_TOKEN_TTL / 60))
                    );
                    if (!$result['ok']) {
                        // The token is already minted and stays valid; only the
                        // delivery failed, and that is worth saying out loud.
                        $sent  = '';
                        $error = $result['msg'];
                    }
                } elseif (APP_ENV === 'local') {
                    // Development convenience only. APP_ENV is derived from the
                    // request host in config.php, so this branch cannot be
                    // reached from a real deployment — but it is what makes the
                    // whole flow testable on XAMPP before SMTP is set up.
                    $devLink = $link;
                    $sent    = '';
                }
            }
        }
    }
}
$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#2450DC">
<title>Forgot Password – UnivoChat</title>
<link rel="manifest" href="<?= base_url('manifest.json') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="icon" href="<?= base_url('assets/icons/icon.svg') ?>">
</head>
<body class="auth-body">

<div class="auth-card">
  <div class="auth-logo">
    <img src="<?= base_url('assets/icons/icon.svg') ?>" alt="UnivoChat" width="64">
    <h1>Forgot Password</h1>
    <p>We'll email you a link to choose a new one</p>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($sent): ?>
  <div class="alert alert-success"><?= e($sent) ?></div>
  <p class="form-hint" style="margin-bottom:1.25rem">
    Nothing after a few minutes? Check your spam folder, then try again.
  </p>
  <a href="<?= base_url('login.php') ?>" class="btn btn-primary w-full">Back to Sign In</a>
  <?php endif; ?>

  <?php if ($devLink): ?>
  <div class="alert alert-info">
    <strong>Development mode.</strong> No SMTP server is configured, so the email was not sent.
    Configure <code>SMTP_*</code> in <code>config/config.local.php</code> to send it for real.
  </div>
  <p class="form-hint" style="margin-bottom:.4rem">Your reset link:</p>
  <p style="word-break:break-all;font-size:.82rem;margin-bottom:1.25rem">
    <a href="<?= e($devLink) ?>"><?= e($devLink) ?></a>
  </p>
  <a href="<?= base_url('login.php') ?>" class="btn btn-outline w-full">Back to Sign In</a>
  <?php endif; ?>

  <?php if (!$sent && !$devLink): ?>
  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <div class="form-group">
      <label class="form-label" for="resetEmail">Email</label>
      <input type="email" name="email" id="resetEmail" class="form-input" placeholder="you@example.com"
             value="<?= e($email) ?>" required autocomplete="email" autofocus>
    </div>

    <button type="submit" class="btn btn-primary w-full">Send reset link</button>
  </form>
  <?php endif; ?>

  <p class="auth-footer">Remembered it? <a href="<?= base_url('login.php') ?>">Sign in</a></p>
</div>

</body>
</html>
