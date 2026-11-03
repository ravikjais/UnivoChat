/* ═══════════════════════════════════════════════════════════
   UnivoChat – Main Application Script
   ═══════════════════════════════════════════════════════════ */

"use strict";

// `Notification` does not exist in every browsing context (older WebViews and
// some insecure-origin mobile browsers). Touching it unguarded here used to
// throw and take the whole script down with it.
const HAS_NOTIFICATIONS =
  typeof window !== "undefined" && "Notification" in window;

// ── State ─────────────────────────────────────────────────────
const State = {
  // A conversation is either with a person or with a group — never both, and
  // chatOpen() is what the rest of the app asks instead of testing either one.
  chatUserId: null, // ID of the user we are currently chatting with
  chatGroupId: null, // ID of the group we are currently chatting in
  chatUser: null, // Full target object (a user, or a group)
  groups: [], // Groups I am in, as the sidebar last saw them
  contacts: [], // Latest contact list, for the member pickers
  groupInfo: null, // Group currently open in the info modal
  sheetMsgId: 0, // Message the touch action sheet is open for
  sheetOpenedAt: 0, // when it opened, so the tap that opened it cannot close it
  recorder: null, // Live MediaRecorder, while a voice note is running
  recStart: 0, // Timestamp the recording began
  recTimer: null,
  recChunks: [],
  pendingVoice: null, // { blob, seconds } staged and waiting for send
  cameraStream: null, // Live camera MediaStream, while the modal is open
  cameraFacing: "environment",
  cameraShot: null, // { blob, url } captured and awaiting confirmation
  lastMsgId: 0, // Highest message ID rendered for the current chat
  initialLoaded: false, // Has the open conversation finished its first load?
  fetching: false, // A message poll is in flight
  fetchPromise: null, // The in-flight poll, so forced fetches can queue
  pollTimer: null,
  statusTimer: null,
  usersTimer: null,
  typingSentAt: 0, // Throttle for outgoing typing pings
  typingAckTimer: null, // Timer for clearing the incoming typing indicator
  pendingFiles: [], // Files staged for upload
  previewUrls: [], // Object URLs to revoke
  sending: false, // a send (possibly several batched requests) is in flight
  sendLabel: "", // what the progress strip calls what is going up
  deferredPrompt: null, // PWA install prompt event
  notifGranted: HAS_NOTIFICATIONS && Notification.permission === "granted",
  unreadCount: 0,
  contactSeen: new Map(), // contactId -> last message id already accounted for
  contactsLoaded: false,
  offline: false,
  netFailures: 0, // consecutive requests that never reached the server
  retryAt: 0, // while offline, background polls wait until this time
  contactsBusy: false, // a contact-list refresh is in flight
  statusBusy: false, // a heartbeat is in flight
  signedOut: false,
  firstMsgId: 0, // oldest message on screen – bounds the reaction sync
  syncedAt: 0, // server clock of the last poll, for picking up edits
  editingId: null, // message currently open in the inline editor
  // Reading backwards through a long conversation. `hasMore` is the server's
  // answer to "is there anything above what you just got?", and is what stops
  // the scroll handler asking again once the beginning has been reached.
  hasMore: false,
  loadingHistory: false,
  // Reading *forwards*. Set when a conversation is opened on one old message —
  // a search result — instead of on its newest page. While it is true there is
  // more below the window than the client has, so the message poll is parked
  // (it would drag in months of backlog three seconds at a time) and the reader
  // is offered a way back to the present.
  hasNewer: false,
  loadingNewer: false,
  anchorId: 0, // message to open centred on, and flash once it is there
  // "At the bottom" is a state the reader is in, not an event. Remembered so it
  // can be re-applied while the pane is still settling: an image that finishes
  // decoding or a bubble that wraps to a second line both grow the conversation
  // after the scroll that was meant to land on the newest message.
  stickBottom: true,
  // The message the composer is currently answering: { id, who, preview, type }.
  replyTo: null,
  pushEnabled: false, // this browser has a stored push subscription
  pushBusy: false,
  pushError: "", // why the last attempt to subscribe did not work
  // Sidebar search. The people come from ajax/get_users.php and the messages
  // from ajax/search.php; both fill the same list, so the query they belong to
  // is kept here and a late answer to a query nobody is typing any more is
  // dropped rather than rendered.
  searchQuery: "",
  searchHits: [],
  searchMore: false, // more matches than the one page that was asked for
  searchBusy: false,

  // Media picker (GIFs / stickers / memes). One search box and one grid serve
  // all three tabs, so there is one set of these rather than three.
  mediaKind: "gif",
  mediaQuery: "", // '' with view 'trending' is the landing view
  mediaView: "trending", // 'trending' | 'search' | 'recent'
  mediaPage: 1, // next page to ask KLIPY for
  mediaMore: true, // KLIPY still has results after the current page
  mediaBusy: false,
  mediaLoaded: false, // the picker has been filled at least once
  mediaAbort: null, // AbortController for the in-flight request
  mediaCols: [], // running masonry column heights, in width-relative units
  mediaCats: {}, // kind -> category strip, fetched once per kind

  // Shared-files panel
  filesOpen: false,
  filesTab: "images",
  filesItems: [], // everything shared here, newest first
  filesLinks: [], // messages that carried a link, newest first
  filesCounts: { images: 0, documents: 0, archives: 0, links: 0 },
  filesToken: "", // conversation the list above belongs to
  filesBusy: false,
  filesCapped: false, // the conversation has more files than one panel shows
  filesStale: false, // an attachment arrived while the panel was open

  // Forward dialog
  forwardMsgId: 0, // the message being forwarded
  forwardKey: "", // idempotency key, kept across retries of the same forward
  forwardPicked: new Set(), // "u12" / "g3" tokens
  forwardDone: new Set(), // destinations that already succeeded under this key
  forwardBusy: false,
  forwardList: [], // every person and group the dialog offers

  // Markdown preview: the request in flight, so a second open cancels it
  mdAbort: null,

  // Group display picture, staged in the "New group" dialog
  groupPhoto: null, // { file, url }
  groupPhotoBusy: false,

  // Levels, streaks and badges
  gamifyTimer: null,
  gamifyBusy: false,
  stats: null, // my latest stats payload
  boardScope: "month",
  boardBusy: false,
  cheerQueue: [], // celebrations waiting their turn on screen
  cheerShowing: false,
};

// ── DOM refs ──────────────────────────────────────────────────
const $ = (id) => document.getElementById(id);
const el = {
  app: $("app"),
  sidebar: $("sidebar"),
  chatArea: $("chatArea"),
  welcomeScreen: $("welcomeScreen"),
  chatWindow: $("chatWindow"),
  contactsList: $("contactsList"),
  searchInput: $("searchInput"),
  searchClear: $("searchClear"),
  messagesContainer: $("messagesContainer"),
  messagesWrap: $("messagesWrap"),
  dateFloat: $("dateFloat"),
  historyTop: $("historyTop"),
  historyLabel: $("historyLabel"),
  replyBar: $("replyBar"),
  replyBarWho: $("replyBarWho"),
  replyBarText: $("replyBarText"),
  replyCancel: $("replyCancel"),
  messageInput: $("messageInput"),
  sendBtn: $("sendBtn"),
  attachBtn: $("attachBtn"),
  fileInput: $("fileInput"),
  attachmentPreview: $("attachmentPreview"),
  attachmentBar: $("attachmentBar"),
  attachmentCount: $("attachmentCount"),
  attachmentClear: $("attachmentClear"),
  attachmentFiles: $("attachmentFiles"),
  sendProgress: $("sendProgress"),
  sendProgressBar: $("sendProgressBar"),
  sendProgressText: $("sendProgressText"),
  sendProgressPct: $("sendProgressPct"),
  jumpBtn: $("jumpBtn"),
  jumpLabel: $("jumpLabel"),
  chatHeader: $("chatHeader"),
  chatAvatar: $("chatAvatar"),
  chatUsername: $("chatUsername"),
  chatStatus: $("chatStatus"),
  backBtn: $("backBtn"),
  typingIndicator: $("typingIndicator"),
  typingAvatar: $("typingAvatar"),
  typingWho: $("typingWho"),
  timeWidget: $("timeWidget"),
  installBtn: $("installBtn"),
  notifBtn: $("notifBtn"),
  notifSlash: $("notifSlash"),
  // Null when KLIPY has no key configured — dashboard.php leaves the whole
  // picker out of the page rather than shipping a button that cannot work.
  mediaBtn: $("mediaBtn"),
  mediaPicker: $("mediaPicker"),
  mpSearch: $("mpSearch"),
  mpSearchClear: $("mpSearchClear"),
  mpClose: $("mpClose"),
  mpTabs: $("mpTabs"),
  mpChips: $("mpChips"),
  mpGrid: $("mpGrid"),

  // Shared files
  filesBtn: $("filesBtn"),
  filesPanel: $("filesPanel"),
  filesBackdrop: $("filesBackdrop"),
  filesClose: $("filesClose"),
  filesTabs: $("filesTabs"),
  filesBody: $("filesBody"),
  filesTitle: $("filesTitle"),

  // Forwarding
  forwardModal: $("forwardModal"),
  forwardPreview: $("forwardPreview"),
  forwardSearch: $("forwardSearch"),
  forwardCount: $("forwardCount"),
  forwardPicker: $("forwardPicker"),
  forwardError: $("forwardError"),
  forwardSend: $("forwardSend"),

  // Markdown preview
  mdModal: $("mdModal"),
  mdTitle: $("mdTitle"),
  mdDownload: $("mdDownload"),
  mdBody: $("mdBody"),

  // Groups
  newGroupBtn: $("newGroupBtn"),
  groupInfoBtn: $("groupInfoBtn"),
  groupModal: $("groupModal"),
  groupName: $("groupName"),
  groupDesc: $("groupDesc"),
  groupPhotoPick: $("groupPhotoPick"),
  groupPhotoInput: $("groupPhotoInput"),
  groupPhotoPreview: $("groupPhotoPreview"),
  groupPhotoEmpty: $("groupPhotoEmpty"),
  groupPhotoClear: $("groupPhotoClear"),
  groupMemberSearch: $("groupMemberSearch"),
  groupMemberPicker: $("groupMemberPicker"),
  groupPickCount: $("groupPickCount"),
  groupCreateBtn: $("groupCreateBtn"),
  groupModalError: $("groupModalError"),
  groupInfoModal: $("groupInfoModal"),
  groupInfoTitle: $("groupInfoTitle"),
  groupInfoBody: $("groupInfoBody"),
  groupInfoError: $("groupInfoError"),
  groupLeaveBtn: $("groupLeaveBtn"),

  // Message actions on touch
  msgSheet: $("msgSheet"),
  msgSheetBackdrop: $("msgSheetBackdrop"),
  msgSheetQuote: $("msgSheetQuote"),
  msgSheetReactions: $("msgSheetReactions"),
  msgSheetActions: $("msgSheetActions"),

  // Camera
  cameraBtn: $("cameraBtn"),
  cameraModal: $("cameraModal"),
  cameraVideo: $("cameraVideo"),
  cameraShot: $("cameraShot"),
  cameraError: $("cameraError"),
  cameraShutter: $("cameraShutter"),
  cameraRetake: $("cameraRetake"),
  cameraUse: $("cameraUse"),
  cameraFlip: $("cameraFlip"),

  // Voice notes
  micBtn: $("micBtn"),
  recordingRow: $("recordingRow"),
  recTime: $("recTime"),
  recStop: $("recStop"),
  recCancel: $("recCancel"),

  // Levels, streaks and badges
  levelRing: $("levelRing"),
  levelPip: $("levelPip"),
  shSub: $("shSub"),
  streakChip: $("streakChip"),
  streakDays: $("streakDays"),
  xpLine: $("xpLine"),
  boardBtn: $("boardBtn"),
  boardModal: $("boardModal"),
  boardTabs: $("boardTabs"),
  boardBody: $("boardBody"),
  boardNote: $("boardNote"),
  cheer: $("cheer"),
  cheerIcon: $("cheerIcon"),
  cheerKicker: $("cheerKicker"),
  cheerTitle: $("cheerTitle"),
  cheerSub: $("cheerSub"),
  cheerClose: $("cheerClose"),
};

// ── The open conversation ─────────────────────────────────────
// One place decides whether a chat is open and how to address it, so every
// caller below works for a person and a group without knowing the difference.
const chatOpen = () => State.chatUserId !== null || State.chatGroupId !== null;
const isGroupChat = () => State.chatGroupId !== null;

/** Query fragment identifying the open conversation to the server. */
const targetQuery = () =>
  isGroupChat() ? `group=${State.chatGroupId}` : `with=${State.chatUserId}`;

/** The same thing as form fields, for POSTs. */
function appendTarget(fd) {
  if (isGroupChat()) fd.append("group_id", State.chatGroupId);
  else fd.append("receiver_id", State.chatUserId);
  return fd;
}

// ── Networking ────────────────────────────────────────────────
const API_BASE = String(UNI.base || "").replace(/\/+$/, "") + "/";

class AuthError extends Error {}

/**
 * How long a GET may go without an answer before it is given up on.
 *
 * A mobile connection that stalls rather than drops leaves fetch() pending for
 * minutes, and the message poll will not start another while one is in flight
 * — so without a ceiling the conversation simply stops updating. POSTs get no
 * ceiling: abandoning a send that may already have arrived would only invite
 * the user to send it twice.
 */
const GET_TIMEOUT_MS = 20000;

/** The longest a background poll waits between attempts while offline. */
const RETRY_MAX_MS = 30000;

function noteNetworkFailure() {
  State.netFailures++;
  // 2s, 4s, 8s … capped: a dead line is not hammered every three seconds by
  // every timer at once, and the first retry still comes quickly.
  State.retryAt = Date.now() + Math.min(RETRY_MAX_MS, 1000 * 2 ** State.netFailures);
  setOffline(true);
}

function noteNetworkSuccess() {
  State.netFailures = 0;
  State.retryAt = 0;
  setOffline(false);
}

/** Should a timer-driven poll sit this tick out? User actions never ask. */
const backingOff = () => State.offline && Date.now() < State.retryAt;

/**
 * Thin fetch wrapper: adds the CSRF header, turns an expired session into a
 * single clean redirect, and never leaves the caller with half-parsed HTML.
 */
async function api(url, opts = {}) {
  const headers = { "X-CSRF-TOKEN": UNI.csrf, ...(opts.headers || {}) };
  const isGet = !opts.method || opts.method === "GET";

  // Our own controller, so a timeout can abort the request; a caller's signal
  // is forwarded into it rather than replaced.
  let timer = null;
  let timedOut = false;
  let signal = opts.signal;
  if (isGet) {
    const ctrl = new AbortController();
    if (signal) {
      if (signal.aborted) ctrl.abort();
      else signal.addEventListener("abort", () => ctrl.abort(), { once: true });
    }
    timer = setTimeout(() => {
      timedOut = true;
      ctrl.abort();
    }, GET_TIMEOUT_MS);
    signal = ctrl.signal;
  }

  let res;
  let text;
  try {
    res = await fetch(API_BASE + url, {
      credentials: "same-origin",
      ...opts,
      headers,
      signal,
    });
    // The service worker answers a failed fetch with a stand-in 503 instead
    // of an error; to us it is the same thing.
    if (res.headers.get("X-Uni-Offline")) throw new TypeError("Offline");
    text = await res.text();
  } catch (err) {
    // An abort is the caller changing its mind — the media search cancels the
    // previous keystroke's request — not the network going away. A timeout
    // is the network going away.
    if (timedOut || err.name !== "AbortError") {
      noteNetworkFailure();
      if (timedOut) throw new Error("The connection timed out.");
    }
    throw err;
  } finally {
    clearTimeout(timer);
  }
  noteNetworkSuccess();

  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch (_) {
    /* not JSON */
  }

  if (data && data.auth === false) {
    handleSignedOut(data.error);
    throw new AuthError(data.error || "Session expired");
  }
  if (!res.ok) {
    if (res.status === 401 || res.status === 403) {
      if (data && data.csrf === false) {
        showToast(
          "Session refreshed",
          "Please reload the page to continue.",
          "",
          6000,
          () => location.reload(),
        );
      }
    }
    throw new Error(
      (data && (data.error || data.message)) ||
        "Request failed (" + res.status + ")",
    );
  }
  if (data === null) throw new Error("Unexpected server response.");

  return data;
}

const apiGet = (url) => api(url);
const apiPost = (url, body) => api(url, { method: "POST", body });

/**
 * POST a body and report how much of it has actually gone out.
 *
 * `fetch()` cannot do this. The half of the Streams specification that would
 * let a request body be observed is not available in any browser this app
 * ships to, so an upload that has to show a percentage goes through
 * XMLHttpRequest instead — the one API that has always been able to answer
 * "how far along is this?".
 *
 * Everything else is deliberately identical to api() above: the same AuthError,
 * the same expired-token toast, the same offline flag. A caller cannot tell the
 * two apart except by the progress it gets.
 *
 * @param {(fraction: number, flushed: boolean) => void} [onProgress]
 *        `fraction` runs 0→1 over the request body. `flushed` turns true when
 *        the last byte has left — everything after that is the server working,
 *        which is a different thing to be waiting for and is shown as such.
 */
function apiUpload(url, body, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", API_BASE + url, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader("X-CSRF-TOKEN", UNI.csrf);

    if (onProgress && xhr.upload) {
      xhr.upload.addEventListener("progress", (e) => {
        if (e.lengthComputable && e.total > 0) onProgress(e.loaded / e.total, false);
      });
      xhr.upload.addEventListener("load", () => onProgress(1, true));
    }

    xhr.addEventListener("load", () => {
      setOffline(false);

      let data = null;
      try {
        data = xhr.responseText ? JSON.parse(xhr.responseText) : null;
      } catch (_) {
        /* not JSON */
      }

      if (data && data.auth === false) {
        handleSignedOut(data.error);
        reject(new AuthError(data.error || "Session expired"));
        return;
      }

      if (xhr.status < 200 || xhr.status >= 300) {
        if (
          (xhr.status === 401 || xhr.status === 403) &&
          data &&
          data.csrf === false
        ) {
          showToast(
            "Session refreshed",
            "Please reload the page to continue.",
            "",
            6000,
            () => location.reload(),
          );
        }
        reject(
          new Error(
            (data && (data.error || data.message)) ||
              "Request failed (" + xhr.status + ")",
          ),
        );
        return;
      }

      if (data === null) {
        reject(new Error("Unexpected server response."));
        return;
      }
      resolve(data);
    });

    xhr.addEventListener("error", () => {
      setOffline(true);
      reject(new Error("The upload could not be completed."));
    });
    xhr.addEventListener("timeout", () =>
      reject(new Error("The upload timed out.")),
    );
    xhr.addEventListener("abort", () => reject(new Error("Upload cancelled.")));

    xhr.send(body);
  });
}

function formData(obj) {
  const fd = new FormData();
  Object.entries(obj).forEach(([k, v]) => fd.append(k, v));
  return fd;
}

function handleSignedOut(message) {
  if (State.signedOut) return;
  State.signedOut = true;
  stopAllTimers();
  showToast(
    "Signed out",
    message || "Your session expired. Redirecting to sign in…",
    "",
    4000,
  );
  setTimeout(() => {
    window.location.href = UNI.loginUrl;
  }, 1500);
}

let connBanner = null;
function setOffline(isOffline) {
  if (isOffline === State.offline) return;
  State.offline = isOffline;

  if (isOffline) {
    if (!connBanner) {
      connBanner = document.createElement("div");
      connBanner.className = "conn-banner";
      connBanner.textContent = "Connection lost — trying to reconnect…";
      document.body.appendChild(connBanner);
    }
  } else if (connBanner) {
    connBanner.remove();
    connBanner = null;
  }
}

// ── Helpers ───────────────────────────────────────────────────
function escHtml(str) {
  const d = document.createElement("div");
  d.textContent = String(str ?? "");
  return d.innerHTML;
}

function escAttr(str) {
  return escHtml(str).replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}

function linkify(text) {
  return escHtml(text).replace(
    /((https?:\/\/|www\.)[^\s<]+)/g,
    (m) =>
      `<a href="${m.startsWith("http") ? m : "https://" + m}" target="_blank" rel="noopener noreferrer">${m}</a>`,
  );
}

// ── Rich composer ─────────────────────────────────────────────
// The composer is a contenteditable div so structured content pasted from Teams,
// ChatGPT, an email, a document or a spreadsheet keeps its shape: lists stay
// lists, paragraphs stay apart, tables stay tables. Everything that leaves it is
// rebuilt from the allow-list below, and the server sanitises it again before it
// is stored.

/** Tags a pasted fragment may keep, mapped to the attributes each one may keep. */
const RICH_TAGS = {
  P: [],
  DIV: [],
  BR: [],
  HR: [],
  B: [],
  STRONG: [],
  I: [],
  EM: [],
  U: [],
  S: [],
  STRIKE: [],
  H1: [],
  H2: [],
  H3: [],
  H4: [],
  H5: [],
  H6: [],
  UL: [],
  OL: [],
  LI: [],
  CODE: [],
  PRE: [],
  BLOCKQUOTE: [],
  TABLE: [],
  THEAD: [],
  TBODY: [],
  TFOOT: [],
  CAPTION: [],
  TR: [],
  TD: ["colspan", "rowspan"],
  TH: ["colspan", "rowspan"],
  A: ["href"],
};

/** Dropped with their contents — unwrapping these would leak script/style text. */
const RICH_DROP = new Set([
  "SCRIPT",
  "STYLE",
  "IFRAME",
  "FRAME",
  "FRAMESET",
  "OBJECT",
  "EMBED",
  "APPLET",
  "LINK",
  "META",
  "BASE",
  "TITLE",
  "HEAD",
  "NOSCRIPT",
  "TEMPLATE",
  "SVG",
  "MATH",
  "FORM",
  "INPUT",
  "BUTTON",
  "SELECT",
  "OPTION",
  "TEXTAREA",
  "LABEL",
  "IMG",
  "PICTURE",
  "SOURCE",
  "AUDIO",
  "VIDEO",
  "CANVAS",
  "MAP",
  "AREA",
]);

/**
 * Markup that makes a message worth storing as HTML. Plain typing only ever
 * produces div/br, and those already survive the plain path as line breaks —
 * which keeps typed URLs clickable, so they are deliberately not listed here.
 */
const RICH_MARKUP =
  "table,ul,ol,li,blockquote,pre,code,hr,h1,h2,h3,h4,h5,h6,b,strong,i,em,u,s,strike,a";

function composerText() {
  return elementText(el.messageInput);
}

/** innerText of an editable node, with the &nbsp; spreadsheets leave behind fixed. */
function elementText(node) {
  return (node.innerText || "").replace(/\u00a0/g, " ").replace(/\s+$/, "");
}

/** Does this editable box hold formatting that plain text could not carry? */
function hasRichMarkup(node) {
  return !!node.querySelector(RICH_MARKUP);
}

function clearComposer() {
  el.messageInput.innerHTML = "";
}

/** What sendMessage() puts on the wire: either a rich fragment or plain text. */
function composerPayload() {
  if (hasRichMarkup(el.messageInput)) {
    return {
      format: "html",
      body: el.messageInput.innerHTML,
      text: composerText().trim(),
    };
  }
  const text = composerText().trim();
  return { format: "plain", body: text, text };
}

/**
 * Reduce a pasted fragment to the allow-list above.
 *
 * Unknown tags are unwrapped rather than deleted, so the words a source wraps in
 * <font>/<span style> survive; only RICH_DROP tags lose their contents. Every
 * style, class, id, script and stray wrapper is dropped on the way in, so what
 * the composer shows is what gets sent.
 *
 * @returns {string} '' when nothing structural is left, so the caller can fall
 *                   back to the plain-text flavour of the same clipboard.
 */
function cleanPastedHtml(html) {
  const doc = new DOMParser().parseFromString(html, "text/html");
  const root = document.createElement("div");
  while (doc.body.firstChild) root.appendChild(doc.body.firstChild);

  cleanRichChildren(root);
  // Unwrapping leaves the halves of a Word bullet \u2014 the "\u00b7" and the spacing
  // behind it \u2014 in separate text nodes; merging them lets the pass below see
  // one marker instead of two fragments.
  root.normalize();
  promoteListParagraphs(root);

  return root.childElementCount && (root.textContent || "").trim() !== ""
    ? root.innerHTML
    : "";
}

/** Recursively strip a node's children down to the allow-list. */
function cleanRichChildren(parent) {
  Array.from(parent.childNodes).forEach((node) => {
    if (node.nodeType === Node.TEXT_NODE) {
      node.nodeValue = (node.nodeValue || "").replace(/\u00a0/g, " ");
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) {
      node.remove();
      return;
    } // comments, CDATA

    const tag = node.tagName;
    if (RICH_DROP.has(tag)) {
      node.remove();
      return;
    }

    cleanRichChildren(node);

    const allowed = RICH_TAGS[tag];
    if (!allowed) {
      unwrapNode(node);
      return;
    } // the tag goes, its contents stay

    cleanRichAttributes(node, allowed);

    // A spreadsheet pads its cells: a text-only cell is tidied the way it always
    // was, while a cell carrying formatting is left exactly as it came.
    if ((tag === "TD" || tag === "TH") && !node.childElementCount) {
      node.textContent = (node.textContent || "").trim();
    }
  });
}

/** Replace an element with its (already cleaned) contents. */
function unwrapNode(node) {
  const parent = node.parentNode;
  if (!parent) return;
  while (node.firstChild) parent.insertBefore(node.firstChild, node);
  parent.removeChild(node);
}

function cleanRichAttributes(node, allowed) {
  const href =
    node.tagName === "A" ? (node.getAttribute("href") || "").trim() : "";

  Array.from(node.attributes).forEach((attr) => {
    const name = attr.name.toLowerCase();

    if (!allowed.includes(name)) {
      node.removeAttribute(attr.name);
      return;
    }

    if (name === "colspan" || name === "rowspan") {
      const span = parseInt(attr.value, 10);
      if (span > 1 && span <= 100) node.setAttribute(name, String(span));
      else node.removeAttribute(name);
    }
  });

  // After the loop, never inside it: a source that listed target before href
  // would otherwise have the pair set and then stripped again.
  if (node.tagName === "A") {
    if (/^(https?:\/\/|mailto:)/i.test(href)) {
      node.setAttribute("href", href);
      node.setAttribute("target", "_blank");
      node.setAttribute("rel", "noopener noreferrer");
    } else {
      node.removeAttribute("href");
    }
  }
}

// A bullet glyph or "1." at the head of a paragraph, plus the spacing that
// separates it from the words \u2014 the shape every mail client uses for a list.
const LIST_BULLET =
  /^[\s\u00a0]*[\u2022\u00b7\u25aa\u25e6\u2023\u2219\u25cb\u25cf*\u2013\u2014-][\s\u00a0]+/;
const LIST_NUMBER = /^[\s\u00a0]*\d{1,3}[.)][\s\u00a0]+/;

/**
 * Word, Outlook and most mail clients do not emit <ul>/<ol>: they emit ordinary
 * paragraphs that open with a bullet glyph or a number. Two or more in a row
 * become a real list; a single one is left alone, since one paragraph starting
 * with a dash is far more likely to be a sentence than a list of one.
 */
function promoteListParagraphs(root) {
  Array.from(root.children).forEach((child) => {
    if (child.tagName === "DIV" || child.tagName === "BLOCKQUOTE")
      promoteListParagraphs(child);
  });

  let run = [];
  let kind = null;

  const flush = () => {
    if (run.length > 1) {
      const list = document.createElement(kind === "number" ? "ol" : "ul");
      run[0].parentNode.insertBefore(list, run[0]);

      run.forEach((para) => {
        const item = document.createElement("li");
        stripListMarker(para, kind === "number" ? LIST_NUMBER : LIST_BULLET);
        while (para.firstChild) item.appendChild(para.firstChild);
        list.appendChild(item);
        para.remove();
      });
    }
    run = [];
    kind = null;
  };

  Array.from(root.children).forEach((node) => {
    if (node.tagName !== "P" && node.tagName !== "DIV") {
      flush();
      return;
    }

    const text = node.textContent || "";
    const found = LIST_BULLET.test(text)
      ? "bullet"
      : LIST_NUMBER.test(text)
        ? "number"
        : null;

    if (!found) {
      flush();
      return;
    }
    if (kind && found !== kind) flush();

    kind = found;
    run.push(node);
  });

  flush();
}

/** Drop the marker from a paragraph on its way into a real list item. */
function stripListMarker(node, pattern) {
  const first = document
    .createTreeWalker(node, NodeFilter.SHOW_TEXT)
    .nextNode();
  if (first) first.nodeValue = first.nodeValue.replace(pattern, "");
}

/**
 * Tab-separated text is what a spreadsheet puts on the clipboard when it offers
 * no HTML flavour at all (some CSV viewers, most terminals, LibreOffice on
 * Linux). Two or more columns on at least one line is treated as a grid.
 */
function tsvToTable(text) {
  const lines = text.replace(/\r\n?/g, "\n").replace(/\n+$/, "").split("\n");
  if (lines.length < 2 && !lines[0].includes("\t")) return "";
  if (!lines.some((line) => line.includes("\t"))) return "";

  const table = document.createElement("table");
  const body = document.createElement("tbody");

  lines.forEach((line) => {
    const row = document.createElement("tr");
    line.split("\t").forEach((value) => {
      const cell = document.createElement("td");
      cell.textContent = value.trim();
      row.appendChild(cell);
    });
    body.appendChild(row);
  });

  table.appendChild(body);
  return table.outerHTML;
}

/** Insert already-cleaned markup at the caret inside an editable element. */
function insertHtmlAtCaret(html, target = el.messageInput) {
  target.focus({ preventScroll: true });

  const selection = window.getSelection();
  if (
    !selection ||
    !selection.rangeCount ||
    !target.contains(selection.anchorNode)
  ) {
    target.insertAdjacentHTML("beforeend", html);
    placeCaretAtEnd(target);
    return;
  }

  const range = selection.getRangeAt(0);
  range.deleteContents();

  const fragment = document.createRange().createContextualFragment(html);
  const last = fragment.lastChild;
  range.insertNode(fragment);

  if (last) {
    const after = document.createRange();
    after.setStartAfter(last);
    after.collapse(true);
    selection.removeAllRanges();
    selection.addRange(after);
  }
}

function insertTextAtCaret(text, target = el.messageInput) {
  target.focus({ preventScroll: true });
  // execCommand is deprecated but is still the only insertion that lands in the
  // browser's own undo stack, which matters in a box people type into.
  if (!document.execCommand("insertText", false, text)) {
    insertHtmlAtCaret(escHtml(text).replace(/\n/g, "<br>"), target);
  }
}

function placeCaretAtEnd(node) {
  const range = document.createRange();
  range.selectNodeContents(node);
  range.collapse(false);

  const selection = window.getSelection();
  selection.removeAllRanges();
  selection.addRange(range);
}

/**
 * Paste into an editable box: structured content keeps its structure, a
 * spreadsheet becomes a table, anything else becomes unstyled text.
 *
 * @returns {boolean} true when the paste was handled and the browser's own
 *                    (style-laden) insertion must be suppressed.
 */
function handleRichPaste(e, target) {
  const data = e.clipboardData;
  if (!data) return false;

  const html = data.getData("text/html");
  const plain = data.getData("text/plain");

  let markup = "";
  if (html) markup = cleanPastedHtml(html);
  if (!markup && plain) markup = tsvToTable(plain);

  // Anything on the clipboard that is a *file* belongs to the attachment
  // handler — a screenshot, a PDF, a spreadsheet, a zip — unless it came with
  // markup (Excel ships a table and a bitmap of it, and the table is the
  // interesting half). Returning false leaves the event to bubble there.
  if (!markup && clipboardFiles(data).length) return false;

  if (markup) {
    e.preventDefault();
    // Excel also puts a bitmap of the selection on the clipboard; without this
    // the document-level image handler would attach it alongside the table.
    e.stopPropagation();
    insertHtmlAtCaret(markup, target);
    return true;
  }

  // Plain paste: drop the source formatting rather than importing a web page.
  if (plain) {
    e.preventDefault();
    e.stopPropagation();
    insertTextAtCaret(plain, target);
    return true;
  }

  return false;
}

/**
 * Every file on a clipboard, named well enough to be sent — each one once.
 *
 * `clipboardData.files` and `clipboardData.items` are two views of the *same*
 * clipboard, so they are never merged: `files` is used whenever it has
 * anything in it (it is the reliable list — `items` misses entries in some
 * browsers when several files are copied at once), and `items` only when
 * `files` is empty, which is the case in a few others.
 *
 * Merging them is what used to paste every screenshot twice. A bitmap on the
 * clipboard has no file behind it, so the browser mints a fresh File object
 * for each accessor — and with it a fresh `lastModified` — which meant the
 * de-duplication key never matched and both copies were attached.
 */
function clipboardFiles(data) {
  const fromFiles = Array.from(data.files || []).filter(Boolean);
  const source = fromFiles.length
    ? fromFiles
    : Array.from(data.items || [])
        .filter((item) => item.kind === "file")
        .map((item) => item.getAsFile())
        .filter(Boolean);

  return source.map(namedImage);
}

