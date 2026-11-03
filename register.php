<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    refresh_session_user(true);
    redirect(($_SESSION['user_status'] ?? '') === 'approved' ? 'dashboard.php' : 'pending.php');
}

$success = $error = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $old    = $_POST;
        $result = register_user($_POST);
        if ($result['success']) {
            $success = $result['message'];
            $old     = [];
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
<title>Register – UnivoChat</title>
<link rel="manifest" href="<?= base_url('manifest.json') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="icon" href="<?= base_url('assets/icons/icon.svg') ?>">
</head>
<body class="auth-body">

<div class="auth-card">
  <div class="auth-logo">
    <img src="<?= base_url('assets/icons/icon.svg') ?>" alt="UnivoChat" width="64">
    <h1>Create Account</h1>
    <p>Join UnivoChat today</p>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
  <div class="alert alert-success"><?= e($success) ?></div>
  <a href="<?= base_url('login.php') ?>" class="btn btn-primary w-full">Go to Sign In</a>
  <?php endif; ?>

  <?php if (!$success): ?>
  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <div class="form-group">
      <label class="form-label">Full Name</label>
      <input type="text" name="full_name" class="form-input" placeholder="John Doe"
             value="<?= e($old['full_name'] ?? '') ?>" required>
    </div>

    <div class="form-group">
      <label class="form-label">Username</label>
      <input type="text" name="username" class="form-input" placeholder="john_doe"
             value="<?= e($old['username'] ?? '') ?>" required>
    </div>

    <div class="form-group">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-input" placeholder="you@example.com"
             value="<?= e($old['email'] ?? '') ?>" required autocomplete="email">
    </div>

    <div class="form-group">
      <label class="form-label">Password <span class="text-muted">(min 6 chars)</span></label>
      <div class="input-eye-wrap">
        <input type="password" name="password" id="regPwd" class="form-input"
               placeholder="••••••••" required autocomplete="new-password">
        <button type="button" class="eye-btn" onclick="togglePwd('regPwd',this)" aria-label="Show password" aria-pressed="false">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
          </svg>
        </button>
      </div>
    </div>

    <button type="submit" class="btn btn-primary w-full">Create Account</button>
  </form>
  <?php endif; ?>

  <p class="auth-footer">Already have an account? <a href="<?= base_url('login.php') ?>">Sign in</a></p>
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
