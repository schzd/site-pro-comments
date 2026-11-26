<?php

declare(strict_types=1);

namespace frontend\components;

/**
 * Server-side twin of Echo.relTime() in echo-core.js (first paint before JS).
 */
final class RelTime
{
    public static function format(int $timestamp, ?int $now = null): string
    {
        $d = max(0, ($now ?? time()) - $timestamp);

        return match (true) {
            $d < 5 => 'сейчас',
            $d < 60 => $d . 'с',
            $d < 3600 => intdiv($d, 60) . 'м',
            $d < 86400 => intdiv($d, 3600) . 'ч',
            $d < 7 * 86400 => intdiv($d, 86400) . 'д',
            default => gmdate('d.m.Y', $timestamp),
        };
    }
}
