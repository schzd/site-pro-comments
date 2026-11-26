<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $name */
/** @var string $message */
/** @var Exception $exception */

use yii\helpers\Html;
use yii\web\HttpException;

$this->title = $name;
$code = $exception instanceof HttpException ? $exception->statusCode : 500;
?>
<section class="cp-error">
    <div class="cp-error-code mono"><?= (int) $code ?></div>
    <h1><?= Html::encode($message) ?></h1>
    <a class="btn btn-lime" href="<?= Yii::$app->homeUrl ?>">Back to comments</a>
</section>
