<?php

declare(strict_types=1);

namespace console\controllers;

use Clue\React\Redis\Client as RedisClient;
use Clue\React\Redis\Factory as RedisFactory;
use common\helpers\Env;
use common\realtime\RelayHub;
use common\realtime\SocketTokenService;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * WebSocket relay daemon.
 *
 *   php yii ws/serve
 *
 * One ReactPHP event loop runs both halves:
 *  - a Ratchet WebSocket server that browsers (admin panel, public feed, demo)
 *    connect to with a signed token;
 *  - a non-blocking Redis SUBSCRIBE on COMMENTS_CHANNEL (clue/redis-react).
 * Each message from Redis is fanned out by RelayHub.
 *
 * Crash-only: if the Redis connection fails or drops, the process exits with
 * a non-zero code and Docker restarts it. Browsers see the socket close and
 * show OFFLINE until it is back — the outage is never masked.
 */
final class WsController extends Controller
{
    public function actionServe(): int
    {
        // Ratchet 0.4.4 (latest release) triggers PHP 8.4 "implicitly nullable"
        // deprecations at class-load time. They are vendor noise, not errors.
        error_reporting(E_ALL & ~E_DEPRECATED);

        $listen = Env::string('WS_LISTEN');
        $channel = Env::string('COMMENTS_CHANNEL');
        $redisUri = sprintf('redis://%s:%d', Env::string('REDIS_HOST'), Env::int('REDIS_PORT'));

        $log = static function (string $level, string $message): void {
            fwrite(STDOUT, sprintf("%s %-7s %s\n", gmdate('Y-m-d\TH:i:s\Z'), strtoupper($level), $message));
        };

        $loop = Loop::get();
        $exitCode = ExitCode::OK;

        $hub = new RelayHub(Yii::$container->get(SocketTokenService::class), $log);

        $wsServer = new WsServer($hub);
        $wsServer->enableKeepAlive($loop, 30);
        new IoServer(new HttpServer($wsServer), new SocketServer($listen, [], $loop), $loop);
        $log('info', "websocket server listening on {$listen}");

        $fail = static function (string $why) use ($log, $loop, &$exitCode): void {
            $log('error', $why . ' — exiting so the supervisor restarts the relay');
            $exitCode = ExitCode::UNAVAILABLE;
            $loop->stop();
        };

        (new RedisFactory($loop))->createClient($redisUri)->then(
            static function (RedisClient $redis) use ($channel, $hub, $log, $fail, $redisUri): void {
                $redis->on('message', static function (string $ch, string $payload) use ($hub): void {
                    $hub->relay($payload);
                });
                $redis->on('close', static fn () => $fail("redis connection to {$redisUri} closed"));
                $redis->on('error', static fn (\Throwable $e) => $fail('redis error: ' . $e->getMessage()));

                $redis->subscribe($channel)->then(
                    static fn () => $log('info', "subscribed to redis channel '{$channel}' at {$redisUri}"),
                    static fn (\Throwable $e) => $fail('redis SUBSCRIBE failed: ' . $e->getMessage()),
                );
            },
            static fn (\Throwable $e) => $fail("cannot connect to redis at {$redisUri}: " . $e->getMessage()),
        );

        $loop->run();

        return $exitCode;
    }
}