function updateTitle(unread) {
  State.unreadCount = Math.max(0, unread | 0);
  document.title =
    State.unreadCount > 0 ? `(${State.unreadCount}) UnivoChat` : "UnivoChat";
}

function isChatVisible() {
  return document.visibilityState === "visible";
}

/**
 * Is the user *at* the app, as opposed to merely having it open somewhere?
 *
 * The difference decides whether a message is announced in the page or has to
 * arrive as a push notification, and conflating the two is what silently cost
 * people their notifications: a browser window sitting behind another
 * application still reports `visible`, so the server was told they were at the
 * keyboard and suppressed every push while they sat in another app all
 * afternoon. Polling still keys off visibility — a background tab should keep
 * itself up to date — but presence needs the focus as well.
 */
function isUserPresent() {
  return document.visibilityState === "visible" && document.hasFocus();
}

// ── Time widget ───────────────────────────────────────────────
// Pinned to the app's timezone (IST), which is also what the server stamps
// every message with — a laptop set to another zone still sees UnivoChat time.
function tickClock() {
  if (!el.timeWidget) return;
  const opts = {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: true,
    timeZone: UNI.tz || "Asia/Kolkata",
  };
  try {
    el.timeWidget.textContent = new Date().toLocaleTimeString("en-IN", opts);
  } catch (_) {
    // A browser without full ICU rejects the timeZone option.
    el.timeWidget.textContent = new Date().toLocaleTimeString([], {
      ...opts,
      timeZone: undefined,
    });
  }
}

// ── Contacts ──────────────────────────────────────────────────
function loadContacts(q = "") {
  const url = q
    ? `ajax/get_users.php?q=${encodeURIComponent(q)}`
    : "ajax/get_users.php";
  State.contactsBusy = true;
  return apiGet(url)
    .finally(() => {
      State.contactsBusy = false;
    })
    .then((data) => {
      if (!data || !Array.isArray(data.users)) return;
      if (typeof data.unread === "number") updateTitle(data.unread);
      if (data.incoming_call && typeof Call !== "undefined") Call.onIncoming(data.incoming_call);

      State.contacts = data.users;
      State.groups = Array.isArray(data.groups) ? data.groups : [];

      notifyFromContacts(data.users, !!q);
      renderContacts(data.users, State.groups, q);
    })
    .catch(() => {});
}

/**
 * People and groups share one list, ordered by whichever spoke last — the same
 * ordering a phone messenger uses, and the reason both arrive in a single poll.
 *
 * The search box filters groups by name client-side; the server already
 * filtered the people.
 */
function renderContacts(users, groups = [], q = "") {
  const needle = q.trim().toLowerCase();
  const rows = [
    ...users.map((u) => ({ ...u, is_group: false })),
    ...groups
      .filter((g) => !needle || String(g.name).toLowerCase().includes(needle))
      .map((g) => ({ ...g, is_group: true })),
  ].sort(
    (a, b) =>
      (b.last_ts || 0) - (a.last_ts || 0) ||
      String(a.full_name || "").localeCompare(String(b.full_name || "")),
  );

  const searching = !!needle;
  const hits = searching && State.searchQuery === q.trim() ? State.searchHits : [];
  const searchingMessages = searching && q.trim().length >= SEARCH_MIN_CHARS;

  if (!rows.length && !hits.length && !State.searchBusy) {
    el.contactsList.innerHTML = searching
      ? `<div class="empty-state">Nothing matched “${escHtml(q.trim())}”.</div>`
      : '<div class="empty-state">No conversations found.</div>';
    return;
  }

  const people = rows
    .map((r) => (r.is_group ? groupRow(r) : contactRow(r)))
    .join("");

  // Unsearched, the list is what it always was: no headings, no sections.
  if (!searching) {
    el.contactsList.innerHTML = people;
    return;
  }

  let html = "";
  if (people) {
    html += '<div class="list-head">Chats</div>' + people;
  }

  if (searchingMessages) {
    html += `<div class="list-head">Messages${
      hits.length ? ` <span class="list-count">${hits.length}${State.searchMore ? "+" : ""}</span>` : ""
    }</div>`;

    if (State.searchBusy && !hits.length) {
      html += '<div class="list-note"><span class="list-spinner"></span>Searching…</div>';
    } else if (!hits.length) {
      html += '<div class="list-note">No messages contain that.</div>';
    } else {
      html += hits.map((hit) => searchRow(hit, q.trim())).join("");
      if (State.searchMore) {
        html += '<div class="list-note">Only the newest matches are shown — keep typing to narrow them.</div>';
      }
    }
  }

  el.contactsList.innerHTML = html;
}

function contactRow(u) {
  return `
    <div class="contact-item ${!isGroupChat() && State.chatUserId === u.id ? "active" : ""}"
         data-uid="${u.id}" role="button" tabindex="0">
      <div class="avatar-wrap avatar-view" data-avatar="${escAttr(u.avatar)}"
           data-avatar-name="${escAttr(u.full_name)}" title="View photo">
        <img src="${escAttr(u.avatar)}" alt="" class="avatar avatar-sm" loading="lazy">
        <span class="online-dot ${u.is_online ? "" : "offline"}"></span>
      </div>
      <div class="contact-info">
        <div class="contact-name">${escHtml(u.full_name)}</div>
        <div class="contact-last">${
          u.last_message
            ? contactTick(u) +
              (u.is_mine ? '<span style="opacity:.7">You: </span>' : "") +
              escHtml(u.last_message)
            : '<em style="opacity:.6">No messages yet</em>'
        }</div>
      </div>
      <div class="contact-meta">
        ${u.last_time ? `<div class="contact-time ${u.unread ? "unread-time" : ""}">${escHtml(u.last_time)}</div>` : ""}
        ${u.unread ? `<div class="unread-badge">${u.unread > 99 ? "99+" : u.unread}</div>` : ""}
      </div>
    </div>`;
}

/**
 * The status of my own last message, in front of its preview — the very ticks
 * its bubble carries, so ✓ and ✓✓ mean the same thing in both places. Nothing
 * for a message somebody sent me: that side is the unread badge's job. The two
 * stay separate — the tick sits in the preview line, the badge in the meta
 * column — because both can be true at once: I replied, and they still have
 * older messages of theirs I have not opened.
 */
function contactTick(row) {
  if (!row.is_mine || row.last_deleted) return "";
  return `<span class="contact-tick" data-tick-for="${row.last_msg_id || 0}">${tick(!!row.last_is_read)}</span>`;
}

/**
 * The other side has read up to `readUpto` in the open conversation: turn the
 * contact row's tick blue now rather than on the next contacts poll, so the
 * list and the bubble change together.
 */
function syncContactTick(userId, readUpto) {
  if (!userId || !readUpto) return;

  const contact = State.contacts.find((u) => u.id === userId);
  if (contact && contact.is_mine && contact.last_msg_id <= readUpto) {
    contact.last_is_read = true;
  }

  const row = el.contactsList.querySelector(`.contact-item[data-uid="${userId}"]`);
  const wrap = row && row.querySelector(".contact-tick");
  if (!wrap || Number(wrap.dataset.tickFor) > readUpto) return;

  const t = wrap.querySelector(".tick");
  if (t && !t.classList.contains("read")) wrap.innerHTML = tick(true);
}

function groupRow(g) {
  // The whole row opens the conversation, photo included: a group picture is
  // chosen by the group, not a face somebody might want to look at on its own.
  return `
    <div class="contact-item ${isGroupChat() && State.chatGroupId === g.id ? "active" : ""}"
         data-gid="${g.id}" role="button" tabindex="0">
      <div class="avatar-wrap">
        ${groupTile(g.name, "avatar-sm", g)}
      </div>
      <div class="contact-info">
        <div class="contact-name">
          ${escHtml(g.name)}
          <span class="group-tag" title="${g.member_count} members">${g.member_count}</span>
        </div>
        <div class="contact-last">${
          g.last_message
            ? contactTick(g) + escHtml(g.last_message)
            : '<em style="opacity:.6">No messages yet</em>'
        }</div>
      </div>
      <div class="contact-meta">
        ${g.last_time ? `<div class="contact-time ${g.unread ? "unread-time" : ""}">${escHtml(g.last_time)}</div>` : ""}
        ${g.unread ? `<div class="unread-badge">${g.unread > 99 ? "99+" : g.unread}</div>` : ""}
      </div>
    </div>`;
}

// ── Group identity ────────────────────────────────────────────
// A group that has been given a display picture shows it; one that has not
// falls back to a coloured tile of its initials. The fallback is drawn by the
// server (see group_avatar_url) and arrives as `avatar` on every group payload,
// so the colour and the initials are decided in exactly one place — these
// helpers are what draws it when no payload is at hand.
const GROUP_COLORS = [
  "#2F5BE8",
  "#25D366",
  "#FFA726",
  "#29B6F6",
  "#EC407A",
  "#26A69A",
  "#7E57C2",
  "#EF5350",
];

function groupInitials(name) {
  const parts = String(name || "?")
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) return "#";
  const first = [...parts[0]][0] || "";
  const last = parts.length > 1 ? [...parts[parts.length - 1]][0] || "" : "";
  return (first + last).toUpperCase();
}

function groupColor(name) {
  let hash = 0;
  for (const ch of String(name || ""))
    hash = (hash * 31 + ch.codePointAt(0)) >>> 0;
  return GROUP_COLORS[hash % GROUP_COLORS.length];
}

/**
 * @param {Object} [group] The group payload, when one is available. Its
 *   `avatar` is used whenever the server supplied one — an uploaded picture, or
 *   the generated initials image — and the locally drawn tile is the fallback
 *   for callers that only know a name.
 */
function groupTile(name, size = "avatar-sm", group = null) {
  const src = group && group.avatar;
  if (src) {
    return `<img src="${escAttr(src)}" alt="" class="avatar group-photo ${size}" loading="lazy">`;
  }
  return `<span class="avatar group-tile ${size}" style="background:${groupColor(name)}"
                aria-hidden="true">${escHtml(groupInitials(name))}</span>`;
}

/** The same tile as an image, for the header's <img id="chatAvatar">. */
function groupAvatarUri(name) {
  const svg =
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">` +
    `<rect width="128" height="128" rx="64" fill="${groupColor(name)}"/>` +
    `<text x="64" y="72" text-anchor="middle" dominant-baseline="middle" fill="#fff"` +
    ` font-family="Segoe UI,system-ui,sans-serif" font-size="48" font-weight="600">` +
    escHtml(groupInitials(name)) +
    `</text></svg>`;
  return "data:image/svg+xml;base64," + btoa(unescape(encodeURIComponent(svg)));
}

/**
 * Toast/notify for messages that arrived in conversations other than the one
 * on screen. The open conversation is handled by the message poll instead, so
 * nothing is announced twice.
 */
function notifyFromContacts(users, isSearch) {
  const first = !State.contactsLoaded;

  users.forEach((u) => {
    const seen = State.contactSeen.get(u.id);
    State.contactSeen.set(u.id, u.last_msg_id);

    if (first || isSearch || seen === undefined) return;
    if (u.last_msg_id <= seen) return;
    if (u.is_mine || !u.unread) return;
    if (!isGroupChat() && u.id === State.chatUserId) return; // handled by fetchMessages()

    announce(
      u,
      u.last_message || (u.last_msg_type === "image" ? "📷 Image" : ""),
    );
  });

  notifyFromGroups(State.groups, isSearch, first);

  if (!isSearch) State.contactsLoaded = true;
}

/**
 * The same for groups. Their last_message already reads "Ana: hello", so the
 * toast title is the group and the body needs no further attribution.
 *
 * Ids are namespaced with a "g" prefix in contactSeen so a group and a user
 * with the same numeric id cannot shadow each other.
 */
function notifyFromGroups(groups, isSearch, first) {
  groups.forEach((g) => {
    const key = "g" + g.id;
    const seen = State.contactSeen.get(key);
    State.contactSeen.set(key, g.last_msg_id);

    if (first || isSearch || seen === undefined) return;
    if (g.last_msg_id <= seen) return;
    if (g.is_mine || !g.unread) return;
    if (isGroupChat() && g.id === State.chatGroupId) return; // handled by fetchMessages()

    announceGroup(g);
  });
}

// ── Open chat ─────────────────────────────────────────────────
function openChat(uid, aroundId = 0) {
  uid = Number(uid);
  if (uid) openConversation({ userId: uid, aroundId: Number(aroundId) || 0 });
}

function openGroup(gid, aroundId = 0) {
  gid = Number(gid);
  if (gid) openConversation({ groupId: gid, aroundId: Number(aroundId) || 0 });
}

/**
 * Switch the whole chat pane to a conversation — with a person or with a group.
 * Everything that is per-conversation is reset here in one place, which is why
 * the two entry points above are three lines each.
 */
function openConversation({ userId = null, groupId = null, aroundId = 0 }) {
  showChatView();

  const sameChat =
    (userId && !isGroupChat() && State.chatUserId === userId) ||
    (groupId && isGroupChat() && State.chatGroupId === groupId);

  if (sameChat) {
    // Already here. A search result still has somewhere to take the reader:
    // the message itself, which may or may not be one of the ones on screen.
    if (aroundId) revealMessage(aroundId);
    return;
  }

  State.chatUserId = userId;
  State.chatGroupId = groupId;
  State.lastMsgId = 0;
  State.initialLoaded = false;
  State.chatUser = null;
  State.firstMsgId = 0;
  State.syncedAt = 0;
  State.editingId = null;
  State.hasMore = false;
  State.loadingHistory = false;
  State.hasNewer = false;
  State.loadingNewer = false;
  State.anchorId = aroundId || 0;
  // Every conversation opens on its newest message. The one exception is a
  // search result, which opens on the message that was searched for.
  State.stickBottom = !aroundId;
  closeEmojiPicker();
  closeMediaPicker();
  closeFilesPanel();
  resetFiles();
  clearPendingFiles();
  discardVoiceNote();
  cancelReply();
  clearComposer();
  hideTyping();
  hideDateFloat();
  setHistoryBanner(false);

  document.querySelectorAll(".contact-item").forEach((item) => {
    item.classList.toggle(
      "active",
      groupId
        ? Number(item.dataset.gid) === groupId
        : Number(item.dataset.uid) === userId,
    );
  });

  el.welcomeScreen.hidden = true;
  el.chatWindow.hidden = false;
  el.messagesContainer.innerHTML = placeholder("Loading…");
  el.chatUsername.textContent = "…";
  el.chatStatus.textContent = "";
  el.chatAvatar.removeAttribute("src");
  el.groupInfoBtn.hidden = !groupId;
  if (typeof syncCallButtons === "function") syncCallButtons();
  renderJumpButton();

  fetchMessages(true);
  startPolling();
  if (!isMobile()) el.messageInput.focus({ preventScroll: true });
}

/**
 * A sidebar row is a person, a group, or a message found in one of the two —
 * and the dataset says which. A message row carries the id it matched, so the
 * conversation opens on that message rather than at its newest page.
 */
function openRow(item) {
  const hit = Number(item.dataset.hitMsg || 0);

  if (item.dataset.hitGid) return openGroup(item.dataset.hitGid, hit);
  if (item.dataset.hitUid) return openChat(item.dataset.hitUid, hit);
  if (item.dataset.gid) return openGroup(item.dataset.gid);
  return openChat(item.dataset.uid);
}

function placeholder(text) {
  return (
    '<div class="empty-chat"><svg width="48" height="48" fill="none" stroke="currentColor" ' +
    'stroke-width="1.5" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>' +
    `</svg><span>${escHtml(text)}</span></div>`
  );
}

function closeChat() {
  stopPolling();
  // The action sheet refers to a message in the conversation being left, so it
  // cannot outlive it. Its backdrop makes it hard to reach this from the sheet
  // itself, but a notification or a deep link can close a chat from underneath.
  closeMsgSheet();
  State.chatUserId = null;
  State.chatGroupId = null;
  State.chatUser = null;
  State.lastMsgId = 0;
  State.initialLoaded = false;
  State.firstMsgId = 0;
  State.syncedAt = 0;
  State.editingId = null;
  State.hasMore = false;
  State.loadingHistory = false;
  State.hasNewer = false;
  State.loadingNewer = false;
  State.anchorId = 0;
  State.stickBottom = true;
  closeEmojiPicker();
  closeFilesPanel();
  resetFiles();
  hideTyping();
  clearPendingFiles();
  discardVoiceNote();
  cancelReply();
  hideDateFloat();
  setHistoryBanner(false);

  el.chatWindow.hidden = true;
  el.welcomeScreen.hidden = false;
  el.groupInfoBtn.hidden = true;
  if (typeof syncCallButtons === "function") syncCallButtons();
  renderJumpButton();
  document
    .querySelectorAll(".contact-item.active")
    .forEach((i) => i.classList.remove("active"));
}

// ── Fetch messages ────────────────────────────────────────────
/**
 * @param {boolean} force  Wait for an in-flight poll and then fetch anyway.
 *                         Used after opening a chat or sending, where the
 *                         caller must see the result; plain polls just skip.
 */
function fetchMessages(force = false) {
  if (!chatOpen()) return Promise.resolve();

  if (State.fetching) {
    if (!force) return Promise.resolve();
    return State.fetchPromise.catch(() => {}).then(() => fetchMessages(true));
  }

  // Remembered so a response that lands after the user has moved on is dropped
  // instead of being poured into the wrong conversation.
  const token = targetQuery();
  const initial = !State.initialLoaded;
  const active = isChatVisible() ? 1 : 0;
  State.fetching = true;

  // A conversation opened from a search result asks for a window around that
  // message instead of the newest page. Only ever on the first fetch: after
  // that the client is reading normally, from whatever it has on screen.
  const anchor = initial && State.anchorId ? `&around_id=${State.anchorId}` : "";

  const query =
    `ajax/get_messages.php?${token}&last_id=${State.lastMsgId}` +
    `&from_id=${State.firstMsgId}&since=${State.syncedAt}&active=${active}${anchor}`;

  State.fetchPromise = apiGet(query)
    .then((data) => {
      if (targetQuery() !== token) return; // user switched chat mid-flight

      if (data.target) {
        State.chatUser = data.target;
        updateChatHeader(data.target);
      }

      if (initial) {
        el.messagesContainer.innerHTML = data.messages.length
          ? ""
          : placeholder(
              isGroupChat()
                ? "No messages in this group yet."
                : "Say hello! 👋",
            );
      }

      // The first page of a search-opened conversation must not land at the
      // bottom on its way to the message that was searched for — one scroll,
      // to one place, is the whole difference between arriving and lurching.
      appendMessages(data.messages, initial, {
        quiet: initial && !!State.anchorId,
      });
      applyEdits(data.edits);
      applyDeleted(data.deleted);
      applyReactions(data.reactions);
      applyReadReceipts(data.read_upto || 0);

      if (typeof data.now === "number") State.syncedAt = data.now;
      if (typeof data.unread === "number") updateTitle(data.unread);
      if (data.incoming_call && typeof Call !== "undefined") Call.onIncoming(data.incoming_call);
      if (data.is_typing) showTyping(data.typists);
      else hideTyping();

      if (initial) {
        // Only the first page reports it: a steady-state poll asks about new
        // messages, and knows nothing about what is above the window.
        State.hasMore = !!data.has_more;
        State.hasNewer = !!data.has_more_after;
        State.initialLoaded = true;

        if (State.anchorId) {
          // Opened on an old message: put it in the middle of the pane and
          // flash it, rather than dropping the reader at the bottom of a page
          // they did not ask for.
          revealMessage(State.anchorId);
          State.anchorId = 0;
        } else {
          stickToBottom();
        }

        setHistoryBanner(State.hasMore, false);
        renderJumpButton();
        fillViewport();
        loadContacts(el.searchInput.value.trim()); // clears the unread badge

        // Parked while the window is somewhere in the middle of the
        // conversation — polling from here would haul in every message since,
        // two hundred at a time, three seconds apart.
        if (State.hasNewer) stopPolling();
      }
    })
    .catch((err) => {
      if (err instanceof AuthError) return;
      if (initial && targetQuery() === token) {
        el.messagesContainer.innerHTML = placeholder(
          "Could not load this conversation.",
        );
      }
    })
    .finally(() => {
      State.fetching = false;
    });

  return State.fetchPromise;
}

// ── Append messages ───────────────────────────────────────────
/**
 * Build one bubble. Shared by the append and the prepend paths, so a message
 * that arrives now and the same message re-read out of history a week later are
 * assembled by the same code.
 */
function buildMessageNode(m) {
  const div = document.createElement("div");
  div.className = `msg ${m.is_mine ? "mine" : "theirs"}`;
  div.dataset.msgid = m.id;
  // Read back by restampDates() and by the floating date indicator, so neither
  // has to re-derive which day a bubble belongs to. The time and the sender are
  // kept for the same reason: markNodeDeleted() rebuilds a bubble in place, and
  // scraping them out of the rendered markup would be guesswork.
  div.dataset.date = m.date || "";
  div.dataset.time = m.time || "";
  div.dataset.sender = m.sender_name || "";
  // Identity rather than name: two people can share a display name, and the
  // run grouping must not merge their messages into one turn.
  div.dataset.senderId = m.sender_id;
  // The avatar comes first in source order. `.msg.mine` is row-reverse, so the
  // same markup puts it on the right for your messages and the left for theirs.
  // .msg-body stacks the bubble and its reaction chips; .msg-tools sits
  // beside them, on the outside of the bubble.
  div.innerHTML =
    messageAvatar(m) +
    `<div class="msg-body">${buildBubble(m)}</div>` +
    // A call record cannot be replied to, reacted to or forwarded.
    (m.type === "call" ? "" : buildTools(m));
  div.querySelectorAll(".msg-rich").forEach(linkifyRich);
  div.querySelectorAll(".voice-note").forEach(wireVoiceNote);

  return div;
}

/**
 * The picture beside a bubble.
 *
 * Three sources, in order of what is actually known: my own picture for my
 * messages, the sender's for a group message (the server sends one per row),
 * and the person I am talking to for everything else. The last case is why the
 * poll sets State.chatUser before appendMessages runs.
 */
function messageAvatar(m) {
  const src = m.is_mine
    ? UNI.avatar
    : m.sender_avatar || (State.chatUser && State.chatUser.avatar) || "";
  if (!src) return "";

  const who = m.is_mine
    ? UNI.full_name
    : m.sender_name || (State.chatUser && State.chatUser.full_name) || "";
  return `<img class="msg-avatar" src="${escAttr(src)}" alt="${escAttr(who)}" loading="lazy">`;
}

/**
 * @param {Object} [opts]
 * @param {boolean} [opts.quiet]  Do not announce, and do not move the view.
 *   Set when filling in the gap between an anchored window and the present:
 *   those messages are backlog, not arrivals, however new their ids are.
 */
function appendMessages(msgs, initial, opts = {}) {
  if (!msgs || !msgs.length) return;
  const wasAtBottom = isNearBottom();
  let added = false;
  let mine = false; // I sent one of these, so the view follows it down
  let files = false; // something arrived that the shared-files panel lists

  msgs.forEach((m) => {
    if (m.id > State.lastMsgId) State.lastMsgId = m.id;

    // Skip anything already on screen: a poll's overlap window can re-deliver a
    // message the client rendered a moment ago.
    if (el.messagesContainer.querySelector(`[data-msgid="${m.id}"]`)) return;

    const ph = el.messagesContainer.querySelector(".empty-chat");
    if (ph) ph.remove();

    const div = buildMessageNode(m);

    // A GIF, sticker or meme only takes up its real height once the CDN answers,
    // which would otherwise leave a just-sent one hanging below the fold. The
    // unforced scroll re-checks at that moment, so a reader who has since
    // scrolled up to read something else is left where they are.
    const remote = div.querySelector(".msg-media-wrap img");
    if (remote)
      remote.addEventListener("load", () => scrollToBottom(), { once: true });

    el.messagesContainer.appendChild(div);
    added = true;
    if (m.is_mine) mine = true;
    if ((m.type === "image" || m.type === "file") && m.file_path) files = true;
    // …and so does a link, for the Links drawer.
    if (m.message && /(https?:\/\/|www\.)\S/i.test(m.message)) files = true;

    // The oldest id on screen bounds the reaction sync on every later poll, and
    // is where the next page of history is asked to start from.
    if (!State.firstMsgId || m.id < State.firstMsgId) State.firstMsgId = m.id;

    // Never announce the backlog that loads when a chat is opened.
    if (!initial && !opts.quiet && !m.is_mine && State.chatUser && !document.hasFocus()) {
      announce(State.chatUser, previewText(m));
    }
  });

  if (added) restampDates();

  // Three reasons to move: the first page of a conversation, a message the
  // reader was already at the bottom for, and — regardless of where they had
  // scrolled to — one they just sent themselves.
  if (added && !opts.quiet && (initial || wasAtBottom || mine || State.stickBottom)) {
    stickToBottom(!initial);
  }
  if (added) renderJumpButton();

  // A file that just landed belongs in the panel. Refreshed straight away when
  // it is on screen, and flagged for the next open when it is not, so the panel
  // is never a list of what was shared as of whenever it was last looked at.
  if (files) {
    if (State.filesOpen) loadFiles();
    else State.filesStale = true;
  }
}

// ── Reading backwards ─────────────────────────────────────────
// A conversation opens on its newest page. Everything before that is fetched a
// page at a time as the reader scrolls up, so the whole history is reachable
// without the first load growing with the length of the friendship.

/** How close to the top of the pane counts as "asking for more". */
const HISTORY_TRIGGER_PX = 320;

function loadOlderMessages() {
  if (!chatOpen() || !State.initialLoaded) return Promise.resolve();
  if (State.loadingHistory || !State.hasMore || !State.firstMsgId)
    return Promise.resolve();

  State.loadingHistory = true;
  setHistoryBanner(true, true);

  const token = targetQuery();
  const query = `ajax/get_messages.php?${token}&before_id=${State.firstMsgId}`;

  return apiGet(query)
    .then((data) => {
      if (targetQuery() !== token) return; // reader switched chat mid-flight

      State.hasMore = !!data.has_more;
      prependMessages(data.messages || []);
    })
    .catch((err) => {
      if (err instanceof AuthError) return;
      // Left switched on so the reader can try again by scrolling; a toast for
      // a background fetch nobody explicitly asked for would be noise.
      showToast(
        "Could not load",
        "Earlier messages could not be fetched. Scroll up to retry.",
        "",
        4000,
      );
    })
    .finally(() => {
      State.loadingHistory = false;
      setHistoryBanner(State.hasMore, false);
      fillViewport();
    });
}

/**
 * Put a page of older messages above what is already on screen without moving
 * the words the reader is looking at.
 *
 * The distance from the *bottom* is what stays fixed: measuring from the top
 * would pin the view to the newly inserted content and yank the conversation
 * away mid-sentence. Smooth scrolling is suspended for the same reason — the
 * correction has to land in the same frame, not animate into place.
 */
function prependMessages(msgs) {
  if (!msgs || !msgs.length) return;

  const wrap = el.messagesWrap;
  const anchor = wrap.scrollHeight - wrap.scrollTop;
  const frag = document.createDocumentFragment();
  let added = false;

  msgs.forEach((m) => {
    if (el.messagesContainer.querySelector(`[data-msgid="${m.id}"]`)) return;

    const div = buildMessageNode(m);
    // History does not slide in: fifty bubbles animating at once reads as a
    // glitch, and they are not new — they have been there all along.
    div.classList.add("msg-quiet");
    frag.appendChild(div);
    added = true;

    if (!State.firstMsgId || m.id < State.firstMsgId) State.firstMsgId = m.id;
  });

  if (!added) return;

  el.messagesContainer.insertBefore(frag, el.messagesContainer.firstChild);
  restampDates();

  const behavior = wrap.style.scrollBehavior;
  wrap.style.scrollBehavior = "auto";
  wrap.scrollTop = wrap.scrollHeight - anchor;
  wrap.style.scrollBehavior = behavior;

  // The freshly prepended window may carry reactions the client has never seen.
  fetchMessages(true);
}

/**
 * The mirror of loadOlderMessages(): one page *newer* than what is on screen.
 *
 * Only ever runs in a conversation opened from a search result, where the
 * bottom of the window is not the bottom of the conversation. Reaching the end
 * of it hands the reader back to the ordinary live view — the poll restarts and
 * the jump button loses its label.
 */
function loadNewerMessages() {
  if (!chatOpen() || !State.initialLoaded) return Promise.resolve();
  if (State.loadingNewer || !State.hasNewer || !State.lastMsgId)
    return Promise.resolve();

  State.loadingNewer = true;
  const token = targetQuery();

  return apiGet(`ajax/get_messages.php?${token}&after_id=${State.lastMsgId}`)
    .then((data) => {
      if (targetQuery() !== token) return; // reader switched chat mid-flight

      State.hasNewer = !!data.has_more_after;
      // Quiet: these are messages the reader is scrolling *towards*, so the
      // view must stay where their eyes are and nothing gets announced.
      appendMessages(data.messages || [], false, { quiet: true });

      if (!State.hasNewer) {
        // Caught up with the present. Live again.
        startPolling();
        fetchMessages(true);
      }
    })
    .catch((err) => {
      if (!(err instanceof AuthError)) State.hasNewer = true;
    })
    .finally(() => {
      State.loadingNewer = false;
      renderJumpButton();
    });
}

/**
 * Put one message in front of the reader, fetching the part of the conversation
 * it lives in if it is not on screen. What a search result opens.
 */
function revealMessage(msgId) {
  msgId = Number(msgId) || 0;
  if (!msgId) return;

  const node = el.messagesContainer.querySelector(`[data-msgid="${msgId}"]`);
  if (node) {
    flashMessage(node);
    return;
  }

  // Not loaded. Re-open the conversation centred on it — cheaper and far more
  // predictable than paging backwards until it happens to appear.
  const userId = State.chatUserId;
  const groupId = State.chatGroupId;
  closeChat();
  openConversation({ userId, groupId, aroundId: msgId });
}

/** Scroll a bubble into the middle of the pane and pulse it once. */
function flashMessage(node) {
  // Let go of the bottom first. Otherwise the pane re-pins itself the moment
  // the next avatar finishes loading and drags the reader straight back down,
  // away from the message they asked to be taken to.
  State.stickBottom = false;

  node.scrollIntoView({
    behavior: REDUCED_MOTION ? "auto" : "smooth",
    block: "center",
  });
  node.classList.remove("flash");
  void node.offsetWidth; // restart the animation on a repeat tap
  node.classList.add("flash");
  setTimeout(() => node.classList.remove("flash"), 1600);
  renderJumpButton();
}

/** The strip at the top of the pane: "load more", "loading", or nothing left. */
function setHistoryBanner(show, busy = false) {
  if (!el.historyTop) return;

  el.historyTop.hidden = !show;
  el.historyTop.classList.toggle("busy", !!busy);
  if (el.historyLabel && show) {
    el.historyLabel.textContent = busy
      ? "Loading earlier messages…"
      : "Scroll up for earlier messages";
  }
}

// ── Date separators ───────────────────────────────────────────
/**
 * Rebuild every "Today / Yesterday / 04 August 2026" divider from the bubbles
 * themselves.
 *
 * Recomputed rather than tracked, because messages now arrive at both ends: a
 * running "last date I stamped" is correct while a conversation only grows
 * downwards and wrong the moment a page of history is prepended above it. One
 * pass over the container is cheap enough to do on every insert and removes a
 * whole class of duplicate-and-missing-divider bugs.
 */
function restampDates() {
  const box = el.messagesContainer;

  box.querySelectorAll(":scope > .date-sep").forEach((sep) => sep.remove());

  let previous = "";
  Array.from(box.querySelectorAll(":scope > .msg[data-date]")).forEach(
    (node) => {
      const date = node.dataset.date;
      if (!date || date === previous) return;
      previous = date;

      const sep = document.createElement("div");
      sep.className = "date-sep";
      sep.innerHTML = `<span>${escHtml(date)}</span>`;
      box.insertBefore(sep, node);
    },
  );

  markMessageRuns();
}

/**
 * Mark every message that is followed by another from the same person on the
 * same day, so only the last of a run shows its avatar.
 *
 * A second DOM-order pass with the same reason to exist as restampDates(): it
 * depends on what a message's neighbours are, and both ends of the list can
 * change. Called from there so the two can never fall out of step.
 */
function markMessageRuns() {
  const rows = Array.from(
    el.messagesContainer.querySelectorAll(":scope > .msg"),
  );

  rows.forEach((node, i) => {
    const next = rows[i + 1];
    // A run must not cross a day boundary — the date divider between them makes
    // two turns out of what would otherwise look like one.
    const sameRun =
      !!next &&
      next.dataset.senderId === node.dataset.senderId &&
      next.dataset.date === node.dataset.date;

    node.classList.toggle("run-mid", sameRun);
  });
}

// ── The floating date indicator ───────────────────────────────
// Which day the reader is currently in, shown over the top of the conversation
// while they scroll and faded out once they stop. The alternative — waiting for
// the next divider to scroll past — leaves somebody paging through months of
// backlog with nothing at all to orient by.

