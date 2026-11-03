<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$me = current_user();
$token = csrf_token();

// Stats
$stats = [
    'pending'  => (int)(fetch_one('SELECT COUNT(*) c FROM users WHERE status = \'pending\'')['c'] ?? 0),
    'approved' => (int)(fetch_one('SELECT COUNT(*) c FROM users WHERE status = \'approved\'')['c'] ?? 0),
    'rejected' => (int)(fetch_one('SELECT COUNT(*) c FROM users WHERE status = \'rejected\'')['c'] ?? 0),
    'disabled' => (int)(fetch_one('SELECT COUNT(*) c FROM users WHERE status = \'disabled\'')['c'] ?? 0),
    'messages' => (int)(fetch_one('SELECT COUNT(*) c FROM messages')['c'] ?? 0),
    'groups'   => (int)(fetch_one('SELECT COUNT(*) c FROM chat_groups')['c'] ?? 0),
];

// ── Groups ────────────────────────────────────────────────────────────────────
// Admins get an overview of every group on the installation: who owns it, who
// is in it and how busy it is. Deliberately not the messages themselves — the
// group's own rule is that only its members read those, and an admin badge in
// the users table does not change that.
$groups = fetch_all(
    "SELECT g.id, g.name, g.description, g.created_at,
            owner.full_name AS owner_name, owner.username AS owner_username,
            (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id)  AS member_count,
            (SELECT COUNT(*) FROM messages m      WHERE m.group_id  = g.id)  AS message_count,
            (SELECT MAX(m2.created_at) FROM messages m2 WHERE m2.group_id = g.id) AS last_activity
       FROM chat_groups g
       JOIN users owner ON owner.id = g.created_by
      ORDER BY g.created_at DESC
      LIMIT 200"
);

// One query for every membership on the page, grouped in PHP: 200 groups would
// otherwise be 200 more round trips.
$groupMembers = [];
if ($groups) {
    $ids   = array_column($groups, 'id');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    foreach (fetch_all(
        "SELECT gm.group_id, gm.role, u.full_name, u.username
           FROM group_members gm
           JOIN users u ON u.id = gm.user_id
          WHERE gm.group_id IN ($marks)
          ORDER BY (gm.role = 'admin') DESC, u.full_name ASC",
        $ids
    ) as $m) {
        $groupMembers[(int)$m['group_id']][] = $m;
    }
}

$filter = $_GET['filter'] ?? 'pending';
$allowed_filters = ['pending','approved','rejected','disabled','all'];
if (!in_array($filter, $allowed_filters)) $filter = 'pending';

$where = $filter !== 'all' ? 'WHERE status = ?' : 'WHERE 1';
$params = $filter !== 'all' ? [$filter] : [];

$users = fetch_all(
    "SELECT id, username, email, full_name, avatar, role, status, is_online, last_active, created_at
     FROM users $where ORDER BY created_at DESC LIMIT 500",
    $params
);

