<?php

declare(strict_types=1);

/**
 * @var yii\web\View $this
 * @var list<common\models\Comment> $items
 * @var array<int, true> $liked
 * @var string|null $nextCursor
 * @var string $guestName
 * @var int $total
 * @var common\models\Comment|null $form  set when a no-JS POST failed validation
 */

use common\models\Comment;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Echo — стена комментариев';
$form ??= null;
$errors = $form ? $form->getFirstErrors() : [];
?>
<div class="wall"
     data-wall
     data-create-url="<?= Url::to(['/comment/create']) ?>"
     data-page-url="<?= Url::to(['/comment/page']) ?>"
     data-like-url="<?= str_replace('/0/', '/{id}/', Url::to(['/comment/like', 'id' => 0])) ?>"
     data-token-url="<?= Url::to(['/comment/ws-token']) ?>">

    <section class="composer" data-composer aria-label="Новый комментарий">
        <?= Html::beginForm(['/comment/create'], 'post', ['class' => 'composer-form', 'data-composer-form' => true, 'novalidate' => true]) ?>
            <img class="post-avatar composer-avatar" data-composer-avatar width="44" height="44" alt=""
                 src="<?= Url::to(['/avatar/index', 'name' => $guestName]) ?>"
                 data-guest="<?= Html::encode($guestName) ?>">
            <div class="composer-main">
                <div class="composer-name field <?= isset($errors['author_name']) ? 'has-error' : '' ?>">
                    <label class="sr-only" for="comment-author_name">Имя</label>
                    <input id="comment-author_name" class="input input-name" name="Comment[author_name]"
                           maxlength="<?= Comment::AUTHOR_MAX ?>" autocomplete="nickname"
                           placeholder="Имя (необязательно) — иначе @<?= Html::encode($guestName) ?>"
                           value="<?= Html::encode($form?->author_name ?? '') ?>">
                    <div class="field-error" data-error-for="author_name"><?= Html::encode($errors['author_name'] ?? '') ?></div>
                </div>
                <div class="field <?= isset($errors['body']) ? 'has-error' : '' ?>">
                    <label class="sr-only" for="comment-body">Комментарий</label>
                    <textarea id="comment-body" class="textarea composer-text" name="Comment[body]" rows="2"
                              maxlength="<?= Comment::BODY_MAX ?>" placeholder="Что думаешь?"
                              required><?= Html::encode($form?->body ?? '') ?></textarea>
                    <div class="field-error" data-error-for="body"><?= Html::encode($errors['body'] ?? '') ?></div>
                </div>
                <div class="composer-bar">
                    <span class="live" data-live data-state="offline">offline</span>
                    <span class="composer-count mono" data-counter data-max="<?= Comment::BODY_MAX ?>">0 / <?= Comment::BODY_MAX ?></span>
                    <button type="submit" class="btn btn-lime" data-submit>Запостить</button>
                </div>
            </div>
        <?= Html::endForm() ?>
    </section>

    <?php if (Yii::$app->session->hasFlash('posted')): ?>
        <div class="notice"><?= Html::encode(Yii::$app->session->getFlash('posted')) ?></div>
    <?php endif ?>

    <button type="button" class="new-pill" data-new-pill hidden>↑ <span data-new-count>0</span> новых</button>

    <div class="feed-head">
        <h1 class="feed-title">Лента</h1>
        <span class="mono muted"><span data-total><?= $total ?></span> постов</span>
    </div>

    <div class="feed" data-feed-wrap>
        <div class="feed-list" data-feed>
            <?php foreach ($items as $comment): ?>
                <?= $this->render('_card', ['data' => $comment->toPublicArray(), 'liked' => isset($liked[$comment->id])]) ?>
            <?php endforeach ?>
        </div>
        <p class="feed-empty muted" data-empty <?= $items ? 'hidden' : '' ?>>Пока тихо. Напишите первым.</p>
        <div class="feed-more">
            <button type="button" class="btn" data-more data-cursor="<?= Html::encode((string) $nextCursor) ?>" <?= $nextCursor ? '' : 'hidden' ?>>Загрузить ещё</button>
            <span class="mono muted" data-end <?= $nextCursor || !$items ? 'hidden' : '' ?>>— это всё —</span>
        </div>
    </div>

    <template id="card-template"><?= $this->render('_card', ['data' => null, 'liked' => false]) ?></template>
</div>
