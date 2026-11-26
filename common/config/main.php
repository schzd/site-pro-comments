<?php

declare(strict_types=1);

use common\helpers\Env;
use common\realtime\CommentEventPublisherInterface;
use common\realtime\RedisCommentEventPublisher;
use common\realtime\SocketTokenService;

return [
    'bootstrap' => [
        \common\bootstrap\MailerBootstrap::class,
    ],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'vendorPath' => dirname(dirname(__DIR__)) . '/vendor',
    'timeZone' => 'UTC',
    'language' => 'en-US',
    'container' => [
        'singletons' => [
            // Realtime fan-out. Replaced by a spy in common/config/test.php.
            CommentEventPublisherInterface::class => static fn () => new RedisCommentEventPublisher(
                Yii::$app->get('redis'),
                Env::string('COMMENTS_CHANNEL'),
            ),
            SocketTokenService::class => static fn () => new SocketTokenService(Env::string('WS_TOKEN_SECRET')),
        ],
    ],
    'components' => [
        'cache' => [
            'class' => \yii\caching\FileCache::class,
        ],
        'db' => [
            'class' => \yii\db\Connection::class,
            'dsn' => Env::string('DB_DSN'),
            'username' => Env::string('DB_USERNAME'),
            'password' => Env::string('DB_PASSWORD'),
            'charset' => 'utf8mb4',
        ],
        'redis' => [
            'class' => \yii\redis\Connection::class,
            'hostname' => Env::string('REDIS_HOST'),
            'port' => Env::int('REDIS_PORT'),
            'database' => 0,
            // A dead Redis must fail fast instead of hanging the request.
            'connectionTimeout' => 1,
            'dataTimeout' => 1,
        ],
        'formatter' => [
            'defaultTimeZone' => 'UTC',
            'timeZone' => 'UTC',
        ],
    ],
];
