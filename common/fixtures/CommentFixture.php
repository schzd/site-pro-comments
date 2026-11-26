<?php

declare(strict_types=1);

namespace common\fixtures;

use common\models\Comment;
use yii\test\ActiveFixture;

class CommentFixture extends ActiveFixture
{
    public $modelClass = Comment::class;
    public $depends = [CommentLikeFixture::class];
}
