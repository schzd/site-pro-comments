<?php

declare(strict_types=1);

namespace common\tests\Unit\Models;

use Codeception\Test\Unit;
use common\models\Comment;

/**
 * Step S2 — what the server accepts from the public form.
 *
 * @group step-S2
 */
final class CommentValidationTest extends Unit
{
    private function newComment(array $input, string $scenario = Comment::SCENARIO_CREATE): Comment
    {
        $c = new Comment(['scenario' => $scenario]);
        $c->load($input, '');

        return $c;
    }

    public function testValidCommentPasses(): void
    {
        $c = $this->newComment(['author_name' => 'Ada', 'body' => 'Hello']);
        $this->assertTrue($c->validate(), json_encode($c->getErrors()));
    }

    /**
     * @dataProvider invalidInputs
     */
    public function testInvalidInputIsRejected(array $input, string $field): void
    {
        $c = $this->newComment($input);
        $this->assertFalse($c->validate());
        $this->assertTrue($c->hasErrors($field), "expected an error on {$field}: " . json_encode($c->getErrors()));
    }

    public static function invalidInputs(): array
    {
        return [
            'body missing' => [['author_name' => 'Ada'], 'body'],
            'body whitespace only' => [['author_name' => 'Ada', 'body' => " \n\t "], 'body'],
            'body too long' => [['author_name' => 'Ada', 'body' => str_repeat('x', Comment::BODY_MAX + 1)], 'body'],
            'author too short' => [['author_name' => 'A', 'body' => 'ok'], 'author_name'],
            'author too long' => [['author_name' => str_repeat('n', Comment::AUTHOR_MAX + 1), 'body' => 'ok'], 'author_name'],
            'body is an array' => [['author_name' => 'Ada', 'body' => ['x']], 'body'],
        ];
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $c = $this->newComment(['author_name' => 'Ада', 'body' => str_repeat('ж', Comment::BODY_MAX)]);
        $this->assertTrue($c->validate(), 'a 1000-char Cyrillic body (2000 bytes) must be accepted');
    }

    public function testNormalisationTrimsAndCollapses(): void
    {
        $c = $this->newComment(['author_name' => "  Ada \t Lovelace\n", 'body' => "\r\n  line1\r\n\r\n\r\n\r\nline2  \x07"]);
        $this->assertTrue($c->validate());
        $this->assertSame('Ada Lovelace', $c->author_name);
        $this->assertSame("line1\n\nline2", $c->body);
    }

    public function testHtmlIsStoredVerbatimAndEscapedOnlyOnOutput(): void
    {
        $c = $this->newComment(['author_name' => 'Ada', 'body' => '<script>alert(1)</script>']);
        $this->assertTrue($c->validate());
        $this->assertSame('<script>alert(1)</script>', $c->body);
    }

    public function testVisitorCannotMassAssignPrivilegedFields(): void
    {
        $c = $this->newComment(['author_name' => 'Ada', 'body' => 'x', 'status' => 0, 'is_demo' => 1, 'likes' => 999, 'ip' => '1.2.3.4', 'id' => 77]);
        $this->assertNull($c->status);
        $this->assertNull($c->is_demo);
        $this->assertNull($c->likes);
        $this->assertNull($c->ip);
        $this->assertNull($c->id);
    }

    public function testAdminCanChangeStatusButOnlyToKnownValues(): void
    {
        $ok = $this->newComment(['author_name' => 'Ada', 'body' => 'x', 'status' => '0'], Comment::SCENARIO_ADMIN);
        $this->assertTrue($ok->validate());
        $this->assertSame(Comment::STATUS_HIDDEN, $ok->status);

        $bad = $this->newComment(['author_name' => 'Ada', 'body' => 'x', 'status' => '5'], Comment::SCENARIO_ADMIN);
        $this->assertFalse($bad->validate());
        $this->assertTrue($bad->hasErrors('status'));
    }

    /**
     * @group step-S4
     * @group step-S6
     */
    public function testPublicProjectionNeverLeaksPrivateFields(): void
    {
        $c = new Comment([
            'id' => 1, 'author_name' => 'Ada', 'body' => 'x', 'status' => 10, 'is_demo' => false,
            'likes' => 3, 'ip' => '10.1.1.1', 'created_at' => 1700000000, 'updated_at' => 1700000000,
        ]);
        $public = $c->toPublicArray();
        $this->assertArrayNotHasKey('ip', $public);
        $this->assertArrayNotHasKey('status', $public);
        $this->assertSame('ada', $public['handle']);
        $this->assertSame('2023-11-14T22:13:20Z', $public['created_at_iso']);
        $this->assertStringEndsWith('/avatar.svg?name=ada', $public['avatar_url']);

        $admin = $c->toAdminArray();
        $this->assertSame('10.1.1.1', $admin['ip']);
        $this->assertSame('published', $admin['status_label']);
    }
}
