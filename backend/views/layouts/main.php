<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $content */

use backend\assets\AppAsset;
use yii\helpers\Html;
use yii\helpers\Url;

AppAsset::register($this);
$user = Yii::$app->user->identity;
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?> · Echo Control</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <?php $this->head() ?>
</head>
<body class="admin">
<?php $this->beginBody() ?>
<header class="cp-top">
    <a class="brand" href="<?= Url::home() ?>"><span class="brand-mark">e</span>Echo <span class="cp-sub mono">/ control</span></a>
    <div class="cp-top-right">
        <span class="live" data-live data-state="offline" title="WebSocket relay">offline</span>
        <a class="cp-link" href="<?= Html::encode(Yii::$app->params['frontend.publicUrl']) ?>" target="_blank" rel="noopener">Public wall ↗</a>
        <?php if ($user): ?>
            <span class="cp-user mono">@<?= Html::encode($user->username) ?></span>
            <?= Html::beginForm(['/site/logout'], 'post', ['class' => 'cp-logout']) ?>
                <button type="submit" class="btn btn-sm">Logout</button>
            <?= Html::endForm() ?>
        <?php endif ?>
    </div>
</header>
<main class="cp-main">
    <?php foreach (['success' => 'ok', 'warning' => 'warn', 'error' => 'err'] as $key => $kind): ?>
        <?php if (Yii::$app->session->hasFlash($key)): ?>
            <div class="flash flash-<?= $kind ?>" role="status"><?= Html::encode(Yii::$app->session->getFlash($key)) ?></div>
        <?php endif ?>
    <?php endforeach ?>
    <?= $content ?>
</main>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
