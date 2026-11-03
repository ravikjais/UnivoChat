<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    refresh_session_user(true);
    redirect(($_SESSION['user_status'] ?? '') === 'approved' ? 'dashboard.php' : 'pending.php');
}

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $result = login_user((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));

        if ($result['success']) {
            redirect('dashboard.php');
        }

        // A non-approved account never gets a session, so it cannot be sent to
        // pending.php (that page would just bounce it back here). Show the
        // reason on this page instead.
        if (isset($result['status'])) {
            $notice = $result['message'];
        } else {
            $error = $result['message'];
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
<title>Login – UnivoChat</title>
<link rel="manifest" href="<?= base_url('manifest.json') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="icon" href="<?= base_url('assets/icons/icon.svg') ?>">
</head>
<body class="auth-body">

<div class="auth-card">
  <div class="auth-logo">
    <img src="<?= base_url('assets/icons/icon.svg') ?>" alt="UnivoChat" width="72">
    <h1>UnivoChat</h1>
    <p>Connect. Chat. Collaborate.</p>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>
  <?php if ($notice): ?>
  <div class="alert alert-info"><?= e($notice) ?></div>
  <?php endif; ?>

  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <div class="form-group">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-input" placeholder="you@example.com"
             value="<?= e($_POST['email'] ?? '') ?>" required autocomplete="email">
    </div>

    <div class="form-group">
      <div class="label-row">
        <label class="form-label" for="loginPwd">Password</label>
        <a class="form-link" href="<?= base_url('forgot_password.php') ?>">Forgot password?</a>
      </div>
      <div class="input-eye-wrap">
        <input type="password" name="password" id="loginPwd" class="form-input"
               placeholder="••••••••" required autocomplete="current-password">
        <button type="button" class="eye-btn" onclick="togglePwd('loginPwd',this)" aria-label="Show password" aria-pressed="false">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
          </svg>
        </button>
      </div>
    </div>

    <button type="submit" class="btn btn-primary w-full">Sign In</button>
  </form>

  <p class="auth-footer">Don't have an account? <a href="<?= base_url('register.php') ?>">Create one</a></p>
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
