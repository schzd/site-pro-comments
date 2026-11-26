<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var common\models\LoginForm $model */

use yii\helpers\Html;

$this->title = 'Sign in';
$errors = $model->getFirstErrors();
?>
<section class="auth-card">
    <div class="brand"><span class="brand-mark">e</span>Echo <span class="cp-sub mono">/ control</span></div>
    <h1>Administrator sign-in</h1>
    <p class="muted">Moderate the public wall. New comments stream in live.</p>

    <?= Html::beginForm(['/site/login'], 'post', ['id' => 'login-form', 'class' => 'auth-form']) ?>
        <div class="field <?= isset($errors['username']) ? 'has-error' : '' ?>">
            <label for="loginform-username">Username</label>
            <input id="loginform-username" class="input" name="LoginForm[username]" autocomplete="username" autofocus
                   value="<?= Html::encode($model->username) ?>">
            <div class="field-error"><?= Html::encode($errors['username'] ?? '') ?></div>
        </div>
        <div class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
            <label for="loginform-password">Password</label>
            <input id="loginform-password" class="input" type="password" name="LoginForm[password]" autocomplete="current-password">
            <div class="field-error"><?= Html::encode($errors['password'] ?? '') ?></div>
        </div>
        <label class="check">
            <input type="hidden" name="LoginForm[rememberMe]" value="0">
            <input type="checkbox" name="LoginForm[rememberMe]" value="1" <?= $model->rememberMe ? 'checked' : '' ?>> Remember me
        </label>
        <button type="submit" class="btn btn-lime btn-block" name="login-button" id="login-button">Sign in</button>
    <?= Html::endForm() ?>
</section>
