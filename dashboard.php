<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/push.php';
require_once __DIR__ . '/includes/klipy.php';
require_once __DIR__ . '/includes/gamify.php';
require_once __DIR__ . '/includes/calls.php';
require_approved();

$me = current_user();
if (!$me) { logout_user(); }

$isAdmin = in_array($me['role'], ['admin', 'superadmin'], true);
$token   = csrf_token();

// Empty when the server cannot do web push (no OpenSSL EC, or the tables have
// not been created yet); the client then simply never offers to subscribe.
$vapidKey = push_available() ? vapid_public_key() : '';
$callsOn  = calls_available() && calls_installed();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>UnivoChat</title>
<meta name="theme-color" content="#2450DC">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="UnivoChat">
<link rel="manifest" href="<?= base_url('manifest.json') ?>">
<link rel="icon" href="<?= base_url('assets/icons/icon.svg') ?>">
<link rel="apple-touch-icon" href="<?= base_url('assets/icons/icon-180.png') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
</head>
<body class="app-body">

<!-- ═══════════════════════════════════════════════════ APP FRAME ═══ -->
<!-- The whole application is a rounded white sheet floating on brand blue,
     with a narrow icon rail beside it. Moving the header's buttons out here is
     what gives the identity block below room to breathe — it used to squeeze a
     full name down to "Priya Sh…" to fit six icons alongside it. -->
