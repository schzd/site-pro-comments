<?php

declare(strict_types=1);

namespace common\realtime;

final class SocketGrant
{
    public function __construct(
        public readonly string $scope,
        public readonly int $subject,
        public readonly int $expiresAt,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->scope === SocketTokenService::SCOPE_ADMIN;
    }
}