let dateFloatTimer = null;
let dateFloatFrame = null;

function updateDateFloat() {
  if (!el.dateFloat || !chatOpen()) return;

  const nodes = el.messagesContainer.querySelectorAll(".msg[data-date]");
  if (!nodes.length) return;

  const top = el.messagesWrap.getBoundingClientRect().top + 4;

  // The topmost bubble still on screen is the one the reader has in front of
  // them. Found by bisection rather than by walking the list: "is this bubble's
  // bottom edge below the top of the pane?" only flips once as you move down
  // the conversation, and a conversation scrolled to the end of a thousand
  // messages would otherwise measure all thousand of them on every frame.
  let lo = 0;
  let hi = nodes.length - 1;
  let at = hi;

  while (lo <= hi) {
    const mid = (lo + hi) >> 1;
    if (nodes[mid].getBoundingClientRect().bottom > top) {
      at = mid;
      hi = mid - 1;
    } else {
      lo = mid + 1;
    }
  }

  const label = nodes[at].dataset.date || "";
  if (!label) return;

  const span = el.dateFloat.firstElementChild;
  if (span.textContent !== label) span.textContent = label;
  el.dateFloat.classList.add("visible");

  clearTimeout(dateFloatTimer);
  dateFloatTimer = setTimeout(hideDateFloat, 1400);
}

function hideDateFloat() {
  clearTimeout(dateFloatTimer);
  if (el.dateFloat) el.dateFloat.classList.remove("visible");
}

/** One update per animation frame however fast the wheel is turning. */
function onMessagesScroll() {
  // Whether the reader is at the bottom is decided here and nowhere else; the
  // rest of the app only reads the answer. Scrolls the app issued itself do not
  // get a vote — arriving at the bottom is the one thing they can say.
  const atBottom = isNearBottom();
  if (atBottom || Date.now() > autoScrollUntil) State.stickBottom = atBottom;

  renderJumpButton();
  if (State.hasNewer && atBottom) loadNewerMessages();

  if (dateFloatFrame) return;
  dateFloatFrame = requestAnimationFrame(() => {
    dateFloatFrame = null;
    updateDateFloat();
    if (el.messagesWrap.scrollTop < HISTORY_TRIGGER_PX) loadOlderMessages();
  });
}

/**
 * Keep loading until the pane can actually be scrolled.
 *
 * Scrolling is what asks for more history — which leaves a hole: on a tall
 * screen a page of short messages may not fill the pane at all, and a
 * conversation that cannot scroll fires no scroll events, so the rest of it
 * would be unreachable. This closes it, and stops as soon as there is
 * something to scroll or there is nothing left above.
 */
function fillViewport() {
  if (!State.hasMore || State.loadingHistory) return;

  const wrap = el.messagesWrap;
  if (wrap.scrollHeight > wrap.clientHeight) return; // the reader can take it from here

  loadOlderMessages();
}

/**
 * In a group, somebody else's bubble is labelled with their name — bubble
 * alignment says "not me", which is not enough in a room of eight people. The
 * server only sends sender_name for group messages, so this is self-limiting.
 */
function senderLabel(m) {
  return !m.is_mine && m.sender_name
    ? `<div class="msg-sender" style="color:${groupColor(m.sender_name)}">${escHtml(m.sender_name)}</div>`
    : "";
}

/**
 * The three message types that hold a KLIPY address instead of a file.
 *
 * `tag` is the corner label every client puts on an animation so it is not
 * mistaken for a photo; a sticker has none because it has no bubble to put one
 * in the corner of.
 */
const MEDIA_BUBBLES = {
  gif: { cls: "gif-bubble", tag: "GIF", alt: "GIF" },
  sticker: { cls: "sticker-bubble", tag: "", alt: "Sticker" },
  meme: { cls: "meme-bubble", tag: "MEME", alt: "Meme" },
};

/**
 * The quoted strip at the head of a reply.
 *
 * Clicking it walks back to the message being answered — which is the whole
 * point of a quote in a long thread, and why it carries the id rather than just
 * the words.
 */
function replyQuote(m) {
  const r = m.reply;
  if (!r) return "";

  return `
    <button class="msg-quote" type="button" data-goto="${r.id}"
            title="Go to the message this replies to">
      <span class="msg-quote-who">${escHtml(r.who)}</span>
      <span class="msg-quote-text">${escHtml(r.preview || "Message")}</span>
    </button>`;
}

function buildBubble(m) {
  const who = senderLabel(m);
  const fwd = forwardedLabel(m);
  const quote = replyQuote(m);
  // Words sent with an attachment, drawn under it inside the same bubble.
  const caption = attachmentCaption(m);

  // A finished call: what happened, how long, and a tap to call back.
  if (m.type === "call" && !m.deleted) {
    const c = callLogInfo(m);
    return `
      <button type="button" class="msg-bubble call-bubble ${c.missed ? "call-missed" : ""}"
              data-call-media="${c.media}" title="Call again">
        <span class="call-bubble-icon" aria-hidden="true">${c.media === "video" ? "📹" : "📞"}</span>
        <span class="call-bubble-text">
          <span class="call-bubble-title">${escHtml(c.title)}</span>
          <span class="call-bubble-sub">${escHtml(c.sub ? c.sub + " · " + m.time : m.time)}</span>
        </span>
      </button>`;
  }

  // A message its sender took back. Everything it carried is already gone from
  // the database — the text, the file name, the bytes — so there is nothing
  // here but the fact that something used to be.
  if (m.deleted) {
    return `
      <div class="msg-bubble deleted-bubble">
        ${who}
        <span class="msg-deleted">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="9"/><line x1="6.3" y1="6.3" x2="17.7" y2="17.7"/>
          </svg>
          ${m.is_mine ? "You deleted this message" : "This message was deleted"}
        </span>
        <div class="msg-time">${escHtml(m.time)}</div>
      </div>`;
  }

  // A voice note is a player, not a download: a bar you can scrub, the length
  // beside it, and nothing to save unless you go looking.
  if (m.type === "audio" && m.file_path) {
    const seconds = voiceSeconds(m.file_name);
    return `
      <div class="msg-bubble voice-bubble">
        ${who}${fwd}${quote}
        <div class="voice-note" data-src="${escAttr(m.file_path)}" data-seconds="${seconds}">
          <button class="voice-play" type="button" aria-label="Play voice message">
            <svg class="ico-play" width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            <svg class="ico-pause" width="16" height="16" fill="currentColor" viewBox="0 0 24 24" hidden><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
          </button>
          <input class="voice-seek" type="range" min="0" max="1000" value="0" step="1"
                 aria-label="Seek within voice message">
          <span class="voice-time">${escHtml(clockText(seconds))}</span>
        </div>
        ${caption}
        <div class="msg-time">${escHtml(m.time)}${m.is_mine ? tick(m.is_read) : ""}</div>
      </div>`;
  }

  // A GIF, sticker or meme is served straight from KLIPY's CDN, so there is no
  // local file to offer for download — tapping it opens the lightbox instead.
  //
  // A sticker gets no bubble behind it and no corner tag: it is meant to read as
  // something sitting on the conversation rather than as a picture that was
  // sent, which is how every other client draws one.
  if (MEDIA_BUBBLES[m.type] && m.file_path) {
    const look = MEDIA_BUBBLES[m.type];

    return `
      <div class="msg-bubble ${look.cls}">
        ${who}${fwd}${quote}
        <div class="msg-media-wrap" data-full="${escAttr(m.file_path)}">
          <img src="${escAttr(m.file_path)}" alt="${escAttr(m.file_name || look.alt)}" loading="lazy">
          ${look.tag ? `<span class="media-tag">${look.tag}</span>` : ""}
        </div>
        ${caption}
        <div class="msg-time">${escHtml(m.time)}${m.is_mine ? tick(m.is_read) : ""}</div>
      </div>`;
  }

  // A document is a download, not a preview: the card names it, sizes it and
  // links straight at the stored file. `download` keeps the name the sender
  // chose, which the random on-disk name has thrown away.
  //
  // Markdown is the exception. Its card opens a formatted preview in the app
  // (data-md), and only the arrow on the right still downloads — a long
  // message that became a .md file should read like a message, not land in a
  // Downloads folder.
  if (m.type === "file" && m.file_path) {
    const name = m.file_name || "file";
    const look = docLook(fileExt(name));
    const md = isMarkdownName(name);
    const sub = [md ? "Markdown" : look.label, m.file_size]
      .filter(Boolean)
      .join(" · ");

    return `
      <div class="msg-bubble file-bubble">
        ${who}${fwd}${quote}
        <a class="msg-file ${look.cls}${md ? " md-file" : ""}" href="${escAttr(m.file_path)}"
           download="${escAttr(name)}"
           ${md ? `data-md="${escAttr(name)}" title="Preview ${escAttr(name)}"` : `title="Download ${escAttr(name)}"`}>
          <span class="file-badge">${escHtml(look.label)}</span>
          <span class="file-meta">
            <span class="file-name">${escHtml(name)}</span>
            <span class="file-sub">${escHtml(sub)}${md ? ' · <span class="file-open">Tap to preview</span>' : ""}</span>
          </span>
          <span class="file-dl"${md ? ` title="Download ${escAttr(name)}"` : ""}>
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
              <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
          </span>
        </a>
        ${caption}
        <div class="msg-time">${escHtml(m.time)}${m.is_mine ? tick(m.is_read) : ""}</div>
      </div>`;
  }

  // An image keeps its name under the picture: the URL is an opaque token now,
  // so the name row is the only place "site-plan-v3.png" is still readable —
  // and it is what the download is saved as. Any caption the sender wrote goes
  // underneath it.
  if (m.type === "image" && m.file_path) {
    const name = m.file_name || "";
    const sub = [fileExt(name).toUpperCase(), m.file_size]
      .filter(Boolean)
      .join(" · ");

    // The row is rendered even for an old image that never recorded a name, so
    // the download button does not quietly disappear from those bubbles.
    const meta = `
      <div class="msg-image-meta">
        <span class="file-meta">
          ${name ? `<span class="file-name">${escHtml(name)}</span>` : ""}
          ${sub ? `<span class="file-sub">${escHtml(sub)}</span>` : ""}
        </span>
        <a href="${escAttr(m.file_path)}" download="${escAttr(name)}" class="img-download"
           title="Download${name ? " " + escAttr(name) : ""}">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
        </a>
      </div>`;

    return `
      <div class="msg-bubble image-bubble">
        ${who}${fwd}${quote}
        <div class="msg-image-wrap" data-full="${escAttr(m.file_path)}" data-name="${escAttr(name)}">
          <img src="${escAttr(m.file_path)}" alt="${escAttr(name || "image")}" loading="lazy">
        </div>
        ${meta}
        ${caption}
        <div class="msg-time">${escHtml(m.time)}${m.is_mine ? tick(m.is_read) : ""}</div>
      </div>`;
  }

  return `
    <div class="msg-bubble">
      ${who}${fwd}${quote}
      ${messageBody(m)}
      <div class="msg-time">${m.edited ? '<span class="msg-edited">edited</span>' : ""}${escHtml(m.time)}${m.is_mine ? tick(m.is_read) : ""}</div>
    </div>`;
}

/**
 * The body of a text message. `format: 'html'` is pasted content that the server
 * already reduced to a fixed tag allow-list, so it goes in as markup; everything
 * else is escaped and only then linkified, and keeps its line breaks through
 * `white-space: pre-wrap` on .msg-text.
 */
function messageBody(m) {
  return m.format === "html"
    ? `<div class="msg-rich">${m.message || ""}</div>`
    : `<span class="msg-text">${linkify(m.message || "")}</span>`;
}

/**
 * The words sent with an attachment. They travel in the attachment's own row
 * (there is no second text message), so they are drawn inside its bubble,
 * under the picture, card or player, and go wherever that bubble goes.
 */
function attachmentCaption(m) {
  if (m.type === "text" || !m.message) return "";
  return `<div class="msg-caption">${messageBody(m)}</div>`;
}

const FORWARD_ICON = `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
    <polyline points="15 17 20 12 15 7"/><path d="M4 18v-2a4 4 0 014-4h12"/>
  </svg>`;

/** The "Forwarded" line at the head of a forwarded message, on both sides. */
function forwardedLabel(m) {
  return m.forwarded
    ? `<div class="msg-forwarded">${FORWARD_ICON}<span>Forwarded</span></div>`
    : "";
}

/** Opened in the Markdown preview rather than downloaded. */
function isMarkdownName(name) {
  const ext = fileExt(name);
  return ext === "md" || ext === "markdown";
}

