<?php

declare(strict_types=1);

namespace frontend\controllers;

use common\models\Comment;
use common\models\CommentLike;
use common\realtime\SocketTokenService;
use common\services\CommentService;
use common\services\LikeRateLimitException;
use frontend\components\Visitor;
use Yii;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

/**
 * Public wall: read the feed and post without any login.
 */
final class CommentController extends Controller
{
    public const PAGE_SIZE = 15;

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
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'index' => ['GET', 'HEAD'],
                    'page' => ['GET'],
                    'create' => ['POST'],
                    'like' => ['POST'],
                    'ws-token' => ['GET'],
                ],
            ],
        ];
    }

    public function actionIndex(): string
    {
        [$items, $nextCursor] = $this->loadPage(null);

        return $this->render('index', [
            'items' => $items,
            'liked' => $this->likedIds($items),
            'nextCursor' => $nextCursor,
            'guestName' => Visitor::guestName(),
            'total' => (int) Comment::find()->published()->count(),
        ]);
    }

    /**
     * "Load more" / infinite scroll: keyset page of server-rendered cards.
     */
    public function actionPage(?string $before = null): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        [$items, $nextCursor] = $this->loadPage($before);
        $liked = $this->likedIds($items);

        $html = '';
        foreach ($items as $comment) {
            $html .= $this->renderPartial('_card', ['data' => $comment->toPublicArray(), 'liked' => isset($liked[$comment->id])]);
        }

        return ['html' => $html, 'count' => count($items), 'next_cursor' => $nextCursor];
    }

    public function actionCreate(): Response|array|string
    {
        $request = Yii::$app->request;
        $input = (array) $request->post('Comment', []);
        $isDemo = $request->post('demo') === '1';
        if ($isDemo && !Yii::$app->params['demo.enabled']) {
            throw new BadRequestHttpException('Demo mode is disabled (DEMO_MODE=off).');
        }

        $outcome = $this->comments->create($input, Visitor::ip(), $isDemo, Visitor::guestName());
        $wantsJson = $request->isAjax || str_contains((string) $request->headers->get('Accept'), 'application/json');

        if ($wantsJson) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            if (!$outcome->isSaved()) {
                Yii::$app->response->statusCode = 422;

                return ['errors' => $outcome->comment->getFirstErrors()];
            }
            Yii::$app->response->statusCode = 201;

            return [
                'comment' => $outcome->comment->toPublicArray(),
                'realtime' => $outcome->realtime->toArray(),
            ];
        }

        // No-JS fallback: classic POST-redirect-GET.
        if ($outcome->isSaved()) {
            Yii::$app->session->setFlash('posted', 'Опубликовано.');

            return $this->redirect(['/comment/index']);
        }

        Yii::$app->response->statusCode = 422;
        [$items, $nextCursor] = $this->loadPage(null);

        return $this->render('index', [
            'items' => $items,
            'liked' => $this->likedIds($items),
            'nextCursor' => $nextCursor,
            'guestName' => Visitor::guestName(),
            'total' => (int) Comment::find()->published()->count(),
            'form' => $outcome->comment,
        ]);
    }

    public function actionLike(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $comment = Comment::find()->published()->andWhere(['id' => $id])->one();
        if ($comment === null) {
            throw new NotFoundHttpException('Comment not found.');
        }

        try {
            $outcome = $this->comments->toggleLike(
                $comment,
                Visitor::voterToken(),
                Visitor::ip(),
                (int) Yii::$app->params['likes.maxPerIpPerMinute'],
            );
        } catch (LikeRateLimitException $e) {
            throw new TooManyRequestsHttpException('Слишком много лайков. Попробуйте через минуту.');
        }

        return ['id' => $id, 'liked' => $outcome->liked, 'likes' => $outcome->likes, 'realtime' => $outcome->realtime->toArray()];
    }

    /**
     * Short-lived token that lets this visitor open a read-only (public) socket.
     */
    public function actionWsToken(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->headers->set('Cache-Control', 'no-store');
        $ttl = (int) Yii::$app->params['ws.tokenTtl'];

        return [
            'url' => Yii::$app->params['ws.publicUrl'],
            'token' => $this->tokens->issue(SocketTokenService::SCOPE_PUBLIC, 0, $ttl),
            'expires_in' => $ttl,
        ];
    }

    /**
     * @return array{0: list<Comment>, 1: ?string}
     */
    private function loadPage(?string $before): array
    {
        $query = Comment::find()->published()->newestFirst()->limit(self::PAGE_SIZE + 1);
        if ($before !== null && $before !== '') {
            if (!preg_match('/^(\d+)_(\d+)$/', $before, $m)) {
                throw new BadRequestHttpException('Malformed cursor.');
            }
            $query->olderThan((int) $m[1], (int) $m[2]);
        }

        /** @var list<Comment> $rows */
        $rows = $query->all();
        $hasMore = count($rows) > self::PAGE_SIZE;
        $items = array_slice($rows, 0, self::PAGE_SIZE);
        $last = end($items);

        return [$items, $hasMore && $last ? $last->created_at . '_' . $last->id : null];
    }

    /**
     * @param list<Comment> $items
     * @return array<int, true>
     */
    private function likedIds(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $voter = Yii::$app->request->cookies->getValue('echo_voter');
        if (!is_string($voter)) {
            return [];
        }
        $ids = CommentLike::find()
            ->select('comment_id')
            ->where(['comment_id' => array_map(static fn (Comment $c) => $c->id, $items), 'voter' => hash('sha256', 'voter|' . $voter)])
            ->column();

        return array_fill_keys(array_map('intval', $ids), true);
    }
}
