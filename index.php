<?php
require_once __DIR__ . '/includes/auth.php';

if (!is_logged_in()) {
    redirect('login.php');
}

refresh_session_user(true);

if (!is_logged_in()) {
    redirect('login.php');
}

redirect(($_SESSION['user_status'] ?? '') === 'approved' ? 'dashboard.php' : 'pending.php');