// ── Voice notes: playback ─────────────────────────────────────
/** "0:07" – also the label a recording shows while it is still running. */
function clockText(seconds) {
  const s = Math.max(0, Math.round(Number(seconds) || 0));
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`;
}

/**
 * The length the sender recorded, parsed back out of the stored file name.
 *
 * A WebM produced by MediaRecorder is streamed, so it carries no duration in
 * its header: asking the <audio> element gives Infinity until the whole thing
 * has been played through. Writing the number down at send time is what lets a
 * bubble show "0:07" before anybody presses play.
 */
function voiceSeconds(fileName) {
  const m = /voice-(\d+)s/.exec(String(fileName || ""));
  return m ? Number(m[1]) : 0;
}

/**
 * Wire up one voice-note bubble.
 *
 * The <audio> is created on first play, not at render: a conversation with
 * thirty voice notes in it would otherwise open thirty media elements, and
 * mobile browsers cap how many they will decode at once.
 */
function wireVoiceNote(node) {
  const btn = node.querySelector(".voice-play");
  const seek = node.querySelector(".voice-seek");
  const label = node.querySelector(".voice-time");
  // What the sender's own clock said, used until the browser knows better.
  const total = Number(node.dataset.seconds) || 0;
  const stated = clockText(total);
  let audio = null;

  const setPlaying = (playing) => {
    node.classList.toggle("playing", playing);
    btn.querySelector(".ico-play").hidden = playing;
    btn.querySelector(".ico-pause").hidden = !playing;
    btn.setAttribute(
      "aria-label",
      playing ? "Pause voice message" : "Play voice message",
    );
  };

  const build = () => {
    audio = new Audio(node.dataset.src);
    audio.preload = "metadata";

    audio.addEventListener("timeupdate", () => {
      const dur =
        Number.isFinite(audio.duration) && audio.duration > 0
          ? audio.duration
          : 0;
      if (dur)
        seek.value = String(Math.round((audio.currentTime / dur) * 1000));
      label.textContent = clockText(audio.currentTime);
    });
    audio.addEventListener("ended", () => {
      setPlaying(false);
      seek.value = "0";
      label.textContent = stated;
    });
    audio.addEventListener("pause", () => setPlaying(false));
    audio.addEventListener("play", () => {
      // One at a time: starting a second note stops the first, the way every
      // messenger behaves and the only way the pause buttons stay honest.
      document.querySelectorAll(".voice-note.playing").forEach((other) => {
        if (other !== node) other.querySelector(".voice-play")?.click();
      });
      setPlaying(true);
    });
    audio.addEventListener("error", () => {
      setPlaying(false);
      label.textContent = "unavailable";
    });
    return audio;
  };

  btn.addEventListener("click", () => {
    if (!audio) build();
    if (audio.paused)
      audio.play().catch(() => {
        label.textContent = "unavailable";
      });
    else audio.pause();
  });

  seek.addEventListener("input", () => {
    if (!audio) build();
    const dur =
      Number.isFinite(audio.duration) && audio.duration > 0
        ? audio.duration
        : total;
    if (dur) audio.currentTime = (Number(seek.value) / 1000) * dur;
  });
}

/** One-line summary of a message for a toast or a system notification. */
/**
 * A call record, read from the JSON the server stored in it, from this side of
 * the call: the caller sees "No answer" where the person called sees "Missed".
 */
function callLogInfo(m) {
  let d = {};
  try {
    d = JSON.parse(m.message || "{}") || {};
  } catch (_) {
    /* an unreadable record still renders as a call */
  }
  const media = d.media === "video" ? "video" : "audio";
  const kind = media === "video" ? "video call" : "voice call";
  const secs = Math.max(0, Number(d.duration) || 0);
  const dur = `${Math.floor(secs / 60)}:${String(secs % 60).padStart(2, "0")}`;
  const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);

  switch (d.status) {
    case "ended":
      return { media, missed: false, title: cap((m.is_mine ? "outgoing " : "incoming ") + kind), sub: dur };
    case "rejected":
      return { media, missed: false, title: m.is_mine ? "Call declined" : "Declined " + kind, sub: "" };
    case "failed":
      return { media, missed: false, title: cap(kind) + " failed", sub: "" };
    case "busy":
      return { media, missed: !m.is_mine, title: m.is_mine ? "Busy" : "Missed " + kind, sub: "" };
    default: // missed, cancelled
      return { media, missed: !m.is_mine, title: m.is_mine ? "No answer" : "Missed " + kind, sub: "" };
  }
}

function previewText(m) {
  if (m.deleted) return "🚫 This message was deleted";
  if (m.type === "call") {
    const c = callLogInfo(m);
    return (c.media === "video" ? "📹 " : "📞 ") + c.title;
  }
  if (m.type === "gif") return "🎞️ GIF";
  if (m.type === "sticker") return "🩷 Sticker";
  if (m.type === "meme") return "😂 Meme";

  // An attachment says what its caption says, where it has one.
  const said = m.type !== "text" && m.message ? flatText(m) : "";
  if (m.type === "image") return "📷 " + (said || "Image");
  if (m.type === "audio") return "🎤 " + (said || "Voice message");
  if (m.type === "file") return "📎 " + (said || m.file_name || "File");
  return flatText(m);
}

/** A message body (or caption) as one line of plain text. */
function flatText(m) {
  if (m.format !== "html") return m.message || "";

  // Markup would be unreadable in a notification; a pasted grid gets a label.
  if (/<table[\s>]/i.test(m.message || "")) return "📊 Table";

  // textContent alone would run the blocks together — "agenda:Budget review" —
  // so the same walk the copy button uses puts the gaps back before flattening.
  const box = document.createElement("div");
  box.innerHTML = m.message || "";
  return richToText(box).replace(/\s+/g, " ").trim();
}

/**
 * Hover/tap controls: reply to anything, react to anything, copy any text,
 * edit and delete your own.
 *
 * A deleted message gets none of them — there is nothing left to answer,
 * decorate, copy or take back a second time.
 */
function buildTools(m) {
  if (m.deleted) return '<div class="msg-tools"></div>';

  const reply = `<button class="msg-tool" data-reply="${m.id}" title="Reply" aria-label="Reply to this message">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 00-4-4H4"/>
      </svg>
    </button>`;

  const react = `<button class="msg-tool" data-react="${m.id}" title="React" aria-label="React">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/>
        <line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>
      </svg>
    </button>`;

  // Anything that can be seen can be passed on: text, a table, a photo, a
  // document, an archive, a voice note, a GIF — with its caption.
  const forward = `<button class="msg-tool" data-forward="${m.id}" title="Forward" aria-label="Forward message">
      ${FORWARD_ICON.replace(/width="13" height="13"/, 'width="15" height="15"')}
    </button>`;

  // A caption is text like any other, and copies the same way.
  const copy =
    m.type === "text" || m.message
      ? `<button class="msg-tool" data-copy="${m.id}" title="Copy" aria-label="Copy message">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <rect x="9" y="9" width="12" height="12" rx="2"/>
          <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
        </svg>
      </button>`
      : "";

  const edit =
    m.is_mine && m.type === "text" && m.can_edit
      ? `<button class="msg-tool" data-edit="${m.id}" title="Edit" aria-label="Edit">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4z"/>
        </svg>
      </button>`
      : "";

  // Deleting is for everybody in the conversation, so it is styled as the one
  // destructive action in the row rather than sitting quietly among the rest.
  const del =
    m.is_mine && m.can_delete
      ? `<button class="msg-tool msg-tool-danger" data-delete="${m.id}" title="Delete" aria-label="Delete message">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
        </svg>
      </button>`
      : "";

  return `<div class="msg-tools">${reply}${react}${forward}${copy}${edit}${del}</div>`;
}

// ── Message actions on a touch screen ─────────────────────────
/**
 * The same actions as the hover row, as a sheet from the bottom of the screen.
 *
 * A phone has no hover, and the previous answer — tap a bubble to reveal the
 * same 26px circles in a column beside it — put five targets well under the
 * 44px minimum into 62px of width, on top of the message they belonged to. The
 * sheet gives each one a full-width row, a label, and nothing to mis-tap.
 *
 * What it offers is read back out of the bubble's own `.msg-tool` buttons
 * rather than re-derived from the message: those already encode exactly what
 * this person may do to this message, and they are rebuilt whenever the message
 * is (an edit, a delete). So the sheet cannot drift from what a desktop shows,
 * and a deleted message — whose tool row is emptied — opens no sheet at all.
 */
const SHEET_LABELS = {
  reply: "Reply",
  forward: "Forward",
  copy: "Copy text",
  edit: "Edit message",
  delete: "Delete message",
};

function openMsgSheet(node) {
  if (!el.msgSheet || !node) return;

  // Every action this message actually has, in the order the hover row uses.
  // `react` is dropped: the emoji are along the top of the sheet instead.
  const tools = [...node.querySelectorAll(".msg-tool")].filter(
    (b) => !b.hasAttribute("data-react"),
  );
  const msgId = Number(node.dataset.msgid);
  if (!msgId) return;

  // Nothing to offer and nothing to react to — a deleted message.
  const canReact = !!node.querySelector("[data-react]");
  if (!tools.length && !canReact) return;

  closeMsgSheet();

  // Which message this is about. Without it the sheet is a set of verbs with no
  // object, which on a screen this small is genuinely ambiguous.
  //
  // dataset.sender is only filled in for group messages — in a one-to-one chat
  // the server does not repeat the name on every row, so it comes from whoever
  // the conversation is with.
  const who = node.classList.contains("mine")
    ? "You"
    : node.dataset.sender ||
      (State.chatUser && (State.chatUser.full_name || State.chatUser.name)) ||
      "Message";
  el.msgSheetQuote.innerHTML =
    `<span class="msg-sheet-who">${escHtml(who)}</span>` +
    `<span class="msg-sheet-preview">${escHtml(sheetPreview(node))}</span>`;

  // Reactions first: it is the most common thing anybody wants, and putting it
  // at the top means it is also the closest to the thumb.
  const mine = new Set(
    [...node.querySelectorAll(".reaction-chip.mine")].map(
      (c) => c.dataset.emoji,
    ),
  );
  el.msgSheetReactions.innerHTML = canReact
    ? (UNI.reactions || [])
        .map(
          (emoji) =>
            `<button class="msg-sheet-emoji${mine.has(emoji) ? " mine" : ""}" data-sheet-react="${escAttr(emoji)}"
                     aria-label="React ${escAttr(emoji)}"${mine.has(emoji) ? ' aria-pressed="true"' : ""}>${escHtml(emoji)}</button>`,
        )
        .join("")
    : "";
  el.msgSheetReactions.hidden = !canReact;

  // One row per action, carrying the button's own icon so the sheet and the
  // hover row cannot end up illustrated differently.
  el.msgSheetActions.innerHTML = tools
    .map((btn) => {
      const kind = ["reply", "forward", "copy", "edit", "delete"].find((k) =>
        btn.hasAttribute(`data-${k}`),
      );
      if (!kind) return "";
      return `<button class="msg-sheet-action${kind === "delete" ? " danger" : ""}" data-sheet-do="${kind}">
                ${btn.innerHTML}<span>${SHEET_LABELS[kind]}</span>
              </button>`;
    })
    .join("");

  State.sheetMsgId = msgId;
  State.sheetOpenedAt = Date.now();
  el.msgSheetBackdrop.hidden = false;
  el.msgSheet.hidden = false;
  // Painted first, then animated, or the sheet would jump straight to its
  // resting position without the slide. Only if it is still this message's
  // sheet by then: a close in between used to be undone here, leaving a sheet
  // on screen that belonged to no message and whose every action did nothing.
  requestAnimationFrame(() => {
    if (State.sheetMsgId === msgId) el.msgSheet.classList.add("open");
  });
  node.classList.add("sheet-target");
}

/** A short, plain-text hint of what the message says. */
function sheetPreview(node) {
  const bubble = node.querySelector(".msg-bubble");
  if (!bubble) return "";
  const clone = bubble.cloneNode(true);
  clone
    .querySelectorAll(".msg-time, .msg-quote, .msg-sender, .msg-forwarded")
    .forEach((n) => n.remove());
  const text = (clone.textContent || "").replace(/\s+/g, " ").trim();
  if (text) return text.length > 60 ? text.slice(0, 60) + "…" : text;

  // An attachment with no caption still needs a name.
  if (node.querySelector(".voice-note")) return "Voice note";
  if (node.querySelector(".msg-file")) return "Attachment";
  if (node.querySelector("img")) return "Photo";
  return "";
}

function closeMsgSheet() {
  if (!el.msgSheet || el.msgSheet.hidden) return;
  el.msgSheet.classList.remove("open");
  el.msgSheetBackdrop.hidden = true;
  el.messagesContainer
    .querySelectorAll(".sheet-target")
    .forEach((n) => n.classList.remove("sheet-target"));
  State.sheetMsgId = 0;

  // Kept in the DOM until the slide-out finishes, so it does not vanish
  // mid-animation. Reduced-motion users get no transition, hence the fallback.
  const sheet = el.msgSheet;
  window.setTimeout(() => {
    if (!sheet.classList.contains("open")) sheet.hidden = true;
  }, 220);
}

function tick(isRead) {
  return isRead
    ? '<span class="tick read" title="Read">✓✓</span>'
    : '<span class="tick" title="Sent">✓</span>';
}

// ── Copy a message ────────────────────────────────────────────
// One click puts the whole message on the clipboard twice over: as HTML, so a
// paste into Word, Teams or an email arrives with its lists, tables and
// emphasis intact, and as text, so a paste into a plain box still reads as a
// list instead of one long line.

const COPY_TICK = `<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
    <polyline points="20 6 9 17 4 12"/>
  </svg>`;

function copyMessage(msgId, btn) {
  const node = el.messagesContainer.querySelector(`[data-msgid="${msgId}"]`);
  const rich = node && node.querySelector(".msg-bubble .msg-rich");
  const flat = node && node.querySelector(".msg-bubble .msg-text");
  if (!rich && !flat) return;

  const text = rich ? richToText(rich) : flat.textContent || "";
  const html = rich ? rich.innerHTML : escHtml(text).replace(/\n/g, "<br>");

  writeToClipboard(text, html)
    .then(() => flashCopied(btn))
    .catch(() =>
      showToast(
        "Not copied",
        "The clipboard is not available in this browser.",
        "",
        4000,
      ),
    );
}

function writeToClipboard(text, html) {
  if (navigator.clipboard && window.ClipboardItem) {
    const item = new ClipboardItem({
      "text/plain": new Blob([text], { type: "text/plain" }),
      "text/html": new Blob([html], { type: "text/html" }),
    });
    return navigator.clipboard
      .write([item])
      .catch(() => legacyCopy(text, html));
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    return navigator.clipboard
      .writeText(text)
      .catch(() => legacyCopy(text, html));
  }
  return legacyCopy(text, html);
}

/**
 * Fallback for browsers without the async clipboard — and for plain-HTTP
 * origins, where it is unavailable however new the browser is. Copying a
 * selection carries both flavours too, so formatting survives this path as well.
 */
function legacyCopy(text, html) {
  const holder = document.createElement("div");
  holder.setAttribute("aria-hidden", "true");
  holder.contentEditable = "true";
  holder.innerHTML = html;
  // Off-screen but still rendered: a display:none element cannot be selected.
  holder.style.cssText =
    "position:fixed;top:0;left:-9999px;white-space:pre-wrap;opacity:0";
  document.body.appendChild(holder);

  const selection = window.getSelection();
  const previous =
    selection && selection.rangeCount ? selection.getRangeAt(0) : null;
  const range = document.createRange();
  range.selectNodeContents(holder);
  selection.removeAllRanges();
  selection.addRange(range);

  let ok = false;
  try {
    ok = document.execCommand("copy");
  } catch (_) {
    ok = false;
  }

  selection.removeAllRanges();
  if (previous) selection.addRange(previous); // put the reader's own selection back
  holder.remove();

  return ok ? Promise.resolve() : Promise.reject(new Error("copy unavailable"));
}

/** Brief tick on the button itself — quieter than a toast for a routine action. */
function flashCopied(btn) {
  if (!btn || btn.dataset.copied) return;

  const original = btn.innerHTML;
  btn.dataset.copied = "1";
  btn.classList.add("copied");
  btn.innerHTML = COPY_TICK;
  btn.title = "Copied";

  setTimeout(() => {
    btn.innerHTML = original;
    btn.classList.remove("copied");
    btn.title = "Copy";
    delete btn.dataset.copied;
  }, 1400);
}

// Blocks that own their line, and blocks that also earn a blank line after them.
const RICH_LINE = new Set(["DIV", "TR", "THEAD", "TBODY", "TFOOT"]);
const RICH_PARA = new Set([
  "P",
  "H1",
  "H2",
  "H3",
  "H4",
  "H5",
  "H6",
  "BLOCKQUOTE",
  "PRE",
  "TABLE",
  "CAPTION",
]);

/**
 * The plain-text half of a copied rich message. innerText would drop the list
 * markers the browser draws rather than stores, so the tree is walked here and
 * every bullet, number, row and blank line is written out explicitly.
 */
function richToText(root) {
  let out = "";

  const endLine = () => {
    out = out.replace(/[ \t]+$/, "");
    if (out && !out.endsWith("\n")) out += "\n";
  };
  const endBlock = () => {
    endLine();
    if (out && !out.endsWith("\n\n")) out += "\n";
  };

  const walk = (node, indent) => {
    node.childNodes.forEach((child) => {
      if (child.nodeType === Node.TEXT_NODE) {
        out += (child.nodeValue || "").replace(/ /g, " ");
        return;
      }
      if (child.nodeType !== Node.ELEMENT_NODE) return;

      const tag = child.tagName;

      if (tag === "BR") {
        endLine();
        out += indent;
        return;
      }
      if (tag === "HR") {
        endLine();
        out += indent + "———";
        endLine();
        return;
      }

      if (tag === "UL" || tag === "OL") {
        endLine();
        let n = 1;
        Array.from(child.children).forEach((item) => {
          if (item.tagName !== "LI") return;
          out += indent + (tag === "OL" ? `${n++}. ` : "• ");
          walk(listItemSource(item), indent + "   ");
          endLine();
        });
        endBlock();
        return;
      }

      // Cells are tab-separated, which is what a spreadsheet expects back.
      if (tag === "TD" || tag === "TH") {
        walk(child, indent);
        out += "\t";
        return;
      }

      if (RICH_PARA.has(tag)) {
        endLine();
        out += indent;
        walk(child, indent);
        endBlock();
        return;
      }
      if (RICH_LINE.has(tag)) {
        endLine();
        out += indent;
        walk(child, indent);
        endLine();
        return;
      }

      walk(child, indent); // inline: b, em, a, code…
    });
  };

  walk(root, "");

  return out
    .replace(/[ \t]+\n/g, "\n")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}

/** `<li><p>text</p></li>` is one line, not a line break straight after the bullet. */
function listItemSource(item) {
  const only = item.childElementCount === 1 ? item.firstElementChild : null;
  return only &&
    (only.tagName === "P" || only.tagName === "DIV") &&
    (item.textContent || "") === (only.textContent || "")
    ? only
    : item;
}

/**
 * Bare URLs inside rich content. The plain path linkifies as it escapes, but
 * rich content is inserted as markup, so its text nodes are walked here instead
 * — skipping anything already inside a link or a code block.
 */
function linkifyRich(root) {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
    acceptNode: (node) =>
      node.parentElement && node.parentElement.closest("a,code,pre")
        ? NodeFilter.FILTER_REJECT
        : NodeFilter.FILTER_ACCEPT,
  });

  const targets = [];
  let node;
  while ((node = walker.nextNode())) {
    if (/(https?:\/\/|www\.)\S/i.test(node.nodeValue || "")) targets.push(node);
  }

  targets.forEach((text) => {
    const holder = document.createElement("span");
    holder.innerHTML = linkify(text.nodeValue || "");
    text.replaceWith(...holder.childNodes);
  });
}

// ── Edits ─────────────────────────────────────────────────────
/** Re-render messages the other side (or another tab) edited after they landed. */
function applyEdits(edits) {
  if (!edits || !edits.length) return;

  edits.forEach((m) => {
    const node = el.messagesContainer.querySelector(`[data-msgid="${m.id}"]`);
    if (!node || Number(m.id) === State.editingId) return; // don't fight the editor

    // A message that was edited and then deleted arrives here too; the deletion
    // is the newer fact, and applyDeleted() is what renders it.
    if (m.deleted) {
      markNodeDeleted(node);
      return;
    }

    const bubble = node.querySelector(".msg-bubble");
    if (!bubble) return;

    // Only the body is replaced. The name above it and the quote in front of it
    // describe who wrote the message and what it answers — neither of which an
    // edit can change — and rebuilding the bubble without them would drop both.
    const time = bubble.querySelector(".msg-time");
    const head = [".msg-sender", ".msg-quote"]
      .map((sel) => bubble.querySelector(sel))
      .filter(Boolean)
      .map((part) => part.outerHTML)
      .join("");

    bubble.innerHTML = head + messageBody(m) + (time ? time.outerHTML : "");
    bubble.querySelectorAll(".msg-rich").forEach(linkifyRich);

    const marker = bubble.querySelector(".msg-time");
    if (marker && m.edited && !marker.querySelector(".msg-edited")) {
      marker.insertAdjacentHTML(
        "afterbegin",
        '<span class="msg-edited">edited</span>',
      );
    }
  });
}

// ── Deletions ─────────────────────────────────────────────────
/**
 * Replace messages the sender has taken back with the placeholder.
 *
 * Driven by a list of ids rather than by rows: the server has nothing left to
 * send for a deleted message, and every one of them renders identically.
 */
function applyDeleted(ids) {
  if (!ids || !ids.length) return;

  ids.forEach((id) => {
    const node = el.messagesContainer.querySelector(`[data-msgid="${id}"]`);
    if (!node || node.classList.contains("is-deleted")) return;

    markNodeDeleted(node);
  });
}

/** Turn one already-rendered bubble into a tombstone, in place. */
function markNodeDeleted(node) {
  const id = Number(node.dataset.msgid);

  // The editor and the reply bar must not be left pointing at a message that no
  // longer exists.
  if (State.editingId === id) cancelEdit();
  if (State.replyTo && State.replyTo.id === id) cancelReply();

  node.classList.add("is-deleted");
  node.innerHTML = `<div class="msg-body">${buildBubble({
    id,
    deleted: true,
    is_mine: node.classList.contains("mine"),
    time: node.dataset.time || "",
    sender_name: node.dataset.sender || "",
  })}</div>${buildTools({ deleted: true })}`;

  // Any reaction chips went with it — message_delete() cleared them server-side,
  // and the signature cache has to forget them too or the next poll will not
  // notice the difference.
  delete node.dataset.reactions;

  // A reply that quoted this one now quotes something that is gone.
  el.messagesContainer
    .querySelectorAll(`.msg-quote[data-goto="${id}"]`)
    .forEach((quote) => {
      quote.classList.add("gone");
      const text = quote.querySelector(".msg-quote-text");
      if (text) text.textContent = "This message was deleted";
    });
}

function deleteMessage(msgId) {
  if (
    !window.confirm("Delete this message for everyone? This cannot be undone.")
  )
    return;

  const node = el.messagesContainer.querySelector(`[data-msgid="${msgId}"]`);
  if (node) node.classList.add("deleting");

  apiPost(
    "ajax/delete_message.php",
    formData({ csrf_token: UNI.csrf, id: msgId }),
  )
    .then(() => {
      applyDeleted([Number(msgId)]);
      // The sidebar preview is very likely this message.
      loadContacts(el.searchInput.value.trim());
    })
    .catch((err) => {
      if (err instanceof AuthError) return;
      if (node) node.classList.remove("deleting");
      showToast(
        "Not deleted",
        err.message || "The message could not be deleted.",
        "",
        5000,
      );
    });
}

// ── Replying ──────────────────────────────────────────────────
/**
 * Stage a message as the one the composer is answering.
 *
 * The quote is read out of the bubble on screen rather than re-fetched: it is
 * already there, and the server checks the id independently when the reply is
 * actually sent — so nothing here has to be trusted.
 */
function startReply(msgId) {
  const node = el.messagesContainer.querySelector(`[data-msgid="${msgId}"]`);
  if (!node || node.classList.contains("is-deleted")) return;

  cancelEdit();

  State.replyTo = {
    id: Number(msgId),
    who: replyWho(node),
    preview: replyPreviewOf(node),
  };

  el.replyBarWho.textContent = State.replyTo.who;
  el.replyBarText.textContent = State.replyTo.preview;
  el.replyBar.hidden = false;

  el.messageInput.focus({ preventScroll: true });
  placeCaretAtEnd(el.messageInput);
  scrollToBottom();
}

/** "You", or the sender's name where the bubble carries one (groups). */
function replyWho(node) {
  if (node.classList.contains("mine")) return "You";

  const label = node.querySelector(".msg-sender");
  if (label) return label.textContent.trim();

  // A one-to-one chat labels nobody, so the header is the only name there is.
  return (State.chatUser && State.chatUser.full_name) || "Them";
}

/** One line describing whatever is in a bubble, for the quote strip. */
function replyPreviewOf(node) {
  if (node.querySelector(".voice-note")) return "🎤 Voice message";
  if (node.querySelector(".gif-bubble")) return "🎞️ GIF";
  if (node.querySelector(".sticker-bubble")) return "🩷 Sticker";
  if (node.querySelector(".meme-bubble")) return "😂 Meme";

  // An attachment's caption describes it better than its file name does.
  const cap = node.querySelector(".msg-caption");
  const said = cap ? cap.textContent.replace(/\s+/g, " ").trim().slice(0, 140) : "";

  const file = node.querySelector(".msg-file .file-name");
  if (file) return "📎 " + (said || file.textContent.trim());

  if (node.querySelector(".msg-image-wrap")) {
    const name = node.querySelector(".msg-image-meta .file-name");
    return "📷 " + (said || (name ? name.textContent.trim() : "Image"));
  }

  const rich = node.querySelector(".msg-bubble .msg-rich");
  if (rich) {
    if (rich.querySelector("table")) return "📊 Table";
    return richToText(rich).replace(/\s+/g, " ").trim().slice(0, 140);
  }

  const text = node.querySelector(".msg-bubble .msg-text");
  return text
    ? text.textContent.replace(/\s+/g, " ").trim().slice(0, 140)
    : "Message";
}

function cancelReply() {
  State.replyTo = null;
  if (!el.replyBar) return;
  el.replyBar.hidden = true;
  el.replyBarWho.textContent = "";
  el.replyBarText.textContent = "";
}

/**
 * Walk to the message a quote points at.
 *
 * When it is not on screen the reader is told rather than left pressing a
 * button that does nothing — it is above the pages loaded so far, and scrolling
 * up until it appears is the honest instruction.
 */
function gotoMessage(msgId) {
  // Used to give up with a toast when the quoted message was above the loaded
  // window. It now fetches the part of the conversation it is in, which is the
  // same machinery a search result opens on.
  revealMessage(msgId);
}

// ── Reactions ─────────────────────────────────────────────────
/** @param {Object} map  { messageId: [{emoji, count, mine, who}] } */
function applyReactions(map) {
  if (!map) return;

  el.messagesContainer.querySelectorAll(".msg[data-msgid]").forEach((node) => {
    const list = map[node.dataset.msgid] || [];
    renderReactions(node, list);
  });
}

function renderReactions(node, list) {
  // A signature keeps the 3-second poll from rebuilding chips that did not move.
  const signature = list
    .map((r) => `${r.emoji}:${r.count}:${r.mine ? 1 : 0}`)
    .join("|");
  if (node.dataset.reactions === signature) return;
  node.dataset.reactions = signature;

  let row = node.querySelector(".msg-reactions");

  if (!list.length) {
    if (row) row.remove();
    return;
  }

  if (!row) {
    row = document.createElement("div");
    row.className = "msg-reactions";
    (node.querySelector(".msg-body") || node).appendChild(row);
  }

  row.innerHTML = list
    .map(
      (r) => `
    <button class="reaction-chip ${r.mine ? "mine" : ""}" data-emoji="${escAttr(r.emoji)}"
            title="${escAttr(r.who || "")}">
      <span class="reaction-emoji">${escHtml(r.emoji)}</span>
      <span class="reaction-count">${r.count}</span>
    </button>`,
    )
    .join("");
}

function toggleReaction(msgId, emoji) {
  return apiPost(
    "ajax/react.php",
    formData({ csrf_token: UNI.csrf, message_id: msgId, emoji }),
  )
    .then((d) => {
      const node = el.messagesContainer.querySelector(`[data-msgid="${d.id}"]`);
      if (node) renderReactions(node, d.reactions || []);
    })
    .catch((err) => {
      if (err instanceof AuthError) return;
      showToast(
        "Not saved",
        err.message || "Could not save your reaction.",
        "",
        4000,
      );
    });
}

// ── Emoji picker ──────────────────────────────────────────────
let emojiPicker = null;

function openEmojiPicker(anchor, msgId) {
  closeEmojiPicker();

  const picker = document.createElement("div");
  picker.className = "emoji-picker";
  picker.innerHTML = (UNI.reactions || [])
    .map(
      (emoji) =>
        `<button class="emoji-option" data-pick="${escAttr(emoji)}">${escHtml(emoji)}</button>`,
    )
    .join("");

  document.body.appendChild(picker);

  // Anchored to the button, then nudged back inside the viewport.
  const box = anchor.getBoundingClientRect();
  const top = box.top - picker.offsetHeight - 8;
  picker.style.top = `${top > 8 ? top : box.bottom + 8}px`;
  picker.style.left = `${Math.max(
    8,
    Math.min(
      box.left - picker.offsetWidth / 2 + box.width / 2,
      window.innerWidth - picker.offsetWidth - 8,
    ),
  )}px`;

  picker.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-pick]");
    if (!btn) return;
    toggleReaction(msgId, btn.dataset.pick);
    closeEmojiPicker();
  });

  emojiPicker = picker;
}

function closeEmojiPicker() {
  if (emojiPicker) {
    emojiPicker.remove();
    emojiPicker = null;
  }
}

// ── Inline editing ────────────────────────────────────────────
function startEdit(msgId) {
  const node = el.messagesContainer.querySelector(`[data-msgid="${msgId}"]`);
  if (!node || node.querySelector(".msg-editor")) return;

  cancelEdit();
  State.editingId = Number(msgId);

  const bubble = node.querySelector(".msg-bubble");
  const body =
    bubble.querySelector(".msg-rich") || bubble.querySelector(".msg-text");
  const isRich = !!bubble.querySelector(".msg-rich");

  node.dataset.original = body ? body.innerHTML : "";
  node.classList.add("editing");

  const editor = document.createElement("div");
  editor.className = "msg-editor";
  editor.innerHTML = `
    <div class="msg-editor-box" contenteditable="true" role="textbox" aria-multiline="true"></div>
    <div class="msg-editor-actions">
      <span class="msg-editor-hint">Enter to save · Esc to cancel</span>
      <button class="msg-editor-btn cancel" data-edit-cancel>Cancel</button>
      <button class="msg-editor-btn save" data-edit-save="${msgId}">Save</button>
    </div>`;

  bubble.hidden = true;
  bubble.parentNode.insertBefore(editor, bubble.nextSibling);

  const box = editor.querySelector(".msg-editor-box");
  // textContent for plain messages: assigning the text as innerHTML would let a
  // "<" somebody typed turn back into markup on the way into the editor.
  if (isRich) {
    // The box wraps plain text in pre-wrap so its newlines survive; markup
    // carries its own structure, and pre-wrap would only add gaps between the
    // blocks where the source left a space.
    box.classList.add("rich");
    box.innerHTML = node.dataset.original;
  } else {
    box.textContent = body ? body.textContent : "";
  }

  placeCaretAtEnd(box);
  box.focus({ preventScroll: true });

  box.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      e.preventDefault();
      cancelEdit();
    }
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      saveEdit(msgId);
    }
  });
  box.addEventListener("paste", (e) => handleRichPaste(e, box));

  scrollToBottom();
}

function cancelEdit() {
  State.editingId = null;

  el.messagesContainer.querySelectorAll(".msg.editing").forEach((node) => {
    node.classList.remove("editing");
    const editor = node.querySelector(".msg-editor");
    if (editor) editor.remove();
    const bubble = node.querySelector(".msg-bubble");
    if (bubble) bubble.hidden = false;
  });
}

function saveEdit(msgId) {
  const node = el.messagesContainer.querySelector(`[data-msgid="${msgId}"]`);
  const box = node && node.querySelector(".msg-editor-box");
  if (!box) return;

  const rich = hasRichMarkup(box);
  const text = elementText(box).trim();

  if (!rich && text === "") {
    showToast(
      "Nothing to save",
      "An edited message cannot be empty.",
      "",
      3500,
    );
    return;
  }
  if (text.length > UNI.maxLen) {
    showToast(
      "Too long",
      `Messages are limited to ${UNI.maxLen} characters.`,
      "",
      4000,
    );
    return;
  }

  const saveBtn = node.querySelector("[data-edit-save]");
  if (saveBtn) saveBtn.disabled = true;

  apiPost(
    "ajax/edit_message.php",
    formData({
      csrf_token: UNI.csrf,
      id: msgId,
      message: rich ? box.innerHTML : text,
      format: rich ? "html" : "plain",
    }),
  )
    .then((d) => {
      cancelEdit();
      applyEdits([
        { id: d.id, message: d.message, format: d.format, edited: d.edited },
      ]);
    })
    .catch((err) => {
      if (err instanceof AuthError) return;
      if (saveBtn) saveBtn.disabled = false;
      showToast(
        "Not saved",
        err.message || "The message could not be edited.",
        "",
        5000,
      );
    });
}

/** Promote single ticks to double ticks once the other side has read them. */
function applyReadReceipts(readUpto) {
  if (!readUpto) return;
  if (!isGroupChat()) syncContactTick(State.chatUserId, readUpto);
  el.messagesContainer.querySelectorAll(".msg.mine").forEach((node) => {
    if (Number(node.dataset.msgid) > readUpto) return;
    const t = node.querySelector(".tick");
    if (t && !t.classList.contains("read")) {
      t.classList.add("read");
      t.textContent = "✓✓";
      t.title = "Read";
    }
  });
}

// ── Chat header ───────────────────────────────────────────────
function updateChatHeader(u) {
  el.chatUsername.textContent = u.full_name;

  // A group's picture opens the member list rather than a lightbox: the info
  // panel is where it can be looked at *and* changed.
  if (u.is_group) {
    el.chatAvatar.src = u.avatar || groupAvatarUri(u.name);
    el.chatAvatar.alt = u.name;
    el.chatAvatar.title = "Group info";
    el.chatAvatar.classList.remove("avatar-view");
    delete el.chatAvatar.dataset.avatar;

    const members = `${u.member_count} member${u.member_count === 1 ? "" : "s"}`;
    el.chatStatus.textContent = u.description
      ? `${members} · ${u.description}`
      : members;
    el.chatStatus.className = "chat-status";
    el.groupInfoBtn.hidden = false;
    return;
  }

  el.chatAvatar.src = u.avatar;
  el.chatAvatar.alt = u.full_name;
  el.chatAvatar.classList.add("avatar-view");
  // Read back by the click/keyboard handler that enlarges the photo.
  el.chatAvatar.dataset.avatar = u.avatar;
  el.chatAvatar.dataset.avatarName = u.full_name;
  el.chatAvatar.title = "View photo";
  el.groupInfoBtn.hidden = true;

  if (u.is_online) {
    el.chatStatus.textContent = "Online";
    el.chatStatus.className = "chat-status online";
  } else {
    el.chatStatus.textContent = u.last_active
      ? "Last seen " + u.last_active
      : "Offline";
    el.chatStatus.className = "chat-status";
  }
}

// ── Send message ──────────────────────────────────────────────
/**
 * One press of Enter, one to forty attachments, and possibly several requests.
 *
 * The batching is invisible from the outside: the quote rides on the first
 * request (a reply is one answer, not one per picture) and the typed message on
 * the last, which is the order the bubbles already appear in — attachments
 * first, then what was said about them.
 */
async function sendMessage() {
  if (!chatOpen() || State.sending) return;

  const payload = composerPayload();
  const files = State.pendingFiles.slice();
  const voice = State.pendingVoice;
  if (!payload.body && !files.length && !voice) return;

  // Past UNI.maxLen the server sends the text as a Markdown file rather than
  // refusing it, and says so in its answer (`as_file`) — it is the one that
  // counts, so the client never second-guesses where the line falls.
  let asFile = false;

  const reply = State.replyTo;

  // Progress is measured in bytes, not in files or batches: forty photographs
  // are not forty equal fortieths, and a percentage that jumps in steps of 20
  // as each batch lands is not a percentage anybody can read a transfer from.
  const voiceBytes = voice ? voice.blob.size : 0;
  const totalBytes = files.reduce((n, f) => n + f.size, 0) + voiceBytes;
  let sentBytes = 0; // everything the batches before this one carried

  const batches = files.length ? batchFiles(files) : [[]];

  // A recording rides on the last request, along with the typed message. If
  // that request is already carrying a large attachment the two together can
  // exceed what the server will accept — a 50 MB archive and a voice note are
  // each fine and their sum is not — so in that case the recording is given a
  // request of its own. The conversation reads the same either way: files,
  // then the note, then what was said about them.
  if (voice) {
    const lastBytes = batches[batches.length - 1].reduce((n, f) => n + f.size, 0);
    if (lastBytes + voiceBytes > (UNI.uploadBatchBytes || 24 * 1024 * 1024)) {
      batches.push([]);
    }
  }

  // Clear the composer optimistically; restore it if the send fails.
  const restore = el.messageInput.innerHTML;
  State.sending = true;
  el.sendBtn.disabled = true;
  clearComposer();
  clearPendingFiles();
  discardVoiceNote();
  cancelReply();

  // The reader follows what they just sent, wherever they had scrolled to.
  State.stickBottom = true;

  let uploaded = 0;
  const warnings = [];
  let failure = null;

  State.sendLabel = uploadLabel(files, voice);
  if (totalBytes > 0) showSendProgress(0, totalBytes, false);

  for (let i = 0; i < batches.length; i++) {
    const batch = batches[i];
    const last = i === batches.length - 1;

    const fd = new FormData();
    fd.append("csrf_token", UNI.csrf);
    appendTarget(fd);
    batch.forEach((f) => fd.append("files[]", f));

    if (i === 0 && reply) fd.append("reply_to", String(reply.id));
    if (last) {
      if (payload.body) {
        fd.append("message", payload.body);
        fd.append("format", payload.format);
      }
      if (voice) {
        fd.append("voice", voice.blob, "voice-note." + voice.ext);
        fd.append("voice_seconds", String(voice.seconds));
      }
    }

    // What this particular request is carrying, in bytes.
    const batchBytes =
      batch.reduce((n, f) => n + f.size, 0) + (last ? voiceBytes : 0);

    try {
      const d =
        totalBytes > 0
          ? await apiUpload("ajax/send_message.php", fd, (fraction, flushed) => {
              // The last batch reaching 100% is not the end of the send — the
              // server still has to store the files and answer. Saying so is
              // more honest than a bar that sits full for two seconds.
              showSendProgress(
                sentBytes + batchBytes * fraction,
                totalBytes,
                flushed && last,
              );
            })
          : await apiPost("ajax/send_message.php", fd);

      if (d && d.warnings && d.warnings.length) warnings.push(...d.warnings);
      if (d && d.as_file) asFile = true;
      uploaded += batch.length;
      sentBytes += batchBytes;
      showSendProgress(sentBytes, totalBytes, last);

      // Each batch appears as it lands rather than all at the end, so a long
      // upload looks like it is doing something.
      if (!last) fetchMessages(true);
    } catch (err) {
      failure = err;
      break;
    }
  }

  hideSendProgress();
  State.sending = false;
  el.sendBtn.disabled = false;

  if (warnings.length) {
    showToast("Some files were skipped", warnings.join(" "), "", 6000);
  } else if (asFile && !failure) {
    showToast(
      "Sent as a file",
      `Messages over ${UNI.maxLen.toLocaleString()} characters are sent as a Markdown (.md) attachment.`,
      "",
      4000,
    );
  }

  if (failure && !(failure instanceof AuthError)) {
    // Give back everything that did not go. The typed message always comes
    // back: it rides on the *last* request, so if anything failed it was never
    // sent — and losing what somebody wrote because the fourth of six batches
    // timed out is not an acceptable way to fail.
    el.messageInput.innerHTML = restore;

    // The un-sent attachments go back into the strip, ready to try again,
    // rather than having to be picked out of a folder a second time.
    const left = files.slice(uploaded);
    if (left.length) {
      State.pendingFiles = left;
      renderPreviews();
    }

    // The quote rides on the first request. If that one landed, the quote has
    // already been spent and putting it back would double it.
    if (!uploaded && reply) {
      State.replyTo = reply;
      el.replyBarWho.textContent = reply.who;
      el.replyBarText.textContent = reply.preview;
      el.replyBar.hidden = false;
    }

    showToast(
      "Not sent",
      uploaded
        ? `${uploaded} of ${files.length} attachments were sent — the rest are ` +
            "still in the composer. " + (failure.message || "")
        : failure.message || "Message could not be sent.",
      "",
      5500,
    );
  }

  // Sending is an act in the present. Somebody who reached this conversation
  // through a search result is looking at last month; leaving them there,
  // staring at history while the thing they just sent sits unseen at the
  // bottom, is not a conversation. Reopening on the newest page is.
  if (State.hasNewer) jumpToLatest();
  else await fetchMessages(true);

  loadContacts(el.searchInput.value.trim());
  focusComposer();
}

/** What the strip calls whatever is going up. */
function uploadLabel(files, voice) {
  if (!files.length && voice) return "Uploading voice message";
  if (files.length === 1 && !voice) {
    const name = String(files[0].name || "attachment");
    return `Uploading ${name.length > 30 ? name.slice(0, 29) + "…" : name}`;
  }

  const count = files.length + (voice ? 1 : 0);
  return `Uploading ${count} attachments`;
}

/**
 * A determinate bar, and the number beside it.
 *
 * @param {number} loaded    bytes on the wire so far
 * @param {number} total     bytes the whole send is carrying
 * @param {boolean} flushed  the body is out and the server is now working
 */
function showSendProgress(loaded, total, flushed) {
  if (!el.sendProgress || total <= 0) return;

  const pct = Math.max(0, Math.min(100, Math.round((loaded / total) * 100)));

  el.sendProgress.hidden = false;
  el.sendProgress.classList.add("show");
  el.sendProgress.classList.toggle("finishing", !!flushed);
  el.sendProgress.setAttribute("aria-valuenow", String(pct));

  if (el.sendProgressBar) el.sendProgressBar.style.width = pct + "%";
  if (el.sendProgressPct) el.sendProgressPct.textContent = pct + "%";
  if (el.sendProgressText) {
    el.sendProgressText.textContent = flushed
      ? "Finishing…"
      : State.sendLabel || "Uploading…";
  }
}

function hideSendProgress() {
  if (!el.sendProgress) return;
  el.sendProgress.classList.remove("show", "finishing");
  el.sendProgress.hidden = true;
  el.sendProgress.setAttribute("aria-valuenow", "0");
  if (el.sendProgressBar) el.sendProgressBar.style.width = "0%";
  if (el.sendProgressPct) el.sendProgressPct.textContent = "";
}

// ── Media picker: GIFs, stickers and memes ────────────────────
// All three come from ajax/klipy.php, which proxies KLIPY so the API key never
// reaches the page. Exactly one request is ever in flight: the next keystroke
// aborts the previous one, which is what stops a fast typist from racing two
// pages of results into the grid in the wrong order.
//
// The search term is deliberately shared across the tabs — type "thanks" once
// and it is a GIF, a sticker and a meme away — while the results, the paging
// and the category strip belong to whichever tab is open.

const MEDIA_DEBOUNCE = 350;

/**
 * How each kind presents itself.
 *
 * `columns` is the masonry width: stickers are small and roughly square, so
 * three of them read as a sheet of stickers where two read as a gallery.
 */
const MEDIA_KINDS = {
  gif: { one: "GIF", many: "GIFs", hint: "Search GIFs…", columns: 2 },
  sticker: {
    one: "sticker",
    many: "stickers",
    hint: "Search stickers…",
    columns: 3,
  },
  meme: { one: "meme", many: "memes", hint: "Search memes…", columns: 2 },
};

// Guarded for the same reason as HAS_NOTIFICATIONS above: this runs at the top
// level, so a context without matchMedia would throw here and take every
// function defined after it down with it — for the sake of an optional nicety.
const MEDIA_STILL_ONLY =
  typeof window.matchMedia === "function"
    ? window.matchMedia("(prefers-reduced-motion: reduce)")
    : { matches: false };

let mediaDebounce = null;

const mediaKind = () => MEDIA_KINDS[State.mediaKind] || MEDIA_KINDS.gif;

function toggleMediaPicker() {
  if (!el.mediaPicker) return;
  el.mediaPicker.hidden ? openMediaPicker() : closeMediaPicker();
}

function openMediaPicker() {
  if (!el.mediaPicker || !chatOpen()) return;

  closeEmojiPicker();
  el.mediaPicker.hidden = false;
  el.mediaBtn.classList.add("active");
  el.mediaBtn.setAttribute("aria-expanded", "true");

  // Trending GIFs are the landing view. A previous search is kept, so re-opening
  // the picker does not throw away what somebody was in the middle of.
  if (!State.mediaLoaded) loadMedia(true);
  loadMediaCategories();

  el.mpSearch.focus({ preventScroll: true });
  el.mpSearch.select();
}

function closeMediaPicker() {
  if (!el.mediaPicker || el.mediaPicker.hidden) return;

  el.mediaPicker.hidden = true;
  el.mediaBtn.classList.remove("active");
  el.mediaBtn.setAttribute("aria-expanded", "false");

  clearTimeout(mediaDebounce);
  abortMediaLoad();
  el.messageInput.focus({ preventScroll: true });
}

function abortMediaLoad() {
  if (State.mediaAbort) {
    State.mediaAbort.abort();
    State.mediaAbort = null;
  }
}

/** Switch tabs. The search term survives; the results do not. */
function setMediaKind(kind) {
  if (!MEDIA_KINDS[kind] || kind === State.mediaKind) return;

  State.mediaKind = kind;

  el.mpTabs.querySelectorAll(".mp-tab").forEach((tab) => {
    const on = tab.dataset.kind === kind;
    tab.classList.toggle("active", on);
    tab.setAttribute("aria-selected", on ? "true" : "false");
  });
  el.mpSearch.placeholder = mediaKind().hint;

  // "Recent" is per kind as well, so the view carries over; trending and search
  // simply re-run against the new library.
  renderMediaChips();
  loadMediaCategories();
  loadMedia(true);
}

/**
 * Point the grid at one of the three views.
 *
 * @param {string} view   'trending' | 'search' | 'recent'
 * @param {string} query  the search term, for 'search'
 */
function setMediaView(view, query = "") {
  State.mediaView = view;
  State.mediaQuery = view === "search" ? query : "";

  // Compared trimmed, because this also runs on a debounced keystroke: somebody
  // half way through "thank you" has a trailing space the search itself does not,
  // and rewriting the box under them would eat it.
  if (el.mpSearch.value.trim() !== State.mediaQuery) {
    el.mpSearch.value = State.mediaQuery;
  }
  el.mpSearchClear.hidden = State.mediaQuery === "";

  clearTimeout(mediaDebounce);
  renderMediaChips();
  loadMedia(true);
}

/**
 * Fetch one page of results.
 *
 * @param {boolean} reset  A new query, tab or view: empty the grid and start
 *                         again from the first page.
 */
function loadMedia(reset) {
  if (!el.mpGrid) return;
  if (!reset && (State.mediaBusy || !State.mediaMore)) return;

  abortMediaLoad();

  if (reset) {
    State.mediaPage = 1;
    State.mediaMore = true;
    renderMediaSkeletons();
  } else {
    setMediaLoadingMore(true);
  }

  const kind = State.mediaKind;
  const view = State.mediaView;
  const query = State.mediaQuery;
  const page = State.mediaPage;

  State.mediaBusy = true;
  State.mediaLoaded = true;
  State.mediaAbort = new AbortController();

  api(
    `ajax/klipy.php?kind=${encodeURIComponent(kind)}` +
      `&view=${encodeURIComponent(view)}` +
      `&q=${encodeURIComponent(query)}&page=${page}`,
    { signal: State.mediaAbort.signal },
  )
    .then((d) => {
      // A late answer to a tab or a query nobody is looking at any more.
      if (
        kind !== State.mediaKind ||
        view !== State.mediaView ||
        query !== State.mediaQuery
      )
        return;

      State.mediaPage = page + 1;
      State.mediaMore = !!d.more;
      renderMediaItems(d.items || [], reset);
    })
    .catch((err) => {
      if (err instanceof AuthError || err.name === "AbortError") return;
      if (reset)
        showMediaState(
          err.message || `${mediaKind().many} could not be loaded.`,
          true,
        );
      else setMediaLoadingMore(false);
    })
    .finally(() => {
      State.mediaBusy = false;
      State.mediaAbort = null;
    });
}

/**
 * KLIPY's own category strip for the open tab, fetched once per tab.
 *
 * Failure is silent: the chips are a shortcut, and a picker without them still
 * searches, still shows trending and still sends.
 */
function loadMediaCategories() {
  const kind = State.mediaKind;
  if (!el.mpChips || State.mediaCats[kind]) return;

  State.mediaCats[kind] = []; // claim it, so one failure is not retried on every open

  api(`ajax/klipy.php?kind=${encodeURIComponent(kind)}&view=categories`)
    .then((d) => {
      State.mediaCats[kind] = d.categories || [];
      if (kind === State.mediaKind) renderMediaChips();
    })
    .catch(() => {});
}

/** Trending and Recent first — they are views, not searches — then KLIPY's own. */
function renderMediaChips() {
  if (!el.mpChips) return;

  const active =
    State.mediaView === "search"
      ? State.mediaQuery.toLowerCase()
      : State.mediaView;
  const chips = [
    { key: "trending", label: "Trending" },
    { key: "recent", label: "Recent" },
    ...(State.mediaCats[State.mediaKind] || []).map((c) => ({
      key: c.query.toLowerCase(),
      label: c.label,
      query: c.query,
    })),
  ];

  el.mpChips.innerHTML = chips
    .map(
      (chip) => `
    <button type="button" class="mp-chip${chip.key === active ? " active" : ""}"
            role="tab" aria-selected="${chip.key === active}"
            data-view="${chip.query ? "search" : escAttr(chip.key)}"
            data-query="${escAttr(chip.query || "")}">${escHtml(chip.label)}</button>`,
    )
    .join("");
}

/**
 * Hand-balanced columns rather than CSS `columns`, because a CSS column reflows
 * everything already on screen each time a page is appended — the results would
 * visibly shuffle under the reader's cursor as they scroll.
 */
function renderMediaItems(items, reset) {
  const grid = el.mpGrid;
  const count = mediaKind().columns;
  setMediaLoadingMore(false);

  if (reset) {
    grid.innerHTML = mediaColumnsHtml(count);
    grid.scrollTop = 0;
    State.mediaCols = new Array(count).fill(0);
  }

  const cols = grid.querySelectorAll(".mp-col");
  if (cols.length !== count) return;
  // A page appended onto a grid this run did not build — belt and braces, so a
  // stray append can never index past the end of the column heights.
  if (State.mediaCols.length !== count)
    State.mediaCols = new Array(count).fill(0);

  if (reset && !items.length) {
    showMediaState(mediaEmptyText(), false);
    return;
  }

  items.forEach((item) => {
    // Height at a fixed column width — enough to keep the columns even without
    // reading layout back out of the DOM on every tile.
    const ratio = item.width > 0 ? item.height / item.width : 1;
    const side = State.mediaCols.indexOf(Math.min(...State.mediaCols));

    State.mediaCols[side] += ratio;
    cols[side].appendChild(buildMediaTile(item));
  });
}

/** What an empty grid should say, which depends on why it is empty. */
function mediaEmptyText() {
  const kind = mediaKind();

  if (State.mediaView === "recent") {
    return `No recent ${kind.many} yet — the ones you send show up here.`;
  }
  return State.mediaQuery
    ? `No ${kind.many} for “${State.mediaQuery}”.`
    : `No ${kind.many} to show right now.`;
}

function mediaColumnsHtml(count) {
  return `<div class="mp-cols">${'<div class="mp-col"></div>'.repeat(count)}</div>`;
}

function buildMediaTile(item) {
  const kind = State.mediaKind;
  const name = item.title || MEDIA_KINDS[kind].one;

  const tile = document.createElement("button");
  tile.type = "button";
  tile.className = `mp-tile kind-${kind}`;
  tile.title = name;
  tile.setAttribute("role", "option");
  tile.setAttribute("aria-label", name);
  // Reserves the slot before the image arrives, so the grid fills in place
  // instead of shunting the tiles below it down the panel.
  tile.style.aspectRatio = `${item.width || 1} / ${item.height || 1}`;
  tile.dataset.kind = kind;
  tile.dataset.url = item.url;
  tile.dataset.title = name;
  tile.dataset.slug = item.slug || "";

  const img = document.createElement("img");
  // Two dozen animations at once is a lot of motion for a small panel.
  img.src = MEDIA_STILL_ONLY.matches && item.still ? item.still : item.preview;
  img.alt = "";
  img.loading = "lazy";
  img.addEventListener("load", () => tile.classList.add("ready"), {
    once: true,
  });
  img.addEventListener("error", () => tile.remove(), { once: true });

  tile.appendChild(img);
  return tile;
}

function renderMediaSkeletons() {
  const count = mediaKind().columns;
  el.mpGrid.innerHTML = mediaColumnsHtml(count);
  const cols = el.mpGrid.querySelectorAll(".mp-col");

  // Uneven on purpose: a placeholder that reads as a masonry grid rather than
  // a table is a smaller jolt when the real results replace it.
  [1, 0.72, 1.25, 0.85, 0.95, 1.15, 1.05, 0.8, 1.3].forEach((ratio, i) => {
    const block = document.createElement("div");
    block.className = "mp-skeleton skeleton";
    block.style.aspectRatio = `1 / ${ratio}`;
    cols[i % count].appendChild(block);
  });
}

function showMediaState(message, retry) {
  el.mpGrid.innerHTML = `
    <div class="mp-state">
      <p>${escHtml(message)}</p>
      ${retry ? '<button type="button" class="mp-retry">Try again</button>' : ""}
    </div>`;
}

function setMediaLoadingMore(on) {
  const existing = el.mpGrid.querySelector(".mp-more");
  if (existing) existing.remove();

  if (on) {
    const more = document.createElement("div");
    more.className = "mp-more";
    more.innerHTML = '<span class="mp-spinner"></span>';
    el.mpGrid.appendChild(more);
  }
}

/** Picking one sends it: a preview step would be a click with nothing to add. */
function sendMedia(tile) {
  const url = tile.dataset.url;
  if (!url || !chatOpen()) return;

  const kind = MEDIA_KINDS[tile.dataset.kind] ? tile.dataset.kind : "gif";
  closeMediaPicker();

  const body = {
    csrf_token: UNI.csrf,
    klipy_url: url,
    klipy_kind: kind,
    klipy_title: tile.dataset.title || MEDIA_KINDS[kind].one,
    klipy_slug: tile.dataset.slug || "",
    // The search that led here, which is what KLIPY's share tracking asks for
    // and what fills the Recent row next time.
    klipy_q: State.mediaView === "search" ? State.mediaQuery : "",
  };
  if (isGroupChat()) body.group_id = State.chatGroupId;
  else body.receiver_id = State.chatUserId;

  apiPost("ajax/send_message.php", formData(body))
    .then(() =>
      fetchMessages(true).then(() => loadContacts(el.searchInput.value.trim())),
    )
    .catch((err) => {
      if (err instanceof AuthError) return;
      showToast(
        "Not sent",
        err.message || `The ${MEDIA_KINDS[kind].one} could not be sent.`,
        "",
        5000,
      );
    });
}

/** Arrows walk the masonry the way it looks: up/down a column, left/right across. */
function moveMediaFocus(tile, key) {
  const column = tile.parentElement;
  const grid = column.parentElement;

  if (key === "ArrowUp" || key === "ArrowDown") {
    const next =
      key === "ArrowUp" ? tile.previousElementSibling : tile.nextElementSibling;
    if (next) next.focus();
    return;
  }

  const columns = Array.from(grid.children);
  const at = columns.indexOf(column);
  const other = columns[key === "ArrowLeft" ? at - 1 : at + 1];
  if (!other) return;

  const peer =
    other.children[Array.from(column.children).indexOf(tile)] ||
    other.lastElementChild;
  if (peer) peer.focus();
}

function bindMediaPicker() {
  if (!el.mediaPicker) return; // KLIPY not configured

  el.mediaBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    toggleMediaPicker();
  });
  el.mpClose.addEventListener("click", closeMediaPicker);

  el.mpTabs.addEventListener("click", (e) => {
    const tab = e.target.closest(".mp-tab");
    if (tab) setMediaKind(tab.dataset.kind);
  });

  el.mpChips.addEventListener("click", (e) => {
    const chip = e.target.closest(".mp-chip");
    if (chip) setMediaView(chip.dataset.view, chip.dataset.query || "");
  });

  el.mpSearch.addEventListener("input", () => {
    const value = el.mpSearch.value.trim();
    el.mpSearchClear.hidden = value === "";

    clearTimeout(mediaDebounce);
    mediaDebounce = setTimeout(() => {
      if (value === State.mediaQuery && State.mediaView !== "recent") return;
      setMediaView(value ? "search" : "trending", value);
    }, MEDIA_DEBOUNCE);
  });

  el.mpSearch.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      e.preventDefault();
      closeMediaPicker();
      return;
    }
    if (e.key === "Enter") {
      e.preventDefault();
      const value = el.mpSearch.value.trim();
      setMediaView(value ? "search" : "trending", value);
      return;
    }
    // Straight from the search box into the results.
    if (e.key === "ArrowDown") {
      const first = el.mpGrid.querySelector(".mp-tile");
      if (first) {
        e.preventDefault();
        first.focus();
      }
    }
  });

  el.mpSearchClear.addEventListener("click", () => {
    setMediaView("trending");
    el.mpSearch.focus();
  });

  el.mpGrid.addEventListener("click", (e) => {
    if (e.target.closest(".mp-retry")) {
      loadMedia(true);
      return;
    }

    const tile = e.target.closest(".mp-tile");
    if (tile) sendMedia(tile);
  });

  el.mpGrid.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      e.preventDefault();
      closeMediaPicker();
      return;
    }

    const tile = e.target.closest(".mp-tile");
    if (tile && e.key.startsWith("Arrow")) {
      e.preventDefault();
      moveMediaFocus(tile, e.key);
    }
  });

  // Infinite scroll.
  el.mpGrid.addEventListener(
    "scroll",
    () => {
      const { scrollTop, scrollHeight, clientHeight } = el.mpGrid;
      if (scrollHeight - scrollTop - clientHeight < 240) loadMedia(false);
    },
    { passive: true },
  );

  // Anything outside the panel closes it, the same way the emoji picker behaves.
  document.addEventListener("click", (e) => {
    if (el.mediaPicker.hidden) return;
    if (e.target.closest("#mediaPicker") || e.target.closest("#mediaBtn"))
      return;
    closeMediaPicker();
  });

  renderMediaChips();
}

// ══ Shared files ══════════════════════════════════════════════
// Everything sent in the open conversation, sorted into three drawers. The
// server hands back the whole list in one answer and the tabs filter it here:
// a file's drawer is decided by what it is, so switching tabs is not a question
// worth another round trip.

const FILE_TABS = ["images", "documents", "archives", "links"];

const FILE_TAB_EMPTY = {
  images: "No pictures have been shared here yet.",
  documents: "No documents have been shared here yet.",
  archives: "No archives have been shared here yet.",
  links: "No links have been shared here yet.",
};

function toggleFilesPanel() {
  State.filesOpen ? closeFilesPanel() : openFilesPanel();
}

function openFilesPanel() {
  if (!chatOpen()) return;

  State.filesOpen = true;
  el.filesPanel.hidden = false;
  el.filesBackdrop.hidden = false;
  el.filesBtn.classList.add("active");
  el.filesBtn.setAttribute("aria-expanded", "true");
  closeEmojiPicker();
  closeMediaPicker();

  // Re-fetched when the conversation changed under it, or when something
  // arrived while it was closed; otherwise the list already on screen stands.
  if (State.filesToken !== targetQuery() || State.filesStale) loadFiles();
  else renderFiles();

  el.filesClose.focus({ preventScroll: true });
}

function closeFilesPanel() {
  if (!State.filesOpen) return;

  State.filesOpen = false;
  el.filesPanel.hidden = true;
  el.filesBackdrop.hidden = true;
  el.filesBtn.classList.remove("active");
  el.filesBtn.setAttribute("aria-expanded", "false");
}

/** Drop everything the panel knows — called when the conversation changes. */
function resetFiles() {
  State.filesItems = [];
  State.filesLinks = [];
  State.filesCounts = { images: 0, documents: 0, archives: 0, links: 0 };
  State.filesToken = "";
  State.filesCapped = false;
  State.filesStale = false;
  State.filesTab = "images";
  if (el.filesBody) el.filesBody.innerHTML = "";
  updateFileCounts();
}

function loadFiles() {
  if (!chatOpen() || State.filesBusy) return;

  const token = targetQuery();
  State.filesBusy = true;
  State.filesStale = false;
  el.filesBody.innerHTML = '<div class="files-state">Loading…</div>';

  apiGet(`ajax/media.php?${token}`)
    .then((d) => {
      if (targetQuery() !== token) return; // the user moved on mid-flight

      State.filesItems = Array.isArray(d.items) ? d.items : [];
      State.filesLinks = Array.isArray(d.links) ? d.links : [];
      State.filesCounts = d.counts || { images: 0, documents: 0, archives: 0, links: 0 };
      State.filesCapped = !!d.capped;
      State.filesToken = token;

      // Land on a drawer that has something in it, rather than on an empty
      // "Images" tab in a conversation that only ever exchanged documents.
      if (!State.filesCounts[State.filesTab]) {
        State.filesTab =
          FILE_TABS.find((tab) => State.filesCounts[tab]) || "images";
      }

      updateFileCounts();
      renderFiles();
    })
    .catch((err) => {
      if (err instanceof AuthError || targetQuery() !== token) return;
      el.filesBody.innerHTML = `<div class="files-state">${escHtml(err.message || "Shared files could not be loaded.")}</div>`;
    })
    .finally(() => {
      State.filesBusy = false;
    });
}

function updateFileCounts() {
  el.filesTabs.querySelectorAll(".files-tab").forEach((tab) => {
    const name = tab.dataset.tab;
    const on = name === State.filesTab;

    tab.classList.toggle("active", on);
    tab.setAttribute("aria-selected", on ? "true" : "false");

    const badge = tab.querySelector(`[data-count="${name}"]`);
    if (badge) badge.textContent = State.filesCounts[name] || 0;
  });
}

function renderFiles() {
  const tab = State.filesTab;

  if (tab === "links") {
    el.filesBody.innerHTML = State.filesLinks.length
      ? `<div class="files-list links-list">${State.filesLinks.map(linkRow).join("")}</div>` +
        (State.filesCapped
          ? '<div class="files-note">Showing the most recent links in this conversation.</div>'
          : "")
      : `<div class="files-state">${escHtml(FILE_TAB_EMPTY.links)}</div>`;
    return;
  }

  const items = State.filesItems.filter((item) => item.category === tab);

  if (!items.length) {
    el.filesBody.innerHTML = `<div class="files-state">${escHtml(FILE_TAB_EMPTY[tab])}</div>`;
    return;
  }

  // A picture is worth showing; a document is worth naming. Two shapes, one
  // list, and the reason the panel is not simply a table of file names.
  const body =
    tab === "images"
      ? `<div class="files-grid">${items.map(fileTile).join("")}</div>`
      : `<div class="files-list">${items.map(fileRow).join("")}</div>`;

  const note = State.filesCapped
    ? '<div class="files-note">Showing the most recent files in this conversation.</div>'
    : "";

  el.filesBody.innerHTML = body + note;
}

function fileTile(item) {
  return `
    <button type="button" class="files-tile" data-file-image="${escAttr(item.url)}"
            title="${escAttr(item.name)}">
      <img src="${escAttr(item.url)}" alt="" loading="lazy">
      <span class="files-tile-gone">${escHtml(item.name)}</span>
      <span class="files-tile-meta">${escHtml(item.date)}</span>
    </button>`;
}

function fileRow(item) {
  const look = docLook(item.ext);
  const sub = [item.sender, item.date, item.size].filter(Boolean).join(" · ");
  const md = isMarkdownName(item.name);

  return `
    <a class="files-row ${look.cls}" href="${escAttr(item.url)}" download="${escAttr(item.name)}"
       ${md ? `data-md="${escAttr(item.name)}" title="Preview ${escAttr(item.name)}"` : `title="Download ${escAttr(item.name)}"`}>
      <span class="file-badge">${escHtml(look.label)}</span>
      <span class="file-meta">
        <span class="file-name">${escHtml(item.name)}</span>
        <span class="file-sub">${escHtml(sub)}</span>
      </span>
      <span class="file-dl">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
      </span>
    </a>`;
}

/**
 * One message's worth of links: every address it carried, each clickable, with
 * the words it was sent with underneath. A caption is marked as one, so
 * "drawing attached" next to a link makes sense without the drawing in view.
 */
function linkRow(item) {
  const urls = (item.urls || [])
    .map((url) => {
      let host = "";
      try {
        host = new URL(url).hostname.replace(/^www\./, "");
      } catch {
        host = "";
      }
      return `
        <a class="link-url" href="${escAttr(url)}" target="_blank" rel="noopener noreferrer">
          <span class="link-host">${escHtml(host || "Link")}</span>
          <span class="link-href">${escHtml(url)}</span>
        </a>`;
    })
    .join("");

  // The message text only when it says more than the address itself does.
  const text = String(item.text || "").trim();
  const onlyUrl = (item.urls || []).some(
    (u) => text === u || "https://" + text === u,
  );
  const words =
    text && !onlyUrl
      ? `<div class="link-text">${escHtml(text.length > 280 ? text.slice(0, 279) + "…" : text)}</div>`
      : "";

  const kind =
    item.kind === "image" ? "📷 Caption" : item.kind && item.kind !== "text" ? "📎 Caption" : "";
  const sub = [kind, item.sender, item.date].filter(Boolean).join(" · ");

  return `
    <div class="files-row link-row">
      <span class="file-badge link-badge" aria-hidden="true">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
          <path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/>
          <path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/>
        </svg>
      </span>
      <span class="file-meta">
        <span class="link-urls">${urls}</span>
        ${words}
        <span class="file-sub">${escHtml(sub)}</span>
      </span>
      <button type="button" class="link-goto" data-goto-msg="${item.id}"
              title="Show in chat" aria-label="Show this message in the chat">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </button>
    </div>`;
}

/** The picture drawer is its own gallery: the arrows walk it, not the chat. */
function openFilesGallery(url) {
  const images = State.filesItems
    .filter((item) => item.category === "images")
    .map((item) => ({ src: item.url, name: item.name }));

  const at = images.findIndex((item) => item.src === url);
  openGallery(images, at < 0 ? 0 : at);
}

function bindFilesPanel() {
  el.filesBtn.addEventListener("click", toggleFilesPanel);
  el.filesClose.addEventListener("click", closeFilesPanel);
  el.filesBackdrop.addEventListener("click", closeFilesPanel);

  el.filesTabs.addEventListener("click", (e) => {
    const tab = e.target.closest(".files-tab");
    if (!tab || tab.dataset.tab === State.filesTab) return;

    State.filesTab = tab.dataset.tab;
    updateFileCounts();
    renderFiles();
    el.filesBody.scrollTop = 0;
  });

  el.filesBody.addEventListener("click", (e) => {
    const tile = e.target.closest("[data-file-image]");
    if (tile) {
      openFilesGallery(tile.dataset.fileImage);
      return;
    }

    // A Markdown document previews here too, not only from its bubble.
    const md = e.target.closest("[data-md]");
    if (md && !e.target.closest(".file-dl")) {
      e.preventDefault();
      openMarkdownPreview(md.getAttribute("href"), md.dataset.md);
      return;
    }

    // A link row's chevron walks to the message that shared it. On a phone the
    // panel covers the chat, so it gets out of the way first.
    const go = e.target.closest("[data-goto-msg]");
    if (go) {
      if (isMobile()) closeFilesPanel();
      revealMessage(Number(go.dataset.gotoMsg));
    }
  });

  // A thumbnail whose file has since gone from disk names itself instead of
  // showing the browser's broken-image glyph. Captured rather than bubbled:
  // an <img> load failure does not bubble.
  el.filesBody.addEventListener(
    "error",
    (e) => {
      const tile =
        e.target.tagName === "IMG" && e.target.closest(".files-tile");
      if (tile) tile.classList.add("gone");
    },
    true,
  );
}

// ── Forwarding ────────────────────────────────────────────────
// One message, into up to UNI.maxForward chats at once. Every attempt carries a
// key minted when the dialog opened; a retry re-sends the same key, so a chat
// that already got the message — because only the *response* was lost — is
// handed back the copy it has rather than given a second one.

/** 32 hex characters: the idempotency key for one forward. */
function newForwardKey() {
  const bytes = new Uint8Array(16);
  window.crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
}

function openForward(msgId) {
  const node = el.messagesContainer.querySelector(`[data-msgid="${msgId}"]`);
  if (!node || node.classList.contains("is-deleted") || !el.forwardModal) return;

  closeMsgSheet();
  closeEmojiPicker();

  State.forwardMsgId = Number(msgId);
  State.forwardKey = newForwardKey();
  State.forwardPicked = new Set();
  State.forwardDone = new Set();
  State.forwardBusy = false;

  el.forwardSearch.value = "";
  el.forwardPreview.innerHTML =
    `<span class="forward-preview-icon">${FORWARD_ICON}</span>` +
    `<span class="forward-preview-text">${escHtml(replyPreviewOf(node))}</span>`;
  showModalError(el.forwardError, "");

  // The sidebar's list is filtered while somebody is searching it, so the
  // dialog starts from what is there and swaps in the full list once fetched.
  State.forwardList = forwardRows(State.contacts, State.groups);
  renderForwardPicker();
  openModal(el.forwardModal);
  el.forwardSearch.focus({ preventScroll: true });

  apiGet("ajax/get_users.php")
    .then((d) => {
      if (!d || !Array.isArray(d.users) || el.forwardModal.hidden) return;
      State.forwardList = forwardRows(d.users, d.groups || []);
      renderForwardPicker();
    })
    .catch(() => {});
}

/** People and groups in one list, most recent conversation first. */
function forwardRows(users, groups) {
  return [
    ...(users || []).map((u) => ({ ...u, is_group: false })),
    ...(groups || []).map((g) => ({ ...g, is_group: true })),
  ].sort(
    (a, b) =>
      (b.last_ts || 0) - (a.last_ts || 0) ||
      String(a.full_name || "").localeCompare(String(b.full_name || "")),
  );
}

const forwardToken = (row) => (row.is_group ? "g" : "u") + row.id;

function renderForwardPicker() {
  const needle = el.forwardSearch.value.trim().toLowerCase();
  const rows = (State.forwardList || []).filter(
    (r) =>
      !needle ||
      String(r.full_name || "").toLowerCase().includes(needle) ||
      String(r.username || "").toLowerCase().includes(needle),
  );

  el.forwardPicker.innerHTML = rows.length
    ? rows
        .map((r) => {
          const token = forwardToken(r);
          const done = State.forwardDone.has(token);
          const on = done || State.forwardPicked.has(token);
          const face = r.is_group
            ? groupTile(r.name, "avatar-xs", r)
            : `<img src="${escAttr(r.avatar)}" alt="" class="avatar avatar-xs">`;
          const handle = r.is_group
            ? `${r.member_count} members`
            : "@" + escHtml(r.username || "");

          return `
        <label class="member-option forward-option${done ? " done" : ""}">
          <input type="checkbox" value="${token}" ${on ? "checked" : ""} ${done ? "disabled" : ""}>
          ${face}
          <span class="member-name">${escHtml(r.full_name || r.name || "")}</span>
          <span class="member-handle">${done ? '<span class="forward-sent">✓ Sent</span>' : handle}</span>
        </label>`;
        })
        .join("")
    : `<div class="empty-state">${State.forwardList && State.forwardList.length ? "No chats match that." : "Loading chats…"}</div>`;

  updateForwardCount();
}

function updateForwardCount() {
  const n = State.forwardPicked.size;
  const sent = State.forwardDone.size;
  el.forwardCount.textContent = n
    ? `${n} of ${UNI.maxForward} selected`
    : sent
      ? "Sent. Pick another chat, or close."
      : `Choose up to ${UNI.maxForward} chats`;
  el.forwardSend.disabled = !n || State.forwardBusy;
  el.forwardSend.textContent = State.forwardBusy
    ? "Forwarding…"
    : sent && n
      ? "Try again"
      : "Forward";
}

function toggleForwardPick(input) {
  const token = input.value;
  if (State.forwardDone.has(token)) return;

  if (input.checked) {
    if (State.forwardPicked.size >= UNI.maxForward) {
      input.checked = false;
      showModalError(el.forwardError, `You can forward to up to ${UNI.maxForward} chats at once.`);
      return;
    }
    State.forwardPicked.add(token);
  } else {
    State.forwardPicked.delete(token);
  }
  showModalError(el.forwardError, "");
  updateForwardCount();
}

async function sendForward() {
  if (State.forwardBusy || !State.forwardMsgId) return;
  const targets = [...State.forwardPicked].filter((t) => !State.forwardDone.has(t));
  if (!targets.length) return;

  State.forwardBusy = true;
  showModalError(el.forwardError, "");
  updateForwardCount();

  const nameOf = (token) => {
    const row = (State.forwardList || []).find((r) => forwardToken(r) === token);
    return row ? row.full_name || row.name : "a chat";
  };

  try {
    const d = await apiPost(
      "ajax/forward_message.php",
      formData({
        csrf_token: UNI.csrf,
        id: State.forwardMsgId,
        key: State.forwardKey,
        targets: targets.join(","),
      }),
    );

    const results = Array.isArray(d.results) ? d.results : [];
    results.forEach((r) => {
      if (!r.ok) return;
      State.forwardDone.add(r.target);
      State.forwardPicked.delete(r.target);
    });
    const failed = results.filter((r) => !r.ok);

    // Whatever landed shows up straight away: in the sidebar, and in this
    // conversation when it was one of the destinations.
    loadContacts(el.searchInput.value.trim());
    const here = isGroupChat() ? "g" + State.chatGroupId : "u" + State.chatUserId;
    if (results.some((r) => r.ok && r.target === here)) fetchMessages(true);

    if (!failed.length) {
      const sent = results.filter((r) => r.ok).length;
      closeModal(el.forwardModal);
      showToast(
        "Forwarded",
        sent === 1 ? `Sent to ${nameOf(results[0].target)}.` : `Sent to ${sent} chats.`,
        "",
        3000,
      );
      return;
    }

    showModalError(
      el.forwardError,
      `Not sent to ${failed.map((r) => nameOf(r.target)).join(", ")}: ${failed[0].error || "something went wrong"}. ` +
        "Try again — chats that already have it will not get a second copy.",
    );
  } catch (err) {
    if (err instanceof AuthError) return;
    // The request as a whole failed, so whether any of it landed is unknown.
    // The key is kept: trying again cannot duplicate whatever did.
    showModalError(
      el.forwardError,
      `${err.message || "The message could not be forwarded."} Try again — nothing will be sent twice.`,
    );
  } finally {
    State.forwardBusy = false;
    renderForwardPicker();
  }
}

function bindForwardUi() {
  if (!el.forwardModal) return;
  el.forwardSearch.addEventListener("input", renderForwardPicker);
  el.forwardPicker.addEventListener("change", (e) => {
    if (e.target.matches('input[type="checkbox"]')) toggleForwardPick(e.target);
  });
  el.forwardSend.addEventListener("click", sendForward);
  // Enter in the search box sends once something is picked, as in the composer.
  el.forwardSearch.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && State.forwardPicked.size) {
      e.preventDefault();
      sendForward();
    }
  });
}

// ── Markdown preview ──────────────────────────────────────────
// A .md attachment — one somebody uploaded, or a long message the server turned
// into one — opens here as a formatted document instead of downloading.
//
// The renderer below escapes every character of the file *before* it adds a
// single tag, and only ever adds tags it wrote itself. Raw HTML in the file is
// therefore shown as text, never parsed; links are kept only for http(s) and
// mailto; images are shown as links rather than fetched from wherever the file
// points. Nothing in a document can run.

/** Previews above this size are offered as a download instead. */
const MD_PREVIEW_MAX = 3 * 1024 * 1024;

async function openMarkdownPreview(url, name) {
  if (!url || !el.mdModal) return;

  if (State.mdAbort) State.mdAbort.abort();
  const ctrl = new AbortController();
  State.mdAbort = ctrl;

  el.mdTitle.textContent = name || "Document";
  el.mdTitle.title = name || "";
  el.mdDownload.href = url;
  el.mdDownload.setAttribute("download", name || "document.md");
  el.mdBody.innerHTML =
    '<div class="md-state"><span class="list-spinner"></span>Loading…</div>';
  openModal(el.mdModal);

  const fail = (message) => {
    el.mdBody.innerHTML = `
      <div class="md-state md-error">
        <strong>${escHtml(message)}</strong>
        <span>You can still try the Download button above.</span>
      </div>`;
  };

  try {
    const res = await fetch(url, { credentials: "same-origin", signal: ctrl.signal });
    if (!res.ok) {
      throw new Error(
        res.status === 404
          ? "This file is no longer available."
          : res.status === 403
            ? "You are signed out. Sign in again to open this file."
            : `The file could not be loaded (error ${res.status}).`,
      );
    }
    if (Number(res.headers.get("Content-Length") || 0) > MD_PREVIEW_MAX) {
      throw new Error("This file is too large to preview.");
    }

    const text = (await res.text()).replace(/^﻿/, "");
    if (State.mdAbort !== ctrl) return; // another preview was opened meanwhile

    if (!text.trim()) {
      el.mdBody.innerHTML = '<div class="md-state">This file is empty.</div>';
      return;
    }

    el.mdBody.innerHTML = `<article class="md-doc">${renderMarkdown(text)}</article>`;
    el.mdBody.scrollTop = 0;
  } catch (err) {
    if (err && err.name === "AbortError") return;
    fail(
      err instanceof TypeError
        ? "The file could not be loaded — check your connection."
        : (err && err.message) || "The file could not be loaded.",
    );
  } finally {
    if (State.mdAbort === ctrl) State.mdAbort = null;
  }
}

/** Escape for both text and attribute positions. */
function mdEsc(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

/** An href that is safe to put in front of a reader, or "" for anything else. */
function mdSafeHref(raw) {
  // `raw` is already escaped; undo the one entity a URL legitimately carries.
  const url = String(raw).replace(/&amp;/g, "&").trim();
  if (/^(https?:\/\/|mailto:)/i.test(url)) return mdEsc(url);
  if (/^www\./i.test(url)) return mdEsc("https://" + url);
  return "";
}

const MD_LINK_ATTRS = 'target="_blank" rel="noopener noreferrer nofollow"';

/** Inline formatting for one line (or one cell) of text. */
function mdInline(src) {
  const held = [];
  const hold = (html) => `\u0000${held.push(html) - 1}\u0000`;

  let s = String(src).replace(/\u0000/g, "");

  // Code spans first: nothing inside one is formatting.
  s = s.replace(/(`+)([^`]|[^`][\s\S]*?[^`])\1(?!`)/g, (_, _f, code) =>
    hold(`<code>${mdEsc(code.replace(/^ (.+) $/, "$1"))}</code>`),
  );

  // Backslash escapes keep their character literally.
  s = s.replace(/\\([\\`*_{}\[\]()#+\-.!|~<>])/g, (_, ch) => hold(mdEsc(ch)));

  s = mdEsc(s);

  // Images become links to the picture: a document does not get to make the
  // reader's browser fetch something from wherever it likes.
  s = s.replace(/!\[([^\]]*)\]\(\s*([^\s)]+)(?:\s+&quot;.*?&quot;)?\s*\)/g, (_m, alt, url) => {
    const href = mdSafeHref(url);
    return href
      ? hold(`<a href="${href}" ${MD_LINK_ATTRS}>🖼 ${alt || "image"}</a>`)
      : alt;
  });

  // [text](url "title")
  s = s.replace(/\[([^\]]+)\]\(\s*([^\s)]+)(?:\s+&quot;.*?&quot;)?\s*\)/g, (_m, text, url) => {
    const href = mdSafeHref(url);
    return href ? hold(`<a href="${href}" ${MD_LINK_ATTRS}>${text}</a>`) : text;
  });

  // <https://autolinks>
  s = s.replace(/&lt;((?:https?:\/\/|mailto:)[^\s]+?)&gt;/gi, (m, url) => {
    const href = mdSafeHref(url);
    return href ? hold(`<a href="${href}" ${MD_LINK_ATTRS}>${url}</a>`) : m;
  });

  // Bare addresses, the way the chat itself linkifies them.
  s = s.replace(
    /(?:https?:\/\/|www\.)(?:(?!&(?:gt|lt|quot|#39);)[^\s\u0000])+/gi,
    (m) => {
      const url = m.replace(/[.,;:!?*_~)]+$/, "");
      const rest = m.slice(url.length);
      const href = mdSafeHref(url);
      return href ? hold(`<a href="${href}" ${MD_LINK_ATTRS}>${url}</a>`) + rest : m;
    },
  );

  s = s
    .replace(/\*\*(?=\S)([\s\S]*?\S)\*\*/g, "<strong>$1</strong>")
    .replace(/(^|[^\w])__(?=\S)([\s\S]*?\S)__(?!\w)/g, "$1<strong>$2</strong>")
    .replace(/~~(?=\S)([\s\S]*?\S)~~/g, "<del>$1</del>")
    .replace(/(^|[^*\w])\*(?=[^\s*])([^*]*?[^\s*])\*(?![*\w])/g, "$1<em>$2</em>")
    .replace(/(^|[^\w])_(?=[^\s_])([^_]*?[^\s_])_(?!\w)/g, "$1<em>$2</em>");

  // Restore held fragments; a fragment may itself hold others (a link's text).
  for (let pass = 0; pass < 3 && s.includes("\u0000"); pass++) {
    s = s.replace(/\u0000(\d+)\u0000/g, (_, n) => held[Number(n)] ?? "");
  }
  return s;
}

const MD_FENCE = /^ {0,3}(`{3,}|~{3,})\s*([\w+#.-]*)/;
const MD_HEADING = /^ {0,3}(#{1,6})(?:\s+(.*?))?\s*#*\s*$/;
const MD_HR = /^ {0,3}([-*_])(?:\s*\1){2,}\s*$/;
const MD_QUOTE = /^ {0,3}>/;
const MD_ITEM = /^(\s*)([-*+]|\d{1,9}[.)])(\s+|$)(.*)$/;
const MD_TABLE_SEP = /^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/;

/** Does a new block start at line i, ending the paragraph before it? */
function mdBlockStart(lines, i) {
  const line = lines[i];
  return (
    MD_FENCE.test(line) ||
    MD_HEADING.test(line) ||
    MD_HR.test(line) ||
    MD_QUOTE.test(line) ||
    MD_ITEM.test(line) ||
    (line.includes("|") && i + 1 < lines.length && MD_TABLE_SEP.test(lines[i + 1]))
  );
}

/** A whole document, or the inside of a quote or list item. */
function renderMarkdown(src) {
  const lines = String(src)
    .replace(/\r\n?/g, "\n")
    .split("\n")
    .map((l) => l.replace(/^\t+/, (t) => "    ".repeat(t.length)));
  return mdBlocks(lines);
}

function mdBlocks(lines) {
  const out = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) {
      i++;
      continue;
    }

    // ``` fenced code ``` — runs to its closing fence or the end of the file.
    let m = MD_FENCE.exec(line);
    if (m) {
      const close = new RegExp(`^ {0,3}${m[1][0] === "`" ? "`" : "~"}{${m[1].length},}\\s*$`);
      const body = [];
      i++;
      while (i < lines.length && !close.test(lines[i])) body.push(lines[i++]);
      i++;
      out.push(
        `<pre><code${m[2] ? ` data-lang="${mdEsc(m[2])}"` : ""}>${mdEsc(body.join("\n"))}</code></pre>`,
      );
      continue;
    }

    m = MD_HEADING.exec(line);
    if (m) {
      const level = m[1].length;
      out.push(`<h${level}>${mdInline(m[2] || "")}</h${level}>`);
      i++;
      continue;
    }

    if (MD_HR.test(line)) {
      out.push("<hr>");
      i++;
      continue;
    }

    if (MD_QUOTE.test(line)) {
      const body = [];
      while (i < lines.length && lines[i].trim() && (MD_QUOTE.test(lines[i]) || body.length)) {
        if (!MD_QUOTE.test(lines[i]) && mdBlockStart(lines, i)) break;
        body.push(lines[i++].replace(/^ {0,3}> ?/, ""));
      }
      out.push(`<blockquote>${mdBlocks(body)}</blockquote>`);
      continue;
    }

    if (line.includes("|") && i + 1 < lines.length && MD_TABLE_SEP.test(lines[i + 1])) {
      const [html, next] = mdTable(lines, i);
      out.push(html);
      i = next;
      continue;
    }

    if (MD_ITEM.test(line)) {
      const [html, next] = mdList(lines, i);
      out.push(html);
      i = next;
      continue;
    }

    // A paragraph: every line up to a blank one or the start of another block.
    // Single line breaks are kept, as they are in the chat — a long message
    // that became this file must read the way it was typed.
    const para = [];
    while (i < lines.length && lines[i].trim() && (!para.length || !mdBlockStart(lines, i))) {
      // "Title" over "=====" or "-----" is a heading too.
      if (para.length && /^ {0,3}(=+|-+)\s*$/.test(lines[i])) break;
      para.push(lines[i++]);
    }
    if (i < lines.length && para.length && /^ {0,3}(=+|-+)\s*$/.test(lines[i])) {
      const level = lines[i].trim()[0] === "=" ? 1 : 2;
      const title = para.pop();
      if (para.length) out.push(`<p>${para.map((l) => mdInline(l.trim())).join("<br>")}</p>`);
      out.push(`<h${level}>${mdInline(title.trim())}</h${level}>`);
      i++;
      continue;
    }
    out.push(`<p>${para.map((l) => mdInline(l.trim())).join("<br>")}</p>`);
  }

  return out.join("\n");
}

/** Split a table row on its unescaped pipes. */
function mdRow(line) {
  let row = line.trim();
  if (row.startsWith("|")) row = row.slice(1);
  if (row.endsWith("|") && !row.endsWith("\\|")) row = row.slice(0, -1);
  return row.split(/(?<!\\)\|/).map((c) => c.trim());
}

function mdTable(lines, start) {
  const head = mdRow(lines[start]);
  const align = mdRow(lines[start + 1]).map((c) =>
    /^:-+:$/.test(c) ? "center" : /^-+:$/.test(c) ? "right" : /^:-+$/.test(c) ? "left" : "",
  );
  const cell = (tag, text, col) =>
    `<${tag}${align[col] ? ` style="text-align:${align[col]}"` : ""}>${mdInline(text || "")}</${tag}>`;

  let i = start + 2;
  const body = [];
  while (i < lines.length && lines[i].trim() && lines[i].includes("|")) {
    const cells = mdRow(lines[i++]);
    body.push(`<tr>${head.map((_, c) => cell("td", cells[c], c)).join("")}</tr>`);
  }

  const html = `
    <div class="md-table"><table>
      <thead><tr>${head.map((h, c) => cell("th", h, c)).join("")}</tr></thead>
      <tbody>${body.join("")}</tbody>
    </table></div>`;
  return [html, i];
}

/** A list, nested lists and all. Returns [html, index of the next line]. */
function mdList(lines, start) {
  const first = MD_ITEM.exec(lines[start]);
  const indent = first[1].length;
  const ordered = /\d/.test(first[2]);
  const items = [];
  let loose = false;
  let i = start;

  while (i < lines.length) {
    const line = lines[i];
    const m = MD_ITEM.exec(line);

    // A sibling: same depth, same kind of list.
    if (m && m[1].length === indent && /\d/.test(m[2]) === ordered) {
      items.push({ lines: [m[4]], offset: m[1].length + m[2].length + Math.max(1, m[3].length) });
      i++;
      continue;
    }

    if (!line.trim()) {
      // A blank line continues the list only if more of it follows.
      let j = i + 1;
      while (j < lines.length && !lines[j].trim()) j++;
      if (j >= lines.length) break;
      const next = MD_ITEM.exec(lines[j]);
      const deeper = lines[j].match(/^\s*/)[0].length > indent;
      if ((next && next[1].length === indent && /\d/.test(next[2]) === ordered) || deeper) {
        items[items.length - 1].lines.push("");
        if (!deeper) loose = true;
        i = j;
        continue;
      }
      break;
    }

    const lead = line.match(/^\s*/)[0].length;
    const item = items[items.length - 1];
    if (lead > indent) {
      item.lines.push(line.slice(Math.min(lead, item.offset)));
      i++;
      continue;
    }
    // A wrapped line of the item's own text, written without indentation.
    if (!mdBlockStart(lines, i) && item.lines[item.lines.length - 1].trim()) {
      item.lines.push(line.trim());
      i++;
      continue;
    }
    break;
  }

  const lis = items.map(({ lines: body }) => {
    let html = mdBlocks(body);
    // A tight list holds its text directly, not wrapped in a paragraph.
    if (!loose) html = html.replace(/^<p>([\s\S]*?)<\/p>/, "$1");

    // - [ ] / - [x] task items.
    const task = /^\[( |x|X)\]\s+/.exec(body[0] || "");
    if (task) {
      html = html.replace(/^(<p>)?\[( |x|X)\]\s+/, "$1");
      return `<li class="md-task"><input type="checkbox" disabled${task[1] !== " " ? " checked" : ""}> ${html}</li>`;
    }
    return `<li>${html}</li>`;
  });

  const startNum = ordered ? parseInt(first[2], 10) : 1;
  const tag = ordered ? "ol" : "ul";
  return [
    `<${tag}${ordered && startNum !== 1 ? ` start="${startNum}"` : ""}>${lis.join("")}</${tag}>`,
    i,
  ];
}

// ── Mark read ─────────────────────────────────────────────────
/** Marks the open conversation read — a person's messages, or a group's. */
function markReadCurrent() {
  if (!chatOpen()) return Promise.resolve();

  const body = isGroupChat()
    ? { csrf_token: UNI.csrf, group: State.chatGroupId }
    : { csrf_token: UNI.csrf, from: State.chatUserId };

  return apiPost("ajax/mark_read.php", formData(body))
    .then((d) => {
      updateTitle(d.unread ?? State.unreadCount);
      if (d.marked) loadContacts(el.searchInput.value.trim());
    })
    .catch(() => {});
}

// ── Typing ────────────────────────────────────────────────────
function sendTyping() {
  if (!chatOpen()) return;

  // The server keeps a typing flag alive for TYPING_TTL seconds, so one ping
  // every couple of seconds is enough while somebody keeps typing.
  const now = Date.now();
  if (now - State.typingSentAt < 2000) return;
  State.typingSentAt = now;

  const body = isGroupChat()
    ? { csrf_token: UNI.csrf, group: State.chatGroupId }
    : { csrf_token: UNI.csrf, to: State.chatUserId };

  apiPost("ajax/typing.php", formData(body)).catch(() => {});
}

/**
 * @param {string[]} [typists] First names, in a group. A direct chat needs no
 *   names — there is only one person it could be.
 */
function showTyping(typists) {
  if (!State.chatUser) return;

  if (el.typingIndicator.hidden) {
    const group = isGroupChat();
    el.typingAvatar.innerHTML = group
      ? groupTile(State.chatUser.name, "avatar-xs", State.chatUser)
      : `<img src="${escAttr(State.chatUser.avatar)}" alt="">`;

    // "Ana is typing…" / "Ana, Ravi are typing…" – a group needs to say who.
    const names = Array.isArray(typists) ? typists : [];
    el.typingWho.textContent =
      group && names.length
        ? `${names.join(", ")} ${names.length === 1 ? "is" : "are"} typing…`
        : "";

    el.typingIndicator.hidden = false;
    scrollToBottom();
  }
  clearTimeout(State.typingAckTimer);
  State.typingAckTimer = setTimeout(hideTyping, 6000);
}

function hideTyping() {
  clearTimeout(State.typingAckTimer);
  el.typingIndicator.hidden = true;
  el.typingWho.textContent = "";
}

// ── Polling ───────────────────────────────────────────────────
function startPolling() {
  stopPolling();
  State.pollTimer = setInterval(() => {
    if (!isChatVisible()) return; // don't hammer the server in a background tab
    if (backingOff()) return;
    fetchMessages();
  }, UNI.pollMs);
}

function stopPolling() {
  if (State.pollTimer) {
    clearInterval(State.pollTimer);
    State.pollTimer = null;
  }
}

function startStatusTimer() {
  updateOnlineStatus();
  State.statusTimer = setInterval(updateOnlineStatus, UNI.statusMs);
}

/**
 * Heartbeat. `focus` tells the server whether the user is actually here — the
 * case where a new message has to reach them as a push notification instead of
 * as something drawn in a page nobody is looking at.
 *
 * Also sent the moment the window loses focus rather than only on the next
 * tick: the server suppresses pushes for PUSH_ACTIVE_TTL seconds after the last
 * focused beat, so a stale one is a stretch of silence for somebody who has
 * already walked away.
 */
function updateOnlineStatus() {
  // On a slow line the previous beat may still be on its way; a second one
  // behind it says nothing new and competes with the message poll.
  if (State.statusBusy || backingOff()) return;
  State.statusBusy = true;
  apiGet("ajax/update_status.php?focus=" + (isUserPresent() ? 1 : 0))
    .finally(() => {
      State.statusBusy = false;
    })
    .then((d) => {
      if (typeof d.unread === "number") updateTitle(d.unread);
      if (d.incoming_call && typeof Call !== "undefined") Call.onIncoming(d.incoming_call);
      // The heartbeat is what extends a streak, so the first one of a new day
      // has just changed the numbers the chip is showing. Refreshing here means
      // the flame ticks over as the day does, rather than up to a minute later.
      if (d.newDay) pollGamify();
    })
    .catch(() => {});
}

function startContactsRefresh() {
  State.usersTimer = setInterval(() => {
    if (!isChatVisible()) return;
    // The timer only refreshes; anything that needs fresh data calls
    // loadContacts() itself and is never skipped.
    if (State.contactsBusy || backingOff()) return;
    loadContacts(el.searchInput.value.trim());
  }, UNI.usersMs);
}

function stopAllTimers() {
  stopPolling();
  if (State.statusTimer) {
    clearInterval(State.statusTimer);
    State.statusTimer = null;
  }
  if (State.usersTimer) {
    clearInterval(State.usersTimer);
    State.usersTimer = null;
  }
  if (State.gamifyTimer) {
    clearInterval(State.gamifyTimer);
    State.gamifyTimer = null;
  }
}

// ── Notifications ─────────────────────────────────────────────
function requestNotifPermission() {
  if (!HAS_NOTIFICATIONS || Notification.permission !== "default") return;
  try {
    const p = Notification.requestPermission();
    if (p && typeof p.then === "function") {
      p.then((result) => {
        State.notifGranted = result === "granted";
        if (State.notifGranted) subscribePush();
        renderNotifButton();
      });
    }
  } catch (_) {
    /* older callback-only implementations */
  }
}

// ── Web push ──────────────────────────────────────────────────
// Notifications that arrive with the tab closed come from the service worker,
// not from this script — which is why the subscription has to be registered
// with the server while the app *is* open.

function pushSupported() {
  return (
    HAS_NOTIFICATIONS &&
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    !!UNI.vapidKey
  );
}

/**
 * The service worker registration, or null — but never a promise that hangs.
 *
 * `navigator.serviceWorker.ready` waits for an *active* worker and waits for
 * ever if registration failed: a blocked origin, a worker that threw on
 * install, a browser with storage turned off. Every push path used to await it
 * bare, so on those machines subscribePush() never resolved, the bell never
 * changed, and nothing anywhere said why.
 */
async function workerRegistration(timeoutMs = 10000) {
  if (!("serviceWorker" in navigator)) return null;

  try {
    // Registering here as well as in initPWA() is deliberate: the two are
    // ordered by whichever finishes first, and register() resolves against the
    // existing registration when there is one.
    navigator.serviceWorker.register(UNI.swUrl).catch(() => {});
  } catch (_) {
    /* insecure origin, or workers are switched off */
  }

  const timeout = new Promise((resolve) => setTimeout(() => resolve(null), timeoutMs));
  return Promise.race([navigator.serviceWorker.ready, timeout]).catch(() => null);
}

/** Why this browser cannot be subscribed, in words somebody can act on. */
function pushBlocker() {
  if (!HAS_NOTIFICATIONS) return "This browser does not support notifications.";
  if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
    return "This browser does not support background notifications.";
  }
  if (!window.isSecureContext) {
    return "Notifications need a secure (https) connection.";
  }
  if (!UNI.vapidKey) return "This server is not set up to send notifications.";
  if (Notification.permission === "denied") {
    return "Notifications are blocked for this site in your browser settings.";
  }
  return "";
}

/** The VAPID public key as the byte array PushManager.subscribe() expects. */
function vapidKeyBytes(base64url) {
  const padded = (base64url + "=".repeat((4 - (base64url.length % 4)) % 4))
    .replace(/-/g, "+")
    .replace(/_/g, "/");
  const raw = atob(padded);
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

async function subscribePush() {
  if (!pushSupported() || State.pushBusy) return false;
  State.pushBusy = true;
  State.pushError = "";

  try {
    const reg = await workerRegistration();
    if (!reg) {
      State.pushError =
        "The background worker could not start, so notifications cannot be delivered.";
      return false;
    }

    let sub = await reg.pushManager.getSubscription();

    // A subscription made against a different VAPID key can never be decrypted
    // by us, so replace it rather than trying to reuse it.
    if (sub && !sameApplicationKey(sub, UNI.vapidKey)) {
      await sub.unsubscribe().catch(() => {});
      sub = null;
    }
    if (!sub) {
      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: vapidKeyBytes(UNI.vapidKey),
      });
    }

    const json = sub.toJSON();
    await apiPost(
      "ajax/push_subscribe.php",
      formData({
        csrf_token: UNI.csrf,
        action: "subscribe",
        endpoint: sub.endpoint,
        p256dh: (json.keys && json.keys.p256dh) || "",
        auth: (json.keys && json.keys.auth) || "",
      }),
    );

    State.pushEnabled = true;
    return true;
  } catch (err) {
    if (!(err instanceof AuthError)) {
      State.pushError = err && err.message ? err.message : "";
      console.warn("[UnivoChat] Push subscription failed:", err);
    }
    return false;
  } finally {
    State.pushBusy = false;
    renderNotifButton();
  }
}

