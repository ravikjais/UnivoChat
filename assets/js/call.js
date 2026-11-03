/**
 * UnivoChat — one-to-one voice and video calls over WebRTC.
 *
 * Loaded after app.js and leans on its globals (api, apiGet, apiPost,
 * API_BASE, State, showToast, escHtml, isGroupChat, openChat).
 *
 * The media goes browser to browser. The server (ajax/call.php) only rings,
 * keeps the call's state, and carries the offer and the answer between the
 * two sides. There is no WebSocket on shared hosting, so that carrying is a
 * poll — and to keep it to one round trip each way, ICE is not trickled: each
 * side waits for candidate gathering to finish and sends one description with
 * every candidate already inside it.
 *
 *   caller: getUserMedia → start → offer (complete) → poll … answer → connected
 *   callee: ring (poll/push) → accept → getUserMedia → poll … offer → answer
 */
const Call = (() => {
  "use strict";

  const supported =
    !!UNI.calls &&
    window.isSecureContext &&
    !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) &&
    "RTCPeerConnection" in window;

  const FAST_POLL_MS = 800; // while the two sides are still finding each other
  const SLOW_POLL_MS = 2500; // once connected: only hangups and the heartbeat
  const ICE_WAIT_MS = 3500; // longest we wait for candidate gathering
  const CONNECT_TIMEOUT_MS = 30000; // accepted but media never flowed
  const TERMINAL = ["rejected", "missed", "cancelled", "busy", "ended", "failed"];

  const $ = (id) => document.getElementById(id);
  const ui = {
    overlay: $("callOverlay"),
    remoteVideo: $("callRemoteVideo"),
    remoteAudio: $("callRemoteAudio"),
    localVideo: $("callLocalVideo"),
    avatar: $("callAvatar"),
    name: $("callName"),
    status: $("callStatus"),
    accept: $("callAcceptBtn"),
    decline: $("callDeclineBtn"),
    hangup: $("callHangupBtn"),
    mute: $("callMuteBtn"),
    camera: $("callCameraBtn"),
    flip: $("callFlipBtn"),
    speaker: $("callSpeakerBtn"),
  };

  /**
   * The one call this tab is part of, or null.
   * { id, role, media, peer, state, pc, local, after, gotRemote,
   *   pollTimer, ringTimer, connectTimer, clock, connectedAt, facing }
   */
  let call = null;
  // Calls this tab has already finished with, so a poll answered a moment
  // before the server settled does not ring a declined call a second time.
  const done = new Set();

  // ── Small helpers ────────────────────────────────────────────
  const form = (fields) => {
    const fd = new FormData();
    Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
    return fd;
  };
  const post = (fields) => apiPost("ajax/call.php", form(fields));

  const fmtDuration = (secs) => {
    secs = Math.max(0, Math.floor(secs));
    const h = Math.floor(secs / 3600);
    const m = Math.floor((secs % 3600) / 60);
    const s = String(secs % 60).padStart(2, "0");
    return h ? `${h}:${String(m).padStart(2, "0")}:${s}` : `${m}:${s}`;
  };

  function setStatus(text) {
    ui.status.textContent = text;
  }

  function setState(state) {
    if (!call) return;
    call.state = state;
    ui.overlay.dataset.state = state;
  }

  function showOverlay(peer, media, state) {
    ui.avatar.src = peer.avatar || "";
    ui.avatar.alt = peer.name || "";
    ui.name.textContent = peer.name || "";
    ui.overlay.dataset.media = media;
    ui.overlay.dataset.state = state;
    ui.overlay.classList.remove("muted", "cam-off");
    ui.overlay.hidden = false;
    document.body.classList.add("in-call");
  }

  function hideOverlay() {
    ui.overlay.hidden = true;
    ui.overlay.dataset.state = "";
    document.body.classList.remove("in-call");
    ui.remoteVideo.srcObject = null;
    ui.remoteAudio.srcObject = null;
    ui.localVideo.srcObject = null;
  }

  // ── Tones ────────────────────────────────────────────────────
  // Generated rather than shipped as files: two short patterns are a few lines
  // of Web Audio, and there is nothing to cache or to fail to load.
  const Tone = (() => {
    let ctx = null;
    let timer = null;

    function beep(freqs, ms) {
      const t = ctx.currentTime;
      const gain = ctx.createGain();
      gain.gain.setValueAtTime(0.0001, t);
      gain.gain.exponentialRampToValueAtTime(0.12, t + 0.03);
      gain.gain.setValueAtTime(0.12, t + ms / 1000 - 0.05);
      gain.gain.exponentialRampToValueAtTime(0.0001, t + ms / 1000);
      gain.connect(ctx.destination);
      freqs.forEach((f) => {
        const osc = ctx.createOscillator();
        osc.frequency.value = f;
        osc.connect(gain);
        osc.start(t);
        osc.stop(t + ms / 1000);
      });
    }

    function play(kind) {
      stop();
      try {
        ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
        if (ctx.state === "suspended") ctx.resume().catch(() => {});
      } catch (_) {
        return;
      }
      // Ringback for the caller (one long purr), a brighter double ring for
      // the person being called.
      const pattern =
        kind === "ring"
          ? () => {
              beep([880, 1320], 350);
              setTimeout(() => ctx && beep([880, 1320], 350), 500);
              if (navigator.vibrate) navigator.vibrate([350, 150, 350]);
            }
          : () => beep([440, 480], 1600);
      pattern();
      timer = setInterval(pattern, kind === "ring" ? 2200 : 4000);
    }

    function stop() {
      clearInterval(timer);
      timer = null;
      if (navigator.vibrate) navigator.vibrate(0);
    }

    return { play, stop };
  })();

  // ── Media ────────────────────────────────────────────────────
  async function getMedia(media) {
    // Before capture starts: iOS settles the audio route when the session
    // becomes active, and "this is a call" is what keeps it off the speaker.
    setAudioSession("play-and-record");
    try {
      return await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        video:
          media === "video"
            ? { facingMode: "user", width: { ideal: 1280 }, height: { ideal: 720 } }
            : false,
      });
    } catch (err) {
      const what = media === "video" ? "camera and microphone" : "microphone";
      const why =
        err && err.name === "NotAllowedError"
          ? `Allow access to your ${what} to make calls.`
          : err && err.name === "NotFoundError"
            ? `No ${what} was found on this device.`
            : err && err.name === "NotReadableError"
              ? `Your ${what} is being used by another app.`
              : `Could not start your ${what}.`;
      showToast("Can't start the call", why, "", 6000);
      return null;
    }
  }

  // ── Audio output ─────────────────────────────────────────────
  // Where a call's sound comes out is mostly the operating system's decision,
  // not the page's. Two levers exist and both are used where present:
  //
  //  • navigator.audioSession (Safari 16.4+): declaring "play-and-record"
  //    tells iOS this is a call, which is what routes it to the earpiece
  //    rather than the loudspeaker.
  //  • setSinkId() on the <audio> element: picks an output by id. Works on
  //    desktop (headset vs speakers) and on any phone whose browser lists the
  //    earpiece as an output. Chrome on Android generally does not, and there
  //    the phone plays calls on the speaker unless a headset is connected.
  const canPickOutput =
    "setSinkId" in HTMLMediaElement.prototype &&
    !!(navigator.mediaDevices && navigator.mediaDevices.enumerateDevices);
  const EARPIECE = /earpiece|receiver|handset|phone/i;
  let outputs = [];
  let outputIndex = 0;

  function setAudioSession(type) {
    try {
      if (navigator.audioSession) navigator.audioSession.type = type;
    } catch (_) {
      /* unsupported value on this browser */
    }
  }

  /** After getUserMedia, when device labels are readable. */
  async function setupOutputs() {
    outputs = [];
    outputIndex = 0;
    ui.speaker.hidden = true;
    if (!canPickOutput) return;

    try {
      const devices = await navigator.mediaDevices.enumerateDevices();
      const seen = new Set();
      outputs = devices.filter((d) => {
        if (d.kind !== "audiooutput" || seen.has(d.groupId + d.label)) return false;
        seen.add(d.groupId + d.label);
        return true;
      });
    } catch (_) {
      return;
    }
    if (outputs.length < 2) return;

    // A voice call starts on the earpiece where there is one — held to the
    // ear, the way a phone call is.
    if (call && call.media === "audio") {
      const ear = outputs.findIndex((d) => EARPIECE.test(d.label));
      if (ear >= 0) outputIndex = ear;
    }
    ui.speaker.hidden = false;
    await applyOutput();
  }

  async function applyOutput() {
    const device = outputs[outputIndex];
    if (!device) return;
    try {
      await ui.remoteAudio.setSinkId(device.deviceId);
    } catch (_) {
      return;
    }
    const label = device.label || "Default output";
    ui.speaker.title = ui.speaker.ariaLabel = "Audio: " + label + " (tap to switch)";
    // "Pressed" means loudspeaker, which only means something when there is
    // an earpiece to be off.
    const hasEar = outputs.some((d) => EARPIECE.test(d.label));
    ui.speaker.setAttribute("aria-pressed", String(hasEar && !EARPIECE.test(label)));
  }

  async function cycleOutput() {
    if (outputs.length < 2) return;
    outputIndex = (outputIndex + 1) % outputs.length;
    await applyOutput();
    showToast("Audio output", outputs[outputIndex].label || "Default output", "", 2000);
  }

  function showLocal(stream) {
    if (call.media !== "video") return;
    ui.localVideo.srcObject = stream;
    ui.localVideo.classList.toggle("mirrored", call.facing === "user");
  }

  async function createPeer() {
    let iceServers = [];
    try {
      iceServers = (await apiGet("ajax/call.php?action=ice_config")).iceServers || [];
    } catch (_) {
      /* host candidates alone still work on one network */
    }

    const pc = new RTCPeerConnection({ iceServers });
    call.local.getTracks().forEach((t) => pc.addTrack(t, call.local));

    pc.ontrack = (e) => {
      const stream = e.streams[0] || new MediaStream([e.track]);
      if (call && call.media === "video") ui.remoteVideo.srcObject = stream;
      ui.remoteAudio.srcObject = stream;
      // Autoplay with sound is allowed here — the user has clicked — but a
      // rejected play() must never take the call down with it.
      ui.remoteAudio.play().catch(() => {});
      if (call && call.media === "video") ui.remoteVideo.play().catch(() => {});
    };

    pc.onconnectionstatechange = () => {
      if (!call || call.pc !== pc) return;
      const s = pc.connectionState;
      if (s === "connected") onConnected();
      else if (s === "disconnected") setStatus("Reconnecting…");
      else if (s === "failed") {
        showToast("Call dropped", "The connection to the other side was lost.", "", 5000);
        hangup("failed");
      }
    };

    return pc;
  }

  /** Resolve once every candidate is in the local description (or time is up). */
  function iceComplete(pc) {
    if (pc.iceGatheringState === "complete") return Promise.resolve();
    return new Promise((resolve) => {
      const finish = () => {
        pc.removeEventListener("icegatheringstatechange", check);
        clearTimeout(timer);
        resolve();
      };
      const check = () => pc.iceGatheringState === "complete" && finish();
      const timer = setTimeout(finish, ICE_WAIT_MS);
      pc.addEventListener("icegatheringstatechange", check);
    });
  }

  async function sendDescription(kind) {
    await iceComplete(call.pc);
    if (!call) return;
    const d = call.pc.localDescription;
    await post({
      action: "signal",
      call: call.id,
      kind,
      payload: JSON.stringify({ type: d.type, sdp: d.sdp }),
    });
  }

  // ── Polling ──────────────────────────────────────────────────
  function schedulePoll(delay) {
    clearTimeout(call.pollTimer);
    call.pollTimer = setTimeout(poll, delay);
  }

  async function poll() {
    if (!call) return;
    const me = call;
    let d;
    try {
      d = await apiGet(`ajax/call.php?action=poll&call=${me.id}&after=${me.after}`);
    } catch (_) {
      if (call === me) schedulePoll(SLOW_POLL_MS);
      return;
    }
    if (call !== me) return;

    if (d.call && TERMINAL.includes(d.call.status)) {
      return finish(d.call.status, d.call.duration);
    }

    // Nothing to apply them to until this side has a peer connection — the
    // callee's offer waits on the server until they pick up.
    for (const sig of me.pc ? d.signals || [] : []) {
      me.after = Math.max(me.after, sig.id);
      try {
        await handleSignal(sig);
      } catch (err) {
        console.error("[call] signal", sig.kind, err);
      }
      if (call !== me) return;
    }

    schedulePoll(me.state === "connected" ? SLOW_POLL_MS : FAST_POLL_MS);
  }

  async function handleSignal(sig) {
    const pc = call.pc;
    if (!pc || !sig.payload) return;

    if (sig.kind === "answer" && call.role === "caller" && !call.gotRemote) {
      call.gotRemote = true;
      Tone.stop();
      clearTimeout(call.ringTimer);
      setState("connecting");
      setStatus("Connecting…");
      armConnectTimeout();
      await pc.setRemoteDescription(sig.payload);
    } else if (sig.kind === "offer" && call.role === "callee" && !call.gotRemote) {
      call.gotRemote = true;
      await pc.setRemoteDescription(sig.payload);
      await pc.setLocalDescription(await pc.createAnswer());
      await sendDescription("answer");
    } else if (sig.kind === "ice") {
      const list = Array.isArray(sig.payload) ? sig.payload : [sig.payload];
      for (const c of list) await pc.addIceCandidate(c).catch(() => {});
    }
  }

  // ── Placing a call ───────────────────────────────────────────
  async function start(media) {
    if (!supported || call) return;
    if (!State.chatUserId || isGroupChat()) return;

    const peerUser = State.chatUser || {};
    const peer = {
      id: State.chatUserId,
      name: peerUser.full_name || peerUser.username || "",
      avatar: peerUser.avatar || "",
    };

    call = {
      id: 0, role: "caller", media, peer, state: "outgoing", pc: null, local: null,
      after: 0, gotRemote: false, facing: "user",
      pollTimer: null, ringTimer: null, connectTimer: null, clock: null, connectedAt: 0,
    };
    const me = call;
    showOverlay(peer, media, "outgoing");
    setStatus("Starting…");

    me.local = await getMedia(media);
    if (call !== me) return stopTracks(me.local);
    if (!me.local) return teardown();
    showLocal(me.local);
    setupOutputs();

    let res;
    try {
      res = await post({ action: "start", to: peer.id, media });
    } catch (err) {
      showToast("Can't start the call", err.message, "", 5000);
      return teardown();
    }
    if (call !== me) return;

    me.id = res.call.id;
    if (res.busy) {
      setStatus(`${peer.name} is on another call`);
      return finish("busy", 0, true);
    }

    setStatus("Calling…");
    Tone.play("ringback");
    // The server would call it missed on its own; ending it here says so
    // straight away instead of on the next sweep.
    me.ringTimer = setTimeout(() => {
      if (call === me && me.state === "outgoing") hangup();
    }, (res.ring || UNI.callRingSec) * 1000);

    try {
      me.pc = await createPeer();
      if (call !== me) return;
      await me.pc.setLocalDescription(await me.pc.createOffer());
      await sendDescription("offer");
    } catch (err) {
      console.error("[call] offer", err);
      return hangup("failed");
    }
    if (call === me) schedulePoll(FAST_POLL_MS);
  }

  // ── Receiving a call ─────────────────────────────────────────
  /** Fed by every poll that carries `incoming_call`, and by the ring push. */
  function onIncoming(info) {
    if (!supported || !info || !info.id) return;
    if (done.has(info.id)) return;
    if (call) return; // already on one; the server answered "busy" for us

    call = {
      id: info.id, role: "callee", media: info.media, peer: info.from || {}, state: "incoming",
      pc: null, local: null, after: 0, gotRemote: false, facing: "user",
      pollTimer: null, ringTimer: null, connectTimer: null, clock: null, connectedAt: 0,
    };
    const me = call;
    showOverlay(me.peer, me.media, "incoming");
    setStatus(me.media === "video" ? "Incoming video call" : "Incoming voice call");
    Tone.play("ring");

    if (document.hidden && State.notifGranted && !State.pushEnabled) {
      try {
        new Notification(me.media === "video" ? "Incoming video call" : "Incoming voice call", {
          body: me.peer.name || "",
          tag: "call-" + me.id,
          icon: me.peer.avatar && !me.peer.avatar.startsWith("data:") ? me.peer.avatar : undefined,
        });
      } catch (_) {
        /* the push already covers it */
      }
    }

    // Ring for no longer than the caller will wait.
    me.ringTimer = setTimeout(() => {
      if (call === me && me.state === "incoming") finish("missed", 0);
    }, Math.max(5, info.ttl || UNI.callRingSec) * 1000);

    // Polling while it rings is how a caller giving up reaches this screen.
    schedulePoll(1500);
  }

  /** The ring push: fetch the call's details, then ring as if a poll had. */
  async function onPush(data) {
    if (!supported || !data || !data.call || call || done.has(Number(data.call))) return;
    try {
      const d = await apiGet(`ajax/call.php?action=poll&call=${Number(data.call)}&after=0`);
      if (d.call && d.call.status === "ringing" && d.call.role === "callee") {
        onIncoming({ id: d.call.id, media: d.call.media, from: d.call.peer, ttl: UNI.callRingSec });
      }
    } catch (_) {
      /* the next poll will ring it */
    }
  }

  async function accept() {
    if (!call || call.state !== "incoming") return;
    const me = call;
    Tone.stop();
    clearTimeout(me.ringTimer);
    setState("connecting");
    setStatus("Connecting…");

    me.local = await getMedia(me.media);
    if (call !== me) return stopTracks(me.local);
    if (!me.local) return decline();
    showLocal(me.local);
    setupOutputs();

    try {
      await post({ action: "accept", call: me.id });
    } catch (err) {
      showToast("Call ended", err.message, "", 4000);
      return finish("ended", 0, true);
    }
    if (call !== me) return;

    try {
      me.pc = await createPeer();
    } catch (err) {
      console.error("[call] peer", err);
      return hangup("failed");
    }
    armConnectTimeout();
    if (call === me) schedulePoll(0);
  }

  async function decline() {
    if (!call) return;
    const me = call;
    finish("rejected", 0, true);
    post({ action: "reject", call: me.id }).catch(() => {});
  }

  // ── In the call ──────────────────────────────────────────────
  function armConnectTimeout() {
    const me = call;
    clearTimeout(me.connectTimer);
    me.connectTimer = setTimeout(() => {
      if (call === me && me.state !== "connected") {
        showToast(
          "Couldn't connect",
          "The call could not reach the other side. A strict network may be blocking it.",
          "",
          7000,
        );
        hangup("failed");
      }
    }, CONNECT_TIMEOUT_MS);
  }

  function onConnected() {
    if (!call) return;
    Tone.stop();
    clearTimeout(call.ringTimer);
    clearTimeout(call.connectTimer);
    if (call.state !== "connected") {
      setState("connected");
      call.connectedAt = call.connectedAt || Date.now();
      clearInterval(call.clock);
      const tick = () => call && setStatus(fmtDuration((Date.now() - call.connectedAt) / 1000));
      tick();
      call.clock = setInterval(tick, 1000);
    } else {
      setStatus(fmtDuration((Date.now() - call.connectedAt) / 1000));
    }
  }

  async function hangup(reason) {
    if (!call) return;
    const me = call;
    // The caller's answer arrives by poll; once it has, the call is "in
    // progress" for the server even before media flows.
    finish(reason === "failed" ? "failed" : me.state === "outgoing" ? "cancelled" : "ended",
      me.connectedAt ? (Date.now() - me.connectedAt) / 1000 : 0, true);
    if (me.id) {
      const fields = { action: "hangup", call: me.id };
      if (reason) fields.reason = reason;
      post(fields).catch(() => {});
    }
  }

  function toggleMute() {
    if (!call || !call.local) return;
    const track = call.local.getAudioTracks()[0];
    if (!track) return;
    track.enabled = !track.enabled;
    ui.overlay.classList.toggle("muted", !track.enabled);
    ui.mute.setAttribute("aria-pressed", String(!track.enabled));
  }

  function toggleCamera() {
    if (!call || !call.local) return;
    const track = call.local.getVideoTracks()[0];
    if (!track) return;
    track.enabled = !track.enabled;
    ui.overlay.classList.toggle("cam-off", !track.enabled);
    ui.camera.setAttribute("aria-pressed", String(!track.enabled));
  }

  /** Front ↔ back camera, without renegotiating: replaceTrack swaps in place. */
  async function flipCamera() {
    if (!call || !call.local || call.media !== "video") return;
    const me = call;
    const facing = me.facing === "user" ? "environment" : "user";
    let stream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { exact: facing } } });
    } catch (_) {
      showToast("Camera", "No other camera is available.", "", 3000);
      return;
    }
    if (call !== me) return stopTracks(stream);

    const fresh = stream.getVideoTracks()[0];
    const old = me.local.getVideoTracks()[0];
    fresh.enabled = !ui.overlay.classList.contains("cam-off");
    const sender = me.pc && me.pc.getSenders().find((s) => s.track && s.track.kind === "video");
    if (sender) await sender.replaceTrack(fresh);
    if (old) {
      me.local.removeTrack(old);
      old.stop();
    }
    me.local.addTrack(fresh);
    me.facing = facing;
    showLocal(me.local);
  }

  // ── Ending ───────────────────────────────────────────────────
  const ENDED_TEXT = {
    rejected: "Call declined",
    missed: "No answer",
    cancelled: "Call cancelled",
    busy: "Busy",
    ended: "Call ended",
    failed: "Call failed",
  };

  /**
   * Stop everything and say how it ended for a moment before closing.
   * $local: this tab ended it, so the message is ours rather than the server's.
   */
  function finish(status, duration, local = false) {
    if (!call) return;
    const me = call;
    done.add(me.id);
    Tone.stop();
    clearTimeout(me.pollTimer);
    clearTimeout(me.ringTimer);
    clearTimeout(me.connectTimer);
    clearInterval(me.clock);
    stopTracks(me.local);
    setAudioSession("auto");
    if (me.pc) {
      me.pc.ontrack = me.pc.onconnectionstatechange = null;
      me.pc.close();
    }
    call = null;

    // The person being called and declining needs no "Call declined" card.
    if (local && me.role === "callee" && status === "rejected") return hideOverlay();
    // Nor does a missed ring on the side that never picked up.
    if (me.role === "callee" && me.state === "incoming" && !local) {
      hideOverlay();
      return;
    }

    ui.overlay.dataset.state = "ended";
    let text = ENDED_TEXT[status] || "Call ended";
    if (status === "busy" && me.peer.name) text = `${me.peer.name} is on another call`;
    if (status === "ended" && duration > 0) text += " · " + fmtDuration(duration);
    setStatus(text);
    setTimeout(() => {
      if (!call) hideOverlay();
    }, 1600);

    // The log line the server just wrote belongs in the open conversation.
    if (typeof fetchMessages === "function" && State.chatUserId === Number(me.peer.id)) {
      setTimeout(() => fetchMessages(true), 600);
    }
  }

  function stopTracks(stream) {
    if (stream) stream.getTracks().forEach((t) => t.stop());
  }

  function teardown() {
    if (!call) return;
    const me = call;
    call = null;
    Tone.stop();
    clearTimeout(me.pollTimer);
    clearTimeout(me.ringTimer);
    stopTracks(me.local);
    setAudioSession("auto");
    if (me.pc) me.pc.close();
    hideOverlay();
  }

  // A closed tab must not leave the other person staring at "Connecting…" for
  // the half minute it takes the heartbeat to expire.
  window.addEventListener("pagehide", () => {
    if (!call || !call.id) return;
    const fd = form({ action: call.state === "incoming" ? "reject" : "hangup", call: call.id, csrf_token: UNI.csrf });
    navigator.sendBeacon(API_BASE + "ajax/call.php", fd);
  });

  // ── Wiring ───────────────────────────────────────────────────
  if (ui.overlay) {
    ui.accept.addEventListener("click", accept);
    ui.decline.addEventListener("click", decline);
    ui.hangup.addEventListener("click", () => hangup());
    ui.mute.addEventListener("click", toggleMute);
    ui.camera.addEventListener("click", toggleCamera);
    ui.flip.addEventListener("click", flipCamera);
    ui.speaker.addEventListener("click", cycleOutput);
  }
  const audioBtn = $("callAudioBtn");
  const videoBtn = $("callVideoBtn");
  if (audioBtn) audioBtn.addEventListener("click", () => start("audio"));
  if (videoBtn) videoBtn.addEventListener("click", () => start("video"));
  // A call record in the conversation calls back with the same media.
  document.addEventListener("click", (e) => {
    const bubble = e.target.closest && e.target.closest(".call-bubble");
    if (bubble) start(bubble.dataset.callMedia === "video" ? "video" : "audio");
  });

  return {
    supported,
    start,
    onIncoming,
    onPush,
    get active() {
      return !!call;
    },
  };
})();

/** Header buttons: one-to-one chats only, and only where calls can work. */
function syncCallButtons() {
  const show = Call.supported && State.chatUserId !== null && !isGroupChat();
  ["callAudioBtn", "callVideoBtn"].forEach((id) => {
    const b = document.getElementById(id);
    if (b) b.hidden = !show;
  });
}
