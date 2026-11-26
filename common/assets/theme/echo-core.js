/*
 * Echo realtime client core — shared by the wall, the demo and the admin panel.
 * No framework, no build step.
 */
(function (global) {
  'use strict';

  const Echo = global.Echo = global.Echo || {};

  Echo.csrf = function () {
    const param = document.querySelector('meta[name="csrf-param"]');
    const token = document.querySelector('meta[name="csrf-token"]');
    return { param: param ? param.content : null, token: token ? token.content : null };
  };

  /** fetch wrapper: JSON in/out, CSRF header, AJAX marker. Resolves {ok, status, data}. */
  Echo.request = async function (url, options) {
    const opts = Object.assign({ method: 'GET', headers: {} }, options || {});
    opts.headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, opts.headers);
    if (opts.method !== 'GET') {
      const csrf = Echo.csrf();
      if (csrf.token) opts.headers['X-CSRF-Token'] = csrf.token;
    }
    opts.credentials = 'same-origin';
    const res = await fetch(url, opts);
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    return { ok: res.ok, status: res.status, data: data };
  };

  /** Compact relative time: "сейчас", "12с", "5м", "3ч", "2д", then a date. */
  Echo.relTime = function (unixSeconds, nowMs) {
    const now = (nowMs || Date.now()) / 1000;
    const d = Math.max(0, Math.floor(now - unixSeconds));
    if (d < 5) return 'сейчас';
    if (d < 60) return d + 'с';
    if (d < 3600) return Math.floor(d / 60) + 'м';
    if (d < 86400) return Math.floor(d / 3600) + 'ч';
    if (d < 7 * 86400) return Math.floor(d / 86400) + 'д';
    const dt = new Date(unixSeconds * 1000);
    return dt.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
  };

  Echo.utc = function (unixSeconds) {
    return new Date(unixSeconds * 1000).toISOString().replace('T', ' ').slice(0, 19);
  };

  Echo.refreshTimes = function (root) {
    (root || document).querySelectorAll('[data-reltime]').forEach(function (el) {
      el.textContent = Echo.relTime(Number(el.getAttribute('data-reltime')));
    });
  };

  Echo.toast = function (text, kind) {
    let box = document.querySelector('.toasts');
    if (!box) {
      box = document.createElement('div');
      box.className = 'toasts';
      box.setAttribute('aria-live', 'polite');
      document.body.appendChild(box);
    }
    const t = document.createElement('div');
    t.className = 'toast toast-' + (kind || 'ok');
    t.textContent = text;
    box.appendChild(t);
    setTimeout(function () { t.remove(); }, 4200);
  };

  /**
   * WS_PUBLIC_URL may be absolute (ws://host:port) or a same-origin path
   * ("/ws"), which follows the page's own host and scheme (https → wss).
   */
  Echo.socketUrl = function (url) {
    if (url.charAt(0) !== '/') return url;
    return (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + url;
  };

  /**
   * Authenticated WebSocket with reconnect.
   *  tokenUrl  — same-origin endpoint returning {url, token}
   *  onFrame   — called with each parsed frame (system.* frames included)
   *  onState   — 'connecting' | 'live' | 'offline'
   * Every (re)connect fetches a fresh short-lived token.
   */
  Echo.Socket = class {
    constructor(opts) {
      this.tokenUrl = opts.tokenUrl;
      this.onFrame = opts.onFrame || function () {};
      this.onState = opts.onState || function () {};
      this.attempt = 0;
      this.ws = null;
      this.stopped = false;
      this.state = 'offline';
    }

    setState(s, detail) {
      this.state = s;
      this.onState(s, detail);
    }

    async connect() {
      this.stopped = false;
      this.setState('connecting');
      let grant;
      try {
        const res = await Echo.request(this.tokenUrl);
        if (!res.ok || !res.data || !res.data.token) throw new Error('token endpoint answered ' + res.status);
        grant = res.data;
      } catch (e) {
        this.setState('offline', 'token: ' + e.message);
        return this.retry();
      }

      const ws = new WebSocket(Echo.socketUrl(grant.url) + '/?token=' + encodeURIComponent(grant.token));
      this.ws = ws;
      ws.addEventListener('message', (ev) => {
        let frame;
        try { frame = JSON.parse(ev.data); } catch (e) { return; }
        frame.received_at = Date.now() / 1000;
        if (frame.event === 'system.hello') {
          this.attempt = 0;
          this.setState('live', frame.data);
        }
        this.onFrame(frame);
      });
      ws.addEventListener('close', (ev) => {
        if (this.ws !== ws) return;
        this.ws = null;
        this.setState('offline', ev.code === 4401 ? 'unauthorized' : 'closed (' + ev.code + ')');
        if (!this.stopped) this.retry();
      });
    }

    retry() {
      if (this.stopped) return;
      const delay = Math.min(10000, 1000 * Math.pow(1.6, this.attempt++));
      clearTimeout(this.timer);
      this.timer = setTimeout(() => this.connect(), delay);
    }

    stop() {
      this.stopped = true;
      clearTimeout(this.timer);
      if (this.ws) this.ws.close();
    }
  };

  /**
   * The wall feed component (also embedded in /demo). Cards are cloned from a
   * server-rendered <template>, so markup has a single source (_card.php).
   */
  Echo.Feed = class {
    constructor(list, template, opts) {
      this.list = list;
      this.template = template;
      this.opts = opts || {};
      this.limit = this.opts.limit || 0;
    }

    card(id) { return this.list.querySelector('[data-comment-id="' + id + '"]'); }
    has(id) { return !!this.card(id); }

    build(c) {
      const node = this.template.content.firstElementChild.cloneNode(true);
      node.setAttribute('data-comment-id', c.id);
      node.querySelector('[data-slot="avatar"]').src = c.avatar_url;
      node.querySelector('[data-slot="name"]').textContent = c.author_name;
      node.querySelector('[data-slot="handle"]').textContent = '@' + c.handle;
      const time = node.querySelector('[data-slot="time"]');
      time.setAttribute('data-reltime', c.created_at);
      time.setAttribute('datetime', c.created_at_iso);
      time.title = Echo.utc(c.created_at) + ' UTC';
      time.textContent = Echo.relTime(c.created_at);
      node.querySelector('[data-slot="body"]').textContent = c.body;
      node.querySelector('[data-slot="likes"]').textContent = c.likes;
      const demo = node.querySelector('[data-slot="demo"]');
      if (demo) demo.hidden = !c.is_demo;
      const like = node.querySelector('[data-like]');
      if (like) like.setAttribute('data-like', c.id);
      return node;
    }

    prepend(c, flash) {
      if (this.has(c.id)) return this.card(c.id);
      const node = this.build(c);
      if (flash !== false) node.classList.add('is-new', 'slide-in');
      this.list.prepend(node);
      const empty = this.list.parentElement && this.list.parentElement.querySelector('[data-empty]');
      if (empty) empty.hidden = true;
      if (this.limit) {
        while (this.list.children.length > this.limit) this.list.lastElementChild.remove();
      }
      return node;
    }

    update(c) {
      const old = this.card(c.id);
      if (!old) return;
      const fresh = this.build(c);
      const like = old.querySelector('[data-like]');
      if (like && like.classList.contains('is-liked')) fresh.querySelector('[data-like]').classList.add('is-liked');
      fresh.classList.add('is-new');
      old.replaceWith(fresh);
    }

    remove(id) {
      const node = this.card(id);
      if (!node) return;
      node.classList.add('slide-out');
      setTimeout(function () { node.remove(); }, 280);
    }

    setLikes(id, likes, liked) {
      const node = this.card(id);
      if (!node) return;
      node.querySelector('[data-slot="likes"]').textContent = likes;
      if (typeof liked === 'boolean') node.querySelector('[data-like]').classList.toggle('is-liked', liked);
    }
  };
})(window);
