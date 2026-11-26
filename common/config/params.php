<?php

declare(strict_types=1);

use common\helpers\Env;

return [
    'adminEmail' => 'admin@example.com',
    'supportEmail' => 'support@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Echo mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,

    // Where browsers open the WebSocket (differs from the in-network address).
    'ws.publicUrl' => Env::string('WS_PUBLIC_URL'),
    // Socket tokens only authorise the handshake, so they can be short-lived.
    'ws.tokenTtl' => 60,
    'frontend.publicUrl' => rtrim(Env::string('FRONTEND_PUBLIC_URL'), '/'),
    'backend.publicUrl' => rtrim(Env::string('BACKEND_PUBLIC_URL'), '/'),
    'demo.enabled' => Env::bool('DEMO_MODE'),

    'likes.maxPerIpPerMinute' => 30,
];
