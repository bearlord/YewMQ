<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_subscription}}`.
 */
class m250930_073920_create_mqtt_subscription_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_subscription}}', [
            // Primary key
            'id' => $this->primaryKey()->comment('Primary key'),

            // MQTT Client identifier (ClientID) of the subscriber
            'client_id' => $this->string(128)->notNull()->comment('MQTT client identifier'),

            // Subscribed topic filter. Supports standard MQTT wildcards like '+' (single-level) and '#' (multi-level)
            'topic' => $this->string(240)->notNull()->comment('Subscribed topic filter, supports + and # wildcards'),

            // Subscription QoS level (0, 1, 2) requested by the client
            'qos' => $this->smallInteger()->notNull()->defaultValue(0)->comment('Subscription QoS level (0, 1, 2)'),

            // Record creation timestamp
            'created_at' => $this->dateTime(6)->null()->comment('Record creation time'),

            // Record update timestamp
            'updated_at' => $this->dateTime(6)->null()->comment('Record update time'),
        ]);

        // Create an index for client_id to speed up subscription lookups by device
        $this->createIndex('client_id', '{{%mqtt_subscription}}', 'client_id');

        // Create an index for topic to speed up subscription lookups by topic
        $this->createIndex('topic', '{{%mqtt_subscription}}', 'topic');

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_subscription}}');

        return true;
    }
}