<div class="app-frame">

  <nav class="rail" aria-label="Main">
    <a class="rail-logo" href="<?= base_url('dashboard.php') ?>" title="UnivoChat">
      <img src="<?= base_url('assets/icons/icon.svg') ?>" alt="UnivoChat" width="30" height="30">
    </a>

    <div class="rail-nav">
      <!-- Chat is where you already are; it is marked rather than linked. -->
      <span class="rail-btn rail-current" title="Chats" aria-current="page">
        <svg width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
          <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
        </svg>
      </span>

      <?php if (FEATURE_GAMIFY_UI): ?>
      <button class="rail-btn" id="boardBtn" title="Leaderboard" aria-label="Leaderboard" hidden>
        <svg width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
          <path d="M8 21h8M12 17v4"/>
          <path d="M7 4h10v5a5 5 0 01-10 0z"/>
          <path d="M17 5h3v2a3 3 0 01-3 3M7 5H4v2a3 3 0 003 3"/>
        </svg>
      </button>
      <?php endif; ?>

      <button class="rail-btn" id="notifBtn" title="Enable notifications" hidden>
        <svg width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
          <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 01-3.46 0"/>
          <line x1="3" y1="3" x2="21" y2="21" id="notifSlash"/>
        </svg>
      </button>

      <button class="rail-btn" id="newGroupBtn" title="New group" aria-label="New group">
        <svg width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
          <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
          <line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
        </svg>
      </button>

      <!-- No profile button here on purpose. The way to your own profile is
           your own face and name at the top of the sidebar, which links to it —
           a second entry point on the rail was the same destination twice, and
           the rail is short enough that every icon on it should earn its slot.
           .rail-nav is a centred flex column (a space-around row on a phone), so
           the remaining icons close the gap on their own. -->

      <?php if ($isAdmin): ?>
      <a class="rail-btn" href="<?= base_url('admin/index.php') ?>" title="Admin panel">
        <svg width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
          <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5C21.27 7.61 17 4.5 12 4.5z"/>
          <circle cx="12" cy="12" r="3"/>
        </svg>
      </a>
      <?php endif; ?>

      <button class="rail-btn" id="installBtn" title="Install app" style="display:none">
        <svg width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
      </button>
    </div>

    <a class="rail-btn rail-foot" href="<?= base_url('logout.php') ?>" title="Sign out">
      <svg width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </a>
  </nav>

  <!-- The white sheet everything else lives on. -->
  <div class="app-sheet">
  <div class="app" id="app">

  <!-- ░░░░░░░░░░░░░░░░░░░░░░░ SIDEBAR ░░░░░░░░░░░░░░░░░░░░░░░ -->
  <aside class="sidebar" id="sidebar">

    <!-- Sidebar Header -->
    <div class="sidebar-header">
      <!-- With FEATURE_GAMIFY_UI on, this block doubles as the progress
           display: a ring that fills with the level, a pip carrying the number
           and a flame once there is a streak worth showing, all filled in by
           app.js. With it off — the current release — it is a plain picture and
           name, and none of that markup is rendered at all. -->
      <a class="sh-left" href="<?= base_url('profile.php') ?>" title="My profile">
        <?php if (FEATURE_GAMIFY_UI): ?>
        <span class="level-ring" id="levelRing" style="--ring:0%">
          <img src="<?= e(avatar_url($me['avatar'], $me['full_name'] ?: $me['username'])) ?>"
               alt="<?= e($me['username']) ?>" class="avatar avatar-sm" id="myAvatar">
          <span class="level-pip" id="levelPip" hidden>1</span>
        </span>
        <?php else: ?>
        <img src="<?= e(avatar_url($me['avatar'], $me['full_name'] ?: $me['username'])) ?>"
             alt="<?= e($me['username']) ?>" class="avatar avatar-md" id="myAvatar">
        <?php endif; ?>
        <div class="sh-meta">
          <div class="sh-name"><?= e($me['full_name']) ?></div>
          <?php if (FEATURE_GAMIFY_UI): ?>
          <div class="sh-sub" id="shSub" hidden>
            <span class="streak-chip" id="streakChip" hidden>
              <span class="streak-flame">🔥</span><b id="streakDays">0</b>
            </span>
            <span class="xp-line" id="xpLine"></span>
          </div>
          <?php else: ?>
          <div class="sh-handle">@<?= e($me['username']) ?></div>
          <?php endif; ?>
        </div>
      </a>
    </div>

    <!-- Search -->
    <div class="search-wrap">
      <div class="search-box">
        <svg class="search-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" id="searchInput" class="search-input" placeholder="Search people and messages…" autocomplete="off">
        <button class="search-clear" id="searchClear" style="display:none">✕</button>
      </div>
    </div>

    <!-- Contacts list -->
    <div class="contacts-list" id="contactsList">
      <div class="skeleton-list">
        <?php for ($i = 0; $i < 6; $i++): ?>
        <div class="skeleton-item">
          <div class="skeleton sk-avatar"></div>
          <div class="sk-lines">
            <div class="skeleton sk-line sk-line-lg"></div>
            <div class="skeleton sk-line sk-line-sm"></div>
          </div>
        </div>
        <?php endfor; ?>
      </div>
    </div>

  </aside>

  <!-- ░░░░░░░░░░░░░░░░░░░░░░░ CHAT AREA ░░░░░░░░░░░░░░░░░░░░░░░ -->
  <main class="chat-area" id="chatArea">

    <!-- Welcome screen -->
    <div class="welcome-screen" id="welcomeScreen">
      <div class="welcome-inner">
        <img src="<?= base_url('assets/icons/icon.svg') ?>" alt="UnivoChat" class="welcome-icon">
        <h2>UnivoChat</h2>
        <p>Select a conversation to start chatting</p>
        <div class="time-widget" id="timeWidget"></div>
      </div>
    </div>

    <!-- Active chat -->
    <div class="chat-window" id="chatWindow" hidden>

      <!-- Chat header -->
      <div class="chat-header" id="chatHeader">
        <button class="icon-btn back-btn" id="backBtn" title="Back">
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <polyline points="15 18 9 12 15 6"/>
          </svg>
        </button>
        <img src="" alt="" class="avatar avatar-md avatar-view" id="chatAvatar"
             role="button" tabindex="0" title="View photo">
        <div class="chat-user-info">
          <div class="chat-username" id="chatUsername"></div>
          <div class="chat-status" id="chatStatus"></div>
        </div>
        <!-- One-to-one chats only; call.js shows them where WebRTC can work. -->
        <button class="icon-btn" id="callAudioBtn" title="Voice call" aria-label="Voice call" hidden>
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1.9.4 1.8.7 2.7a2 2 0 01-.5 2.1L8 9.8a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c.9.3 1.8.6 2.7.7a2 2 0 011.7 2z"/>
          </svg>
        </button>
        <button class="icon-btn" id="callVideoBtn" title="Video call" aria-label="Video call" hidden>
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>
          </svg>
        </button>
        <!-- Everything shared in this conversation, sorted into drawers. -->
        <button class="icon-btn" id="filesBtn" title="Shared files"
                aria-label="Shared files" aria-expanded="false" aria-controls="filesPanel">
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
          </svg>
        </button>
        <!-- Groups only: members, adding and removing people, leaving. -->
        <button class="icon-btn" id="groupInfoBtn" title="Group info" aria-label="Group info" hidden>
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/>
            <line x1="12" y1="8" x2="12.01" y2="8"/>
          </svg>
        </button>
      </div>

      <!-- Messages.
           The stage exists so the floating date below can be positioned over
           the conversation instead of inside it: anything absolutely placed
           within .messages-wrap scrolls away with the content it is meant to
           label. -->
      <div class="messages-stage">

        <!-- Which day the reader is currently looking at. Floats over the top
             of the conversation while scrolling and fades out once they stop,
             so a long backlog never leaves anybody wondering what week they are
             in. Driven by app.js from each bubble's own date. -->
        <div class="date-float" id="dateFloat" aria-hidden="true"><span></span></div>

        <div class="messages-wrap" id="messagesWrap">
          <!-- Fills as the reader scrolls to the top: one page of older
               messages at a time, back to the very first thing either of them
               said. Hidden once there is nothing left above. -->
          <div class="history-top" id="historyTop" hidden>
            <span class="history-spinner" aria-hidden="true"></span>
            <span class="history-label" id="historyLabel">Loading earlier messages…</span>
          </div>

          <div class="messages-container" id="messagesContainer"></div>
        </div>

        <!-- Back to the newest message. Ordinarily just the "you have scrolled
             up" affordance every messenger has; in a conversation opened from a
             search result it is also the way out of its own history, and grows
             a "Latest" label to say so. -->
        <button class="jump-btn" id="jumpBtn" hidden
                title="Scroll to the bottom" aria-label="Scroll to the newest message">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
            <line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>
          </svg>
          <span class="jump-label" id="jumpLabel"></span>
        </button>
      </div>

      <!-- Typing indicator -->
      <div class="typing-indicator" id="typingIndicator" hidden>
        <span class="typing-avatar" id="typingAvatar"></span>
        <div class="typing-bubble">
          <span class="dot"></span><span class="dot"></span><span class="dot"></span>
        </div>
        <!-- Groups name who is typing; a one-to-one chat has nobody to name. -->
        <span class="typing-who" id="typingWho"></span>
      </div>

      <!-- Attachment preview.
           Its own header rather than a bare strip of thumbnails: with up to
           forty pictures staged, how many there are and one control that drops
           the lot are what make the thing usable. The tiles below scroll inside
           a fixed height so the composer never gets pushed off the screen. -->
      <div class="attachment-preview" id="attachmentPreview" hidden>
        <div class="attachment-bar" id="attachmentBar">
          <span class="attachment-count" id="attachmentCount"></span>
          <button type="button" class="attachment-clear" id="attachmentClear"
                  title="Remove every attachment" aria-label="Clear all attachments">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
              <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
            </svg>
            Clear all
          </button>
        </div>
        <div class="attachment-files" id="attachmentFiles"></div>
      </div>

      <!-- Forty attachments do not travel as one request (see batchFiles), so
           the send has a middle as well as a beginning and an end — and the
           middle is measured in bytes actually on the wire, not in requests
           completed. A progressbar role rather than aria-live: the percentage
           changes many times a second, and announcing every one of them would
           make the composer unusable with a screen reader. -->
      <div class="send-progress" id="sendProgress" hidden
           role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
           aria-label="Attachment upload progress">
        <span class="send-progress-text" id="sendProgressText"></span>
        <div class="send-progress-track"><span class="send-progress-bar" id="sendProgressBar"></span></div>
        <span class="send-progress-pct" id="sendProgressPct"></span>
      </div>

      <!-- Input area -->
      <div class="chat-input-area">

        <!-- The message being answered. Sits directly above the composer, the
             way every messenger draws it, and is filled in by app.js. -->
        <div class="reply-bar" id="replyBar" hidden>
          <div class="reply-bar-quote">
            <div class="reply-bar-who" id="replyBarWho"></div>
            <div class="reply-bar-text" id="replyBarText"></div>
          </div>
          <button class="icon-btn reply-bar-cancel" id="replyCancel"
                  title="Cancel reply" aria-label="Cancel reply">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>

        <?php if (klipy_enabled()): ?>
        <!-- Media picker: GIFs, stickers and memes, all three from KLIPY. Sits
             above the composer and is only filled on first open; the tabs, the
             category strip and the grid are all driven by app.js. -->
        <div class="media-picker" id="mediaPicker" hidden>
          <div class="mp-head">
            <div class="mp-search">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
              </svg>
              <input type="text" id="mpSearch" placeholder="Search GIFs…" autocomplete="off"
                     spellcheck="false" aria-label="Search GIFs, stickers and memes"
                     aria-controls="mpGrid">
              <button class="mp-search-clear" id="mpSearchClear" title="Clear" aria-label="Clear search" hidden>✕</button>
            </div>
            <button class="icon-btn mp-close" id="mpClose" title="Close" aria-label="Close media picker">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>

          <!-- The search term carries across the tabs on purpose: type "thanks"
               once and it is a GIF, a sticker and a meme away. -->
          <div class="mp-tabs" id="mpTabs" role="tablist" aria-label="Media type">
            <button class="mp-tab active" data-kind="gif"     role="tab" aria-selected="true">GIFs</button>
            <button class="mp-tab"        data-kind="sticker" role="tab" aria-selected="false">Stickers</button>
            <button class="mp-tab"        data-kind="meme"    role="tab" aria-selected="false">Memes</button>
          </div>

          <!-- Trending / Recent / KLIPY's own categories for the open tab. -->
          <div class="mp-chips" id="mpChips" role="tablist" aria-label="Categories"></div>

          <div class="mp-grid" id="mpGrid" role="listbox" aria-label="Results" tabindex="-1"></div>
          <!-- Attribution is a condition of using the KLIPY API. -->
          <div class="mp-foot">Powered by <strong>KLIPY</strong></div>
        </div>
        <?php endif; ?>

        <div class="input-row">
          <!-- The one filled control on the left of the composer, the way the
               reference draws it. Still the file picker it always was. -->
          <button class="attach-btn" id="attachBtn" title="Attach a file"
                  aria-label="Attach a file">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
              <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
          </button>
          <?php if (klipy_enabled()): ?>
          <!-- One button for all three libraries — a peeling sticker corner is
               what every other client uses to mean "not a file, not an emoji". -->
          <button class="icon-btn media-btn" id="mediaBtn" title="GIFs, stickers &amp; memes"
                  aria-label="GIFs, stickers and memes" aria-expanded="false" aria-controls="mediaPicker">
            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
              <path d="M14.5 3H6a3 3 0 00-3 3v12a3 3 0 003 3h4.5L21 10.5V6a3 3 0 00-3-3z"/>
              <path d="M21 10.5h-7.5a3 3 0 00-3 3V21"/>
              <circle cx="9" cy="9.5" r="1.1" fill="currentColor" stroke="none"/>
              <circle cx="14.5" cy="7.5" r="1.1" fill="currentColor" stroke="none"/>
              <path d="M7.6 14a3.4 3.4 0 003.1 1.9" stroke-linecap="round"/>
            </svg>
          </button>
          <?php endif; ?>
          <?php if (FEATURE_CAMERA): ?>
          <!-- Only wired up where the browser has the API; app.js hides the
               button rather than shipping one that fails on click. -->
          <button class="icon-btn camera-btn" id="cameraBtn" title="Take a photo" aria-label="Take a photo" hidden>
            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
              <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/>
              <circle cx="12" cy="13" r="4"/>
            </svg>
          </button>
          <?php endif; ?>
          <?php if (FEATURE_VOICE): ?>
          <button class="icon-btn mic-btn" id="micBtn" title="Record a voice message" aria-label="Record a voice message" hidden>
            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
              <rect x="9" y="2" width="6" height="12" rx="3"/>
              <path d="M5 11a7 7 0 0014 0"/><line x1="12" y1="18" x2="12" y2="22"/>
            </svg>
          </button>
          <?php endif; ?>
          <!-- No `accept`: any file type may be attached, and the server's
               BLOCKED_EXTS list is the one place that says otherwise. A filter
               here would only hide files the server would have taken. -->
          <input type="file" id="fileInput" multiple hidden>
          <!-- contenteditable, not <input>: a spreadsheet pasted from Excel or
               Google Sheets is shown as a real table before it is sent. -->
          <div id="messageInput" class="message-input" contenteditable="true"
               role="textbox" aria-multiline="true" aria-label="Type a message"
               data-placeholder="Type a message…"></div>
          <button class="send-btn" id="sendBtn" title="Send">
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
            </svg>
          </button>
        </div>

        <?php if (FEATURE_VOICE): ?>
        <!-- Takes the composer's place while the microphone is live. -->
        <div class="recording-row" id="recordingRow" hidden>
          <button class="icon-btn rec-cancel" id="recCancel" title="Discard recording" aria-label="Discard recording">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
              <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
            </svg>
          </button>
          <span class="rec-dot" aria-hidden="true"></span>
          <span class="rec-time" id="recTime" role="timer">0:00</span>
          <span class="rec-hint">Recording…</span>
          <button class="send-btn rec-stop" id="recStop" title="Stop and attach" aria-label="Stop and attach">
            <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
          </button>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- /chat-window -->

    <!-- ░░░ Message actions, on a touch screen ░░░
         There is no hover on a phone, so the row of small round buttons that
         sits beside a bubble on a desktop cannot be reached there. Tapping a
         message opens this sheet instead: the reactions along the top, then
         one full-width row per action, each big enough to hit and labelled
         rather than left as a bare icon.

         Filled in by app.js from the buttons the message actually has, so what
         is offered here and what a desktop offers on hover can never drift
         apart. -->
    <div class="msg-sheet-backdrop" id="msgSheetBackdrop" hidden></div>
    <div class="msg-sheet" id="msgSheet" role="dialog" aria-modal="true"
         aria-label="Message actions" hidden>
      <div class="msg-sheet-grip" aria-hidden="true"></div>
      <div class="msg-sheet-quote" id="msgSheetQuote"></div>
      <div class="msg-sheet-reactions" id="msgSheetReactions" role="group" aria-label="React"></div>
      <div class="msg-sheet-actions" id="msgSheetActions"></div>
    </div>

    <!-- ░░░ Shared files ░░░
         Slides in over the right-hand edge of the conversation rather than
         opening as a dialog: the point of it is to look through what has been
         shared *while* the chat stays where it was. Filled in by app.js. -->
    <div class="files-backdrop" id="filesBackdrop" hidden></div>
    <aside class="files-panel" id="filesPanel" hidden aria-label="Shared files">
      <div class="files-head">
        <h2 id="filesTitle">Shared files</h2>
        <button class="icon-btn" id="filesClose" title="Close" aria-label="Close shared files">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>

      <div class="files-tabs" id="filesTabs" role="tablist">
        <button class="files-tab active" data-tab="images"    role="tab" aria-selected="true">
          Images <span class="files-count" data-count="images">0</span>
        </button>
        <button class="files-tab" data-tab="documents" role="tab" aria-selected="false">
          Documents <span class="files-count" data-count="documents">0</span>
        </button>
        <button class="files-tab" data-tab="archives"  role="tab" aria-selected="false">
          Archives <span class="files-count" data-count="archives">0</span>
        </button>
        <button class="files-tab" data-tab="links"     role="tab" aria-selected="false">
          Links <span class="files-count" data-count="links">0</span>
        </button>
      </div>

      <div class="files-body" id="filesBody" role="tabpanel"></div>
    </aside>

  </main><!-- /chat-area -->

  </div><!-- /app -->
  </div><!-- /app-sheet -->
