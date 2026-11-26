<?php

declare(strict_types=1);

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php',
);

return [
    'id' => 'app-frontend',
    'name' => 'Echo',
    'language' => 'ru-RU',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'frontend\controllers',
    'defaultRoute' => 'comment/index',
    'components' => [
        'request' => [
            'csrfParam' => '_csrf-frontend',
        ],
        // Visitors never log in. The session only remembers the generated guest name.
        'user' => [
            'identityClass' => \common\models\User::class,
            'enableSession' => false,
            'enableAutoLogin' => false,
        ],
        'session' => [
            'name' => 'echo-visitor',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => [
                '' => 'comment/index',
                'GET comments' => 'comment/page',
                'POST comments' => 'comment/create',
                'POST comments/<id:\d+>/like' => 'comment/like',
                'GET ws-token' => 'comment/ws-token',
                'GET avatar.svg' => 'avatar/index',
                'GET demo' => 'demo/index',
                'POST demo/reset' => 'demo/reset',
                // Any other verb on a known path must answer 405, not 404.
                'comments' => 'comment/create',
                'comments/<id:\d+>/like' => 'comment/like',
                'demo/reset' => 'demo/reset',
            ],
        ],
    ],
    'params' => $params,
];
