/* Echo Control: live comment table over the admin-scope WebSocket. */
(function () {
  'use strict';

  const live = document.querySelector('[data-live]');
  const setLive = (s) => { if (live) { live.dataset.state = s; live.textContent = s; } };

  // Delete confirmation for every row, including rows added live.
  document.addEventListener('submit', (ev) => {
    const form = ev.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) ev.preventDefault();
  });

  const panel = document.querySelector('[data-admin-live]');
  if (!panel) {
    // Other admin pages still show the relay state.
    if (live && document.querySelector('meta[name="csrf-token"]') && document.body.classList.contains('admin') && !document.body.classList.contains('auth')) {
      new Echo.Socket({ tokenUrl: '/ws-token', onState: setLive }).connect();
    }
    return;
  }

  const rows = panel.querySelector('[data-rows]');
  const template = document.getElementById('row-template');
  const empty = panel.querySelector('[data-empty]');
  const banner = panel.querySelector('[data-banner]');
  const bannerText = panel.querySelector('[data-banner-text]');
  const liveCount = panel.querySelector('[data-live-count]');
  const filter = panel.dataset.filter;
  const onFirstPage = panel.dataset.page === '0';
  const csrf = Echo.csrf();
  let missed = 0;

  const row = (id) => rows.querySelector('tr[data-comment-id="' + id + '"]');
  const count = (key, d) => {
    panel.querySelectorAll('[data-count="' + key + '"]').forEach((el) => {
      el.textContent = Math.max(0, Number(el.textContent) + d);
    });
  };

  function matchesFilter(c) {
    if (filter === 'published') return c.status_label === 'published';
    if (filter === 'hidden') return c.status_label === 'hidden';
    if (filter === 'demo') return !!c.is_demo;
    return true;
  }

  function build(c) {
    const tr = template.content.firstElementChild.cloneNode(true);
    tr.dataset.commentId = c.id;
    tr.className = 'cp-row status-' + c.status_label;
    const slot = (n) => tr.querySelector('[data-slot="' + n + '"]');
    slot('id').textContent = '#' + c.id;
    slot('avatar').src = c.avatar_url;
    slot('name').textContent = c.author_name;
    slot('handle').textContent = '@' + c.handle;
    slot('body').textContent = c.body;
    const status = slot('status');
    status.textContent = c.status_label;
    status.className = 'pill ' + (c.status_label === 'published' ? 'pill-lime' : 'pill-amber');
    slot('demo').hidden = !c.is_demo;
    slot('likes').textContent = c.likes;
    slot('ip').textContent = c.ip || '';
    slot('time').textContent = Echo.utc(c.created_at);
    slot('edit').href = panel.dataset.editUrl.replace('{id}', c.id);
    const del = slot('delete');
    del.action = panel.dataset.deleteUrl.replace('{id}', c.id);
    const csrfInput = del.querySelector('input[type="hidden"]');
    if (csrf.param) { csrfInput.name = csrf.param; csrfInput.value = csrf.token; }
    return tr;
  }

  function flash(tr) {
    tr.classList.remove('is-new');
    void tr.offsetWidth; // restart the animation
    tr.classList.add('is-new');
  }

  function onCreated(c) {
    count('all', 1);
    count(c.status_label, 1);
    if (c.is_demo) count('demo', 1);
    liveCount.textContent = Number(liveCount.textContent) + 1;
    if (!matchesFilter(c) || row(c.id)) return;
    if (!onFirstPage) {
      missed++;
      bannerText.textContent = missed + ' new comment' + (missed > 1 ? 's' : '') + ' arrived while you were on this page.';
      banner.hidden = false;
      return;
    }
    const tr = build(c);
    rows.prepend(tr);
    flash(tr);
    empty.hidden = true;
  }

  function onUpdated(c) {
    const old = row(c.id);
    if (!old) return;
    const oldStatus = old.className.match(/status-(\w+)/);
    if (oldStatus && oldStatus[1] !== c.status_label) {
      count(oldStatus[1], -1);
      count(c.status_label, 1);
    }
    if (!matchesFilter(c)) { old.remove(); return; }
    const tr = build(c);
    old.replaceWith(tr);
    flash(tr);
  }

  function onDeleted(id) {
    const tr = row(id);
    if (!tr) return;
    const st = tr.className.match(/status-(\w+)/);
    count('all', -1);
    if (st) count(st[1], -1);
    if (!tr.querySelector('[data-slot="demo"]').hidden) count('demo', -1);
    tr.classList.add('is-removed');
    setTimeout(() => tr.remove(), 300);
  }

  function onFrame(f) {
    const d = f.data || {};
    switch (f.event) {
      case 'comment.created': onCreated(d); break;
      case 'comment.updated': onUpdated(d); break;
      case 'comment.deleted': onDeleted(d.id); break;
      case 'comment.purged': (d.ids || []).forEach(onDeleted); break;
      case 'comment.liked': {
        const tr = row(d.id);
        if (tr) tr.querySelector('[data-slot="likes"]').textContent = d.likes;
        break;
      }
    }
  }

  window.echoAdminSocket = new Echo.Socket({ tokenUrl: panel.dataset.tokenUrl, onFrame: onFrame, onState: setLive });
  window.echoAdminSocket.connect();
})();