</div><!-- /app-frame -->

<!-- ░░░░░░░░░░░░░░░░░░░░░░░ MODALS ░░░░░░░░░░░░░░░░░░░░░░░ -->
<!-- New group. Any approved user may create one and becomes its admin. -->
<div class="modal" id="groupModal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="groupModalTitle">
    <div class="modal-head">
      <h2 id="groupModalTitle">New group</h2>
      <button class="icon-btn" data-close-modal title="Close" aria-label="Close">✕</button>
    </div>
    <div class="modal-body">
      <!-- The display picture, chosen up front. Optional: a group with no photo
           falls back to the same initials tile it always had. -->
      <div class="group-photo-row">
        <button type="button" class="group-photo-pick" id="groupPhotoPick" title="Choose a group picture">
          <img src="" alt="" id="groupPhotoPreview" hidden>
          <span class="group-photo-empty" id="groupPhotoEmpty">
            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
              <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/>
              <circle cx="12" cy="13" r="4"/>
            </svg>
          </span>
        </button>
        <div class="group-photo-copy">
          <div class="form-label">Group picture <span class="text-muted">(optional)</span></div>
          <p class="form-hint">JPG, PNG, GIF or WebP · up to <?= (int)(MAX_AVATAR_SIZE / 1024 / 1024) ?> MB</p>
          <button type="button" class="btn btn-ghost btn-sm" id="groupPhotoClear" hidden>Remove</button>
        </div>
        <input type="file" id="groupPhotoInput" accept="image/*" hidden>
      </div>

      <label class="form-label" for="groupName">Group name</label>
      <input type="text" id="groupName" class="form-input" maxlength="80" placeholder="e.g. Q3 Planning">

      <label class="form-label" for="groupDesc">Description <span class="text-muted">(optional)</span></label>
      <input type="text" id="groupDesc" class="form-input" maxlength="255" placeholder="What is this group for?">

      <label class="form-label">Members <span class="text-muted" id="groupPickCount">(none selected)</span></label>
      <input type="text" id="groupMemberSearch" class="form-input" placeholder="Search people…" autocomplete="off">
      <div class="member-picker" id="groupMemberPicker"></div>
    </div>
    <div class="modal-foot">
      <div class="modal-error" id="groupModalError" hidden></div>
      <button class="btn btn-ghost" data-close-modal>Cancel</button>
      <button class="btn btn-primary" id="groupCreateBtn">Create group</button>
    </div>
  </div>
