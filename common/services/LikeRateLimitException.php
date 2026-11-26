<?php

declare(strict_types=1);

namespace common\services;

final class LikeRateLimitException extends \RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("Like rate limit reached ({$limit}/min per IP).");
    }
}
