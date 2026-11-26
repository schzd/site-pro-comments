<?php

declare(strict_types=1);

namespace common\models;

use common\helpers\Handle;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A comment posted on the public wall.
 *
 * @property int $id
 * @property string $author_name
 * @property string $body
 * @property int $status
 * @property bool $is_demo     posted by the /demo auto-poster; purgeable in bulk
 * @property int $likes        denormalised counter, source of truth is comment_like
 * @property string|null $ip
 * @property int $created_at
 * @property int $updated_at
 */
class Comment extends ActiveRecord
{
    public const STATUS_HIDDEN = 0;
    public const STATUS_PUBLISHED = 10;

    public const STATUS_LABELS = [
        self::STATUS_PUBLISHED => 'published',
        self::STATUS_HIDDEN => 'hidden',
    ];

    /** Visitor posting from the public form: only these two fields are mass-assignable. */
    public const SCENARIO_CREATE = 'create';
    /** Administrator editing in the backend: may also change the status. */
    public const SCENARIO_ADMIN = 'admin';

    public const AUTHOR_MIN = 2;
    public const AUTHOR_MAX = 40;
    public const BODY_MIN = 1;
    public const BODY_MAX = 1000;

    public static function tableName(): string
    {
        return '{{%comment}}';
    }

    public static function find(): CommentQuery
    {
        return new CommentQuery(static::class);
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function scenarios(): array
    {
        return [
            self::SCENARIO_DEFAULT => ['author_name', 'body'],
            self::SCENARIO_CREATE => ['author_name', 'body'],
            self::SCENARIO_ADMIN => ['author_name', 'body', 'status'],
        ];
    }

    public function rules(): array
    {
        return [
            ['author_name', 'filter', 'filter' => [self::class, 'normalizeLine']],
            ['body', 'filter', 'filter' => [self::class, 'normalizeText']],
            [['author_name', 'body'], 'required'],
            ['author_name', 'string', 'min' => self::AUTHOR_MIN, 'max' => self::AUTHOR_MAX],
            ['body', 'string', 'min' => self::BODY_MIN, 'max' => self::BODY_MAX],
            ['status', 'filter', 'filter' => 'intval', 'skipOnEmpty' => true],
            ['status', 'default', 'value' => self::STATUS_PUBLISHED],
            ['status', 'in', 'range' => array_keys(self::STATUS_LABELS)],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'author_name' => 'Name',
            'body' => 'Comment',
            'status' => 'Status',
        ];
    }

    /**
     * Single-line field: trim, collapse whitespace, drop control characters.
     */
    public static function normalizeLine(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * Multi-line field: unify newlines, strip control characters (except \n),
     * collapse runs of blank lines, trim.
     */
    public static function normalizeText(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', '', $value) ?? '';
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

        return trim($value);
    }

    public function isPublished(): bool
    {
        return (int) $this->status === self::STATUS_PUBLISHED;
    }

    public function getHandle(): string
    {
        return Handle::fromName((string) $this->author_name);
    }

    public function getAvatarUrl(): string
    {
        return Yii::$app->params['frontend.publicUrl'] . '/avatar.svg?name=' . rawurlencode($this->getHandle());
    }

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[(int) $this->status] ?? 'unknown';
    }

    /**
     * What any visitor may see (HTTP responses, public sockets).
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => (int) $this->id,
            'author_name' => $this->author_name,
            'handle' => $this->getHandle(),
            'avatar_url' => $this->getAvatarUrl(),
            'body' => $this->body,
            'likes' => (int) $this->likes,
            'is_demo' => (bool) $this->is_demo,
            'created_at' => (int) $this->created_at,
            'created_at_iso' => gmdate('Y-m-d\TH:i:s\Z', (int) $this->created_at),
        ];
    }

    /**
     * Full projection for administrators (admin sockets, backend).
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return $this->toPublicArray() + [
            'status' => (int) $this->status,
            'status_label' => $this->getStatusLabel(),
            'ip' => $this->ip,
            'updated_at' => (int) $this->updated_at,
        ];
    }
}