/**
 * Send one notification to this device, now.
 *
 * The single most useful thing to be able to do when somebody says
 * notifications "do not work": it walks the whole path — subscription, VAPID
 * signature, push service, service worker, operating system — and whichever
 * link is broken, the answer arrives in seconds instead of after a day of
 * waiting for a message that never announces itself. macOS in particular has
 * its own switch for this, in System Settings, that the browser cannot see.
 */
async function testPush() {
  try {
    const d = await apiPost(
      "ajax/push_subscribe.php",
      formData({ csrf_token: UNI.csrf, action: "test" }),
    );
    return !!(d && d.ok);
  } catch (_) {
    return false;
  }
}

async function unsubscribePush() {
  if (!("serviceWorker" in navigator) || State.pushBusy) return;
  State.pushBusy = true;

  try {
    const reg = await workerRegistration();
    const sub = reg && (await reg.pushManager.getSubscription());
    if (!sub) {
      State.pushEnabled = false;
      return;
    }

    await apiPost(
      "ajax/push_subscribe.php",
      formData({
        csrf_token: UNI.csrf,
        action: "unsubscribe",
        endpoint: sub.endpoint,
      }),
    ).catch(() => {});

    await sub.unsubscribe().catch(() => {});
    State.pushEnabled = false;
  } finally {
    State.pushBusy = false;
    renderNotifButton();
  }
}

