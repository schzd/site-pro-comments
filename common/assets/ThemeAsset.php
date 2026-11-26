<?php

declare(strict_types=1);

namespace common\assets;

use yii\web\AssetBundle;

/**
 * Shared design tokens + realtime client used by both the public wall and the
 * admin panel (Vidmage design system: #121310 / #dcff48 / Chakra Petch).
 */
class ThemeAsset extends AssetBundle
{
    public $sourcePath = '@common/assets/theme';
    public $css = ['echo-base.css'];
    public $js = ['echo-core.js'];
}