</div>

<!-- Group info: members, admin controls, leave. Filled in by app.js. -->
<div class="modal" id="groupInfoModal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="groupInfoTitle">
    <div class="modal-head">
      <h2 id="groupInfoTitle">Group</h2>
      <button class="icon-btn" data-close-modal title="Close" aria-label="Close">✕</button>
    </div>
    <div class="modal-body" id="groupInfoBody"></div>
    <div class="modal-foot">
      <div class="modal-error" id="groupInfoError" hidden></div>
      <button class="btn btn-danger" id="groupLeaveBtn">Leave group</button>
      <button class="btn btn-ghost" data-close-modal>Close</button>
    </div>
  </div>
</div>

<!-- Forward a message. One message, up to FORWARD_MAX_TARGETS chats, picked
     from the same people-and-groups list as the sidebar. Filled in by app.js. -->
<div class="modal" id="forwardModal" hidden>
  <div class="modal-card forward-card" role="dialog" aria-modal="true" aria-labelledby="forwardTitle">
    <div class="modal-head">
      <h2 id="forwardTitle">Forward message</h2>
      <button class="icon-btn" data-close-modal title="Close" aria-label="Close">✕</button>
    </div>
    <div class="modal-body">
      <div class="forward-preview" id="forwardPreview"></div>
      <input type="text" id="forwardSearch" class="form-input" placeholder="Search chats…"
             autocomplete="off" aria-label="Search chats">
      <div class="form-label forward-count" id="forwardCount"></div>
      <div class="member-picker forward-picker" id="forwardPicker" role="listbox"
           aria-multiselectable="true" aria-label="Chats to forward to"></div>
    </div>
    <div class="modal-foot">
      <div class="modal-error" id="forwardError" hidden></div>
      <button class="btn btn-ghost" data-close-modal>Cancel</button>
      <button class="btn btn-primary" id="forwardSend" disabled>Forward</button>
    </div>
  </div>
