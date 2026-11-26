/*
 * /demo — self-playing showcase driven by REAL signals:
 *   - the auto-poster sends real POST /comments (demo=1) requests;
 *   - Visitor→Front-end→MySQL/Redis light up when the POST actually resolves;
 *   - Redis→WS→Admin light up when the WebSocket frame actually arrives;
 *   - frames are matched to their POST by the envelope trace id → true e2e latency.
 */
(function () {
  'use strict';

  const root = document.querySelector('[data-demo]');
  if (!root) return;

  const personas = JSON.parse(root.dataset.personas);
  const feed = new Echo.Feed(root.querySelector('[data-demo-feed]'), document.getElementById('card-template'), { limit: 30 });
  const adminList = root.querySelector('[data-demo-admin]');
  const traceList = root.querySelector('[data-trace]');
  const toggle = root.querySelector('[data-toggle]');
  const speed = root.querySelector('[data-speed]');
  const speedLabel = root.querySelector('[data-speed-label]');
  const live = root.querySelector('[data-live]');
  const particles = root.querySelector('[data-particles]');
  const stat = (k) => root.querySelector('[data-stat="' + k + '"]');

  const state = {
    playing: false, started: false, inFlight: false, timer: null, cursor: 0,
    posted: 0, relayed: 0, latencies: [],
    waiting: new Map(), // trace → { t0, id }       POST answered, frame not yet seen
    early: new Map(),   // trace → frame received_at  frame seen before the POST answer
  };

  // The relay is often FASTER than the HTTP response: the WS frame can land
  // before fetch() resolves. Latency is recorded in whichever order they meet.
  function recordLatency(t0, receivedAt, id) {
    const e2e = (receivedAt - t0) * 1000;
    state.latencies.push(e2e);
    if (state.latencies.length > 200) state.latencies.shift();
    trace('cli', 'frame #' + id + ' matched  e2e ' + e2e.toFixed(0) + 'ms', 'ok', receivedAt);
  }

  // ---- trace ticker ----------------------------------------------------------
  const clock = (sec) => {
    const d = new Date(sec * 1000);
    return d.toTimeString().slice(0, 8) + '.' + String(d.getMilliseconds()).padStart(3, '0');
  };
  function trace(src, text, kind, atSeconds) {
    const li = document.createElement('li');
    li.innerHTML = '<span class="t"></span><span class="src"></span><span class="m"></span>';
    li.children[0].textContent = clock(atSeconds || Date.now() / 1000);
    li.children[1].textContent = src;
    li.children[2].textContent = text;
    if (kind) li.children[2].className = kind;
    traceList.prepend(li);
    while (traceList.children.length > 160) traceList.lastElementChild.remove();
  }

  // ---- pipeline animation (triggered only by real events) ---------------------
  function hot(el, ms) {
    el.classList.add('hot');
    clearTimeout(el._hotTimer);
    el._hotTimer = setTimeout(() => el.classList.remove('hot'), ms || 650);
  }
  function pulse(edgeId, from, to) {
    const path = document.getElementById('edge-' + edgeId);
    if (!path) return Promise.resolve();
    hot(path);
    if (from) hot(document.getElementById('node-' + from));
    const len = path.getTotalLength();
    const dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    dot.setAttribute('r', '5');
    dot.setAttribute('class', 'particle');
    particles.appendChild(dot);
    const dur = 420;
    const t0 = performance.now();
    return new Promise((resolve) => {
      function step(now) {
        const k = Math.min(1, (now - t0) / dur);
        const p = path.getPointAtLength(len * (1 - Math.pow(1 - k, 2)));
        dot.setAttribute('cx', p.x);
        dot.setAttribute('cy', p.y);
        if (k < 1) requestAnimationFrame(step); else {
          dot.remove();
          if (to) hot(document.getElementById('node-' + to), 800);
          resolve();
        }
      }
      requestAnimationFrame(step);
    });
  }

  // ---- stats -----------------------------------------------------------------
  function renderStats() {
    stat('posted').textContent = state.posted;
    stat('relayed').textContent = state.relayed;
    const l = state.latencies;
    if (!l.length) return;
    const sorted = l.slice().sort((a, b) => a - b);
    stat('last').textContent = l[l.length - 1].toFixed(0) + 'ms';
    stat('avg').textContent = (l.reduce((a, b) => a + b, 0) / l.length).toFixed(0) + 'ms';
    stat('p95').textContent = sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * 0.95))].toFixed(0) + 'ms';
  }

  // ---- auto-poster (real HTTP) ---------------------------------------------------
  function nextMessage() {
    const flat = [];
    personas.forEach((p) => p.lines.forEach((line) => flat.push({ name: p.name, body: line })));
    const pick = flat[state.cursor % flat.length];
    state.cursor++;
    return pick;
  }

  async function postOne() {
    if (state.inFlight) return; // never queue requests behind a slow one
    state.inFlight = true;
    const msg = nextMessage();
    const data = new FormData();
    const csrf = Echo.csrf();
    data.append(csrf.param, csrf.token);
    data.append('Comment[author_name]', msg.name);
    data.append('Comment[body]', msg.body);
    data.append('demo', '1');

    const t0 = Date.now() / 1000;
    trace('cli', 'POST /comments  ' + msg.name);
    pulse('visitor-frontend', 'visitor', 'frontend');
    let res;
    try {
      res = await Echo.request(root.dataset.createUrl, { method: 'POST', body: data });
    } catch (e) {
      trace('cli', 'network error: ' + e.message, 'err');
      state.inFlight = false;
      return;
    }
    state.inFlight = false;
    const ms = (Date.now() / 1000 - t0) * 1000;

    if (res.status !== 201) {
      trace('cli', 'POST /comments → ' + res.status, 'err');
      return;
    }
    const c = res.data.comment;
    const rt = res.data.realtime;
    state.posted++;
    trace('cli', 'POST /comments → 201  #' + c.id + '  (' + ms.toFixed(0) + 'ms)', 'ok');
    pulse('frontend-mysql', 'frontend', 'mysql');
    trace('srv', 'INSERT comment #' + c.id, null, rt.published_at);
    if (rt.delivered) {
      pulse('frontend-redis', 'frontend', 'redis');
      trace('srv', 'redis PUBLISH → ' + rt.subscribers + ' subscriber(s)  trace ' + rt.trace.slice(0, 8), rt.subscribers > 0 ? 'ok' : 'warn', rt.published_at);
      if (state.early.has(rt.trace)) {
        recordLatency(t0, state.early.get(rt.trace), c.id);
        state.early.delete(rt.trace);
      } else {
        state.waiting.set(rt.trace, { t0: t0, id: c.id });
      }
    } else {
      trace('srv', 'redis PUBLISH failed — relay unavailable', 'err', rt.published_at);
    }
    feed.prepend(c);
    renderStats();
  }

  function schedule() {
    clearTimeout(state.timer);
    if (!state.playing) return;
    state.timer = setTimeout(async () => { await postOne(); schedule(); }, Number(speed.value));
  }

  // ---- admin stream (real WebSocket) ------------------------------------------
  function adminRow(c) {
    if (adminList.querySelector('[data-id="' + c.id + '"]')) return;
    const row = document.createElement('div');
    row.className = 'demo-admin-row is-new slide-in';
    row.dataset.id = c.id;
    row.innerHTML = '<span class="mono"></span><div><b></b><p></p></div><span class="mono"></span>';
    row.children[0].textContent = '#' + c.id;
    row.children[1].children[0].textContent = c.author_name;
    row.children[1].children[1].textContent = c.body;
    row.children[2].textContent = '🔥 ' + c.likes;
    adminList.prepend(row);
    while (adminList.children.length > 30) adminList.lastElementChild.remove();
  }

  function onFrame(f) {
    const d = f.data || {};
    if (f.event === 'comment.created' && d.is_demo) {
      const pending = state.waiting.get(f.trace);
      state.waiting.delete(f.trace);
      state.relayed++;
      trace('ws', 'relay → ' + f.relay.recipients + ' socket(s)', 'ok', f.relay.relayed_at);
      pulse('redis-ws', 'redis', 'ws').then(() => pulse('ws-admin', 'ws', 'admin')).then(() => adminRow(d));
      if (pending) {
        recordLatency(pending.t0, f.received_at, d.id);
      } else {
        trace('cli', 'frame #' + d.id + ' arrived before its POST response', null, f.received_at);
        state.early.set(f.trace, f.received_at);
      }
      renderStats();
    } else if (f.event === 'comment.purged') {
      trace('ws', 'comment.purged → ' + (d.ids || []).length + ' row(s) removed', 'ok', f.relay.relayed_at);
      adminList.innerHTML = '';
    } else if (f.event === 'comment.deleted') {
      const row = adminList.querySelector('[data-id="' + d.id + '"]');
      if (row) row.remove();
      feed.remove(d.id);
    }
  }

  new Echo.Socket({
    tokenUrl: root.dataset.tokenUrl,
    onFrame: onFrame,
    onState: (s, detail) => {
      live.dataset.state = s;
      live.textContent = s;
      if (s === 'live') trace('ws', 'socket open  scope=public', 'ok');
      if (s === 'offline' && state.started) trace('ws', 'socket ' + (detail || 'closed') + ' — reconnecting', 'warn');
    },
  }).connect();

  // ---- controls ------------------------------------------------------------------
  function setPlaying(on) {
    state.playing = on;
    if (on && !state.started) { state.started = true; root.classList.add('is-running'); }
    toggle.textContent = on ? '❚❚ Pause' : (state.started ? '▶ Resume' : '▶ Play');
    if (on) { postOne(); schedule(); } else clearTimeout(state.timer);
  }

  toggle.addEventListener('click', () => setPlaying(!state.playing));
  root.querySelector('[data-play-big]').addEventListener('click', () => setPlaying(true));
  speed.addEventListener('input', () => {
    speedLabel.textContent = (Number(speed.value) / 1000).toFixed(1) + 's';
    if (state.playing) schedule();
  });

  root.querySelector('[data-reset]').addEventListener('click', async () => {
    setPlaying(false);
    trace('cli', 'POST /demo/reset');
    const res = await Echo.request(root.dataset.resetUrl, { method: 'POST' });
    if (!res.ok) { trace('cli', 'reset failed → ' + res.status, 'err'); return; }
    trace('srv', 'purged ' + res.data.deleted + ' demo comment(s)  (real comments untouched)', 'ok', res.data.realtime.published_at);
    feed.list.innerHTML = '';
    state.posted = 0; state.relayed = 0; state.latencies = []; state.waiting.clear(); state.early.clear();
    ['last', 'avg', 'p95'].forEach((k) => { stat(k).textContent = '—'; });
    renderStats();
  });

  // QA hook: lets the E2E suite observe the demo without scraping animations.
  window.echoDemo = { state: state, play: () => setPlaying(true), pause: () => setPlaying(false) };
})();

/* Bonus: Pegasus Dodge in a modal over the demo. The iframe is loaded on open
   and unloaded on close, so the game's render loop never runs in the background. */
(function () {
  'use strict';
  const dialog = document.querySelector('[data-game-dialog]');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const frame = dialog.querySelector('[data-game-frame]');
  const close = () => { dialog.close(); };

  document.querySelector('[data-game-open]').addEventListener('click', () => {
    if (window.echoDemo && window.echoDemo.state.playing) window.echoDemo.pause();
    frame.src = '/game/';
    dialog.showModal();
    frame.addEventListener('load', () => frame.focus(), { once: true });
  });
  dialog.querySelector('[data-game-close]').addEventListener('click', close);
  dialog.addEventListener('click', (ev) => { if (ev.target === dialog) close(); });
  dialog.addEventListener('close', () => { frame.src = 'about:blank'; });
})();
