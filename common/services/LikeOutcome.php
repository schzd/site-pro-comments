<?php

declare(strict_types=1);

namespace common\services;

use common\realtime\PublishResult;

final class LikeOutcome
{
    public function __construct(
        public readonly bool $liked,
        public readonly int $likes,
        public readonly PublishResult $realtime,
    ) {
    }
}