</div>

<!-- Markdown preview. The file is fetched through the same attachment gate as
     a download and rendered by app.js from escaped text, so nothing in it can
     run. -->
<div class="modal" id="mdModal" hidden>
  <div class="modal-card md-card" role="dialog" aria-modal="true" aria-labelledby="mdTitle">
    <div class="modal-head">
      <h2 id="mdTitle" class="md-title">Document</h2>
      <a class="btn btn-primary btn-sm md-download" id="mdDownload" href="#" download>
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
        Download
      </a>
      <button class="icon-btn" data-close-modal title="Close" aria-label="Close">✕</button>
    </div>
    <div class="modal-body md-body" id="mdBody"></div>
  </div>
</div>

<?php if (FEATURE_CAMERA): ?>
<!-- Camera. The stream is only requested when this opens, and is stopped on
     close so the recording light never stays on behind a closed dialog. -->
<div class="modal" id="cameraModal" hidden>
  <div class="modal-card camera-card" role="dialog" aria-modal="true" aria-labelledby="cameraTitle">
    <div class="modal-head">
      <h2 id="cameraTitle">Take a photo</h2>
      <button class="icon-btn" data-close-modal title="Close" aria-label="Close">✕</button>
    </div>
    <div class="modal-body camera-body">
      <div class="camera-stage">
        <video id="cameraVideo" playsinline muted autoplay></video>
        <img id="cameraShot" alt="Captured photo" hidden>
        <div class="camera-error" id="cameraError" hidden></div>
      </div>
    </div>
    <div class="modal-foot camera-foot">
      <button class="btn btn-ghost" id="cameraFlip" title="Switch camera" hidden>Flip</button>
      <button class="btn btn-ghost" id="cameraRetake" hidden>Retake</button>
      <button class="btn btn-primary camera-shutter" id="cameraShutter">Capture</button>
      <button class="btn btn-primary" id="cameraUse" hidden>Attach photo</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (FEATURE_GAMIFY_UI): ?>
