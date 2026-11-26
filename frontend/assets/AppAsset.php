<?php

declare(strict_types=1);

namespace frontend\assets;

use common\assets\ThemeAsset;
use yii\web\AssetBundle;

/**
 * Public wall. Plain CSS + vanilla JS on top of the shared theme — no
 * Bootstrap, no jQuery.
 */
class AppAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = ['css/wall.css'];
    public $js = ['js/wall.js'];
    public $depends = [ThemeAsset::class];
}
