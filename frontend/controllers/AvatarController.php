<?php

declare(strict_types=1);

namespace frontend\controllers;

use common\helpers\Avatar;
use common\helpers\Handle;
use Yii;
use yii\web\Controller;
use yii\web\Response;

/**
 * GET /avatar.svg?name=…  — deterministic identicon for any name.
 * The name is reduced to its handle first, so "Анна К." and "anna_k" share an avatar.
 */
final class AvatarController extends Controller
{
    public function actionIndex(string $name = ''): Response
    {
        $seed = Handle::fromName($name);
        $svg = Avatar::svg($seed);
        $etag = '"' . md5($svg) . '"';

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'image/svg+xml; charset=utf-8');
        $response->headers->set('Cache-Control', 'public, max-age=86400, immutable');
        $response->headers->set('ETag', $etag);

        if (Yii::$app->request->headers->get('If-None-Match') === $etag) {
            $response->statusCode = 304;

            return $response;
        }
        $response->content = $svg;

        return $response;
    }
}