<!-- ░░░ Leaderboard ░░░
     Two scopes: this month, which anybody can still climb, and all time, which
     rewards the long haul. Filled in by app.js. -->
<div class="modal" id="boardModal" hidden>
  <div class="modal-card board-card" role="dialog" aria-modal="true" aria-labelledby="boardTitle">
    <div class="modal-head">
      <h2 id="boardTitle">Leaderboard</h2>
      <button class="icon-btn" data-close-modal title="Close" aria-label="Close">✕</button>
    </div>

    <div class="board-tabs" id="boardTabs" role="tablist">
      <button class="board-tab active" data-scope="month" role="tab" aria-selected="true">This month</button>
      <button class="board-tab" data-scope="all" role="tab" aria-selected="false">All time</button>
    </div>

    <div class="modal-body board-body" id="boardBody" role="tabpanel"></div>

    <div class="modal-foot">
      <span class="board-note" id="boardNote"></span>
      <button class="btn btn-ghost" data-close-modal>Close</button>
    </div>
  </div>
</div>

<!-- ░░░ Level-up / badge celebration ░░░
     One overlay reused for both. Deliberately dismissible and short-lived: a
     reward that blocks the conversation stops being a reward the second time
     it happens. -->
<div class="cheer" id="cheer" hidden aria-live="polite">
  <div class="cheer-card" role="status">
    <div class="cheer-burst" aria-hidden="true"></div>
    <div class="cheer-icon" id="cheerIcon">🏆</div>
    <div class="cheer-kicker" id="cheerKicker">Level up</div>
    <div class="cheer-title" id="cheerTitle">Level 4</div>
    <div class="cheer-sub" id="cheerSub"></div>
    <button class="btn btn-primary btn-sm" id="cheerClose">Nice</button>
  </div>
