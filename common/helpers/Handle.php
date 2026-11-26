<?php

declare(strict_types=1);

namespace common\helpers;

/**
 * Derives the "@handle" shown next to a display name.
 *
 * Deterministic and idempotent — fromName(fromName(x)) === fromName(x) — so a
 * handle can itself be used as the avatar seed. Cyrillic is transliterated,
 * everything else outside [a-z0-9_] becomes "_".
 */
final class Handle
{
    public const MAX_LENGTH = 24;
    public const FALLBACK = 'anon';

    private const CYRILLIC = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya', 'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g', 'ў' => 'u', 'қ' => 'q', 'ғ' => 'g',
        'ҳ' => 'h', 'ұ' => 'u', 'ү' => 'u', 'ө' => 'o', 'ә' => 'a', 'ң' => 'n',
    ];

    public static function fromName(string $name): string
    {
        $lower = mb_strtolower($name, 'UTF-8');
        $latin = strtr($lower, self::CYRILLIC);
        $ascii = preg_replace('/[^a-z0-9_]+/', '_', $latin) ?? '';
        $ascii = trim(preg_replace('/_+/', '_', $ascii) ?? '', '_');
        $ascii = rtrim(substr($ascii, 0, self::MAX_LENGTH), '_');

        return $ascii === '' ? self::FALLBACK : $ascii;
    }

    /**
     * Name given to a visitor who leaves the name field empty, e.g. "guest_3fa9".
     * Stable for the visitor's session (the caller persists it).
     */
    public static function guest(): string
    {
        return 'guest_' . bin2hex(random_bytes(2));
    }
}
