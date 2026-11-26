<?php

declare(strict_types=1);

namespace common\models;

use yii\db\ActiveRecord;

/**
 * One like of one comment by one voter.
 *
 * @property int $id
 * @property int $comment_id
 * @property string $voter    sha256 of the visitor's long-lived voter cookie
 * @property string $ip_hash  sha256 of the client IP — used only for rate limiting
 * @property int $created_at
 */
class CommentLike extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%comment_like}}';
    }
}
