<?php
require_once __DIR__ . '/includes/auth.php';

if (!is_logged_in()) {
    redirect('login.php');
}

// Read the live status so an approval taking effect while this page is open
// lets the user straight through on the next refresh.
refresh_session_user(true);

if (!is_logged_in()) {
    redirect('login.php');
}
if (($_SESSION['user_status'] ?? '') === 'approved') {
    redirect('dashboard.php');
}
$status = $_SESSION['user_status'] ?? 'pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#2450DC">
<?php if ($status === 'pending'): ?>
<meta http-equiv="refresh" content="20">
<?php endif; ?>
<title>Account Status – UnivoChat</title>
<link rel="icon" href="<?= base_url('assets/icons/icon.svg') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-logo">
    <img src="<?= base_url('assets/icons/icon.svg') ?>" alt="UnivoChat" width="64">
    <h1>UnivoChat</h1>
  </div>

  <?php if ($status === 'pending'): ?>
  <div class="pending-icon">⏳</div>
  <h2 class="text-center">Awaiting Approval</h2>
  <p class="text-center text-muted">Your account is under review. This page checks again automatically — you'll be taken to the chat as soon as an admin approves you.</p>
  <?php elseif ($status === 'rejected'): ?>
  <div class="pending-icon" style="color:#FF4444">✗</div>
  <h2 class="text-center">Registration Rejected</h2>
  <p class="text-center text-muted">Your registration was not approved. Please contact the administrator for more information.</p>
  <?php elseif ($status === 'disabled'): ?>
  <div class="pending-icon" style="color:#FFA726">⊘</div>
  <h2 class="text-center">Account Disabled</h2>
  <p class="text-center text-muted">Your account has been disabled. Contact the administrator.</p>
  <?php endif; ?>

  <a href="<?= base_url('logout.php') ?>" class="btn btn-outline w-full mt-4">Log Out</a>
</div>
</body>
</html>