</div>
<?php endif; ?>

<?php if ($callsOn): ?>
<!-- The call screen. One element for every stage of a call; data-state
     (outgoing, incoming, connecting, connected, ended) decides which controls
     show, and data-media whether the videos do. Driven by assets/js/call.js. -->
<div class="call-overlay" id="callOverlay" hidden data-state="" data-media="audio"
     role="dialog" aria-modal="true" aria-labelledby="callName">
  <!-- Muted: the sound plays through the <audio> below, which is the element
       the speaker/earpiece switch reroutes. Two elements both playing it
       would be the same voice twice. -->
  <video class="call-remote-video" id="callRemoteVideo" autoplay playsinline muted></video>
  <audio id="callRemoteAudio" autoplay></audio>

  <div class="call-identity">
    <img class="call-avatar" id="callAvatar" src="data:," alt="">
    <div class="call-name" id="callName"></div>
    <div class="call-status" id="callStatus" aria-live="polite"></div>
  </div>

  <video class="call-local-video" id="callLocalVideo" autoplay playsinline muted></video>

  <div class="call-controls call-controls-incoming">
    <button class="call-btn call-btn-end" id="callDeclineBtn" aria-label="Decline">
      <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path transform="rotate(135 12 12)" d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1.9.4 1.8.7 2.7a2 2 0 01-.5 2.1L8 9.8a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c.9.3 1.8.6 2.7.7a2 2 0 011.7 2z"/>
      </svg>
      <span>Decline</span>
    </button>
    <button class="call-btn call-btn-accept" id="callAcceptBtn" aria-label="Accept">
      <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1.9.4 1.8.7 2.7a2 2 0 01-.5 2.1L8 9.8a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c.9.3 1.8.6 2.7.7a2 2 0 011.7 2z"/>
      </svg>
      <span>Accept</span>
    </button>
  </div>

  <div class="call-controls call-controls-active">
    <button class="call-btn" id="callMuteBtn" aria-label="Mute" aria-pressed="false" title="Mute">
      <svg class="when-on" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v1a7 7 0 0014 0v-1"/><line x1="12" y1="18" x2="12" y2="22"/>
      </svg>
      <svg class="when-off" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <line x1="2" y1="2" x2="22" y2="22"/><path d="M9 9v2a3 3 0 005.1 2.1M15 9.3V5a3 3 0 00-5.9-.6"/>
        <path d="M17 16.9A7 7 0 015 11v-1m14 0v1a7 7 0 01-.1 1.2"/><line x1="12" y1="18" x2="12" y2="22"/>
      </svg>
    </button>
    <button class="call-btn call-video-only" id="callCameraBtn" aria-label="Turn camera off" aria-pressed="false" title="Camera">
      <svg class="when-on" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>
      </svg>
      <svg class="when-off" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <line x1="2" y1="2" x2="22" y2="22"/><path d="M16 16v1a2 2 0 01-2 2H3a2 2 0 01-2-2V7a2 2 0 012-2h2m5.7 0H14a2 2 0 012 2v3.3l1 1L23 7v10"/>
      </svg>
    </button>
    <button class="call-btn call-video-only" id="callFlipBtn" aria-label="Switch camera" title="Switch camera">
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M20 7h-3l-2-3H9L7 7H4a2 2 0 00-2 2v9a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/>
        <path d="M9 13a3 3 0 015-2.2M15 13a3 3 0 01-5 2.2"/><polyline points="14 9 14 11 12 11"/><polyline points="10 17 10 15 12 15"/>
      </svg>
    </button>
    <!-- Shown only where the browser lets a page pick the audio output and
         there is more than one to pick from; call.js labels it with the one
         in use. -->
    <button class="call-btn" id="callSpeakerBtn" aria-label="Audio output" title="Audio output" hidden>
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
        <path d="M15.5 8.5a5 5 0 010 7M19 5a10 10 0 010 14"/>
      </svg>
    </button>
    <button class="call-btn call-btn-end" id="callHangupBtn" aria-label="End call" title="End call">
      <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path transform="rotate(135 12 12)" d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1.9.4 1.8.7 2.7a2 2 0 01-.5 2.1L8 9.8a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c.9.3 1.8.6 2.7.7a2 2 0 011.7 2z"/>
      </svg>
    </button>
  </div>
