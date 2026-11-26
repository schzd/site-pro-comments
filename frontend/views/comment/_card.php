<?php

declare(strict_types=1);

/**
 * One wall card. Rendered server-side for real rows, and once with $data = null
 * inside a <template> that the JS clones for live rows — one markup source.
 *
 * @var array<string, mixed>|null $data  Comment::toPublicArray()
 * @var bool $liked
 */

use yii\helpers\Html;

$tpl = $data === null;
$id = $tpl ? '' : (int) $data['id'];
$ts = $tpl ? 0 : (int) $data['created_at'];
?>
<article class="post" data-comment-id="<?= $id ?>">
    <img class="post-avatar" data-slot="avatar" alt="" width="44" height="44" loading="lazy"
         src="<?= $tpl ? '' : Html::encode($data['avatar_url']) ?>">
    <div class="post-main">
        <header class="post-head">
            <span class="post-name" data-slot="name"><?= $tpl ? '' : Html::encode($data['author_name']) ?></span>
            <span class="post-handle mono" data-slot="handle"><?= $tpl ? '' : '@' . Html::encode($data['handle']) ?></span>
            <span class="post-dot" aria-hidden="true">·</span>
            <time class="post-time mono" data-slot="time"
                  data-reltime="<?= $ts ?>"
                  datetime="<?= $tpl ? '' : Html::encode($data['created_at_iso']) ?>"
                  title="<?= $tpl ? '' : gmdate('Y-m-d H:i:s', $ts) . ' UTC' ?>"><?= $tpl ? '' : Html::encode(\frontend\components\RelTime::format($ts)) ?></time>
            <span class="pill pill-lime post-demo" data-slot="demo" <?= ($tpl || !$data['is_demo']) ? 'hidden' : '' ?>>demo</span>
        </header>
        <p class="post-body" data-slot="body"><?= $tpl ? '' : Html::encode($data['body']) ?></p>
        <footer class="post-actions">
            <button type="button" class="like<?= !$tpl && $liked ? ' is-liked' : '' ?>" data-like="<?= $id ?>" aria-label="Нравится">
                <span class="like-icon" aria-hidden="true">🔥</span>
                <span class="like-count mono" data-slot="likes"><?= $tpl ? '0' : (int) $data['likes'] ?></span>
            </button>
        </footer>
    </div>
</article>
