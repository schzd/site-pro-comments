<?php

/**
 * Source files that intentionally belong to no pipeline step, with the reason.
 * `qa gaps` ignores these; everything else must be claimed by a step.
 */
return [
    'common/assets/ColorModeAsset.php' => 'Yii skeleton leftover, not registered by any layout',
    'common/assets/colormode/*' => 'Yii skeleton leftover, not registered by any layout',
    'common/assets/ThemeAsset.php' => 'asset registration only; its CSS/JS are claimed by S1 / S6',
];
