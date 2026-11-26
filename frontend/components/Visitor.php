<?php

declare(strict_types=1);

namespace frontend\components;

use common\helpers\Handle;
use Yii;
use yii\web\Cookie;

/**
 * The anonymous visitor: no account, no login.
 *
 *  - guestName(): generated "guest_xxxx" name, stable for the session, used
 *    when the name field is left empty.
 *  - voterToken(): random long-lived id in a signed, httpOnly cookie. Likes are
 *    unique per (comment, voter); the per-IP cap stops cookie-clearing abuse.
 */
final class Visitor
{
    private const GUEST_KEY = 'echo.guest';
    private const VOTER_COOKIE = 'echo_voter';

    public static function guestName(): string
    {
        $session = Yii::$app->session;
        $name = $session->get(self::GUEST_KEY);
        if (!is_string($name) || !preg_match('/^guest_[a-f0-9]{4}$/', $name)) {
            $name = Handle::guest();
            $session->set(self::GUEST_KEY, $name);
        }

        return $name;
    }

    public static function voterToken(): string
    {
        $token = Yii::$app->request->cookies->getValue(self::VOTER_COOKIE);
        if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        Yii::$app->response->cookies->add(new Cookie([
            'name' => self::VOTER_COOKIE,
            'value' => $token,
            'httpOnly' => true,
            'sameSite' => Cookie::SAME_SITE_LAX,
            'expire' => time() + 365 * 86400,
        ]));
        // Make it visible to the rest of this request as well.
        Yii::$app->request->cookies->readOnly = false;
        Yii::$app->request->cookies->add(new Cookie(['name' => self::VOTER_COOKIE, 'value' => $token]));

        return $token;
    }

    public static function ip(): string
    {
        return (string) Yii::$app->request->userIP;
    }
}
