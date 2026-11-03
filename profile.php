<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gamify.php';
require_approved();

$me = current_user();
if (!$me) { logout_user(); }

$uid     = (int)$me['id'];
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $result = match ($_POST['action'] ?? '') {
            'name'         => update_display_name($uid, (string)($_POST['full_name'] ?? '')),
            'password'     => change_password(
                                  $uid,
                                  (string)($_POST['current_password'] ?? ''),
                                  (string)($_POST['new_password'] ?? ''),
                                  (string)($_POST['confirm_password'] ?? '')
                              ),
            'avatar'       => isset($_FILES['avatar'])
                                  ? update_avatar($uid, $_FILES['avatar'])
                                  : ['success' => false, 'message' => 'No picture was uploaded.'],
            'avatar_clear' => remove_avatar($uid),
            default        => ['success' => false, 'message' => 'Unknown action.'],
        };

        if ($result['success']) {
            $success = $result['message'];
            $me      = current_user();     // re-read so the page shows the new values
        } else {
            $error = $result['message'];
        }
    }
}

$token = csrf_token();

// Rendered server-side rather than fetched: this page is opened deliberately,
// so the numbers should already be on it when it appears. gamify_sync() also
// awards anything newly earned, which makes opening the profile the one place
// a badge can never be late.
// FEATURE_GAMIFY_UI is holding the display back for a later release. The
// scores behind it keep accruing regardless — see the flag in config.php.
$game   = (gamify_available() && FEATURE_GAMIFY_UI) ? gamify_sync($uid) : null;
$stats  = $game['stats']  ?? [];
$badges = $game['badges'] ?? [];
$earned = count(array_filter($badges, static fn(array $b): bool => $b['earned']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#2450DC">
<title>My Profile – UnivoChat</title>
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="icon" href="<?= base_url('assets/icons/icon.svg') ?>">
</head>
<body class="admin-body">

<nav class="admin-nav">
  <div class="admin-nav-brand">
    <img src="<?= base_url('assets/icons/icon.svg') ?>" alt="" width="28">
    <span>My Profile</span>
  </div>
  <div class="admin-nav-links">
    <a href="<?= base_url('dashboard.php') ?>">← Back to Chat</a>
    <a href="<?= base_url('logout.php') ?>">Logout</a>
  </div>
</nav>

<div class="admin-container profile-container">

  <?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
  <div class="alert alert-success"><?= e($success) ?></div>
  <?php endif; ?>

  <!-- ── Identity card ────────────────────────────────────────── -->
  <div class="admin-card profile-card">
    <div class="profile-head">
      <img src="<?= e(avatar_url($me['avatar'], $me['full_name'] ?: $me['username'])) ?>"
           alt="" class="avatar profile-avatar" id="avatarPreview">
      <div class="profile-identity">
        <h2><?= e($me['full_name']) ?></h2>
        <p>@<?= e($me['username']) ?> · <?= e($me['email']) ?></p>
        <span class="role-badge role-<?= e($me['role']) ?>"><?= e($me['role']) ?></span>
      </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="profile-form" id="avatarForm">
      <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
      <input type="hidden" name="action" value="avatar">

      <label class="form-label" for="avatarInput">Profile picture</label>
      <p class="form-hint">
        JPG, PNG, GIF or WebP · up to <?= (int)(MAX_AVATAR_SIZE / 1024 / 1024) ?> MB
      </p>

      <div class="profile-actions">
        <input type="file" name="avatar" id="avatarInput" accept="image/*" class="form-input file-input" required>
        <button type="submit" class="btn btn-primary">Upload</button>
      </div>
    </form>

    <?php if (!empty($me['avatar'])): ?>
    <form method="POST" class="profile-form profile-form-inline">
      <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
      <input type="hidden" name="action" value="avatar_clear">
      <button type="submit" class="btn btn-outline btn-sm">Remove picture</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($game): ?>
  <!-- ── Progress ─────────────────────────────────────────────── -->
  <div class="admin-card profile-card progress-card">
    <div class="progress-head">
      <div class="progress-level">
        <span class="progress-level-num"><?= (int)$stats['level'] ?></span>
        <span class="progress-level-word">Level</span>
      </div>
      <div class="progress-meter">
        <div class="progress-meter-top">
          <strong><?= number_format((int)$stats['xp']) ?> XP</strong>
          <span class="text-muted">
            <?= (int)$stats['to_next'] ?> to level <?= (int)$stats['level'] + 1 ?>
          </span>
        </div>
        <div class="progress-bar" role="progressbar"
             aria-valuenow="<?= (int)$stats['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
          <span style="width: <?= (int)$stats['percent'] ?>%"></span>
        </div>
      </div>
      <div class="progress-streak <?= (int)$stats['streak'] >= 2 ? 'lit' : '' ?>">
        <span class="progress-streak-flame">🔥</span>
        <span class="progress-streak-num"><?= (int)$stats['streak'] ?></span>
        <span class="progress-streak-word">day streak</span>
        <span class="progress-streak-best">best <?= (int)$stats['best_streak'] ?></span>
      </div>
    </div>

    <div class="stat-tiles">
      <?php
      // Only the counters that mean something to the person reading them —
      // "peers" and "groups" describe reach, the rest describe output.
      $tiles = [
          ['💬', 'Messages',  (int)$stats['messages']],
          ['↩️', 'Replies',   (int)$stats['replies']],
          ['📷', 'Photos',    (int)$stats['images']],
          ['📎', 'Files',     (int)$stats['files']],
          ['🎤', 'Voice',     (int)$stats['voice']],
          ['🎯', 'Reactions given',    (int)$stats['given']],
          ['❤️', 'Reactions received', (int)$stats['received']],
          ['🤝', 'People',    (int)$stats['peers']],
      ];
      foreach ($tiles as [$icon, $label, $value]): ?>
      <div class="stat-tile">
        <span class="stat-tile-icon"><?= $icon ?></span>
        <span class="stat-tile-value"><?= number_format($value) ?></span>
        <span class="stat-tile-label"><?= e($label) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── Badges ───────────────────────────────────────────────── -->
  <div class="admin-card profile-card">
    <h3 class="profile-section-title">
      Badges <span class="badge-count"><?= $earned ?> of <?= count($badges) ?></span>
    </h3>
    <p class="form-hint">Locked badges show what earns them — nothing here is ever taken away.</p>

    <div class="badge-grid">
      <?php foreach ($badges as $b): ?>
      <div class="badge-tile <?= $b['earned'] ? 'earned' : 'locked' ?>"
           title="<?= e($b['name'] . ' — ' . $b['how']) ?>">
        <span class="badge-icon"><?= $b['icon'] ?></span>
        <span class="badge-name"><?= e($b['name']) ?></span>
        <span class="badge-how"><?= e($b['how']) ?></span>
        <?php if ($b['earned'] && $b['at']): ?>
        <span class="badge-when"><?= e(date('d M Y', strtotime($b['at']))) ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Name ─────────────────────────────────────────────────── -->
  <div class="admin-card profile-card">
    <h3 class="profile-section-title">Display name</h3>
    <p class="form-hint">This is the name other people see in their chat list.</p>

    <form method="POST" class="profile-form">
      <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
      <input type="hidden" name="action" value="name">

      <div class="form-group">
        <label class="form-label" for="fullName">Full name</label>
        <input type="text" name="full_name" id="fullName" class="form-input"
               value="<?= e($me['full_name']) ?>" maxlength="100" required>
      </div>

      <button type="submit" class="btn btn-primary">Save name</button>
    </form>
  </div>

  <!-- ── Password ─────────────────────────────────────────────── -->
  <div class="admin-card profile-card">
    <h3 class="profile-section-title">Password</h3>
    <p class="form-hint">You stay signed in on this device; other devices will need the new password.</p>

    <form method="POST" class="profile-form" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
      <input type="hidden" name="action" value="password">

      <div class="form-group">
        <label class="form-label" for="curPwd">Current password</label>
        <div class="input-eye-wrap">
          <input type="password" name="current_password" id="curPwd" class="form-input"
                 placeholder="••••••••" required autocomplete="current-password">
          <button type="button" class="eye-btn" onclick="togglePwd('curPwd',this)" aria-label="Show password" aria-pressed="false">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="newPwd">New password <span class="text-muted">(min 6 chars)</span></label>
        <div class="input-eye-wrap">
          <input type="password" name="new_password" id="newPwd" class="form-input"
                 placeholder="••••••••" required autocomplete="new-password">
          <button type="button" class="eye-btn" onclick="togglePwd('newPwd',this)" aria-label="Show password" aria-pressed="false">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="confirmPwd">Confirm new password</label>
        <input type="password" name="confirm_password" id="confirmPwd" class="form-input"
               placeholder="••••••••" required autocomplete="new-password">
      </div>

      <button type="submit" class="btn btn-primary">Change password</button>
    </form>
  </div>

</div>

<script>
function togglePwd(id, btn) {
  const inp = document.getElementById(id);
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.style.opacity = inp.type === 'text' ? '1' : '0.5';
  btn.setAttribute('aria-pressed', inp.type === 'text' ? 'true' : 'false');
}

// Show the picked picture straight away instead of after the round trip.
const avatarInput = document.getElementById('avatarInput');
avatarInput.addEventListener('change', () => {
  const file = avatarInput.files && avatarInput.files[0];
  if (!file) return;

  const url = URL.createObjectURL(file);
  const img = document.getElementById('avatarPreview');
  img.src = url;
  img.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
});
</script>
</body>
</html>