function sameApplicationKey(sub, expected) {
  const key = sub.options && sub.options.applicationServerKey;
  if (!key) return true; // nothing to compare against – assume it is ours

  const bytes = new Uint8Array(key);
  let binary = "";
  bytes.forEach((b) => {
    binary += String.fromCharCode(b);
  });

  return (
    btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "") ===
    expected
  );
}

/** Reflect the current state on the bell button in the sidebar. */
function renderNotifButton() {
  if (!el.notifBtn) return;

  // No push support (no service worker, no VAPID key, or an insecure origin)
  // means there is nothing the button could switch on.
  if (!pushSupported()) {
    el.notifBtn.hidden = true;
    return;
  }

  const blocked = HAS_NOTIFICATIONS && Notification.permission === "denied";
  const on = State.pushEnabled && !blocked;

  el.notifBtn.hidden = false;
  el.notifBtn.classList.toggle("active", on);
  el.notifBtn.title = blocked
    ? "Notifications are blocked in your browser settings"
    : on
      ? "Notifications on — click to turn off"
      : "Turn on notifications";

  // The slash across the bell is the "off" state.
  if (el.notifSlash) el.notifSlash.style.display = on ? "none" : "";
}

async function toggleNotifications() {
  if (State.pushBusy) return;

  if (HAS_NOTIFICATIONS && Notification.permission === "denied") {
    showToast(
      "Notifications blocked",
      "Allow notifications for this site in your browser settings, then try again.",
      "",
      6000,
    );
    return;
  }

  if (State.pushEnabled) {
    await unsubscribePush();
    showToast(
      "Notifications off",
      "You will not be notified while UnivoChat is closed.",
      "",
      3500,
    );
    return;
  }

  if (HAS_NOTIFICATIONS && Notification.permission === "default") {
    const result = await Notification.requestPermission();
    State.notifGranted = result === "granted";
    if (result !== "granted") {
      renderNotifButton();
      return;
    }
  }

  const ok = await subscribePush();
  if (!ok) {
    showToast(
      "Not enabled",
      State.pushError ||
        pushBlocker() ||
        "This browser could not be subscribed for notifications.",
      "",
      6000,
    );
    return;
  }

  showToast(
    "Notifications on",
    "You will be notified about new messages even when UnivoChat is closed.",
    "",
    3500,
  );

  // …and prove it, rather than leaving somebody to find out days later that
  // their operating system was swallowing them all along.
  const delivered = await testPush();
  if (!delivered) {
    showToast(
      "Test notification not delivered",
      "UnivoChat is subscribed, but nothing came through. Check that notifications " +
        "are allowed for your browser in your system settings.",
      "",
      8000,
    );
  }
}

/** Ask the browser whether this device is already subscribed. */
async function initPush() {
  renderNotifButton();
  if (!pushSupported()) return;

  try {
    const reg = await workerRegistration();
    if (!reg) return;

    const sub = await reg.pushManager.getSubscription();
    State.pushEnabled = !!sub && Notification.permission === "granted";

    // Somebody who already granted permission gets re-registered on every load.
    // That is what repairs a subscription the push service retired while the
    // app was closed: the worker renews it locally, this hands it back to the
    // server, and notifications carry on without the user doing anything.
    //
    // Retried once, a few seconds later, because the failure this recovers from
    // is nearly always a network that was not up yet when the page loaded — and
    // a subscription that never reaches the server is a person who quietly
    // stops being notified until they next think to check the bell.
    if (Notification.permission === "granted") {
      const ok = await subscribePush();
      if (!ok) setTimeout(() => subscribePush(), 8000);
    }
  } catch (_) {
    /* no service worker on this origin */
  }

  renderNotifButton();
}

/** In-app toast always; a system notification too when the window is hidden. */
function announce(user, preview) {
  const body = (preview || "").slice(0, 80) || "New message";
  showToast(user.full_name, body, user.avatar, 5000, () => {
    window.focus();
    openChat(user.id);
  });

  if (!document.hasFocus() && State.notifGranted) {
    try {
      const n = new Notification("UnivoChat – " + user.full_name, {
        body,
        icon: user.avatar,
        tag: "msg-" + user.id,
      });
      n.onclick = () => {
        window.focus();
        openChat(user.id);
        n.close();
      };
    } catch (_) {
      /* notification construction can throw on mobile */
    }
  }
}

/**
 * The group flavour of announce(). A group that has a display picture is
 * announced with it; one that has not falls back to the drawn tile, which is a
 * data: URI the system notification is better off without.
 */
function announceGroup(group) {
  const body = (group.last_message || "New message").slice(0, 80);
  const photo = group.has_photo ? group.avatar : "";

  showToast(group.name, body, group.avatar || "", 5000, () => {
    window.focus();
    openGroup(group.id);
  });

  if (!document.hasFocus() && State.notifGranted) {
    try {
      const n = new Notification("UnivoChat – " + group.name, {
        body,
        ...(photo ? { icon: photo } : {}),
        tag: "group-" + group.id,
      });
      n.onclick = () => {
        window.focus();
        openGroup(group.id);
        n.close();
      };
    } catch (_) {
      /* notification construction can throw on mobile */
    }
  }
}

// ══ Groups ════════════════════════════════════════════════════
// Any approved user can create a group and becomes its admin. Everything the
// server enforces (membership to read, admin to manage) is mirrored here only
// so the UI does not offer buttons that would be refused.

function openModal(modal) {
  modal.hidden = false;
  document.body.classList.add("modal-open");
  modal
    .querySelector("input, button, [tabindex]")
    ?.focus({ preventScroll: true });
}

function closeModal(modal) {
  modal.hidden = true;
  if (!document.querySelector(".modal:not([hidden])")) {
    document.body.classList.remove("modal-open");
  }
  if (modal === el.cameraModal) stopCamera();
  // A preview closed before its file arrived stops waiting for it.
  if (modal === el.mdModal && State.mdAbort) State.mdAbort.abort();
  // A staged picture holds an object URL; abandoning the dialog releases it.
  if (modal === el.groupModal) clearStagedGroupPhoto();
}

function showModalError(node, message) {
  node.textContent = message || "";
  node.hidden = !message;
}

// ── Create a group ────────────────────────────────────────────
function openGroupModal() {
  el.groupName.value = "";
  el.groupDesc.value = "";
  el.groupMemberSearch.value = "";
  showModalError(el.groupModalError, "");
  clearStagedGroupPhoto();
  renderMemberPicker(new Set());
  openModal(el.groupModal);
}

// ── The group's display picture ───────────────────────────────
// Validated here before it is sent for the ordinary reason: a 6 MB photo that
// the server is going to refuse should be refused before it is uploaded, not
// after. The server checks it again either way.

/** @returns {string} Empty when the picture is fine, otherwise why it is not. */
function pictureError(file) {
  if (!file) return "No picture was chosen.";
  if (!file.type || !file.type.startsWith("image/")) {
    return "Choose an image file (JPG, PNG, GIF or WebP).";
  }
  if (file.size > UNI.maxAvatar) {
    return `The picture must be ${Math.round(UNI.maxAvatar / 1048576)} MB or smaller.`;
  }
  return "";
}

/** Hold a chosen picture until the group it belongs to actually exists. */
function stageGroupPhoto(file) {
  const problem = pictureError(file);
  if (problem) {
    showModalError(el.groupModalError, problem);
    return;
  }

  clearStagedGroupPhoto();
  State.groupPhoto = { file, url: URL.createObjectURL(file) };
  showModalError(el.groupModalError, "");

  el.groupPhotoPreview.src = State.groupPhoto.url;
  el.groupPhotoPreview.hidden = false;
  el.groupPhotoEmpty.hidden = true;
  el.groupPhotoClear.hidden = false;
}

function clearStagedGroupPhoto() {
  if (State.groupPhoto) URL.revokeObjectURL(State.groupPhoto.url);
  State.groupPhoto = null;

  el.groupPhotoInput.value = "";
  el.groupPhotoPreview.hidden = true;
  el.groupPhotoPreview.removeAttribute("src");
  el.groupPhotoEmpty.hidden = false;
  el.groupPhotoClear.hidden = true;
}

/**
 * @param {Set<number>} chosen Ids that stay ticked across a re-render, so
 *   typing in the filter box does not silently drop earlier selections.
 */
function renderMemberPicker(chosen) {
  const needle = el.groupMemberSearch.value.trim().toLowerCase();
  const people = State.contacts.filter(
    (u) =>
      !needle ||
      String(u.full_name).toLowerCase().includes(needle) ||
      String(u.username).toLowerCase().includes(needle),
  );

  el.groupMemberPicker.innerHTML = people.length
    ? people
        .map(
          (u) => `
        <label class="member-option">
          <input type="checkbox" value="${u.id}" ${chosen.has(u.id) ? "checked" : ""}>
          <img src="${escAttr(u.avatar)}" alt="" class="avatar avatar-xs">
          <span class="member-name">${escHtml(u.full_name)}</span>
          <span class="member-handle">@${escHtml(u.username)}</span>
        </label>`,
        )
        .join("")
    : '<div class="empty-state">Nobody matches that.</div>';

  updatePickCount(chosen);
}

function pickedMembers() {
  return new Set(
    [...el.groupMemberPicker.querySelectorAll("input:checked")].map((i) =>
      Number(i.value),
    ),
  );
}

function updatePickCount(chosen) {
  const n = chosen.size;
  el.groupPickCount.textContent = n
    ? `(${n} selected)`
    : "(none selected — you can add people later)";
}

function createGroup() {
  const name = el.groupName.value.trim();
  if (!name) {
    showModalError(el.groupModalError, "Give the group a name.");
    el.groupName.focus();
    return;
  }

  const members = [...pickedMembers()];
  const fd = formData({
    csrf_token: UNI.csrf,
    action: "create",
    name,
    description: el.groupDesc.value.trim(),
  });
  members.forEach((id) => fd.append("members[]", id));
  if (State.groupPhoto)
    fd.append("avatar", State.groupPhoto.file, State.groupPhoto.file.name);

  el.groupCreateBtn.disabled = true;
  showModalError(el.groupModalError, "");

  apiPost("ajax/groups.php", fd)
    .then((d) => {
      closeModal(el.groupModal);
      clearStagedGroupPhoto();
      // The group was made either way; a picture the server would not take is
      // said out loud rather than silently dropped.
      if (d.warning) showToast("Picture not saved", d.warning, "", 5000);
      showToast(
        "Group created",
        `${d.group.name} · ${d.group.member_count} members`,
        d.group.has_photo ? d.group.avatar : "",
        3500,
      );
      return loadContacts().then(() => openGroup(d.group.id));
    })
    .catch((err) => {
      if (err instanceof AuthError) return;
      showModalError(
        el.groupModalError,
        err.message || "The group could not be created.",
      );
    })
    .finally(() => {
      el.groupCreateBtn.disabled = false;
    });
}

// ── Group info ────────────────────────────────────────────────
function openGroupInfo() {
  if (!isGroupChat()) return;

  el.groupInfoBody.innerHTML = '<div class="empty-state">Loading…</div>';
  showModalError(el.groupInfoError, "");
  openModal(el.groupInfoModal);

  apiGet(`ajax/groups.php?action=info&group_id=${State.chatGroupId}`)
    .then((d) => renderGroupInfo(d.group))
    .catch((err) => {
      if (err instanceof AuthError) return;
      el.groupInfoBody.innerHTML =
        '<div class="empty-state">This group is not available.</div>';
    });
}

function renderGroupInfo(group) {
  State.groupInfo = group;
  const admin = group.my_role === "admin";
  el.groupInfoTitle.textContent = group.name;

  // Everyone sees the roster; only an admin is offered the controls that
  // change it — the server refuses the rest either way.
  const inGroup = new Set(group.members.map((m) => m.id));
  const addable = State.contacts.filter((u) => !inGroup.has(u.id));

  // The default group has a fixed roster: everybody approved is in it and
  // stays in it. So the controls that would take somebody out of it are not
  // offered — leaving, and the per-member ✕. Renaming and the picture still
  // are, because those change how the room looks, not who is in it.
  const fixed = !!group.is_default;
  if (el.groupLeaveBtn) el.groupLeaveBtn.hidden = fixed;

  // The picture is the first thing in the panel, the way it is in every other
  // messenger. Everybody sees it; only an admin gets the button over it.
  const photo = `
    <div class="group-photo-block">
      <div class="group-photo-frame ${admin && group.can_photo ? "editable" : ""}">
        <img src="${escAttr(group.avatar)}" alt="${escAttr(group.name)}" id="groupInfoAvatar">
        ${
          admin && group.can_photo
            ? `
          <button type="button" class="group-photo-edit" id="groupPhotoEdit"
                  title="Change group picture" aria-label="Change group picture">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
              <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/>
              <circle cx="12" cy="13" r="4"/>
            </svg>
          </button>
          <input type="file" id="groupPhotoUpload" accept="image/*" hidden>`
            : ""
        }
      </div>
      ${
        admin && group.can_photo
          ? `
        <div class="group-photo-actions">
          <button type="button" class="btn btn-ghost btn-sm" id="groupPhotoChange">
            ${group.has_photo ? "Change picture" : "Add picture"}
          </button>
          ${
            group.has_photo
              ? '<button type="button" class="btn btn-ghost btn-sm" id="groupPhotoRemove">Remove</button>'
              : ""
          }
        </div>`
          : ""
      }
    </div>`;

  el.groupInfoBody.innerHTML = `
    ${photo}

    ${
      admin
        ? `
      <label class="form-label" for="groupRename">Group name</label>
      <div class="inline-form">
        <input type="text" id="groupRename" class="form-input" maxlength="80" value="${escAttr(group.name)}">
        <button class="btn btn-ghost" id="groupRenameBtn">Save</button>
      </div>`
        : ""
    }

    ${group.description ? `<p class="group-desc">${escHtml(group.description)}</p>` : ""}

    ${
      fixed
        ? `<div class="group-default-note">
             <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
               <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
               <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
             </svg>
             <span>Everyone joins automatically. This group cannot be left.</span>
           </div>`
        : ""
    }

    <div class="form-label">${group.member_count} member${group.member_count === 1 ? "" : "s"}</div>
    <div class="member-list-panel">
      ${group.members
        .map(
          (m) => `
        <div class="member-row">
          <img src="${escAttr(m.avatar)}" alt="" class="avatar avatar-xs">
          <span class="member-name">
            ${escHtml(m.full_name)}${m.id === UNI.uid ? ' <span class="text-muted">(you)</span>' : ""}
          </span>
          ${m.role === "admin" ? '<span class="group-role">admin</span>' : ""}
          ${
            admin && !fixed && m.id !== UNI.uid
              ? `<button class="member-remove" data-remove-member="${m.id}" title="Remove from group">✕</button>`
              : ""
          }
        </div>`,
        )
        .join("")}
    </div>

    ${
      admin
        ? addable.length
          ? `
      <div class="form-label">Add people</div>
      <div class="member-picker" id="groupAddPicker">
        ${addable
          .map(
            (u) => `
          <label class="member-option">
            <input type="checkbox" value="${u.id}">
            <img src="${escAttr(u.avatar)}" alt="" class="avatar avatar-xs">
            <span class="member-name">${escHtml(u.full_name)}</span>
          </label>`,
          )
          .join("")}
      </div>
      <button class="btn btn-primary btn-block" id="groupAddBtn">Add selected</button>`
          : '<div class="form-hint">Everybody is already in this group.</div>'
        : ""
    }
  `;
}

function groupAction(fields, onDone) {
  showModalError(el.groupInfoError, "");
  return apiPost("ajax/groups.php", fields)
    .then((d) => {
      loadContacts(el.searchInput.value.trim());
      if (onDone) onDone(d);
    })
    .catch((err) => {
      if (err instanceof AuthError) return;
      showModalError(el.groupInfoError, err.message || "That did not work.");
    });
}

/**
 * Send a new group picture, or clear the one that is there.
 *
 * Everything that shows a group re-reads it afterwards: the info panel, the
 * chat header (through the message poll) and the sidebar, so the new picture
 * appears everywhere at once rather than only where it was changed.
 */
function saveGroupPhoto(file) {
  const group = State.groupInfo;
  if (!group || State.groupPhotoBusy) return;

  if (file) {
    const problem = pictureError(file);
    if (problem) {
      showModalError(el.groupInfoError, problem);
      return;
    }
  }

  const fd = formData({
    csrf_token: UNI.csrf,
    action: file ? "avatar" : "avatar_clear",
    group_id: group.id,
  });
  if (file) fd.append("avatar", file, file.name);

  State.groupPhotoBusy = true;
  showModalError(el.groupInfoError, "");
  el.groupInfoBody.classList.add("busy");

  groupAction(fd, (d) => {
    renderGroupInfo(d.group);
    fetchMessages(true); // repaints the chat header with the new picture
    showToast(
      file ? "Picture updated" : "Picture removed",
      `${d.group.name} now shows ${file ? "its new picture" : "its initials"}.`,
      file ? d.group.avatar : "",
      3500,
    );
  }).finally(() => {
    State.groupPhotoBusy = false;
    el.groupInfoBody.classList.remove("busy");
  });
}

function leaveGroup() {
  const group = State.groupInfo;
  if (!group || group.is_default) return;
  if (!confirm(`Leave “${group.name}”? You will stop receiving its messages.`))
    return;

  groupAction(
    formData({ csrf_token: UNI.csrf, action: "leave", group_id: group.id }),
    () => {
      closeModal(el.groupInfoModal);
      closeChat();
      showToast("Left group", `You are no longer in ${group.name}.`, "", 3500);
      loadContacts(el.searchInput.value.trim());
    },
  );
}

// ══ Camera ════════════════════════════════════════════════════
// getUserMedia needs a secure context, so the button is only shown when the
// API is actually there; on plain http:// over a LAN it stays hidden rather
// than failing on click.
// UNI.hasCamera comes first: while the feature is held back the markup is not
// rendered at all, so nothing here can run and no permission prompt can appear.
const HAS_CAMERA = !!(
  UNI.hasCamera &&
  navigator.mediaDevices &&
  navigator.mediaDevices.getUserMedia
);

async function openCamera() {
  if (!HAS_CAMERA || !chatOpen()) return;

  State.cameraShot = null;
  el.cameraShot.hidden = true;
  el.cameraVideo.hidden = false;
  el.cameraRetake.hidden = true;
  el.cameraUse.hidden = true;
  el.cameraShutter.hidden = false;
  showModalError(el.cameraError, "");
  el.cameraError.hidden = true;
  openModal(el.cameraModal);

  await startCamera();
}

