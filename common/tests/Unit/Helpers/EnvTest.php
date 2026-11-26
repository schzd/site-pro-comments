<?php

declare(strict_types=1);

namespace common\tests\Unit\Helpers;

use Codeception\Test\Unit;
use common\helpers\Env;
use yii\base\InvalidConfigException;

/**
 * Configuration must fail loudly — no silent defaults.
 *
 * @group config
 */
final class EnvTest extends Unit
{
    protected function _after(): void
    {
        putenv('ECHO_TEST_VAR');
    }

    public function testReturnsSetValue(): void
    {
        putenv('ECHO_TEST_VAR=hello');
        $this->assertSame('hello', Env::string('ECHO_TEST_VAR'));
    }

    public function testMissingVariableThrowsAndNamesIt(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('ECHO_TEST_VAR');
        Env::string('ECHO_TEST_VAR');
    }

    public function testBlankVariableCountsAsMissing(): void
    {
        putenv('ECHO_TEST_VAR=   ');
        $this->expectException(InvalidConfigException::class);
        Env::string('ECHO_TEST_VAR');
    }

    public function testIntRejectsNonNumeric(): void
    {
        putenv('ECHO_TEST_VAR=63a79');
        $this->expectException(InvalidConfigException::class);
        Env::int('ECHO_TEST_VAR');
    }

    /**
     * @dataProvider booleans
     */
    public function testBoolParsesOnlyExplicitValues(string $raw, bool $expected): void
    {
        putenv("ECHO_TEST_VAR={$raw}");
        $this->assertSame($expected, Env::bool('ECHO_TEST_VAR'));
    }

    public static function booleans(): array
    {
        return [['on', true], ['TRUE', true], ['1', true], ['off', false], ['no', false], ['0', false]];
    }

    public function testBoolRejectsAmbiguousValue(): void
    {
        putenv('ECHO_TEST_VAR=maybe');
        $this->expectException(InvalidConfigException::class);
        Env::bool('ECHO_TEST_VAR');
    }
}
