<?php

declare(strict_types=1);

namespace common\services;

use common\models\Comment;
use common\realtime\PublishResult;

final class CreateOutcome
{
    public function __construct(
        public readonly Comment $comment,
        /** null when the comment did not pass validation and was not saved */
        public readonly ?PublishResult $realtime,
    ) {
    }

    public function isSaved(): bool
    {
        return $this->realtime !== null;
    }
}
