<?php

declare(strict_types=1);

namespace common\fixtures;

use common\models\CommentLike;
use yii\test\ActiveFixture;

/**
 * Empties comment_like (it references comment, so it is reset first).
 */
class CommentLikeFixture extends ActiveFixture
{
    public $modelClass = CommentLike::class;
    public $dataFile = false;
}
