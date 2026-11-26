<?php

return [
    'container' => [
        'singletons' => [
            \yii\mail\MailerInterface::class => [
                'class' => \yii\symfonymailer\Mailer::class,
                'viewPath' => '@common/mail',
            ],
        ],
    ],
    'components' => [
        // 'db' is configured from environment variables in common/config/main.php
        'mailer' => \yii\mail\MailerInterface::class,
    ],
];
