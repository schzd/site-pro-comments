<?php

declare(strict_types=1);

namespace backend\controllers;

use common\models\Comment;
use common\realtime\SocketTokenService;
use common\services\CommentService;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Admin: list, edit, delete comments. The list updates live over WebSocket.
 */
final class CommentController extends Controller
{
    public const FILTERS = ['all', 'published', 'hidden', 'demo'];

    public function __construct(
        $id,
        $module,
        private readonly CommentService $comments,
        private readonly SocketTokenService $tokens,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'index' => ['GET', 'HEAD'],
                    'update' => ['GET', 'POST'],
                    'delete' => ['POST'],
                    'ws-token' => ['GET'],
                ],
            ],
        ];
    }

    public function actionIndex(string $filter = 'all'): string
    {
        if (!in_array($filter, self::FILTERS, true)) {
            $filter = 'all';
        }

        $query = Comment::find()->newestFirst();
        match ($filter) {
            'published' => $query->published(),
            'hidden' => $query->hidden(),
            'demo' => $query->demo(),
            'all' => $query,
        };

        $provider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
            'sort' => false,
        ]);

        return $this->render('index', [
            'provider' => $provider,
            'filter' => $filter,
            'counts' => [
                'all' => (int) Comment::find()->count(),
                'published' => (int) Comment::find()->published()->count(),
                'hidden' => (int) Comment::find()->hidden()->count(),
                'demo' => (int) Comment::find()->demo()->count(),
            ],
        ]);
    }

    public function actionUpdate(int $id): string|Response
    {
        $comment = $this->find($id);
        $comment->scenario = Comment::SCENARIO_ADMIN;

        if (Yii::$app->request->isPost) {
            $result = $this->comments->update($comment, (array) Yii::$app->request->post('Comment', []));
            if ($result !== null) {
                Yii::$app->session->setFlash('success', "Comment #{$comment->id} saved.");
                if (!$result->delivered) {
                    Yii::$app->session->setFlash('warning', 'Saved, but the realtime relay is unavailable — other viewers will see it after a reload.');
                }

                return $this->redirect(['index']);
            }
            Yii::$app->response->statusCode = 422;
        }

        return $this->render('update', ['model' => $comment]);
    }

    public function actionDelete(int $id): Response
    {
        $comment = $this->find($id);
        $result = $this->comments->delete($comment);
        Yii::$app->session->setFlash('success', "Comment #{$id} deleted.");
        if (!$result->delivered) {
            Yii::$app->session->setFlash('warning', 'Deleted, but the realtime relay is unavailable.');
        }

        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * Fresh admin-scope socket token for the logged-in administrator.
     */
    public function actionWsToken(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->headers->set('Cache-Control', 'no-store');
        $ttl = (int) Yii::$app->params['ws.tokenTtl'];

        return [
            'url' => Yii::$app->params['ws.publicUrl'],
            'token' => $this->tokens->issue(SocketTokenService::SCOPE_ADMIN, (int) Yii::$app->user->id, $ttl),
            'expires_in' => $ttl,
        ];
    }

    private function find(int $id): Comment
    {
        return Comment::findOne($id) ?? throw new NotFoundHttpException("Comment #{$id} not found.");
    }
}
