<?php

declare(strict_types=1);

namespace common\tests\Unit\Helpers;

use Codeception\Test\Unit;
use common\helpers\Avatar;
use common\helpers\Handle;

/**
 * Presentation of an author on the wall: @handle and identicon.
 *
 * @group step-S4
 */
final class HandleAvatarTest extends Unit
{
    /**
     * @dataProvider names
     */
    public function testHandleFromName(string $name, string $expected): void
    {
        $this->assertSame($expected, Handle::fromName($name));
    }

    public static function names(): array
    {
        return [
            'latin' => ['Ada Lovelace', 'ada_lovelace'],
            'cyrillic transliterated' => ['Шахзод', 'shahzod'],
            'mixed + punctuation' => ['Анна QA!!', 'anna_qa'],
            'emoji only' => ['🔥🔥', Handle::FALLBACK],
            'empty' => ['', Handle::FALLBACK],
            'trimmed underscores' => ['__x__', 'x'],
            'length capped' => [str_repeat('a', 60), str_repeat('a', Handle::MAX_LENGTH)],
        ];
    }

    public function testHandleIsIdempotentSoItCanSeedAvatars(): void
    {
        foreach (['Ада Л.', 'guest_3fa9', 'Max SDET', str_repeat('щ', 30)] as $name) {
            $handle = Handle::fromName($name);
            $this->assertSame($handle, Handle::fromName($handle), "not idempotent for '{$name}'");
        }
    }

    public function testGuestNameFormat(): void
    {
        $this->assertMatchesRegularExpression('/^guest_[a-f0-9]{4}$/', Handle::guest());
    }

    public function testAvatarIsDeterministicAndSeedSensitive(): void
    {
        $this->assertSame(Avatar::svg('ada'), Avatar::svg('ada'));
        $this->assertNotSame(Avatar::svg('ada'), Avatar::svg('bob'));
    }

    public function testAvatarIsSelfContainedSvg(): void
    {
        $svg = Avatar::svg('ada');
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringNotContainsString('http://', substr($svg, 50), 'avatar must not reference external resources');
        $this->assertNotFalse(simplexml_load_string($svg), 'avatar must be well-formed XML');
    }
}
