<?php

declare(strict_types=1);

/**
 * One admin table row. Rendered for real rows and once (with $data = null)
 * inside a <template> that admin-live.js clones for rows arriving over WS.
 *
 * @var array<string, mixed>|null $data  Comment::toAdminArray()
 */

use yii\helpers\Html;

$tpl = $data === null;
$id = $tpl ? '' : (int) $data['id'];
$status = $tpl ? 'published' : $data['status_label'];
?>
<tr class="cp-row status-<?= Html::encode($status) ?>" data-comment-id="<?= $id ?>">
    <td class="mono cp-id" data-slot="id"><?= $tpl ? '' : '#' . $id ?></td>
    <td class="cp-author">
        <img class="cp-avatar" data-slot="avatar" alt="" width="28" height="28" src="<?= $tpl ? '' : Html::encode($data['avatar_url']) ?>">
        <div>
            <div class="cp-name" data-slot="name"><?= $tpl ? '' : Html::encode($data['author_name']) ?></div>
            <div class="cp-handle mono" data-slot="handle"><?= $tpl ? '' : '@' . Html::encode($data['handle']) ?></div>
        </div>
    </td>
    <td class="cp-body" data-slot="body"><?= $tpl ? '' : Html::encode($data['body']) ?></td>
    <td class="cp-status">
        <span class="pill <?= $status === 'published' ? 'pill-lime' : 'pill-amber' ?>" data-slot="status"><?= Html::encode($status) ?></span>
        <span class="pill" data-slot="demo" <?= ($tpl || !$data['is_demo']) ? 'hidden' : '' ?>>demo</span>
    </td>
    <td class="mono cp-num" data-slot="likes"><?= $tpl ? '0' : (int) $data['likes'] ?></td>
    <td class="mono cp-ip" data-slot="ip"><?= $tpl ? '' : Html::encode((string) $data['ip']) ?></td>
    <td class="mono cp-time" data-slot="time"><?= $tpl ? '' : gmdate('Y-m-d H:i:s', (int) $data['created_at']) ?></td>
    <td class="cp-actions">
        <a class="btn btn-sm" data-slot="edit" href="<?= $tpl ? '' : Html::encode(\yii\helpers\Url::to(['/comment/update', 'id' => $id])) ?>">Edit</a>
        <form method="post" class="cp-delete" data-slot="delete" data-confirm="Delete this comment permanently?"
              action="<?= $tpl ? '' : Html::encode(\yii\helpers\Url::to(['/comment/delete', 'id' => $id])) ?>">
            <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
        </form>
    </td>
</tr>
