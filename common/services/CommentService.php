<?php

declare(strict_types=1);

namespace common\services;

use common\models\Comment;
use common\models\CommentLike;
use common\realtime\CommentEvent;
use common\realtime\CommentEventPublisherInterface;
use common\realtime\PublishResult;
use Yii;
use yii\db\IntegrityException;

/**
 * Every state change of a comment goes through here, so every change is
 * announced on the realtime channel exactly once — after it is committed.
 * Publishing is explicit (not hidden in ActiveRecord events) so fixtures,
 * migrations and console scripts never emit live events by accident.
 */
final class CommentService
{
    public function __construct(private readonly CommentEventPublisherInterface $publisher)
    {
    }

    /**
     * @param array<string, mixed> $input   untrusted form data (author_name, body)
     * @param string $guestName             name to use when the visitor leaves it blank
     */
    public function create(array $input, ?string $ip, bool $isDemo, string $guestName): CreateOutcome
    {
        $comment = new Comment(['scenario' => Comment::SCENARIO_CREATE]);
        $comment->load($input, '');
        if (Comment::normalizeLine($comment->author_name) === '') {
            $comment->author_name = $guestName;
        }
        $comment->ip = $ip;
        $comment->is_demo = $isDemo;
        $comment->status = Comment::STATUS_PUBLISHED;
        $comment->likes = 0;

        if (!$comment->save()) {
            return new CreateOutcome($comment, null);
        }
        $comment->refresh();

        return new CreateOutcome($comment, $this->publisher->publish(CommentEvent::CREATED, $comment->toAdminArray()));
    }

    /**
     * @param array<string, mixed> $input
     * @return PublishResult|null  null when validation failed (errors are on the model)
     */
    public function update(Comment $comment, array $input): ?PublishResult
    {
        $comment->scenario = Comment::SCENARIO_ADMIN;
        $comment->load($input, '');
        if (!$comment->save()) {
            return null;
        }
        $comment->refresh();

        return $this->publisher->publish(CommentEvent::UPDATED, $comment->toAdminArray());
    }

    public function delete(Comment $comment): PublishResult
    {
        $payload = ['id' => (int) $comment->id, 'is_demo' => (bool) $comment->is_demo, 'status' => (int) $comment->status];
        $comment->delete();

        return $this->publisher->publish(CommentEvent::DELETED, $payload);
    }

    /**
     * Like / unlike. One like per comment per voter (enforced by a unique key);
     * new likes are additionally capped per IP per minute.
     *
     * @throws LikeRateLimitException
     */
    public function toggleLike(Comment $comment, string $voterToken, string $ip, int $maxPerIpPerMinute): LikeOutcome
    {
        $voter = hash('sha256', 'voter|' . $voterToken);
        $ipHash = hash('sha256', 'ip|' . $ip);
        $db = Comment::getDb();

        $existing = CommentLike::findOne(['comment_id' => $comment->id, 'voter' => $voter]);

        if ($existing === null) {
            $recent = (int) CommentLike::find()
                ->where(['ip_hash' => $ipHash])
                ->andWhere(['>=', 'created_at', time() - 60])
                ->count();
            if ($recent >= $maxPerIpPerMinute) {
                throw new LikeRateLimitException($maxPerIpPerMinute);
            }
        }

        $transaction = $db->beginTransaction();
        try {
            if ($existing === null) {
                $db->createCommand()->insert(CommentLike::tableName(), [
                    'comment_id' => $comment->id,
                    'voter' => $voter,
                    'ip_hash' => $ipHash,
                    'created_at' => time(),
                ])->execute();
                $db->createCommand('UPDATE {{%comment}} SET [[likes]] = [[likes]] + 1 WHERE [[id]] = :id', [':id' => $comment->id])->execute();
                $liked = true;
            } else {
                $existing->delete();
                // NB: not GREATEST(likes - 1, 0) — on an UNSIGNED column `0 - 1` overflows
                // before GREATEST can clamp it (found by CommentServiceTest, see README REG-001).
                $db->createCommand('UPDATE {{%comment}} SET [[likes]] = CASE WHEN [[likes]] > 0 THEN [[likes]] - 1 ELSE 0 END WHERE [[id]] = :id', [':id' => $comment->id])->execute();
                $liked = false;
            }
            $transaction->commit();
        } catch (IntegrityException) {
            // Two concurrent likes from the same voter: the unique key won. State is unchanged.
            $transaction->rollBack();
            $liked = true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $comment->refresh();
        $publish = $this->publisher->publish(CommentEvent::LIKED, [
            'id' => (int) $comment->id,
            'likes' => (int) $comment->likes,
            'status' => (int) $comment->status,
            'is_demo' => (bool) $comment->is_demo,
        ]);

        return new LikeOutcome($liked, (int) $comment->likes, $publish);
    }

    public function hasLiked(Comment $comment, string $voterToken): bool
    {
        return CommentLike::find()
            ->where(['comment_id' => $comment->id, 'voter' => hash('sha256', 'voter|' . $voterToken)])
            ->exists();
    }

    /**
     * Remove every demo-mode comment (likes cascade via FK). Real comments are untouched.
     *
     * @return array{deleted:int, ids:list<int>, realtime:PublishResult}
     */
    public function purgeDemo(): array
    {
        $ids = array_map('intval', Comment::find()->demo()->select('id')->column());
        $deleted = $ids === [] ? 0 : Comment::deleteAll(['id' => $ids, 'is_demo' => true]);
        Yii::info(sprintf('purged %d demo comment(s)', $deleted), 'demo');

        return [
            'deleted' => $deleted,
            'ids' => $ids,
            'realtime' => $this->publisher->publish(CommentEvent::PURGED, ['ids' => $ids, 'is_demo' => true]),
        ];
    }
}