async function startCamera() {
  stopCamera();
  try {
    State.cameraStream = await navigator.mediaDevices.getUserMedia({
      video: {
        facingMode: State.cameraFacing,
        width: { ideal: 1920 },
        height: { ideal: 1080 },
      },
      audio: false,
    });
    el.cameraVideo.srcObject = State.cameraStream;
    el.cameraVideo
      .closest(".camera-stage")
      .classList.toggle("mirrored", State.cameraFacing === "user");
    await el.cameraVideo.play().catch(() => {});

    // Only worth offering where there is more than one camera to flip to.
    const cams = await navigator.mediaDevices
      .enumerateDevices()
      .then((list) => list.filter((d) => d.kind === "videoinput"))
      .catch(() => []);
    el.cameraFlip.hidden = cams.length < 2;
  } catch (err) {
    el.cameraVideo.hidden = true;
    el.cameraShutter.hidden = true;
    el.cameraError.hidden = false;
    el.cameraError.textContent =
      err && err.name === "NotAllowedError"
        ? "Camera access was blocked. Allow it in your browser’s site settings and try again."
        : "No camera could be started on this device.";
  }
}

/** Always called on close: a live camera light behind a closed dialog is a bug. */
function stopCamera() {
  if (!el.cameraVideo) return; // feature held back: nothing was ever started
  if (State.cameraStream) {
    State.cameraStream.getTracks().forEach((t) => t.stop());
    State.cameraStream = null;
  }
  el.cameraVideo.srcObject = null;
}

function capturePhoto() {
  const video = el.cameraVideo;
  const w = video.videoWidth,
    h = video.videoHeight;
  if (!w || !h) return;

  const canvas = document.createElement("canvas");
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext("2d");

  // A selfie camera is mirrored on screen; capturing it unmirrored would hand
  // back a photo that does not match what the person was just looking at.
  if (State.cameraFacing === "user") {
    ctx.translate(w, 0);
    ctx.scale(-1, 1);
  }
  ctx.drawImage(video, 0, 0, w, h);

  canvas.toBlob(
    (blob) => {
      if (!blob) return;
      if (State.cameraShot?.url) URL.revokeObjectURL(State.cameraShot.url);

      const url = URL.createObjectURL(blob);
      State.cameraShot = { blob, url };

      el.cameraShot.src = url;
      el.cameraShot.hidden = false;
      el.cameraVideo.hidden = true;
      el.cameraShutter.hidden = true;
      el.cameraFlip.hidden = true;
      el.cameraRetake.hidden = false;
      el.cameraUse.hidden = false;
      stopCamera(); // nothing to show live while a still is up
    },
    "image/jpeg",
    0.9,
  );
}

function useCapturedPhoto() {
  const shot = State.cameraShot;
  if (!shot) return;

  // Straight into the same staging area as any other attachment, so it is sent,
  // previewed and validated by exactly the code an uploaded photo goes through.
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  handleFiles([
    new File([shot.blob], `photo-${stamp}.jpg`, { type: "image/jpeg" }),
  ]);

  URL.revokeObjectURL(shot.url);
  State.cameraShot = null;
  closeModal(el.cameraModal);
  el.messageInput.focus({ preventScroll: true });
}

// ══ Voice notes ═══════════════════════════════════════════════
const HAS_RECORDER = !!(
  UNI.hasVoice &&
  navigator.mediaDevices &&
  navigator.mediaDevices.getUserMedia &&
  typeof window.MediaRecorder === "function"
);

/** The first container this browser will actually record — see ALLOWED_AUDIO. */
function recorderFormat() {
  const options = [
    ["audio/webm;codecs=opus", "webm"],
    ["audio/webm", "webm"],
    ["audio/ogg;codecs=opus", "ogg"],
    ["audio/mp4", "m4a"],
  ];
  for (const [mime, ext] of options) {
    if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(mime)) {
      return { mime, ext };
    }
  }
  return { mime: "", ext: "webm" }; // let the browser choose
}

async function startRecording() {
  if (!HAS_RECORDER || !chatOpen() || State.recorder) return;

  let stream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
  } catch (err) {
    showToast(
      "Microphone unavailable",
      err && err.name === "NotAllowedError"
        ? "Allow microphone access in your browser’s site settings."
        : "No microphone could be started on this device.",
      "",
      5000,
    );
    return;
  }

  const { mime, ext } = recorderFormat();
  const recorder = new MediaRecorder(
    stream,
    mime ? { mimeType: mime } : undefined,
  );

  State.recorder = recorder;
  State.recChunks = [];
  State.recStart = Date.now();

  recorder.addEventListener("dataavailable", (e) => {
    if (e.data && e.data.size) State.recChunks.push(e.data);
  });

  recorder.addEventListener("stop", () => {
    stream.getTracks().forEach((t) => t.stop());
    const seconds = Math.round((Date.now() - State.recStart) / 1000);
    const chunks = State.recChunks;

    State.recorder = null;
    State.recChunks = [];
    clearInterval(State.recTimer);
    el.recordingRow.hidden = true;
    el.micBtn.classList.remove("recording");

    // recorder.cancelled is set by discardRecording(): the stop event fires
    // either way, and this is what tells the two apart.
    if (recorder.cancelled || !chunks.length) return;
    if (seconds < 1) {
      showToast(
        "Too short",
        "Hold the microphone button a moment longer.",
        "",
        3000,
      );
      return;
    }

    stageVoiceNote(
      new Blob(chunks, { type: recorder.mimeType || mime || "audio/webm" }),
      seconds,
      ext,
    );
  });

  recorder.start();
  el.recordingRow.hidden = false;
  el.micBtn.classList.add("recording");
  el.recTime.textContent = "0:00";

  State.recTimer = setInterval(() => {
    const seconds = Math.round((Date.now() - State.recStart) / 1000);
    el.recTime.textContent = clockText(seconds);
    // A hard ceiling, so a forgotten open microphone cannot fill the disk.
    if (seconds >= UNI.maxVoiceSec) {
      showToast(
        "Recording stopped",
        `Voice messages are limited to ${Math.round(UNI.maxVoiceSec / 60)} minutes.`,
        "",
        4000,
      );
      stopRecording();
    }
  }, 250);
}

function stopRecording() {
  if (State.recorder && State.recorder.state !== "inactive")
    State.recorder.stop();
}

function discardRecording() {
  if (!State.recorder) return;
  State.recorder.cancelled = true;
  stopRecording();
}

/** A finished recording waits in the attachment strip until it is sent. */
function stageVoiceNote(blob, seconds, ext) {
  if (blob.size > UNI.maxAudioSize) {
    showToast(
      "Recording too large",
      "That voice message is longer than the server accepts.",
      "",
      4500,
    );
    return;
  }

  discardVoiceNote();
  State.pendingVoice = { blob, seconds, ext, url: URL.createObjectURL(blob) };
  renderPreviews();
  // Same reason as every other staging path: the next Enter should send this,
  // not re-press whichever button was used to record it.
  focusComposer();
}

function discardVoiceNote() {
  if (State.pendingVoice?.url) URL.revokeObjectURL(State.pendingVoice.url);
  State.pendingVoice = null;
}

// ── Wiring for the three panels above ─────────────────────────
function bindGroupUi() {
  el.newGroupBtn.addEventListener("click", openGroupModal);
  el.groupInfoBtn.addEventListener("click", openGroupInfo);
  el.groupCreateBtn.addEventListener("click", createGroup);
  el.groupLeaveBtn.addEventListener("click", leaveGroup);

  // Re-rendering on every keystroke would drop the ticks, so they are read
  // back out first and restored.
  el.groupMemberSearch.addEventListener("input", () =>
    renderMemberPicker(pickedMembers()),
  );
  el.groupMemberPicker.addEventListener("change", () =>
    updatePickCount(pickedMembers()),
  );
  el.groupName.addEventListener("keydown", (e) => {
    if (e.key === "Enter") createGroup();
  });

  // The picture staged in the "New group" dialog.
  el.groupPhotoPick.addEventListener("click", () => el.groupPhotoInput.click());
  el.groupPhotoClear.addEventListener("click", () => clearStagedGroupPhoto());
  el.groupPhotoInput.addEventListener("change", () => {
    const file = el.groupPhotoInput.files && el.groupPhotoInput.files[0];
    if (file) stageGroupPhoto(file);
  });

  // The info panel's contents are rebuilt on every open, so its buttons are
  // handled by delegation rather than re-bound each time.
  // The file input is rebuilt with the rest of the panel, so its change event
  // is caught here on the way up rather than bound to each new element.
  el.groupInfoBody.addEventListener("change", (e) => {
    if (!e.target.matches("#groupPhotoUpload")) return;
    const file = e.target.files && e.target.files[0];
    if (file) saveGroupPhoto(file);
    e.target.value = "";
  });

  el.groupInfoBody.addEventListener("click", (e) => {
    const group = State.groupInfo;
    if (!group) return;

    if (
      e.target.closest("#groupPhotoEdit") ||
      e.target.closest("#groupPhotoChange")
    ) {
      el.groupInfoBody.querySelector("#groupPhotoUpload")?.click();
      return;
    }

    if (e.target.closest("#groupPhotoRemove")) {
      if (confirm(`Remove the picture for “${group.name}”?`))
        saveGroupPhoto(null);
      return;
    }

    const remove = e.target.closest("[data-remove-member]");
    if (remove) {
      groupAction(
        formData({
          csrf_token: UNI.csrf,
          action: "remove",
          group_id: group.id,
          user_id: Number(remove.dataset.removeMember),
        }),
        (d) => renderGroupInfo(d.group),
      );
      return;
    }

    if (e.target.closest("#groupRenameBtn")) {
      const name = el.groupInfoBody.querySelector("#groupRename")?.value.trim();
      groupAction(
        formData({
          csrf_token: UNI.csrf,
          action: "rename",
          group_id: group.id,
          name,
          description: group.description || "",
        }),
        (d) => {
          renderGroupInfo(d.group);
          fetchMessages(true); // refreshes the header
        },
      );
      return;
    }

    if (e.target.closest("#groupAddBtn")) {
      const picked = [
        ...el.groupInfoBody.querySelectorAll("#groupAddPicker input:checked"),
      ].map((i) => Number(i.value));
      if (!picked.length) {
        showModalError(el.groupInfoError, "Tick at least one person to add.");
        return;
      }
      const fd = formData({
        csrf_token: UNI.csrf,
        action: "add",
        group_id: group.id,
      });
      picked.forEach((id) => fd.append("members[]", id));
      groupAction(fd, (d) => {
        renderGroupInfo(d.group);
        fetchMessages(true);
      });
    }
  });
}

function bindCameraUi() {
  // el.cameraBtn is null while the feature is held back — dashboard.php leaves
  // the whole thing out of the page — so nothing below is reachable.
  if (!HAS_CAMERA || !el.cameraBtn) {
    if (el.cameraBtn) el.cameraBtn.hidden = true; // API missing, markup present
    return;
  }
  // Hidden rather than disabled where the API is missing: a button that can
  // never work is worse than no button.
  el.cameraBtn.hidden = false;

  el.cameraBtn.addEventListener("click", openCamera);
  el.cameraShutter.addEventListener("click", capturePhoto);
  el.cameraUse.addEventListener("click", useCapturedPhoto);
  el.cameraRetake.addEventListener("click", openCamera);
  el.cameraFlip.addEventListener("click", () => {
    State.cameraFacing = State.cameraFacing === "user" ? "environment" : "user";
    startCamera();
  });
}

function bindVoiceUi() {
  if (!HAS_RECORDER || !el.micBtn) {
    if (el.micBtn) el.micBtn.hidden = true;
    return;
  }
  el.micBtn.hidden = false;

  // Click to start, click to stop — not hold-to-talk, which loses the recording
  // on every stray pointer-cancel a phone browser decides to send.
  el.micBtn.addEventListener("click", () => {
    State.recorder ? stopRecording() : startRecording();
  });
  el.recStop.addEventListener("click", stopRecording);
  el.recCancel.addEventListener("click", discardRecording);
}

// ══ Levels, streaks and badges ════════════════════════════════
// The client half of the cold path: one poll a minute for my own numbers, a
// leaderboard opened on demand, and a celebration when something is worth
// celebrating. Nothing here is on the path of sending a message.
//
// Every entry point checks UNI.gamify first, so an installation whose database
// has not been migrated simply never grows a level chip.

function startGamify() {
  if (!UNI.gamify) return;

  el.boardBtn.hidden = false;
  pollGamify();
  State.gamifyTimer = setInterval(() => {
    // A background tab does not need its badge kept warm; the next foreground
    // poll picks up everything it missed, because the server tracks what has
    // been celebrated rather than the client.
    if (isChatVisible()) pollGamify();
  }, UNI.gamifyMs || 60000);
}

function pollGamify() {
  if (!UNI.gamify || State.gamifyBusy || State.signedOut)
    return Promise.resolve();
  State.gamifyBusy = true;

  return apiGet("ajax/gamify.php?action=me")
    .then((data) => {
      if (!data || data.enabled === false) return;

      State.stats = data.stats;
      renderLevelChip(data.stats);

      // Order matters: a badge earned by reaching a level reads better after
      // the level-up that caused it.
      if (data.level_up)
        queueCheer({
          icon: "🏆",
          kicker: "Level up",
          title: `Level ${data.level_up}`,
          sub: "Keep going — the next one is already in sight.",
        });

      (data.new_badges || []).forEach((badge) =>
        queueCheer({
          icon: badge.icon,
          kicker: "Badge unlocked",
          title: badge.name,
          sub: badge.how,
        }),
      );
    })
    .catch(() => {})
    .finally(() => {
      State.gamifyBusy = false;
    });
}

/** The ring, the pip and the flame in the sidebar header. */
function renderLevelChip(stats) {
  if (!stats || !el.levelRing) return;

  el.levelRing.style.setProperty("--ring", `${stats.percent}%`);
  el.levelPip.textContent = stats.level;
  el.levelPip.hidden = false;
  el.levelRing.title = `Level ${stats.level} · ${stats.xp} XP · ${stats.to_next} to next`;

  el.shSub.hidden = false;
  el.xpLine.textContent = `Lv ${stats.level} · ${stats.xp.toLocaleString()} XP`;

  // A streak of one is just "today", which is not an achievement worth a flame.
  const show = stats.streak >= 2;
  el.streakChip.hidden = !show;
  if (show) {
    el.streakDays.textContent = stats.streak;
    el.streakChip.title = `${stats.streak}-day streak · best ${stats.best_streak}`;
  }
}

// ── Celebrations ──────────────────────────────────────────────
// Queued rather than stacked: earning two badges in the same minute should be
// two moments, not two overlapping cards.

function queueCheer(item) {
  State.cheerQueue.push(item);
  if (!State.cheerShowing) showNextCheer();
}

function showNextCheer() {
  const item = State.cheerQueue.shift();
  if (!item) {
    State.cheerShowing = false;
    return;
  }

  State.cheerShowing = true;
  el.cheerIcon.textContent = item.icon;
  el.cheerKicker.textContent = item.kicker;
  el.cheerTitle.textContent = item.title;
  el.cheerSub.textContent = item.sub || "";
  el.cheer.hidden = false;
  // Removed and re-added so a second celebration replays the animation.
  el.cheer.classList.remove("in");
  void el.cheer.offsetWidth;
  el.cheer.classList.add("in");

  clearTimeout(showNextCheer.timer);
  showNextCheer.timer = setTimeout(dismissCheer, 4200);
}

function dismissCheer() {
  clearTimeout(showNextCheer.timer);
  el.cheer.classList.remove("in");

  // Waits out the fade rather than snapping away mid-transition.
  setTimeout(() => {
    el.cheer.hidden = true;
    State.cheerShowing = false;
    showNextCheer();
  }, 200);
}

// ── Leaderboard ───────────────────────────────────────────────
function openBoard() {
  if (!UNI.gamify) return;
  openModal(el.boardModal);
  loadBoard(State.boardScope);
}

function loadBoard(scope) {
  State.boardScope = scope;
  State.boardBusy = true;

  el.boardTabs.querySelectorAll(".board-tab").forEach((tab) => {
    const on = tab.dataset.scope === scope;
    tab.classList.toggle("active", on);
    tab.setAttribute("aria-selected", on ? "true" : "false");
  });

  el.boardBody.innerHTML = '<div class="board-loading">Counting…</div>';

  apiGet(
    `ajax/gamify.php?action=leaderboard&scope=${encodeURIComponent(scope)}`,
  )
    .then((data) => {
      if (State.boardScope !== scope) return; // a tab was switched mid-flight
      renderBoard(data.board || []);
      el.boardNote.textContent =
        scope === "month"
          ? `XP earned in ${data.month}`
          : "XP earned since the beginning";
    })
    .catch(() => {
      el.boardBody.innerHTML =
        '<div class="board-loading">The leaderboard could not be loaded.</div>';
    })
    .finally(() => {
      State.boardBusy = false;
    });
}

/** Medals for the top three, plain numerals after that. */
const BOARD_MEDALS = ["🥇", "🥈", "🥉"];

function renderBoard(rows) {
  if (!rows.length) {
    el.boardBody.innerHTML =
      '<div class="board-loading">Nobody has scored yet.</div>';
    return;
  }

  el.boardBody.innerHTML = rows
    .map(
      (r) => `
    <div class="board-row ${r.is_me ? "me" : ""} ${r.rank <= 3 ? "podium" : ""}">
      <span class="board-rank">${BOARD_MEDALS[r.rank - 1] || r.rank}</span>
      <img class="avatar avatar-sm" src="${escAttr(r.avatar)}" alt="" loading="lazy">
      <span class="board-who">
        <span class="board-name">${escHtml(r.name)}${r.is_me ? " <em>(you)</em>" : ""}</span>
        <span class="board-meta">
          Level ${r.level}
          ${r.streak >= 2 ? ` · 🔥 ${r.streak}` : ""}
          ${r.badges ? ` · 🏅 ${r.badges}` : ""}
        </span>
      </span>
      <span class="board-xp">${r.xp.toLocaleString()}<small>XP</small></span>
    </div>`,
    )
    .join("");
}

function bindGamifyUi() {
  if (!UNI.gamify) return;

  el.boardBtn.addEventListener("click", openBoard);
  el.boardTabs.addEventListener("click", (e) => {
    const tab = e.target.closest(".board-tab");
    if (tab && !State.boardBusy) loadBoard(tab.dataset.scope);
  });

  el.cheerClose.addEventListener("click", dismissCheer);
  el.cheer.addEventListener("click", (e) => {
    if (e.target === el.cheer) dismissCheer();
  });
}

// ── Toast ─────────────────────────────────────────────────────
let toastContainer;
function ensureToastContainer() {
  if (!toastContainer || !toastContainer.isConnected) {
    toastContainer = document.createElement("div");
    toastContainer.className = "toast-container";
    document.body.appendChild(toastContainer);
  }
  return toastContainer;
}

function showToast(title, msg, avatar, duration = 4000, onClick = null) {
  const toast = document.createElement("div");
  toast.className = "toast";
  toast.innerHTML = `
    ${avatar ? `<img src="${escAttr(avatar)}" alt="">` : ""}
    <div class="toast-content">
      <div class="toast-name">${escHtml(title)}</div>
      <div class="toast-msg">${escHtml(msg)}</div>
    </div>`;
  if (onClick) toast.style.cursor = "pointer";
  toast.addEventListener("click", () => {
    toast.remove();
    if (onClick) onClick();
  });

  ensureToastContainer().appendChild(toast);
  setTimeout(() => toast.remove(), duration);
}

// ── File handling ─────────────────────────────────────────────
// Any file may be attached except the executables and server-side scripts in
// BLOCKED_EXTS. Recognition is by extension rather than by the browser's MIME
// guess: Windows hands over an empty type for .csv often enough, and
// application/octet-stream for .docx, that the guess cannot be relied on — and
// for a .vue or a .lock there is no guess at all.
//
// Everything that is not one of the four raster formats is a download at the
// far end: a code file is named and offered, never rendered into the
// conversation. See ajax/file.php for the headers that make that true.
const BLOCKED_TYPES = new Set(UNI.blockedTypes || []);
const IMAGE_TYPES = new Set(UNI.imageTypes || []);
const CODE_TYPES = new Set(UNI.codeTypes || []);

/** Lower-case extension of a file name, without the dot. */
function fileExt(name) {
  const str = String(name || "");
  const dot = str.lastIndexOf(".");
  return dot > 0 ? str.slice(dot + 1).toLowerCase() : "";
}

/**
 * The extension leads, not the browser's type: a .svg reports as image/svg+xml
 * and must still travel as a download, because it is markup rather than a
 * picture the app is willing to draw into a bubble.
 *
 * The MIME fallback is only for the nameless case — a blob dragged out of
 * another page, or a screenshot some browsers hand over as "blob" with no
 * extension at all. It lists the four raster types explicitly rather than
 * testing `image/`, so an SVG cannot slip through it either.
 */
function isImageFile(f) {
  const ext = fileExt(f.name);
  if (ext) return IMAGE_TYPES.has(ext);
  return /^image\/(jpeg|png|gif|webp)$/i.test(f.type || "");
}

const isBlockedFile = (f) => BLOCKED_TYPES.has(fileExt(f.name));

/**
 * A pasted or dragged image often arrives as "image.png", sometimes as "blob",
 * and occasionally with no name at all. Give it one the server can file by
 * extension and the recipient can save under.
 */
function namedImage(file) {
  if (fileExt(file.name)) return file;

  const ext = {
    "image/jpeg": "jpg",
    "image/png": "png",
    "image/gif": "gif",
    "image/webp": "webp",
  }[String(file.type).toLowerCase()];
  if (!ext) return file;

  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  return new File([file], `pasted-${stamp}.${ext}`, { type: file.type });
}

/** A photo is capped tighter than an archive. */
const fileLimit = (f) => (isImageFile(f) ? UNI.maxFileSize : UNI.maxDocSize);

/** Badge text and colour class for an attachment, by extension. */
const DOC_LOOK = {
  pdf: { label: "PDF", cls: "doc-pdf" },
  doc: { label: "DOC", cls: "doc-word" },
  docx: { label: "DOC", cls: "doc-word" },
  odt: { label: "ODT", cls: "doc-word" },
  rtf: { label: "RTF", cls: "doc-word" },
  xls: { label: "XLS", cls: "doc-excel" },
  xlsx: { label: "XLS", cls: "doc-excel" },
  ods: { label: "ODS", cls: "doc-excel" },
  csv: { label: "CSV", cls: "doc-excel" },
  ppt: { label: "PPT", cls: "doc-ppt" },
  pptx: { label: "PPT", cls: "doc-ppt" },
  odp: { label: "ODP", cls: "doc-ppt" },
  txt: { label: "TXT", cls: "doc-text" },
  mp4: { label: "MP4", cls: "doc-media" },
  mov: { label: "MOV", cls: "doc-media" },
  mp3: { label: "MP3", cls: "doc-media" },
  wav: { label: "WAV", cls: "doc-media" },
  svg: { label: "SVG", cls: "doc-code" },
};

// Everything in ARCHIVE-land shares one badge colour, so a .7z reads the same
// way a .zip does instead of falling through to the generic grey.
const ARCHIVE_LOOK = new Set([
  "zip",
  "rar",
  "7z",
  "tar",
  "gz",
  "tgz",
  "bz2",
  "xz",
  "zst",
  "lz",
  "lzma",
  "cab",
]);

function docLook(ext) {
  if (DOC_LOOK[ext]) return DOC_LOOK[ext];
  if (ARCHIVE_LOOK.has(ext))
    return { label: ext.toUpperCase().slice(0, 4), cls: "doc-zip" };
  if (CODE_TYPES.has(ext))
    return { label: ext.toUpperCase().slice(0, 4), cls: "doc-code" };

  return { label: (ext || "file").slice(0, 4).toUpperCase(), cls: "doc-text" };
}

function formatBytes(bytes) {
  if (!bytes || bytes < 0) return "";
  if (bytes < 1024) return bytes + " B";

  const units = ["KB", "MB", "GB"];
  let value = bytes / 1024;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }

  return `${value < 10 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`;
}

function handleFiles(files) {
  const picked = Array.from(files || []).filter(Boolean);
  const blocked = picked.filter(isBlockedFile);
  const accepted = picked.filter((f) => !isBlockedFile(f));

  if (blocked.length) {
    showToast(
      "Blocked file type",
      `.${fileExt(blocked[0].name)} files cannot be sent — programs and scripts are not accepted.`,
      "",
      4500,
    );
  }
  if (!accepted.length) return;

  const tooBig = accepted.filter((f) => f.size > fileLimit(f));
  const okSize = accepted.filter((f) => f.size <= fileLimit(f));
  if (tooBig.length) {
    showToast(
      "File too large",
      `${tooBig[0].name} exceeds ${Math.round(fileLimit(tooBig[0]) / 1048576)} MB.`,
      "",
      4500,
    );
  }
  if (!okSize.length) return;

  const combined = State.pendingFiles.concat(okSize);
  if (combined.length > UNI.maxFiles) {
    showToast(
      "Too many files",
      `You can attach up to ${UNI.maxFiles} files at once.`,
      "",
      3500,
    );
  }

  State.pendingFiles = combined.slice(0, UNI.maxFiles);
  renderPreviews();

  // Where the caret goes after picking files is not cosmetic: it used to stay
  // on the paperclip, so the very next Enter re-opened the file dialog instead
  // of sending what had just been chosen.
  focusComposer();
}

/**
 * Put the caret back in the message box.
 *
 * Every path that stages an attachment ends here — the picker, a drop, a paste,
 * the camera, the recorder — so "type a caption, press Enter" works the same
 * way whichever of them was used.
 */
function focusComposer() {
  if (!chatOpen() || isMobile()) return;
  if (document.activeElement && document.activeElement !== document.body) {
    document.activeElement.blur();
  }
  el.messageInput.focus({ preventScroll: true });
  placeCaretAtEnd(el.messageInput);
}

/**
 * Split a pile of attachments into requests a stock PHP will actually accept.
 *
 * `max_file_uploads` is 20 by default and cannot be changed at runtime, and
 * `post_max_size` caps the whole body — so forty pictures were never going to
 * travel as one multipart form however high MAX_FILES_PER_MESSAGE is set. Every
 * file becomes its own row at the far end regardless, so a batched send and a
 * single one produce exactly the same conversation.
 */
function batchFiles(files) {
  const perBatch = Math.max(1, UNI.uploadBatch || 8);
  const byteBudget = Math.max(1, UNI.uploadBatchBytes || 24 * 1024 * 1024);
  const batches = [];
  let batch = [];
  let bytes = 0;

  files.forEach((file) => {
    // A single file larger than the budget still goes on its own — refusing it
    // here would contradict the size limit it already passed.
    if (batch.length && (batch.length >= perBatch || bytes + file.size > byteBudget)) {
      batches.push(batch);
      batch = [];
      bytes = 0;
    }
    batch.push(file);
    bytes += file.size;
  });

  if (batch.length) batches.push(batch);
  return batches;
}

function renderPreviews() {
  revokePreviewUrls();

  if (!State.pendingFiles.length && !State.pendingVoice) {
    el.attachmentPreview.hidden = true;
    el.attachmentFiles.innerHTML = "";
    return;
  }

  // Forty thumbnails is a lot of composer. The strip carries its own header —
  // how many are staged, and one control that drops the lot — and scrolls
  // inside a fixed height rather than pushing the message box off the screen.
  const count = State.pendingFiles.length + (State.pendingVoice ? 1 : 0);
  if (el.attachmentCount) {
    el.attachmentCount.textContent =
      count === 1 ? "1 attachment" : `${count} attachments`;
  }
  if (el.attachmentBar) {
    el.attachmentBar.classList.toggle("crowded", count > 1);
  }
  el.attachmentPreview.classList.toggle("many", State.pendingFiles.length > 8);

  // A staged recording sits in the same strip as the files, with a player so
  // you can hear it before it goes.
  const voice = State.pendingVoice
    ? `
    <div class="preview-thumb preview-voice">
      <span class="preview-badge doc-voice">🎤</span>
      <span class="preview-info">
        <span class="preview-name">Voice message</span>
        <span class="preview-size">${escHtml(clockText(State.pendingVoice.seconds))}</span>
      </span>
      <audio controls src="${escAttr(State.pendingVoice.url)}" preload="metadata"></audio>
      <span class="preview-remove" data-remove-voice title="Remove">✕</span>
    </div>`
    : "";

  el.attachmentPreview.hidden = false;
  el.attachmentFiles.innerHTML =
    voice +
    State.pendingFiles
      .map((f, i) => {
        // Only an image gets an object URL — a document is described, not rendered.
        if (isImageFile(f)) {
          const url = URL.createObjectURL(f);
          State.previewUrls.push(url);
          return `<div class="preview-thumb" data-idx="${i}">
        <img src="${url}" alt="${escAttr(f.name)}">
        <span class="preview-remove" data-remove="${i}" title="Remove">✕</span>
      </div>`;
        }

        const look = docLook(fileExt(f.name));
        return `<div class="preview-thumb preview-doc ${look.cls}" data-idx="${i}" title="${escAttr(f.name)}">
      <span class="preview-badge">${escHtml(look.label)}</span>
      <span class="preview-info">
        <span class="preview-name">${escHtml(f.name)}</span>
        <span class="preview-size">${escHtml(formatBytes(f.size))}</span>
      </span>
      <span class="preview-remove" data-remove="${i}" title="Remove">✕</span>
    </div>`;
      })
      .join("");
}

function revokePreviewUrls() {
  State.previewUrls.forEach((u) => URL.revokeObjectURL(u));
  State.previewUrls = [];
}

function removePendingFile(idx) {
  State.pendingFiles.splice(idx, 1);
  renderPreviews();
}

function clearPendingFiles() {
  State.pendingFiles = [];
  revokePreviewUrls();
  el.attachmentPreview.hidden = true;
  el.attachmentFiles.innerHTML = "";
  el.fileInput.value = "";
}

/** The Clear all control: everything staged goes, including a recording. */
function clearAllAttachments() {
  const had = State.pendingFiles.length + (State.pendingVoice ? 1 : 0);
  if (!had) return;

  discardVoiceNote();
  clearPendingFiles();
  focusComposer();
}

// ── Lightbox / gallery ────────────────────────────────────────
// One viewer for every picture in the app. Opened on a list rather than a
// single URL, so a picture in a conversation can be stepped through with the
// arrows on either side instead of closing and re-opening the viewer for each
// one. A list of one simply hides the arrows, which is the avatar case.

// The viewer sits on top of everything, so the app's own Escape handlers stand
// aside while it is up — otherwise one press would close the viewer *and* the
// panel behind it.
let galleryBox = null;
const galleryOpen = () => !!galleryBox;

/**
 * @param {Array<{src:string,name?:string}>} items The pictures to page through,
 *   in the order the reader sees them.
 * @param {number} start Index of the one that was clicked.
 */
function openGallery(
  items,
  start = 0,
  { download = true, avatar = false } = {},
) {
  const pictures = (items || []).filter((item) => item && item.src);
  if (!pictures.length) return;

  if (galleryBox) galleryBox.remove();

  let index = Math.min(Math.max(0, start), pictures.length - 1);
  const many = pictures.length > 1;

  const box = document.createElement("div");
  box.className = "lightbox" + (avatar ? " lightbox-avatar" : "");
  box.innerHTML = `
    <button class="lightbox-close" title="Close" aria-label="Close">✕</button>
    ${download ? `<a class="lightbox-dl" download title="Download">↓</a>` : ""}
    ${
      many
        ? `
      <button class="lightbox-nav prev" title="Previous" aria-label="Previous image">
        <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
      </button>
      <button class="lightbox-nav next" title="Next" aria-label="Next image">
        <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </button>
      <div class="lightbox-counter" aria-live="polite"></div>`
        : ""
    }
    <div class="lightbox-stage"><img alt=""></div>
    <div class="lightbox-name"></div>`;

  const img = box.querySelector(".lightbox-stage img");
  const link = box.querySelector(".lightbox-dl");
  const caption = box.querySelector(".lightbox-name");
  const counter = box.querySelector(".lightbox-counter");

  const show = (to) => {
    // Wraps around, the way a phone gallery does: the last picture's "next" is
    // the first, so a long strip never dead-ends under the reader's finger.
    index = (to + pictures.length) % pictures.length;
    const item = pictures[index];
    const name = item.name || "";

    // The swap is what re-runs the entry animation, so paging reads as a move
    // rather than as the same frame quietly changing its contents.
    img.classList.remove("sliding");
    void img.offsetWidth;
    img.classList.add("sliding");

    img.src = item.src;
    img.alt = name || "Image";

    caption.textContent = name;
    caption.hidden = name === "";

    if (link) {
      link.href = item.src;
      link.setAttribute("download", name);
      link.title = "Download" + (name ? " " + name : "");
    }
    if (counter) counter.textContent = `${index + 1} / ${pictures.length}`;
  };

  const close = () => {
    box.remove();
    if (galleryBox === box) galleryBox = null;
    document.removeEventListener("keydown", onKey);
  };

  const onKey = (e) => {
    if (e.key === "Escape") {
      close();
      return;
    }
    if (!many) return;
    if (e.key === "ArrowLeft") {
      e.preventDefault();
      show(index - 1);
    }
    if (e.key === "ArrowRight") {
      e.preventDefault();
      show(index + 1);
    }
  };

  box.addEventListener("click", (e) => {
    if (e.target.closest(".lightbox-dl")) return; // let the download happen

    const nav = e.target.closest(".lightbox-nav");
    if (nav) {
      e.stopPropagation();
      show(index + (nav.classList.contains("prev") ? -1 : 1));
      return;
    }

    // The picture itself is not a way out — only the backdrop and the ✕ are,
    // so a mis-aimed tap while paging does not close the viewer.
    if (
      e.target.closest(".lightbox-stage img") ||
      e.target.closest(".lightbox-name")
    )
      return;
    close();
  });

  // Swipe, for the phones where the arrows are a small target.
  if (many) {
    let startX = 0,
      startY = 0,
      tracking = false;
    box.addEventListener(
      "touchstart",
      (e) => {
        if (e.touches.length !== 1) {
          tracking = false;
          return;
        }
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        tracking = true;
      },
      { passive: true },
    );

    box.addEventListener(
      "touchend",
      (e) => {
        if (!tracking) return;
        tracking = false;
        const touch = e.changedTouches[0];
        const dx = touch.clientX - startX;
        // Ignore anything more vertical than horizontal: that is a scroll or a
        // pinch trailing off, not somebody asking for the next picture.
        if (
          Math.abs(dx) < 45 ||
          Math.abs(touch.clientY - startY) > Math.abs(dx)
        )
          return;
        show(index + (dx < 0 ? 1 : -1));
      },
      { passive: true },
    );
  }

  document.addEventListener("keydown", onKey);
  document.body.appendChild(box);
  galleryBox = box;
  show(index);
  box.querySelector(".lightbox-close").focus({ preventScroll: true });
}

