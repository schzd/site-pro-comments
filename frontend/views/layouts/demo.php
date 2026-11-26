<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $content */

use frontend\assets\DemoAsset;
use yii\helpers\Html;
use yii\helpers\Url;

DemoAsset::register($this);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#121310">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <?php $this->head() ?>
</head>
<body class="demo-body">
<?php $this->beginBody() ?>
<header class="demo-top">
    <a class="brand" href="<?= Url::home() ?>"><span class="brand-mark">e</span>Echo <span class="cp-sub mono">/ live demo</span></a>
    <nav class="topnav">
        <a href="<?= Url::home() ?>">Лента</a>
        <a href="/game/" class="topnav-game">▲ Игра</a>
        <a href="<?= Html::encode(Yii::$app->params['backend.publicUrl']) ?>" target="_blank" rel="noopener">Админ ↗</a>
    </nav>
</header>
<?= $content ?>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
