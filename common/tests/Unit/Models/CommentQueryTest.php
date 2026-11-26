<?php

declare(strict_types=1);

namespace common\tests\Unit\Models;

use Codeception\Test\Unit;
use common\fixtures\CommentFixture;
use common\models\Comment;

/**
 * Feed ordering and paging against real MySQL.
 *
 * @group step-S4
 */
final class CommentQueryTest extends Unit
{
    public function _fixtures(): array
    {
        return ['comments' => ['class' => CommentFixture::class, 'dataFile' => codecept_data_dir() . 'comment.php']];
    }

    private function ids(array $rows): array
    {
        return array_map(static fn (Comment $c) => (int) $c->id, $rows);
    }

    public function testPublishedFeedIsNewestFirstAndExcludesHidden(): void
    {
        $this->assertSame([5, 4, 3, 1], $this->ids(Comment::find()->published()->newestFirst()->all()));
    }

    public function testSameSecondCommentsAreOrderedByIdDescending(): void
    {
        $tied = Comment::find()->where(['created_at' => 1700000200])->newestFirst()->all();
        $this->assertSame([4, 3], $this->ids($tied), 'ties must break on id so paging is deterministic');
    }

    public function testKeysetCursorContinuesExactlyAfterTheLastRow(): void
    {
        // Page 1 ends on #4 (created_at 1700000200). Page 2 must start at #3 — same second.
        $page2 = Comment::find()->published()->newestFirst()->olderThan(1700000200, 4)->all();
        $this->assertSame([3, 1], $this->ids($page2));
    }

    public function testDemoScope(): void
    {
        $this->assertSame([5], $this->ids(Comment::find()->demo()->all()));
        $this->assertCount(4, Comment::find()->demo(false)->all());
    }
}
