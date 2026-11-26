<?php

declare(strict_types=1);

namespace console\controllers;

use common\helpers\Env;
use common\models\User;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Seeds data that the application needs to be usable.
 */
final class SeedController extends Controller
{
    /**
     * Creates (or resets the password of) the administrator from
     * ADMIN_USERNAME / ADMIN_EMAIL / ADMIN_PASSWORD. Idempotent.
     */
    public function actionAdmin(): int
    {
        $username = Env::string('ADMIN_USERNAME');
        $email = Env::string('ADMIN_EMAIL');
        $password = Env::string('ADMIN_PASSWORD');

        $user = User::findOne(['username' => $username]) ?? new User();
        $isNew = $user->isNewRecord;

        $user->username = $username;
        $user->email = $email;
        $user->status = User::STATUS_ACTIVE;
        $user->setPassword($password);
        if ($isNew) {
            $user->generateAuthKey();
        }

        if (!$user->save()) {
            $this->stderr('Could not save admin user: ' . json_encode($user->getErrors()) . PHP_EOL, Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout(sprintf("Admin user '%s' %s (id=%d).\n", $username, $isNew ? 'created' : 'updated', $user->id), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
