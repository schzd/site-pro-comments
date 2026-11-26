<?php

declare(strict_types=1);

namespace common\realtime;

/**
 * Names of the events that travel  app -> Redis -> WS relay -> browsers.
 */
final class CommentEvent
{
    public const CREATED = 'comment.created';
    public const UPDATED = 'comment.updated';
    public const DELETED = 'comment.deleted';
    public const LIKED = 'comment.liked';
    /** Bulk removal of demo-mode comments. */
    public const PURGED = 'comment.purged';

    public const ALL = [self::CREATED, self::UPDATED, self::DELETED, self::LIKED, self::PURGED];

    private function __construct()
    {
    }
}
