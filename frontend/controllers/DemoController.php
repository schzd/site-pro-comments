<?php

declare(strict_types=1);

namespace frontend\controllers;

use common\demo\DemoPersonas;
use common\services\CommentService;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * /demo — a self-playing showcase that drives the REAL pipeline:
 * the browser POSTs to /comments (flagged demo=1), and the page listens on a
 * real public socket. Reset purges only is_demo comments.
 */
final class DemoController extends Controller
{
    public function __construct($id, $module, private readonly CommentService $comments, $config = [])
    {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'reset' => ['POST']]],
        ];
    }

    public function beforeAction($action): bool
    {
        if (!Yii::$app->params['demo.enabled']) {
            throw new NotFoundHttpException('Demo mode is disabled (DEMO_MODE=off).');
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): string
    {
        $this->layout = 'demo';

        return $this->render('index', ['personas' => DemoPersonas::all()]);
    }

    public function actionReset(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $result = $this->comments->purgeDemo();

        return ['deleted' => $result['deleted'], 'ids' => $result['ids'], 'realtime' => $result['realtime']->toArray()];
    }
}
