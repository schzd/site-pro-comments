<?php

declare(strict_types=1);

namespace frontend\controllers;

use yii\web\Controller;
use yii\web\ErrorAction;

final class SiteController extends Controller
{
    public function actions(): array
    {
        return [
            'error' => ['class' => ErrorAction::class],
        ];
    }
}
