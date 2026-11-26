<?php

declare(strict_types=1);

namespace common\models;

use yii\db\ActiveQuery;

/**
 * @extends ActiveQuery<Comment>
 */
class CommentQuery extends ActiveQuery
{
    public function published(): static
    {
        return $this->andWhere(['status' => Comment::STATUS_PUBLISHED]);
    }

    public function hidden(): static
    {
        return $this->andWhere(['status' => Comment::STATUS_HIDDEN]);
    }

    public function demo(bool $isDemo = true): static
    {
        return $this->andWhere(['is_demo' => $isDemo]);
    }

    /**
     * Newest first. `id` breaks ties between comments created in the same
     * second, which keeps ordering — and keyset pagination — deterministic.
     */
    public function newestFirst(): static
    {
        return $this->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);
    }

    /**
     * Keyset ("cursor") page: everything strictly older than the given row.
     * Unlike OFFSET paging this never repeats or skips rows while new
     * comments keep arriving at the top of a live feed.
     */
    public function olderThan(int $createdAt, int $id): static
    {
        return $this->andWhere([
            'or',
            ['<', 'created_at', $createdAt],
            ['and', ['created_at' => $createdAt], ['<', 'id', $id]],
        ]);
    }
}
