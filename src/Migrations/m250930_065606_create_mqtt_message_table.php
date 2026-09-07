<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_message}}`.
 */
class m250930_065606_create_mqtt_message_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_message}}', [
            'id' => $this->bigPrimaryKey()->comment('primary key'),

            'direction' => $this->smallInteger()->notNull()->defaultValue(0)->comment('1: up, 2: down'),

            'sender_id' => $this->string(128)->null()->comment('sender client id'),

            'receiver_id' => $this->string(128)->null()->comment('receiver client id'),

            'topic' => $this->string(240)->notNull()->comment('topic'),

            'payload' => $this->text()->notNull()->comment('payload'),

            'qos' => $this->smallInteger()->notNull()->defaultValue(0)->comment('qos'),

            'retain' => $this->smallInteger()->notNull()->defaultValue(0)->comment('retain'),

            'published_time' => $this->dateTime(6)->notNull()->comment('published time'),

            'created_at' => $this->dateTime(6)->null()->comment('created at'),

            'updated_at' => $this->dateTime(6)->null()->comment('updated at'),
        ]);

        $this->createIndex('idx_sender_id', '{{%mqtt_message}}', 'sender_id');
        $this->createIndex('idx_receiver_id', '{{%mqtt_message}}', 'receiver_id');
        $this->createIndex('idx_topic', '{{%mqtt_message}}', 'topic');
        $this->createIndex('idx_published_time', '{{%mqtt_message}}', 'published_time');

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_message}}');

        return true;
    }
}
