<?php

declare(strict_types=1);

use yii\db\Migration;

class m261126_100000_create_comment_table extends Migration
{
    public function safeUp(): void
    {
        $options = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $this->createTable('{{%comment}}', [
            'id' => $this->primaryKey(),
            'author_name' => $this->string(40)->notNull(),
            'body' => $this->text()->notNull(),
            'status' => $this->smallInteger()->notNull()->defaultValue(10),
            'is_demo' => $this->boolean()->notNull()->defaultValue(false),
            'likes' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'ip' => $this->string(45)->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ], $options);

        // Public feed: WHERE status = 10 ORDER BY created_at DESC, id DESC (+ keyset cursor).
        $this->createIndex('idx-comment-status-created-id', '{{%comment}}', ['status', 'created_at', 'id']);
        // Admin list (all statuses) and demo purge.
        $this->createIndex('idx-comment-created-id', '{{%comment}}', ['created_at', 'id']);
        $this->createIndex('idx-comment-is_demo', '{{%comment}}', ['is_demo']);

        $this->createTable('{{%comment_like}}', [
            'id' => $this->primaryKey(),
            'comment_id' => $this->integer()->notNull(),
            'voter' => $this->char(64)->notNull(),
            'ip_hash' => $this->char(64)->notNull(),
            'created_at' => $this->integer()->notNull(),
        ], $options);

        $this->createIndex('uq-comment_like-comment-voter', '{{%comment_like}}', ['comment_id', 'voter'], true);
        $this->createIndex('idx-comment_like-ip-created', '{{%comment_like}}', ['ip_hash', 'created_at']);
        $this->addForeignKey(
            'fk-comment_like-comment',
            '{{%comment_like}}',
            'comment_id',
            '{{%comment}}',
            'id',
            'CASCADE',
            'CASCADE',
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%comment_like}}');
        $this->dropTable('{{%comment}}');
    }
}
