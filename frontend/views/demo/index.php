<?php

declare(strict_types=1);

/**
 * @var yii\web\View $this
 * @var list<array{name:string, lines:list<string>}> $personas
 */

use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

$this->title = 'Echo — live pipeline demo';

$nodes = [
    // id, x, y, title, subtitle
    ['visitor', 20, 82, 'VISITOR', 'browser · fetch()'],
    ['frontend', 215, 82, 'FRONT-END', 'Yii2 · /comments'],
    ['mysql', 430, 18, 'MYSQL', 'INSERT comment'],
    ['redis', 430, 146, 'REDIS', 'PUBLISH pub/sub'],
    ['ws', 645, 146, 'WS RELAY', 'Ratchet · ReactPHP'],
    ['admin', 845, 82, 'ADMIN', 'WebSocket frame'],
];
$edges = [
    ['visitor-frontend', 'M150 110 L215 110'],
    ['frontend-mysql', 'M345 100 C385 100 390 46 430 46'],
    ['frontend-redis', 'M345 120 C385 120 390 174 430 174'],
    ['redis-ws', 'M560 174 L645 174'],
    ['ws-admin', 'M775 174 C815 174 805 110 845 110'],
];
?>
<div class="demo"
     data-demo
     data-create-url="<?= Url::to(['/comment/create']) ?>"
     data-reset-url="<?= Url::to(['/demo/reset']) ?>"
     data-token-url="<?= Url::to(['/comment/ws-token']) ?>"
     data-personas="<?= Html::encode(Json::encode($personas)) ?>">

    <section class="demo-intro" data-intro>
        <p class="mono demo-kicker">real traffic · real redis · real websocket</p>
        <h1>Один комментарий.<br>Пять систем.<br><span>Меньше 50&nbsp;мс.</span></h1>
        <p class="muted">Авто-постер отправляет настоящие POST-запросы от лица симулированных пользователей.
            Каждая вспышка ниже — реальное событие: ответ сервера или кадр WebSocket.</p>
        <button type="button" class="btn btn-lime demo-play" data-play-big>▶ Play Demo</button>
    </section>

    <section class="demo-stage" aria-label="Pipeline">
        <svg class="pipeline" viewBox="0 0 1000 220" role="img" aria-label="Visitor → Front-end → MySQL + Redis → WS relay → Admin">
            <defs>
                <filter id="glow" x="-50%" y="-50%" width="200%" height="200%">
                    <feGaussianBlur stdDeviation="3" result="b"/>
                    <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                </filter>
            </defs>
            <?php foreach ($edges as [$id, $d]): ?>
                <path class="edge" id="edge-<?= $id ?>" d="<?= $d ?>"/>
            <?php endforeach ?>
            <?php foreach ($nodes as [$id, $x, $y, $title, $sub]): ?>
                <g class="node" id="node-<?= $id ?>" transform="translate(<?= $x ?> <?= $y ?>)">
                    <rect width="130" height="56" rx="10"/>
                    <text x="14" y="25" class="node-title"><?= $title ?></text>
                    <text x="14" y="43" class="node-sub"><?= Html::encode($sub) ?></text>
                </g>
            <?php endforeach ?>
            <g data-particles></g>
        </svg>

        <div class="demo-controls">
            <button type="button" class="btn btn-lime" data-toggle>▶ Play</button>
            <button type="button" class="btn" data-reset title="Удалить все demo-комментарии">⟲ Reset</button>
            <label class="demo-speed">
                <span class="mono">интервал</span>
                <input type="range" min="600" max="6000" step="100" value="2500" data-speed>
                <span class="mono" data-speed-label>2.5s</span>
            </label>
            <span class="live" data-live data-state="offline">offline</span>
            <div class="demo-stats mono">
                <span>posted <b data-stat="posted">0</b></span>
                <span>relayed <b data-stat="relayed">0</b></span>
                <span>e2e last <b data-stat="last">—</b></span>
                <span>e2e avg <b data-stat="avg">—</b></span>
                <span>p95 <b data-stat="p95">—</b></span>
            </div>
        </div>
    </section>

    <section class="demo-grid">
        <div class="demo-pane">
            <div class="demo-pane-head"><span>Public wall</span><span class="mono muted">POST response</span></div>
            <div class="demo-feed feed-list" data-demo-feed></div>
        </div>
        <div class="demo-pane">
            <div class="demo-pane-head"><span>Admin stream</span><span class="mono muted">WebSocket frames</span></div>
            <div class="demo-admin" data-demo-admin></div>
        </div>
        <div class="demo-pane demo-pane-trace">
            <div class="demo-pane-head"><span>Trace</span><span class="mono muted">hop log</span></div>
            <ol class="trace mono" data-trace></ol>
        </div>
    </section>

    <section class="demo-bonus" aria-label="Bonus">
        <div class="demo-bonus-ship" aria-hidden="true">▲</div>
        <div class="demo-bonus-text">
            <p class="mono demo-kicker">bonus · easter egg</p>
            <h2>Pegasus Dodge</h2>
            <p class="muted">Мини-игра на three.js в том же фирменном стиле: проведи «Пегас» сквозь поле астероидов.
                Стрелки или перетаскивание, <span class="mono">P</span> — пауза.</p>
        </div>
        <div class="demo-bonus-actions">
            <button type="button" class="btn btn-lime" data-game-open>▲ Играть</button>
            <a class="btn" href="/game/" target="_blank" rel="noopener">в новой вкладке ↗</a>
        </div>
    </section>

    <dialog class="game-dialog" data-game-dialog aria-label="Pegasus Dodge">
        <button type="button" class="btn btn-sm game-close" data-game-close>✕ Закрыть</button>
        <iframe title="Pegasus Dodge" data-game-frame allow="fullscreen"></iframe>
    </dialog>

    <template id="card-template"><?= $this->render('/comment/_card', ['data' => null, 'liked' => false]) ?></template>
</div>