$myId = (int)$me['id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Panel – UnivoChat</title>
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="icon" href="<?= base_url('assets/icons/icon.svg') ?>">
</head>
<body class="admin-body">

<nav class="admin-nav">
  <div class="admin-nav-brand">
    <img src="<?= base_url('assets/icons/icon.svg') ?>" alt="" width="28">
    <span>UnivoChat Admin</span>
  </div>
  <div class="admin-nav-links">
    <a href="<?= base_url('dashboard.php') ?>">← Back to Chat</a>
    <a href="<?= base_url('logout.php') ?>">Logout</a>
  </div>
</nav>

<div class="admin-container">

  <!-- Stats row -->
  <div class="stat-grid">
    <div class="stat-card" style="--c:#2450DC">
      <div class="stat-num"><?= $stats['approved'] ?></div>
      <div class="stat-label">Approved</div>
    </div>
    <div class="stat-card" style="--c:#FFA726">
      <div class="stat-num"><?= $stats['pending'] ?></div>
      <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card" style="--c:#FF4444">
      <div class="stat-num"><?= $stats['rejected'] ?></div>
      <div class="stat-label">Rejected</div>
    </div>
    <div class="stat-card" style="--c:#8696a0">
      <div class="stat-num"><?= $stats['disabled'] ?></div>
      <div class="stat-label">Disabled</div>
    </div>
    <div class="stat-card" style="--c:#25D366">
      <div class="stat-num"><?= $stats['messages'] ?></div>
      <div class="stat-label">Messages</div>
    </div>
    <div class="stat-card" style="--c:#29B6F6">
      <div class="stat-num"><?= $stats['groups'] ?></div>
      <div class="stat-label">Groups</div>
    </div>
  </div>

  <!-- Filter tabs -->
  <div class="filter-tabs">
    <?php foreach (['pending','approved','rejected','disabled','all'] as $f): ?>
    <a href="?filter=<?= $f ?>" class="filter-tab <?= $filter === $f ? 'active' : '' ?>">
      <?= ucfirst($f) ?>
      <?php if ($f !== 'all' && isset($stats[$f])): ?>
      <span class="badge"><?= $stats[$f] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Users table -->
  <div class="admin-card">
    <div id="adminMsg"></div>
    <?php if (empty($users)): ?>
    <div class="empty-state">No users found for this filter.</div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>User</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th>Joined</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr id="row-<?= $u['id'] ?>">
          <td>
            <div class="user-cell">
              <img src="<?= e(avatar_url($u['avatar'], $u['username'])) ?>"
                   alt="" class="avatar avatar-xs">
              <div>
                <strong><?= e($u['full_name']) ?></strong>
                <small>@<?= e($u['username']) ?></small>
              </div>
            </div>
          </td>
          <td><?= e($u['email']) ?></td>
          <td><span class="role-badge role-<?= e($u['role']) ?>"><?= e($u['role']) ?></span></td>
          <td><span class="status-badge status-<?= e($u['status']) ?>"><?= e($u['status']) ?></span></td>
          <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
          <td class="actions-cell">
            <?php
              $uid      = (int)$u['id'];
              $isSelf   = $uid === $myId;
              // A superadmin is untouchable; a plain admin may not manage other admins.
              $editable = !$isSelf
                          && $u['role'] !== 'superadmin'
                          && ($u['role'] !== 'admin' || is_superadmin());
            ?>
            <?php if ($isSelf): ?>
              <em class="text-muted">You</em>
            <?php elseif (!$editable): ?>
              <em class="text-muted">Protected</em>
            <?php else: ?>
              <?php if ($u['status'] !== 'approved'): ?>
              <button class="admin-btn btn-approve" onclick="doAction(this,<?= $uid ?>,'approve')">
                <?= $u['status'] === 'disabled' ? 'Re-enable' : 'Approve' ?>
              </button>
              <?php endif; ?>
              <?php if ($u['status'] !== 'rejected'): ?>
              <button class="admin-btn btn-reject" onclick="doAction(this,<?= $uid ?>,'reject')">Reject</button>
              <?php endif; ?>
              <?php if ($u['status'] === 'approved'): ?>
              <button class="admin-btn btn-disable" onclick="doAction(this,<?= $uid ?>,'disable')">Disable</button>
              <?php endif; ?>
              <?php if (is_superadmin()): ?>
              <button class="admin-btn btn-role" onclick="doAction(this,<?= $uid ?>,'<?= $u['role'] === 'admin' ? 'demote' : 'promote' ?>')">
                <?= $u['role'] === 'admin' ? 'Demote' : 'Make Admin' ?>
              </button>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Groups -->
  <div class="admin-card">
    <h2 class="admin-card-title">
      Groups
      <span class="admin-card-note">Membership and activity only — group messages stay with their members.</span>
    </h2>

    <?php if (empty($groups)): ?>
    <div class="empty-state">Nobody has created a group yet.</div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Group</th>
          <th>Created by</th>
          <th>Members</th>
          <th>Messages</th>
          <th>Last activity</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($groups as $g): ?>
        <tr>
          <td>
            <strong><?= e($g['name']) ?></strong>
            <?php if (!empty($g['description'])): ?>
            <small><?= e($g['description']) ?></small>
            <?php endif; ?>
          </td>
          <td>
            <?= e($g['owner_name']) ?>
            <small>@<?= e($g['owner_username']) ?></small>
          </td>
          <td>
            <details class="member-list">
              <summary><?= (int)$g['member_count'] ?> member<?= (int)$g['member_count'] === 1 ? '' : 's' ?></summary>
              <ul>
                <?php foreach ($groupMembers[(int)$g['id']] ?? [] as $m): ?>
                <li>
                  <?= e($m['full_name']) ?>
                  <?php if ($m['role'] === 'admin'): ?>
                  <span class="role-badge role-admin">group admin</span>
                  <?php endif; ?>
                </li>
                <?php endforeach; ?>
              </ul>
            </details>
          </td>
          <td><?= (int)$g['message_count'] ?></td>
          <td><?= $g['last_activity'] ? e(time_ago($g['last_activity'])) : '<em class="text-muted">never</em>' ?></td>
          <td><?= date('d M Y', strtotime($g['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /admin-container -->

<script>
const ADMIN_ENDPOINT = <?= json_encode(base_url('admin/ajax.php')) ?>;
const ADMIN_CSRF     = <?= json_encode($token) ?>;

const ACTION_PROMPTS = {
  approve: 'Approve this user and give them chat access?',
  reject:  'Reject this user? They will not be able to sign in.',
  disable: 'Disable this account? The user is signed out immediately.',
  promote: 'Give this user administrator rights?',
  demote:  'Remove administrator rights from this user?',
};

function adminNotice(text, ok) {
  const box = document.getElementById('adminMsg');
  box.textContent = '';
  const div = document.createElement('div');
  div.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
  div.textContent = text;
  box.appendChild(div);
}

function doAction(btn, uid, action) {
  if (!confirm(ACTION_PROMPTS[action] || 'Are you sure?')) return;

  const row = btn.closest('tr');
  if (row) row.querySelectorAll('button').forEach(b => { b.disabled = true; });

  fetch(ADMIN_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ uid: uid, action: action, csrf_token: ADMIN_CSRF }),
  })
    .then(async r => {
      const text = await r.text();
      try { return JSON.parse(text); }
      catch (_) { throw new Error(r.status === 401 || r.status === 403 ? 'Your session expired. Please sign in again.' : 'Unexpected server response.'); }
    })
    .then(d => {
      if (d.success) {
        adminNotice(d.message, true);
        setTimeout(() => location.reload(), 800);
      } else {
        adminNotice(d.message || d.error || 'Action failed.', false);
        if (row) row.querySelectorAll('button').forEach(b => { b.disabled = false; });
      }
    })
    .catch(err => {
      adminNotice(err.message || 'Network error. Please try again.', false);
      if (row) row.querySelectorAll('button').forEach(b => { b.disabled = false; });
    });
}
</script>
</body>
</html>
