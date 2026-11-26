<?php

declare(strict_types=1);

namespace common\tests\Unit\Property;

use Codeception\Test\Unit;
use common\helpers\Handle;
use common\models\Comment;
use common\realtime\CommentEvent;
use common\realtime\Envelope;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Property-based guard for everything a visitor can type, with a corpus that
 * GROWS FROM ITS OWN FAILURES:
 *
 *  1. testGeneratedInputs() throws QA_PROPERTY_RUNS random hostile strings
 *     (control chars, NBSP/U+2028, RTL, combining marks, emoji, invalid
 *     UTF-8, huge inputs) at the normalisers, validators and wire format.
 *  2. Any counterexample is appended to qa/corpus/input-invariants.json and
 *     the test fails, printing the seed to reproduce it.
 *  3. testCorpusReplay() re-checks every stored counterexample on every run,
 *     forever — once a bug is found and fixed it can never silently return.
 *
 * Reproduce a run:  QA_SEED=1234 docker compose run --rm tester unit --group property
 *
 * @group step-S2
 * @group property
 */
final class InputInvariantsTest extends Unit
{
    private const CORPUS = __DIR__ . '/../../../../qa/corpus/input-invariants.json';

    public function testGeneratedInputs(): void
    {
        $seed = (int) (getenv('QA_SEED') ?: random_int(1, PHP_INT_MAX >> 16));
        $runs = (int) (getenv('QA_PROPERTY_RUNS') ?: 300);
        mt_srand($seed);
        codecept_debug("property seed={$seed} runs={$runs}");

        for ($i = 0; $i < $runs; $i++) {
            $author = self::hostileString(mt_rand(0, 60));
            $body = self::hostileString(mt_rand(0, mt_rand(0, 1) ? 40 : 1400));
            try {
                self::assertInvariants($author, $body);
            } catch (AssertionFailedError $e) {
                $saved = self::remember($author, $body, $seed, $e->getMessage());
                $this->fail(sprintf(
                    "Invariant violated (seed=%d, iteration=%d): %s\n%s",
                    $seed,
                    $i,
                    $e->getMessage(),
                    $saved ? 'Counterexample saved to qa/corpus/input-invariants.json — it will be replayed on every run.' : 'Counterexample already in corpus.',
                ));
            }
        }
    }

    /**
     * @dataProvider corpus
     */
    public function testCorpusReplay(string $author, string $body): void
    {
        self::assertInvariants($author, $body);
    }

    public static function corpus(): array
    {
        $cases = [];
        foreach (self::loadCorpus() as $i => $case) {
            $cases[sprintf('#%d %s', $i, $case['note'] ?? '')] = [base64_decode($case['author_b64']), base64_decode($case['body_b64'])];
        }

        return $cases ?: ['empty corpus' => ['', '']];
    }

    private static function assertInvariants(string $author, string $body): void
    {
        // Normalisers are total, idempotent, and strip what they promise.
        $line = Comment::normalizeLine($author);
        self::assertSame($line, Comment::normalizeLine($line), 'normalizeLine must be idempotent');
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $line, 'no control chars in a line');
        self::assertSame($line, trim($line), 'line is trimmed');

        $text = Comment::normalizeText($body);
        self::assertSame($text, Comment::normalizeText($text), 'normalizeText must be idempotent');
        self::assertStringNotContainsString("\r", $text);
        self::assertStringNotContainsString("\n\n\n", $text);

        // Handles are always a safe, idempotent slug (they seed avatar URLs).
        $handle = Handle::fromName($author);
        self::assertMatchesRegularExpression('/^[a-z0-9_]{1,' . Handle::MAX_LENGTH . '}$/', $handle);
        self::assertSame($handle, Handle::fromName($handle));

        // Validation never throws, and whatever it accepts is within bounds
        // and serialisable onto the realtime wire.
        $c = new Comment(['scenario' => Comment::SCENARIO_CREATE]);
        $c->load(['author_name' => $author, 'body' => $body], '');
        if ($c->validate()) {
            self::assertTrue(mb_check_encoding($c->author_name, 'UTF-8') && mb_check_encoding($c->body, 'UTF-8'), 'accepted text is valid UTF-8');
            self::assertLessThanOrEqual(Comment::AUTHOR_MAX, mb_strlen($c->author_name));
            self::assertLessThanOrEqual(Comment::BODY_MAX, mb_strlen($c->body));
            $c->setAttributes(['id' => 1, 'status' => 10, 'is_demo' => false, 'likes' => 0, 'created_at' => 1, 'updated_at' => 1], false);
            $decoded = Envelope::decode(Envelope::create(CommentEvent::CREATED, $c->toAdminArray())->encode());
            self::assertSame($c->body, $decoded->data['body'], 'body survives the wire byte-for-byte');
        }
    }

    private static function hostileString(int $length): string
    {
        $alphabets = [
            fn () => chr(mt_rand(32, 126)),
            fn () => chr(mt_rand(0, 31)),
            fn () => ["\r\n", "\n", "\r", "\t", ' ', "\u{00A0}", "\u{2028}", "\u{200B}", "\u{FEFF}"][mt_rand(0, 8)],
            fn () => mb_chr(mt_rand(0x0410, 0x044F)),
            fn () => mb_chr(mt_rand(0x1F300, 0x1F64F)),
            fn () => ["\u{202E}", "\u{0301}", "\u{05D0}", 'ё', 'Ё', 'ў', 'ғ'][mt_rand(0, 6)],
            fn () => ['<script>', '"\'><img src=x onerror=alert(1)>', '{{7*7}}', '../', '%00', '\\u0000'][mt_rand(0, 5)],
            fn () => chr(mt_rand(0x80, 0xFF)), // invalid UTF-8 byte
        ];
        $s = '';
        for ($i = 0; $i < $length; $i++) {
            $s .= $alphabets[mt_rand(0, mt_rand(0, 4) === 0 ? 7 : 6)]();
        }

        return $s;
    }

    /**
     * @return list<array{author_b64:string, body_b64:string, seed?:int, note?:string}>
     */
    private static function loadCorpus(): array
    {
        if (!is_file(self::CORPUS)) {
            return [];
        }
        $data = json_decode((string) file_get_contents(self::CORPUS), true);

        return is_array($data['cases'] ?? null) ? $data['cases'] : [];
    }

    private static function remember(string $author, string $body, int $seed, string $why): bool
    {
        $cases = self::loadCorpus();
        $a = base64_encode($author);
        $b = base64_encode($body);
        foreach ($cases as $case) {
            if ($case['author_b64'] === $a && $case['body_b64'] === $b) {
                return false;
            }
        }
        $cases[] = ['author_b64' => $a, 'body_b64' => $b, 'seed' => $seed, 'found_at' => gmdate('c'), 'note' => mb_substr($why, 0, 120)];
        @mkdir(dirname(self::CORPUS), 0777, true);
        file_put_contents(self::CORPUS, json_encode(['description' => 'Counterexamples found by InputInvariantsTest; replayed on every run.', 'cases' => $cases], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return true;
    }
}
