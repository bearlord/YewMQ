<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_retained_message}}`.
 */
class m250930_081922_create_mqtt_retained_message_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_retained_message}}', [
            // Primary key
            'id' => $this->primaryKey()->comment('Primary key'),

            // Retained message topic. Supports standard MQTT wildcards when querying
            'topic' => $this->string(240)->notNull()->comment('Retained message topic'),

            // Retained message payload (binary)
            'payload' => $this->binary()->notNull()->comment('Retained message payload'),

            // QoS level (0, 1, 2) of the retained message
            'qos' => $this->smallInteger()->notNull()->defaultValue(0)->comment('Retained message QoS level (0, 1, 2)'),

            // Retain flag: 1 = retained, 0 = not retained
            'retain' => $this->smallInteger()->notNull()->defaultValue(0)->comment('Retain flag: 1 = retained, 0 = not retained'),

            // Record creation timestamp
            'created_at' => $this->dateTime(6)->null()->comment('Record creation time'),

            // Record update timestamp
            'updated_at' => $this->dateTime(6)->null()->comment('Record update time'),
        ]);

        // Create an index for topic to speed up retained message lookups by topic
        $this->createIndex('topic', '{{%mqtt_retained_message}}', 'topic');

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_retained_message}}');

        return true;
    }
}
