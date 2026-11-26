<?php

declare(strict_types=1);

namespace frontend\assets;

use common\assets\ThemeAsset;
use yii\web\AssetBundle;

class DemoAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = ['css/wall.css', 'css/demo.css'];
    public $js = ['js/demo.js'];
    public $depends = [ThemeAsset::class];
}
