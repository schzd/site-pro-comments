<?php

declare(strict_types=1);

/**
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $provider
 * @var string $filter
 * @var array<string, int> $counts
 */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\LinkPager;

$this->title = 'Comments';
$page = $provider->pagination->getPage();
?>
<section class="cp-panel"
         data-admin-live
         data-filter="<?= Html::encode($filter) ?>"
         data-page="<?= $page ?>"
         data-token-url="<?= Url::to(['/comment/ws-token']) ?>"
         data-edit-url="<?= str_replace('/0/', '/{id}/', Url::to(['/comment/update', 'id' => 0])) ?>"
         data-delete-url="<?= str_replace('/0/', '/{id}/', Url::to(['/comment/delete', 'id' => 0])) ?>">

    <div class="cp-head">
        <div>
            <h1>Comments</h1>
            <p class="muted">Newest first. Rows arrive live over WebSocket — no reload.</p>
        </div>
        <div class="cp-stats">
            <div class="cp-stat"><span class="cp-stat-n mono" data-count="all"><?= $counts['all'] ?></span><span>total</span></div>
            <div class="cp-stat"><span class="cp-stat-n mono" data-count="published"><?= $counts['published'] ?></span><span>published</span></div>
            <div class="cp-stat"><span class="cp-stat-n mono" data-count="hidden"><?= $counts['hidden'] ?></span><span>hidden</span></div>
            <div class="cp-stat cp-stat-live"><span class="cp-stat-n mono" data-live-count>0</span><span>live this session</span></div>
        </div>
    </div>

    <nav class="cp-tabs" aria-label="Filter">
        <?php foreach (['all' => 'All', 'published' => 'Published', 'hidden' => 'Hidden', 'demo' => 'Demo'] as $key => $label): ?>
            <a href="<?= Url::to(['/comment/index', 'filter' => $key === 'all' ? null : $key]) ?>"
               class="cp-tab <?= $filter === $key ? 'is-active' : '' ?>"><?= $label ?> <span class="mono" data-count="<?= $key ?>"><?= $counts[$key] ?></span></a>
        <?php endforeach ?>
    </nav>

    <div class="cp-banner" data-banner hidden>
        <span data-banner-text></span>
        <a class="btn btn-sm btn-lime" href="<?= Url::to(['/comment/index']) ?>">Show newest</a>
    </div>

    <div class="cp-table-wrap">
        <table class="cp-table" id="comments-table">
            <thead>
            <tr>
                <th>ID</th><th>Author</th><th>Comment</th><th>Status</th><th>🔥</th><th>IP</th><th>Created (UTC)</th><th></th>
            </tr>
            </thead>
            <tbody data-rows>
            <?php foreach ($provider->getModels() as $comment): ?>
                <?= $this->render('_row', ['data' => $comment->toAdminArray()]) ?>
            <?php endforeach ?>
            </tbody>
        </table>
        <p class="cp-empty muted" data-empty <?= $provider->getCount() ? 'hidden' : '' ?>>No comments yet. Post one on the public wall — it will appear here instantly.</p>
    </div>

    <?= LinkPager::widget([
        'pagination' => $provider->pagination,
        'options' => ['class' => 'cp-pager'],
        'linkContainerOptions' => ['class' => 'cp-page'],
        'disabledListItemSubTagOptions' => ['tag' => 'span'],
    ]) ?>

    <template id="row-template"><?= $this->render('_row', ['data' => null]) ?></template>
</section>
