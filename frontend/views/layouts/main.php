<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $content */

use frontend\assets\AppAsset;
use yii\helpers\Html;
use yii\helpers\Url;

AppAsset::register($this);
$route = Yii::$app->controller->route;
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#121310">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?= Url::home() ?>"><span class="brand-mark">e</span>Echo</a>
        <nav class="topnav" aria-label="Навигация">
            <a href="<?= Url::home() ?>" class="<?= $route === 'comment/index' ? 'is-active' : '' ?>">Лента</a>
            <?php if (Yii::$app->params['demo.enabled']): ?>
                <a href="<?= Url::to(['/demo/index']) ?>">Демо</a>
            <?php endif ?>
            <a href="/game/" class="topnav-game" title="Pegasus Dodge — пасхалка">▲ Игра</a>
            <a href="<?= Html::encode(Yii::$app->params['backend.publicUrl']) ?>" class="topnav-admin">Админ ↗</a>
        </nav>
    </div>
</header>

<main class="page">
    <?= $content ?>
</main>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
