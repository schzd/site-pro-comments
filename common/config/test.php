<?php

declare(strict_types=1);

use common\realtime\CommentEventPublisherInterface;
use common\tests\Support\Realtime\SpyCommentEventPublisher;

return [
    'id' => 'app-common-tests',
    'basePath' => dirname(__DIR__),
    'container' => [
        'singletons' => [
            // In-process suites (Unit, Regression) assert on what WOULD be
            // published instead of needing a live Redis. The real Redis path is
            // covered by the black-box Smoke and E2E suites.
            CommentEventPublisherInterface::class => SpyCommentEventPublisher::class,
        ],
    ],
    'params' => array_merge(require __DIR__ . '/params.php', require __DIR__ . '/params-local.php'),
    'components' => [
        'user' => [
            'class' => \yii\web\User::class,
            'identityClass' => \common\models\User::class,
        ],
    ],
];