</div>
<?php endif; ?>

<!-- Hidden data passed to JS -->
<script>
const UNI = {
  uid:         <?= (int)$me['id'] ?>,
  username:    <?= json_encode($me['username']) ?>,
  full_name:   <?= json_encode($me['full_name']) ?>,
  avatar:      <?= json_encode(avatar_url($me['avatar'], $me['full_name'] ?: $me['username'])) ?>,
  csrf:        <?= json_encode($token) ?>,
  base:        <?= json_encode(rtrim(base_url(), '/')) ?>,
  loginUrl:    <?= json_encode(base_url('login.php')) ?>,
  swUrl:       <?= json_encode(base_url('sw.js')) ?>,
  pollMs:      <?= MSG_POLL_INTERVAL ?>,
  statusMs:    <?= STATUS_INTERVAL ?>,
  usersMs:     <?= USERS_INTERVAL ?>,
  maxLen:      <?= MAX_MESSAGE_LEN ?>,
  maxForward:  <?= FORWARD_MAX_TARGETS ?>,
  maxFiles:    <?= MAX_FILES_PER_MESSAGE ?>,
  // Files (and bytes) per upload request. The client splits a large batch
  // itself, because PHP's own max_file_uploads is 20 and cannot be raised at
  // runtime — see batchFiles() in app.js.
  uploadBatch:      <?= UPLOAD_BATCH_SIZE ?>,
  uploadBatchBytes: <?= UPLOAD_BATCH_BYTES ?>,
  maxFileSize: <?= MAX_FILE_SIZE ?>,
  maxDocSize:  <?= MAX_DOC_SIZE ?>,
  maxAudioSize:<?= MAX_AUDIO_SIZE ?>,
  maxVoiceSec: <?= MAX_VOICE_SECONDS ?>,
  maxAvatar:   <?= MAX_AVATAR_SIZE ?>,
  hasCamera:   <?= FEATURE_CAMERA ? 'true' : 'false' ?>,
  hasVoice:    <?= FEATURE_VOICE  ? 'true' : 'false' ?>,
  // Any file may be attached except these. The client checks the list so a
  // refusal is instant instead of arriving after a 20 MB upload.
  blockedTypes: <?= json_encode(BLOCKED_EXTS) ?>,
  imageTypes:   <?= json_encode(ALLOWED_IMAGES) ?>,
  codeTypes:    <?= json_encode(CODE_EXTS) ?>,
  isAdmin:     <?= $isAdmin ? 'true' : 'false' ?>,
  tz:          <?= json_encode(TIMEZONE) ?>,
  reactions:   <?= json_encode(REACTION_EMOJIS, JSON_UNESCAPED_UNICODE) ?>,
  editWindow:  <?= MSG_EDIT_WINDOW ?>,
  delWindow:   <?= MSG_DELETE_WINDOW ?>,
  // Levels, streaks and badges. False on a database that has not been migrated
  // for them, which hides the chip, the trophy and the profile section rather
  // than showing a score that would always read zero.
  gamify:      <?= (gamify_available() && FEATURE_GAMIFY_UI) ? 'true' : 'false' ?>,
  gamifyMs:    <?= GAMIFY_POLL_INTERVAL ?>,
  vapidKey:    <?= json_encode($vapidKey) ?>,
  // GIFs, stickers and memes: one picker, one provider, one switch.
  klipyEnabled: <?= klipy_enabled() ? 'true' : 'false' ?>,
  klipyKinds:   <?= json_encode(array_keys(KLIPY_KINDS)) ?>,
  // Voice and video calls: off when the feature flag is, or before
  // setup/install.php has created the tables.
  calls:        <?= $callsOn ? 'true' : 'false' ?>,
  callRingSec:  <?= CALL_RING_TIMEOUT ?>,
};
</script>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php if ($callsOn): ?>
<script src="<?= asset_url('assets/js/call.js') ?>"></script>
<?php endif; ?>
</body>
</html>
