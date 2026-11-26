<?php

declare(strict_types=1);

namespace backend\assets;

use common\assets\ThemeAsset;
use yii\web\AssetBundle;

class AppAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = ['css/admin.css'];
    public $js = ['js/admin-live.js'];
    public $depends = [ThemeAsset::class];
}
