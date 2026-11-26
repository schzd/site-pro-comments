<?php

declare(strict_types=1);

namespace common\helpers;

/**
 * Deterministic identicon avatars, rendered locally (no third-party calls).
 *
 * A 5×5 grid mirrored around the vertical axis; cells and colour come from
 * the md5 of the seed. Same seed → byte-identical SVG.
 */
final class Avatar
{
    public const BACKGROUND = '#1b1c18';

    public static function svg(string $seed): string
    {
        $hash = md5($seed);
        $bytes = array_map('hexdec', str_split($hash, 2));

        $hue = (int) round(($bytes[0] * 256 + $bytes[1]) / 65535 * 360);
        $saturation = 55 + $bytes[2] % 30;
        $lightness = 55 + $bytes[3] % 15;
        $fill = sprintf('hsl(%d %d%% %d%%)', $hue, $saturation, $lightness);

        $cells = '';
        for ($row = 0; $row < 5; $row++) {
            for ($col = 0; $col < 3; $col++) {
                $bit = $bytes[4 + $row * 3 + $col] ?? $bytes[$row + $col];
                if ($bit % 2 === 0) {
                    continue;
                }
                $cells .= sprintf('<rect x="%d" y="%d" width="1" height="1"/>', $col + 1, $row + 1);
                if ($col < 2) {
                    $cells .= sprintf('<rect x="%d" y="%d" width="1" height="1"/>', 5 - $col, $row + 1);
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 7 7" shape-rendering="crispEdges">'
            . '<rect width="7" height="7" fill="' . self::BACKGROUND . '"/>'
            . '<g fill="' . $fill . '">' . $cells . '</g></svg>';
    }
}
