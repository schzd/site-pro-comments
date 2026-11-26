<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var common\models\Comment $model */

use common\models\Comment;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = "Edit #{$model->id}";
$errors = $model->getFirstErrors();
?>
<section class="cp-panel cp-edit">
    <a class="cp-back mono" href="<?= Url::to(['/comment/index']) ?>">← all comments</a>
    <h1>Edit comment <span class="mono muted">#<?= (int) $model->id ?></span></h1>
    <p class="muted mono">
        posted <?= gmdate('Y-m-d H:i:s', (int) $model->created_at) ?> UTC from <?= Html::encode((string) $model->ip) ?>
        · <?= (int) $model->likes ?> 🔥
        <?php if ($model->is_demo): ?><span class="pill">demo</span><?php endif ?>
    </p>

    <?= Html::beginForm(['/comment/update', 'id' => $model->id], 'post', ['id' => 'comment-form', 'class' => 'cp-form']) ?>
        <div class="field <?= isset($errors['author_name']) ? 'has-error' : '' ?>">
            <label for="comment-author_name">Author name</label>
            <input id="comment-author_name" class="input" name="Comment[author_name]" maxlength="<?= Comment::AUTHOR_MAX ?>"
                   value="<?= Html::encode($model->author_name) ?>">
            <div class="field-error"><?= Html::encode($errors['author_name'] ?? '') ?></div>
        </div>
        <div class="field <?= isset($errors['body']) ? 'has-error' : '' ?>">
            <label for="comment-body">Comment</label>
            <textarea id="comment-body" class="textarea" name="Comment[body]" rows="6" maxlength="<?= Comment::BODY_MAX ?>"><?= Html::encode($model->body) ?></textarea>
            <div class="field-error"><?= Html::encode($errors['body'] ?? '') ?></div>
        </div>
        <div class="field">
            <label for="comment-status">Status</label>
            <select id="comment-status" class="select" name="Comment[status]">
                <?php foreach (Comment::STATUS_LABELS as $value => $label): ?>
                    <option value="<?= $value ?>" <?= (int) $model->status === $value ? 'selected' : '' ?>><?= ucfirst($label) ?></option>
                <?php endforeach ?>
            </select>
            <div class="field-error"><?= Html::encode($errors['status'] ?? '') ?></div>
        </div>
        <div class="cp-form-actions">
            <button type="submit" class="btn btn-lime" id="save-button">Save changes</button>
            <a class="btn" href="<?= Url::to(['/comment/index']) ?>">Cancel</a>
        </div>
    <?= Html::endForm() ?>
</section>