/**
 * @param {string} name The sender's file name. Passed explicitly because the
 *   URL is an opaque token and no longer ends in anything a browser could use
 *   to name the download.
 */
function openLightbox(src, name = "", opts = {}) {
  openGallery([{ src, name }], 0, opts);
}

/**
 * Somebody's profile picture, full size.
 *
 * No download button: this is for putting a face to a name, not for collecting
 * other people's photographs. Works for the generated initials avatars too —
 * they are SVG, so they scale up rather than blur.
 */
function openAvatar(src, name = "") {
  openLightbox(src, name, { download: false, avatar: true });
}

/**
 * Every picture currently in the conversation, in the order they were sent —
 * which is the order on screen, and therefore the order the arrows walk.
 *
 * Read out of the DOM rather than kept as a list: the bubbles are the record of
 * what has been rendered, and a message removed or not yet loaded is exactly
 * the one that should not appear in the strip.
 */
function conversationImages() {
  return Array.from(
    el.messagesContainer.querySelectorAll(".msg-image-wrap, .msg-media-wrap"),
  )
    .map((wrap) => ({ src: wrap.dataset.full, name: wrap.dataset.name || "" }))
    .filter((item) => item.src);
}

/** Open the conversation gallery at whichever picture was clicked. */
function openConversationGallery(wrap) {
  const images = conversationImages();
  const at = images.findIndex((item) => item.src === wrap.dataset.full);
  openGallery(images, at < 0 ? 0 : at);
}

// ── Scroll helpers ────────────────────────────────────────────
// Opening a conversation, and sending into one, both have to end with the
// newest message on screen. Neither is a single scroll: the pane is still
// growing when the scroll lands — pictures decode, a pasted table lays itself
// out, a long line wraps — so the *intent* to be at the bottom is recorded and
// re-applied by the observer below until the reader scrolls away themselves.

const REDUCED_MOTION =
  typeof matchMedia === "function" &&
  matchMedia("(prefers-reduced-motion: reduce)").matches;

/** How far from the bottom still counts as "reading the newest message". */
const NEAR_BOTTOM_PX = 150;

/**
 * When a scroll the app issued itself stops counting as the reader moving.
 *
 * The pane is `scroll-behavior: smooth` in the stylesheet, so even a plain
 * `scrollTop = …` is an animation, and every frame of it fires a scroll event.
 * Read literally, those events say "the reader is not at the bottom" — which is
 * how the stick used to switch itself off a few milliseconds after being
 * switched on, leaving a conversation open half a screen above its newest
 * message however many times it was told to go there.
 */
let autoScrollUntil = 0;

function scrollToBottom(force = false, smooth = false) {
  if (!force && !isNearBottom()) return;

  const wrap = el.messagesWrap;
  const top = wrap.scrollHeight - wrap.clientHeight;

  // Smooth is for a message arriving under the reader's eyes. A long jump is
  // not animated: sliding past four hundred bubbles is a lurch, not a
  // transition, and it takes long enough that the next one interrupts it.
  const animate =
    smooth && !REDUCED_MOTION && Math.abs(top - wrap.scrollTop) < 2000;

  autoScrollUntil = Date.now() + (animate ? 700 : 150);

  if (animate) {
    wrap.scrollTo({ top, behavior: "smooth" });
    return;
  }

  // Land in this frame, not over the next few hundred milliseconds: an instant
  // scroll is the only kind that is still correct when the pane grows again
  // immediately afterwards, which on a conversation full of pictures it does.
  const previous = wrap.style.scrollBehavior;
  wrap.style.scrollBehavior = "auto";
  wrap.scrollTop = top;
  wrap.style.scrollBehavior = previous;
}

/** Land on the bottom now, and stay there while the layout settles. */
function stickToBottom(smooth = false) {
  State.stickBottom = true;
  scrollToBottom(true, smooth);
}

function isNearBottom() {
  const { scrollTop, scrollHeight, clientHeight } = el.messagesWrap;
  return scrollHeight - scrollTop - clientHeight < NEAR_BOTTOM_PX;
}

/**
 * Re-pin the pane whenever the conversation changes height while the reader is
 * meant to be at the bottom of it.
 *
 * This is what actually makes "open at the latest message" true. Without it the
 * scroll issued on load is measured against a pane that has not drawn its
 * images yet, and the newest message ends up somewhere below the fold.
 */
function watchPaneGrowth() {
  if (typeof ResizeObserver !== "function") return;

  let last = 0;
  const observer = new ResizeObserver(() => {
    const height = el.messagesContainer.scrollHeight;
    if (height === last) return;
    last = height;
    if (State.stickBottom) scrollToBottom(true);
  });
  observer.observe(el.messagesContainer);
}

/**
 * The round button that drops the reader back to the newest message.
 *
 * Two jobs in one control: the ordinary "you have scrolled up" affordance, and
 * the way out of a conversation opened in its own history from a search result
 * — where "the bottom" is not on screen at all and has to be re-fetched.
 */
function renderJumpButton() {
  if (!el.jumpBtn) return;

  // `hidden` is reserved for "there is no conversation": inside one, showing
  // and hiding is a class, so the button can actually animate in and out
  // instead of being removed from the layout mid-transition.
  if (!chatOpen()) {
    el.jumpBtn.hidden = true;
    el.jumpBtn.classList.remove("show");
    return;
  }

  el.jumpBtn.hidden = false;
  el.jumpBtn.classList.toggle("show", State.hasNewer || !isNearBottom());

  if (el.jumpLabel) {
    el.jumpLabel.textContent = State.hasNewer ? "Latest" : "";
  }
  el.jumpBtn.classList.toggle("has-label", State.hasNewer);
  el.jumpBtn.title = State.hasNewer
    ? "Jump to the newest message"
    : "Scroll to the bottom";
}

/** What the jump button does: back to the present, however far away it is. */
function jumpToLatest() {
  if (State.hasNewer) {
    // The bottom of this conversation was never loaded — re-open it on its
    // newest page rather than paging down to it one screen at a time.
    const userId = State.chatUserId;
    const groupId = State.chatGroupId;
    closeChat();
    openConversation({ userId, groupId });
    return;
  }
  stickToBottom(true);
}

// ── Mobile view toggle ────────────────────────────────────────
function isMobile() {
  return window.innerWidth <= 768;
}

function showChatView() {
  if (isMobile()) {
    el.sidebar.classList.add("slide-out");
    el.chatArea.classList.add("slide-in");
  }
  // Read by the stylesheet to fold the rail away on a phone: with a
  // conversation open the back button is the way out, and the composer wants
  // the height more than a second navigation control does.
  document.body.classList.add("chat-open");
  pushBackEntry();
}

// ── Native back button ────────────────────────────────────────
// A phone's hardware or gesture back is history.back(). With no entry of our
// own it left the page — and in the installed app, which starts with a single
// entry, it closed the app from inside a conversation. An open conversation
// therefore holds one entry, and popping it unwinds the way Escape does: the
// innermost open thing first, the conversation last.
function pushBackEntry() {
  if (isMobile() && !(history.state && history.state.univoChat)) {
    history.pushState({ univoChat: true }, "");
  }
}

window.addEventListener("popstate", () => {
  // Back on the contact list (or a stale entry) is allowed to leave.
  if (!chatOpen()) return;
  // A call overlay covers everything; back must not hang up or close the
  // conversation underneath it.
  const inCall = typeof Call !== "undefined" && Call.active;
  if (!inCall) {
    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
  }
  // Something inside the conversation closed instead: it is still open, so it
  // needs its entry back for the next press.
  if (chatOpen()) pushBackEntry();
});

function showSidebarView() {
  // Left by the on-screen arrow or Escape: drop the entry too, so the next
  // hardware back does not land on a dead step. Coming from popstate, the
  // entry is already gone and this does nothing.
  if (history.state && history.state.univoChat) history.back();
  el.sidebar.classList.remove("slide-out");
  el.chatArea.classList.remove("slide-in");
  if (el.jumpBtn) el.jumpBtn.classList.remove("show");
  document.body.classList.remove("chat-open");
  closeChat();
  loadContacts(el.searchInput.value.trim());
}

// ── PWA ───────────────────────────────────────────────────────
function initPWA() {
  if ("serviceWorker" in navigator && location.protocol !== "file:") {
    navigator.serviceWorker.register(UNI.swUrl).catch(() => {});
  }

  window.addEventListener("beforeinstallprompt", (e) => {
    e.preventDefault();
    State.deferredPrompt = e;
    el.installBtn.style.display = "block";
    showInstallBanner();
  });

  el.installBtn.addEventListener("click", triggerInstall);

  window.addEventListener("appinstalled", () => {
    el.installBtn.style.display = "none";
    const b = document.getElementById("installBanner");
    if (b) b.remove();
    State.deferredPrompt = null;
  });
}

function showInstallBanner() {
  if (
    document.getElementById("installBanner") ||
    sessionStorage.getItem("uni_install_dismissed")
  )
    return;

  const banner = document.createElement("div");
  banner.className = "install-banner";
  banner.id = "installBanner";
  banner.innerHTML = `
    <p>Install UnivoChat for the best experience</p>
    <button class="install-banner-btn" type="button">Install</button>
    <span class="install-banner-close" title="Dismiss">✕</span>`;

  banner
    .querySelector(".install-banner-btn")
    .addEventListener("click", triggerInstall);
  banner
    .querySelector(".install-banner-close")
    .addEventListener("click", () => {
      sessionStorage.setItem("uni_install_dismissed", "1");
      banner.remove();
    });

  document.body.appendChild(banner);
  setTimeout(() => banner.remove(), 15000);
}

function triggerInstall() {
  if (!State.deferredPrompt) return;
  State.deferredPrompt.prompt();
  State.deferredPrompt.userChoice.finally(() => {
    State.deferredPrompt = null;
  });
}

// ── Search ────────────────────────────────────────────────────
// Two questions, one box. "Who is this?" is answered by ajax/get_users.php, the
// same request that fills the sidebar when nothing is being searched for; "who
// said this?" is answered by ajax/search.php. They land in one list, people
// first, because a name is nearly always what somebody meant.

/** Below this, a search matches most of the database and none of it usefully. */
const SEARCH_MIN_CHARS = 2;

let searchDebounce;
function handleSearch(q) {
  clearTimeout(searchDebounce);
  el.searchClear.style.display = q ? "flex" : "none";

  const needle = q.trim();

  // Clearing the box has to take the message results with it immediately —
  // waiting out the debounce leaves stale hits under a list that has moved on.
  if (!needle) {
    State.searchQuery = "";
    State.searchHits = [];
    State.searchMore = false;
    State.searchBusy = false;
  }

  searchDebounce = setTimeout(() => {
    loadContacts(needle);
    searchMessages(needle);
  }, 300);
}

/**
 * The other half of the search: messages containing the words, wherever they
 * were said — a private conversation or any group the searcher is in. The
 * server decides what is visible; this only draws it.
 */
function searchMessages(q) {
  if (q.length < SEARCH_MIN_CHARS) {
    if (State.searchHits.length || State.searchQuery) {
      State.searchQuery = "";
      State.searchHits = [];
      State.searchMore = false;
      renderContacts(State.contacts, State.groups, q);
    }
    return Promise.resolve();
  }

  State.searchQuery = q;
  State.searchBusy = true;
  renderContacts(State.contacts, State.groups, q);

  return apiGet(`ajax/search.php?q=${encodeURIComponent(q)}`)
    .then((data) => {
      // A slow answer to a query the user has already typed past is not an
      // answer to anything.
      if (!data || data.query !== State.searchQuery) return;

      State.searchHits = Array.isArray(data.messages) ? data.messages : [];
      State.searchMore = !!data.truncated;
    })
    .catch(() => {
      State.searchHits = [];
      State.searchMore = false;
    })
    .finally(() => {
      State.searchBusy = false;
      renderContacts(State.contacts, State.groups, el.searchInput.value.trim());
    });
}

/** One message hit, filed under the conversation it was found in. */
function searchRow(hit, needle) {
  const target = hit.group_id
    ? `data-hit-gid="${hit.group_id}"`
    : `data-hit-uid="${hit.user_id}"`;

  return `
    <div class="contact-item search-hit" ${target} data-hit-msg="${hit.id}"
         role="button" tabindex="0">
      <div class="avatar-wrap">
        <img src="${escAttr(hit.avatar)}" alt="" class="avatar avatar-sm" loading="lazy">
      </div>
      <div class="contact-info">
        <div class="contact-name">${escHtml(hit.where)}</div>
        <div class="contact-last">
          <span class="hit-who">${escHtml(hit.who)}:</span>
          ${highlight(hit.snippet, needle)}
        </div>
      </div>
      <div class="contact-meta">
        <div class="contact-time">${escHtml(hit.time)}</div>
      </div>
    </div>`;
}

/**
 * Mark every occurrence of the search term in a snippet.
 *
 * The text is escaped first and the <mark> tags added afterwards, so a message
 * that contains "<script>" is still a message about "<script>" and never
 * anything more than that.
 */
function highlight(text, needle) {
  const safe = escHtml(String(text || ""));
  const term = String(needle || "").trim();
  if (!term) return safe;

  const pattern = term.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  return safe.replace(new RegExp(pattern, "gi"), (hit) => `<mark>${hit}</mark>`);
}

// ── Event listeners ───────────────────────────────────────────
function bindEvents() {
  // Contact selection (delegated – the list is re-rendered every few seconds)
  el.contactsList.addEventListener("click", (e) => {
    // The photo and the row do different things, as they do in every other
    // messenger: tap the face to look at it, tap anywhere else to start talking.
    const face = e.target.closest(".avatar-view");
    if (face) {
      openAvatar(face.dataset.avatar, face.dataset.avatarName);
      return;
    }

    const item = e.target.closest(".contact-item");
    if (item) openRow(item);
  });
  el.contactsList.addEventListener("keydown", (e) => {
    if (e.key !== "Enter" && e.key !== " ") return;
    const item = e.target.closest(".contact-item");
    if (item) {
      e.preventDefault();
      openRow(item);
    }
  });

  // The one in the chat header is a single element, so it can afford to be a
  // proper keyboard target — the contact rows cannot without doubling every
  // tab stop in the list.
  // A group's tile is not a photograph of anybody, so it opens the member list
  // instead of the lightbox.
  const openHeaderAvatar = () => {
    if (isGroupChat()) {
      openGroupInfo();
      return;
    }
    openAvatar(
      el.chatAvatar.dataset.avatar || el.chatAvatar.src,
      el.chatAvatar.dataset.avatarName || el.chatAvatar.alt,
    );
  };

  el.chatAvatar.addEventListener("click", openHeaderAvatar);
  el.chatAvatar.addEventListener("keydown", (e) => {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      openHeaderAvatar();
    }
  });

  // Everything clickable inside a message (delegated – bubbles are re-rendered)
  el.messagesContainer.addEventListener("click", (e) => {
    if (e.target.closest(".img-download")) return; // let the download link work

    // A Markdown card opens the preview; its arrow still downloads.
    const md = e.target.closest(".msg-file[data-md]");
    if (md && !e.target.closest(".file-dl")) {
      e.preventDefault();
      openMarkdownPreview(md.getAttribute("href"), md.dataset.md);
      return;
    }

    if (e.target.closest(".msg-file")) return; // and the attachment card
    if (e.target.closest(".msg-rich a")) return; // and links inside a table

    // The quote at the head of a reply: walk back to what it answers.
    const goto = e.target.closest("[data-goto]");
    if (goto) {
      gotoMessage(Number(goto.dataset.goto));
      return;
    }

    const react = e.target.closest("[data-react]");
    if (react) {
      e.stopPropagation(); // not an "outside" click
      openEmojiPicker(react, Number(react.dataset.react));
      return;
    }

    const reply = e.target.closest("[data-reply]");
    if (reply) {
      startReply(Number(reply.dataset.reply));
      return;
    }

    const fwd = e.target.closest("[data-forward]");
    if (fwd) {
      openForward(Number(fwd.dataset.forward));
      return;
    }

    const copy = e.target.closest("[data-copy]");
    if (copy) {
      copyMessage(Number(copy.dataset.copy), copy);
      return;
    }

    const edit = e.target.closest("[data-edit]");
    if (edit) {
      startEdit(Number(edit.dataset.edit));
      return;
    }

    const del = e.target.closest("[data-delete]");
    if (del) {
      deleteMessage(Number(del.dataset.delete));
      return;
    }

    const save = e.target.closest("[data-edit-save]");
    if (save) {
      saveEdit(Number(save.dataset.editSave));
      return;
    }

    if (e.target.closest("[data-edit-cancel]")) {
      cancelEdit();
      return;
    }

    const chip = e.target.closest(".reaction-chip");
    if (chip) {
      const msg = chip.closest("[data-msgid]");
      if (msg) toggleReaction(Number(msg.dataset.msgid), chip.dataset.emoji);
      return;
    }

    // Opens the whole conversation as a strip, starting on this picture, so the
    // rest can be browsed with the arrows instead of one lightbox at a time.
    const wrap = e.target.closest(".msg-image-wrap, .msg-media-wrap");
    if (wrap) {
      openConversationGallery(wrap);
      return;
    }

    // On a touch screen there is no hover, so a tap on the bubble opens the
    // action sheet — reply, react, copy, edit and delete, all at a size a thumb
    // can actually hit. On a desktop this does nothing: hover already shows the
    // same actions beside the message.
    if (isMobile()) {
      const msg = e.target.closest(".msg[data-msgid]");
      if (msg && !msg.classList.contains("editing")) openMsgSheet(msg);
    }
  });

  // Closing the sheet: the backdrop, Escape, and anything that moves the
  // conversation underneath it.
  if (el.msgSheetBackdrop)
    el.msgSheetBackdrop.addEventListener("click", closeMsgSheet);

  if (el.msgSheet) {
    el.msgSheet.addEventListener("click", (e) => {
      const emoji = e.target.closest("[data-sheet-react]");
      if (emoji) {
        toggleReaction(State.sheetMsgId, emoji.dataset.sheetReact);
        closeMsgSheet();
        return;
      }

      const act = e.target.closest("[data-sheet-do]");
      if (!act) return;

      const id = State.sheetMsgId;
      closeMsgSheet();
      // After the sheet is out of the way: startEdit focuses an input, and a
      // sheet still covering the bottom of the screen would sit over it.
      ({
        reply: () => startReply(id),
        forward: () => openForward(id),
        copy: () => copyMessage(id, act),
        edit: () => startEdit(id),
        delete: () => deleteMessage(id),
      })[act.dataset.sheetDo]?.();
    });

    // A swipe down on the sheet dismisses it, the way a sheet is expected to.
    let startY = null;
    el.msgSheet.addEventListener(
      "touchstart",
      (e) => {
        startY = e.touches[0].clientY;
      },
      { passive: true },
    );
    el.msgSheet.addEventListener(
      "touchmove",
      (e) => {
        if (startY !== null && e.touches[0].clientY - startY > 60)
          closeMsgSheet();
      },
      { passive: true },
    );
  }

  // Replying is the hover button and nothing else. Double-click was tried and
  // removed: a double-click *is* how a browser selects a word, so the gesture
  // could not tell "reply to this" from "copy that word" without breaking one
  // of them.
  if (el.replyCancel) el.replyCancel.addEventListener("click", cancelReply);

  // One scroll handler for the pane: it keeps the floating date honest and
  // pulls in the next page of history as the top comes into view.
  el.messagesWrap.addEventListener("scroll", onMessagesScroll, {
    passive: true,
  });
  // Clicking the banner does what scrolling to it would, for anyone who lands
  // on it with a keyboard or an inertia-free mouse wheel.
  if (el.historyTop) el.historyTop.addEventListener("click", loadOlderMessages);

  // The picker is anchored to a fixed position, so anything that moves the page
  // underneath it closes it. The sheet goes with it: scrolling the conversation
  // away from the message it refers to would leave it pointing at nothing.
  //
  // Except in the first moments after the sheet opens: the tap that opened it
  // can itself nudge the pane (a picture finishing loading and the view
  // following it down), and that is not the reader scrolling away.
  el.messagesWrap.addEventListener(
    "scroll",
    () => {
      closeEmojiPicker();
      if (Date.now() - (State.sheetOpenedAt || 0) > 400) closeMsgSheet();
    },
    { passive: true },
  );
  document.addEventListener("click", (e) => {
    if (emojiPicker && !e.target.closest(".emoji-picker")) closeEmojiPicker();
  });

  // Attachment removal (delegated)
  el.attachmentPreview.addEventListener("click", (e) => {
    if (e.target.closest("#attachmentClear")) {
      clearAllAttachments();
      return;
    }
    if (e.target.closest("[data-remove-voice]")) {
      discardVoiceNote();
      renderPreviews();
      return;
    }
    const btn = e.target.closest("[data-remove]");
    if (btn) removePendingFile(Number(btn.dataset.remove));
  });

  if (el.jumpBtn) el.jumpBtn.addEventListener("click", jumpToLatest);

  bindGroupUi();
  bindFilesPanel();
  bindForwardUi();
  bindCameraUi();
  bindVoiceUi();
  bindGamifyUi();

  // Every modal closes the same three ways: its ✕/Cancel, the backdrop, Escape.
  document.querySelectorAll(".modal").forEach((modal) => {
    modal.addEventListener("click", (e) => {
      if (e.target === modal || e.target.closest("[data-close-modal]"))
        closeModal(modal);
    });
  });
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape" || galleryOpen()) return;
    // The action sheet is the topmost thing when it is up, so it goes first.
    // Either one consumes the key: the "back out of the innermost thing"
    // handler registered further down would otherwise see nothing open any
    // more and, on a phone, leave the conversation as well.
    if (el.msgSheet && !el.msgSheet.hidden) {
      e.stopImmediatePropagation();
      closeMsgSheet();
      return;
    }
    const open = document.querySelector(".modal:not([hidden])");
    if (open) {
      e.stopImmediatePropagation();
      closeModal(open);
    }
  });

  el.backBtn.addEventListener("click", showSidebarView);
  el.sendBtn.addEventListener("click", sendMessage);
  bindMediaPicker();

  el.messageInput.addEventListener("keydown", (e) => {
    if (e.key !== "Enter") return;
    e.preventDefault();

    // Shift+Enter is a line break. Inserting it explicitly stops the browser
    // from wrapping the composer's contents in <div>s of its own choosing.
    if (e.shiftKey) {
      if (!document.execCommand("insertLineBreak")) insertHtmlAtCaret("<br>");
      return;
    }
    sendMessage();
  });
  el.messageInput.addEventListener("input", sendTyping);
  el.messageInput.addEventListener("paste", (e) =>
    handleRichPaste(e, el.messageInput),
  );

  el.attachBtn.addEventListener("click", () => el.fileInput.click());
  el.fileInput.addEventListener("change", () => {
    if (el.fileInput.files.length) handleFiles(el.fileInput.files);
    el.fileInput.value = "";
    // handleFiles() puts the caret back in the composer; this covers the case
    // where the dialog was dismissed without picking anything, so the next
    // Enter still does not re-open it.
    focusComposer();
  });

  /**
   * Enter sends the staged attachments, whatever happens to hold the focus.
   *
   * The composer has its own handler and needs no help. This is for everywhere
   * else the focus can legitimately be sitting when somebody has just chosen
   * files — most of all the paperclip itself, where Enter used to re-open the
   * file dialog instead of sending what had already been picked.
   */
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Enter" || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) return;
    if (!chatOpen() || State.sending) return;
    if (!State.pendingFiles.length && !State.pendingVoice) return;

    const active = document.activeElement;
    // Anywhere that is genuinely taking typed input keeps its Enter.
    if (
      active &&
      active !== document.body &&
      (active.isContentEditable ||
        active.tagName === "INPUT" ||
        active.tagName === "TEXTAREA" ||
        active.tagName === "SELECT")
    )
      return;
    // …as does anything on top of the conversation.
    if (document.querySelector(".modal:not([hidden])")) return;
    if (el.msgSheet && !el.msgSheet.hidden) return;
    if (galleryOpen()) return;

    e.preventDefault();
    sendMessage();
  });

  el.searchInput.addEventListener("input", (e) => handleSearch(e.target.value));
  el.searchClear.addEventListener("click", () => {
    el.searchInput.value = "";
    handleSearch("");
    el.searchInput.focus();
  });

  // Enter on the search box goes straight to the first thing it found, which
  // is what a search box is expected to do.
  el.searchInput.addEventListener("keydown", (e) => {
    if (e.key !== "Enter") return;
    e.preventDefault();
    const first = el.contactsList.querySelector(".contact-item");
    if (first) openRow(first);
  });

  // Drag & drop
  let dragDepth = 0;
  el.chatArea.addEventListener("dragenter", (e) => {
    if (!chatOpen()) return;
    e.preventDefault();
    dragDepth++;
    el.chatArea.classList.add("drag-over");
  });
  el.chatArea.addEventListener("dragover", (e) => {
    if (chatOpen()) e.preventDefault();
  });
  el.chatArea.addEventListener("dragleave", () => {
    if (--dragDepth <= 0) {
      dragDepth = 0;
      el.chatArea.classList.remove("drag-over");
    }
  });
  el.chatArea.addEventListener("drop", (e) => {
    e.preventDefault();
    dragDepth = 0;
    el.chatArea.classList.remove("drag-over");
    if (chatOpen() && e.dataTransfer.files.length)
      handleFiles(e.dataTransfer.files);
  });

  // Paste files straight into the open conversation — a screenshot, a PDF
  // copied out of a folder, a spreadsheet, an archive. Whatever the composer's
  // own handler decided was not text ends up here, and from here in the same
  // preview strip as anything picked with the paperclip.
  document.addEventListener("paste", (e) => {
    if (!chatOpen() || !e.clipboardData) return;

    const files = clipboardFiles(e.clipboardData);
    if (!files.length) return;

    e.preventDefault();
    handleFiles(files);
  });

  document.addEventListener("click", requestNotifPermission, { once: true });

  if (el.notifBtn) el.notifBtn.addEventListener("click", toggleNotifications);

  // Coming back to the tab: refresh immediately instead of waiting for a tick.
  // Leaving it also pings the server, so it learns straight away that new
  // messages now need a push instead of an in-page toast.
  document.addEventListener("visibilitychange", () => {
    if (State.signedOut) return;

    updateOnlineStatus();
    if (!isChatVisible()) {
      closeEmojiPicker();
      return;
    }

    loadContacts(el.searchInput.value.trim());
    if (chatOpen()) {
      markReadCurrent();
      fetchMessages(true);
    }
  });

  // A push that arrives while the app is open is handed to the page by the
  // service worker instead of becoming a second, duplicate notification.
  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.addEventListener("message", (e) => {
      const data = e.data || {};

      // Somebody tapped a system notification while a tab was already open.
      // A group push carries both `group` and `from` — the group is the
      // conversation it came from, and `from` is only who spoke in it.
      // A ring. Handled before anything else: it is not a message, and the
      // incoming-call screen is the only thing it should raise.
      if (data.type === "call" || (data.type === "notification-click" && data.call)) {
        if (typeof Call !== "undefined") Call.onPush(data);
        if (data.type === "call") return;
      }

      if (data.type === "notification-click") {
        if (data.group) openGroup(Number(data.group));
        else if (data.from) openChat(Number(data.from));
        return;
      }

      if (data.type !== "push" && data.type !== "push-background") return;

      // The push is for the conversation already on screen: refresh it in
      // place rather than announcing a message the reader is looking at.
      const forOpenChat = data.group
        ? isGroupChat() && Number(data.group) === State.chatGroupId
        : data.from && !isGroupChat() && Number(data.from) === State.chatUserId;

      if (forOpenChat && isChatVisible()) {
        fetchMessages(true);
        loadContacts(el.searchInput.value.trim());
        return;
      }

      // The worker already showed a system notification for a background push;
      // a toast on top of it would be the same message twice.
      if (data.type === "push") {
        showToast(
          data.title || "New message",
          data.body || "",
          data.icon || "",
          5000,
          () => {
            window.focus();
            if (data.group) openGroup(Number(data.group));
            else if (data.from) openChat(Number(data.from));
          },
        );
      }
      loadContacts(el.searchInput.value.trim());
    });
  }

  // Arriving and leaving both change whether a push is needed, and the server
  // cannot guess either one — so it is told immediately instead of up to half a
  // minute later on the next heartbeat.
  window.addEventListener("focus", () => {
    if (State.signedOut) return;
    updateOnlineStatus();
    if (chatOpen()) markReadCurrent();
  });

  window.addEventListener("blur", () => {
    if (!State.signedOut) updateOnlineStatus();
  });

  // The last word before the tab goes away. sendBeacon survives a page that is
  // being torn down, which a fetch() started at the same moment does not.
  window.addEventListener("pagehide", () => {
    if (State.signedOut || !navigator.sendBeacon) return;
    try {
      navigator.sendBeacon(`${UNI.base}/ajax/update_status.php?focus=0`);
    } catch (_) {
      /* the browser is leaving anyway */
    }
  });

  // Back online: catch up now rather than whenever the backoff next allows.
  window.addEventListener("online", () => {
    State.retryAt = 0;
    if (State.signedOut) return;
    fetchMessages();
    loadContacts(el.searchInput.value.trim());
  });
  window.addEventListener("offline", () => setOffline(true));

  // Escape backs out of the innermost thing that is open.
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape" || galleryOpen()) return;

    if (State.cheerShowing) {
      dismissCheer();
      return;
    }
    if (emojiPicker) {
      closeEmojiPicker();
      return;
    }
    if (State.editingId) {
      cancelEdit();
      return;
    }
    if (State.replyTo) {
      cancelReply();
      return;
    }
    if (State.filesOpen) {
      closeFilesPanel();
      return;
    }
    if (isMobile() && chatOpen()) showSidebarView();
  });

  // Keep the mobile slide state in sync when the window is resized across the
  // breakpoint with a conversation open.
  let wasMobile = isMobile();
  window.addEventListener("resize", () => {
    const nowMobile = isMobile();
    if (nowMobile === wasMobile) return;
    wasMobile = nowMobile;
    if (nowMobile && chatOpen()) showChatView();
    if (!nowMobile) {
      el.sidebar.classList.remove("slide-out");
      el.chatArea.classList.remove("slide-in");
    }
  });

  window.addEventListener("beforeunload", revokePreviewUrls);
}

// ── Init ──────────────────────────────────────────────────────
/**
 * ?chat=12 / ?group=3 — how a clicked notification lands on the right
 * conversation. A group push carries the second form.
 */
function openChatFromUrl() {
  const params = new URLSearchParams(location.search);
  const gid = Number(params.get("group") || 0);
  const uid = Number(params.get("chat") || 0);
  const callId = Number(params.get("call") || 0);

  // Opened from a ring notification: show the call if it is still ringing.
  if (callId && typeof Call !== "undefined") Call.onPush({ call: callId });

  if (!gid && !(uid && uid !== UNI.uid)) return;

  // Drop the parameter so a refresh does not keep re-opening the same chat.
  // First: opening pushes the back-button entry, which this must not replace.
  history.replaceState(null, "", location.pathname);

  if (gid) openGroup(gid);
  else openChat(uid);
}

function init() {
  bindEvents();
  watchPaneGrowth();
  loadContacts();
  startStatusTimer();
  startContactsRefresh();
  startGamify();
  initPWA();
  initPush();
  openChatFromUrl();

  tickClock();
  setInterval(tickClock, 1000);
}

// Exposed for anything still using inline handlers
window.openChat = openChat;
window.openLightbox = openLightbox;

document.addEventListener("DOMContentLoaded", init);
