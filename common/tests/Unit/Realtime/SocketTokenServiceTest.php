<?php

declare(strict_types=1);

namespace common\tests\Unit\Realtime;

use Codeception\Test\Unit;
use common\realtime\SocketTokenService;
use InvalidArgumentException;

/**
 * Steps S6/S7 — only holders of a fresh, signed token may open a socket.
 *
 * @group step-S6
 * @group step-S7
 */
final class SocketTokenServiceTest extends Unit
{
    private int $now = 1700000000;

    private function service(string $secret = 'unit-test-secret-0123456789'): SocketTokenService
    {
        return new SocketTokenService($secret, fn (): int => $this->now);
    }

    public function testIssuedTokenVerifiesWithScopeAndSubject(): void
    {
        $grant = $this->service()->verify($this->service()->issue('admin', 42, 60));
        $this->assertNotNull($grant);
        $this->assertSame('admin', $grant->scope);
        $this->assertSame(42, $grant->subject);
        $this->assertTrue($grant->isAdmin());
        $this->assertSame($this->now + 60, $grant->expiresAt);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->service()->issue('public', 0, 60);
        $this->now += 61;
        $this->assertNull($this->service()->verify($token));
    }

    public function testTokenFromAnotherSecretIsRejected(): void
    {
        $token = $this->service('another-secret-abcdefghijk')->issue('admin', 1, 60);
        $this->assertNull($this->service()->verify($token));
    }

    public function testPrivilegeEscalationByEditingPayloadIsRejected(): void
    {
        $token = $this->service()->issue('public', 0, 60);
        [, $sig] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(['s' => 'admin', 'u' => 1, 'e' => $this->now + 60, 'n' => 'x'])), '+/', '-_'), '=');
        $this->assertNull($this->service()->verify($forged . '.' . $sig));
    }

    /**
     * @dataProvider malformed
     */
    public function testMalformedTokensAreRejected(string $token): void
    {
        $this->assertNull($this->service()->verify($token));
    }

    public static function malformed(): array
    {
        return [[''], ['abc'], ['a.b.c'], ['.'], ['%%%.$$$'], ['eyJ9.']];
    }

    public function testTokensAreUniqueEvenWithinTheSameSecond(): void
    {
        $s = $this->service();
        $this->assertNotSame($s->issue('admin', 1, 60), $s->issue('admin', 1, 60));
    }

    public function testRefusesWeakSecretAndUnknownScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SocketTokenService('short');
    }

    public function testRefusesUnknownScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->issue('root', 1, 60);
    }
}
