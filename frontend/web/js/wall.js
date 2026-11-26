/* Echo public wall: AJAX composer, live feed over WebSocket, likes, keyset "load more". */
(function () {
  'use strict';

  const root = document.querySelector('[data-wall]');
  if (!root) return;

  const feed = new Echo.Feed(root.querySelector('[data-feed]'), document.getElementById('card-template'));
  const form = root.querySelector('[data-composer-form]');
  const composer = root.querySelector('[data-composer]');
  const nameInput = form.querySelector('[name="Comment[author_name]"]');
  const body = form.querySelector('[name="Comment[body]"]');
  const submit = form.querySelector('[data-submit]');
  const counter = form.querySelector('[data-counter]');
  const avatar = form.querySelector('[data-composer-avatar]');
  const live = root.querySelector('[data-live]');
  const pill = root.querySelector('[data-new-pill]');
  const pillCount = root.querySelector('[data-new-count]');
  const total = root.querySelector('[data-total]');
  const moreBtn = root.querySelector('[data-more]');
  const endMark = root.querySelector('[data-end]');
  const likeUrl = (id) => root.dataset.likeUrl.replace('__ID__', id);

  const pending = [];      // live arrivals held back while the reader is scrolled down
  const mine = new Set();  // ids this tab posted (already rendered from the POST response)

  // ---- composer -----------------------------------------------------------
  const max = Number(counter.dataset.max);
  function updateCounter() {
    const n = body.value.length;
    counter.textContent = n + ' / ' + max;
    counter.classList.toggle('is-near', n > max * 0.9);
    counter.classList.toggle('is-over', n > max);
    submit.disabled = body.value.trim().length === 0;
  }
  body.addEventListener('input', updateCounter);
  body.addEventListener('focus', () => composer.classList.add('is-open'));
  updateCounter();

  let avatarTimer;
  nameInput.addEventListener('input', () => {
    clearTimeout(avatarTimer);
    avatarTimer = setTimeout(() => {
      const name = nameInput.value.trim() || avatar.dataset.guest;
      avatar.src = '/avatar.svg?name=' + encodeURIComponent(name);
    }, 250);
  });

  function showErrors(errors) {
    form.querySelectorAll('[data-error-for]').forEach((el) => {
      const msg = (errors || {})[el.dataset.errorFor] || '';
      el.textContent = msg;
      el.closest('.field').classList.toggle('has-error', !!msg);
    });
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (submit.disabled) return;
    submit.disabled = true;
    submit.textContent = '…';
    try {
      const res = await Echo.request(root.dataset.createUrl, { method: 'POST', body: new FormData(form) });
      if (res.status === 201) {
        showErrors({});
        mine.add(res.data.comment.id);
        feed.prepend(res.data.comment);
        bumpTotal(1);
        body.value = '';
        window.scrollTo({ top: 0, behavior: 'smooth' });
        if (!res.data.realtime.delivered) Echo.toast('Опубликовано, но live-релей недоступен', 'warn');
      } else if (res.status === 422) {
        showErrors(res.data && res.data.errors);
      } else {
        Echo.toast('Ошибка сервера (' + res.status + ')', 'err');
      }
    } catch (e) {
      Echo.toast('Сеть недоступна', 'err');
    } finally {
      submit.textContent = 'Запостить';
      updateCounter();
    }
  });

  function bumpTotal(d) { total.textContent = Math.max(0, Number(total.textContent) + d); }

  // ---- likes (optimistic) -------------------------------------------------
  root.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-like]');
    if (!btn) return;
    const id = btn.dataset.like;
    const countEl = btn.querySelector('[data-slot="likes"]');
    const wasLiked = btn.classList.contains('is-liked');
    const before = Number(countEl.textContent);
    btn.classList.toggle('is-liked', !wasLiked);
    countEl.textContent = Math.max(0, before + (wasLiked ? -1 : 1));
    btn.classList.add('pop');
    setTimeout(() => btn.classList.remove('pop'), 160);
    const res = await Echo.request(likeUrl(id), { method: 'POST' });
    if (res.ok) {
      feed.setLikes(id, res.data.likes, res.data.liked);
    } else {
      btn.classList.toggle('is-liked', wasLiked);
      countEl.textContent = before;
      Echo.toast(res.status === 429 ? 'Слишком много лайков, подождите минуту' : 'Лайк не сохранён', 'warn');
    }
  });

  // ---- load more (keyset cursor) ------------------------------------------
  async function loadMore() {
    const cursor = moreBtn.dataset.cursor;
    if (!cursor || moreBtn.disabled) return;
    moreBtn.disabled = true;
    const res = await Echo.request(root.dataset.pageUrl + '?before=' + encodeURIComponent(cursor));
    moreBtn.disabled = false;
    if (!res.ok) { Echo.toast('Не удалось загрузить', 'err'); return; }
    const tmp = document.createElement('div');
    tmp.innerHTML = res.data.html;
    Array.from(tmp.children).forEach((card) => {
      if (!feed.has(card.dataset.commentId)) feed.list.appendChild(card);
    });
    moreBtn.dataset.cursor = res.data.next_cursor || '';
    moreBtn.hidden = !res.data.next_cursor;
    endMark.hidden = !!res.data.next_cursor;
  }
  moreBtn.addEventListener('click', loadMore);
  if ('IntersectionObserver' in window) {
    new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting) && !moreBtn.hidden) loadMore();
    }, { rootMargin: '400px' }).observe(moreBtn);
  }

  // ---- live feed ----------------------------------------------------------
  const atTop = () => window.scrollY < 160;

  function flushPending() {
    while (pending.length) feed.prepend(pending.shift());
    pill.hidden = true;
  }
  pill.addEventListener('click', () => {
    flushPending();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  window.addEventListener('scroll', () => { if (atTop() && pending.length) flushPending(); }, { passive: true });

  function onFrame(f) {
    const d = f.data || {};
    switch (f.event) {
      case 'comment.created':
        if (mine.has(d.id) || feed.has(d.id)) return;
        bumpTotal(1);
        if (atTop()) { feed.prepend(d); } else {
          pending.push(d);
          pillCount.textContent = pending.length;
          pill.hidden = false;
        }
        break;
      case 'comment.updated': feed.update(d); break;
      case 'comment.liked': feed.setLikes(d.id, d.likes); break;
      case 'comment.deleted':
        if (feed.has(d.id)) bumpTotal(-1);
        feed.remove(d.id);
        break;
      case 'comment.purged':
        (d.ids || []).forEach((id) => { if (feed.has(id)) bumpTotal(-1); feed.remove(id); });
        break;
    }
  }

  const labels = { live: 'live', connecting: 'connecting', offline: 'offline' };
  new Echo.Socket({
    tokenUrl: root.dataset.tokenUrl,
    onFrame: onFrame,
    onState: (s) => { live.dataset.state = s; live.textContent = labels[s]; },
  }).connect();

  setInterval(() => Echo.refreshTimes(root), 30000);
})();
