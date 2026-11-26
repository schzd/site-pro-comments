<?php

/**
 * THE PIPELINE REGISTRY — single source of truth for "which code does what,
 * and which suites must guard it". Everything in qa/qa.php derives from it:
 *
 *   matrix   step -> guarding tests (from @group tags) + last results
 *   gaps     routes / source files / steps that nothing guards
 *   plan     changed files -> impacted steps -> minimal test selection
 *   scaffold new test pre-wired to a step
 *
 * When the application grows: add a step (or extend `routes`/`paths` of an
 * existing one), run `qa gaps`, scaffold what is missing. Tests opt in to a
 * step with `@group step-<ID>` on the class or method.
 *
 * routes:  "<app>:<controller>/<action>" for every web action implementing the step
 * paths:   globs (relative to repo root) of the code implementing the step
 * require: suites that MUST contain at least one test for the step
 */
return [
    'S1' => [
        'title' => 'Visitor opens the wall; the composer renders, no login',
        'routes' => ['frontend:comment/index'],
        'paths' => ['frontend/views/layouts/main.php', 'frontend/views/comment/index.php', 'frontend/web/css/wall.css', 'frontend/assets/*', 'common/assets/*', 'common/assets/theme/echo-base.css'],
        'require' => ['regression', 'smoke', 'e2e'],
    ],
    'S2' => [
        'title' => 'Visitor submits; the server validates and normalises the input',
        'routes' => ['frontend:comment/create'],
        'paths' => ['common/models/Comment.php', 'frontend/components/Visitor.php', 'frontend/controllers/CommentController.php', 'frontend/web/js/wall.js'],
        'require' => ['unit', 'regression', 'smoke', 'e2e'],
    ],
    'S3' => [
        'title' => 'The comment is persisted in MySQL',
        'routes' => [],
        'paths' => ['common/services/*', 'common/models/CommentQuery.php', 'common/models/CommentLike.php', 'console/migrations/*', 'common/fixtures/*'],
        'require' => ['unit', 'regression', 'smoke'],
    ],
    'S4' => [
        'title' => 'It appears in the public feed: order, keyset paging, escaping, likes, avatars',
        'routes' => ['frontend:comment/page', 'frontend:comment/like', 'frontend:avatar/index'],
        'paths' => ['frontend/views/comment/_card.php', 'frontend/components/RelTime.php', 'frontend/controllers/AvatarController.php', 'common/helpers/Handle.php', 'common/helpers/Avatar.php'],
        'require' => ['unit', 'regression', 'smoke', 'e2e'],
    ],
    'S5' => [
        'title' => 'An event is published on the Redis channel (envelope with trace id)',
        'routes' => [],
        'paths' => ['common/realtime/CommentEvent.php', 'common/realtime/Envelope.php', 'common/realtime/PublishResult.php', 'common/realtime/*Publisher*.php', 'common/config/main.php'],
        'require' => ['unit', 'regression', 'smoke'],
    ],
    'S6' => [
        'title' => 'The WS relay authenticates sockets and fans the event out per scope',
        'routes' => ['frontend:comment/ws-token'],
        'paths' => ['common/realtime/RelayHub.php', 'common/realtime/SocketTokenService.php', 'common/realtime/SocketGrant.php', 'console/controllers/WsController.php', 'common/assets/theme/echo-core.js', 'frontend/Dockerfile', 'backend/Dockerfile'],
        'require' => ['unit', 'smoke', 'e2e'],
    ],
    'S7' => [
        'title' => 'The administrator signs in and gets an admin-scope socket token',
        'routes' => ['backend:site/login', 'backend:site/logout', 'backend:comment/ws-token', 'backend:site/error'],
        'paths' => ['backend/controllers/SiteController.php', 'backend/views/site/*', 'backend/views/layouts/*', 'common/models/LoginForm.php', 'common/models/User.php', 'backend/config/main.php'],
        'require' => ['regression', 'smoke', 'e2e'],
    ],
    'S8' => [
        'title' => 'The admin list shows it; new rows arrive live over WebSocket, no reload',
        'routes' => ['backend:comment/index'],
        'paths' => ['backend/controllers/CommentController.php', 'backend/views/comment/index.php', 'backend/views/comment/_row.php', 'backend/web/js/admin-live.js', 'backend/web/css/admin.css', 'backend/assets/*'],
        'require' => ['regression', 'smoke', 'e2e'],
    ],
    'S9' => [
        'title' => 'The admin edits / hides / deletes; every open page follows live',
        'routes' => ['backend:comment/update', 'backend:comment/delete'],
        'paths' => ['backend/views/comment/update.php'],
        'require' => ['unit', 'regression', 'e2e'],
    ],
    'DEMO' => [
        'title' => '/demo drives the real pipeline; Reset purges demo rows only',
        'group' => 'demo',
        'routes' => ['frontend:demo/index', 'frontend:demo/reset'],
        'paths' => ['frontend/controllers/DemoController.php', 'frontend/views/demo/*', 'frontend/views/layouts/demo.php', 'frontend/web/js/demo.js', 'frontend/web/css/demo.css', 'common/demo/*'],
        'require' => ['regression', 'e2e'],
    ],
    'CONFIG' => [
        'title' => 'Strict environment configuration and bootstrap (no silent defaults)',
        'group' => 'config',
        'routes' => ['frontend:site/error'],
        'paths' => ['common/helpers/Env.php', 'console/controllers/SeedController.php', 'frontend/controllers/SiteController.php', 'frontend/views/site/*', 'frontend/config/main.php'],
        'require' => ['unit'],
    ],
];
