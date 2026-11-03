<?php
/**
 * "Forgot password" — step two: the link from the email lands here.
 *
 * The token travels in the query string on the way in and in a hidden field on
 * the way back, so a mistyped password does not cost the whole link. It is
 * checked on both requests: the one that renders the form, and the one that
 * changes the password.
 */
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    refresh_session_user(true);
    redirect(($_SESSION['user_status'] ?? '') === 'approved' ? 'dashboard.php' : 'pending.php');
}

$error = $done = '';

$token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string)($_POST['token'] ?? '')
    : (string)($_GET['token'] ?? '');

$row   = $token !== '' ? password_reset_user($token) : null;
$valid = $row !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$valid) {
        $error = 'This reset link is no longer valid. Please request a new one.';
    } else {
        $result = complete_password_reset(
            $token,
            (string)($_POST['password'] ?? ''),
            (string)($_POST['confirm']  ?? '')
        );

        if ($result['success']) {
            $done  = $result['message'];
            $valid = false;             // the form has nothing left to do
        } else {
            $error = $result['message'];
        }
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#2450DC">
<meta name="robots" content="noindex,nofollow">
<title>Reset Password – UnivoChat</title>
<link rel="manifest" href="<?= base_url('manifest.json') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="icon" href="<?= base_url('assets/icons/icon.svg') ?>">
</head>
<body class="auth-body">

<div class="auth-card">
  <div class="auth-logo">
    <img src="<?= base_url('assets/icons/icon.svg') ?>" alt="UnivoChat" width="64">
    <h1>Choose a new password</h1>
    <?php if ($valid): ?>
    <p>For <?= e($row['email']) ?></p>
    <?php endif; ?>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($done): ?>
  <div class="alert alert-success"><?= e($done) ?></div>
  <a href="<?= base_url('login.php') ?>" class="btn btn-primary w-full">Go to Sign In</a>

  <?php elseif ($valid): ?>
  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <div class="form-group">
      <label class="form-label" for="newPwd">New password <span class="text-muted">(min 6 chars)</span></label>
      <div class="input-eye-wrap">
        <input type="password" name="password" id="newPwd" class="form-input"
               placeholder="••••••••" required autocomplete="new-password" autofocus>
        <button type="button" class="eye-btn" onclick="togglePwd('newPwd',this)" aria-label="Show password" aria-pressed="false">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
          </svg>
        </button>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="confirmPwd">Confirm new password</label>
      <div class="input-eye-wrap">
        <input type="password" name="confirm" id="confirmPwd" class="form-input"
               placeholder="••••••••" required autocomplete="new-password">
        <button type="button" class="eye-btn" onclick="togglePwd('confirmPwd',this)" aria-label="Show password" aria-pressed="false">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
          </svg>
        </button>
      </div>
    </div>

    <button type="submit" class="btn btn-primary w-full">Change password</button>
  </form>

  <?php else: ?>
  <?php if (!$error): ?>
  <div class="alert alert-danger">
    This reset link is invalid, has already been used, or has expired.
  </div>
  <?php endif; ?>
  <a href="<?= base_url('forgot_password.php') ?>" class="btn btn-primary w-full">Request a new link</a>
  <?php endif; ?>

  <p class="auth-footer"><a href="<?= base_url('login.php') ?>">Back to sign in</a></p>
</div>

<script>
function togglePwd(id, btn) {
  const inp = document.getElementById(id);
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.style.opacity = inp.type === 'text' ? '1' : '0.5';
  btn.setAttribute('aria-pressed', inp.type === 'text' ? 'true' : 'false');
}
</script>
</body>
</html>
