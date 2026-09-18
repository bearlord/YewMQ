<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_offline_message}}`.
 */
class m250930_084859_create_mqtt_offline_message_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_offline_message}}', [
            // Primary key
            'id' => $this->primaryKey()->comment('Primary key'),

            // MQTT Client identifier (ClientID) of the offline client
            'client_id' => $this->string(128)->notNull()->comment('MQTT client identifier'),

            // Offline message topic
            'topic' => $this->string(240)->notNull()->comment('Offline message topic'),

            // Offline message payload (binary)
            'payload' => $this->binary()->notNull()->comment('Offline message payload'),

            // QoS level (0, 1, 2) of the offline message
            'qos' => $this->smallInteger()->notNull()->defaultValue(0)->comment('Offline message QoS level (0, 1, 2)'),

            // Down-leg packet id assigned on delivery; links this row to its ack record
            'packet_id' => $this->integer()->null()->comment('Down-leg packet id; links to ack record'),

            // Delivery status: 0 = not delivered, 1 = delivered
            'delivered' => $this->smallInteger()->notNull()->defaultValue(0)->comment('Delivery status: 0 = not delivered, 1 = delivered'),

            // Timestamp when the message was delivered (Null if not yet delivered)
            'delivered_at' => $this->dateTime(6)->null()->comment('Delivery time. Null if not yet delivered'),

            // Record creation timestamp
            'created_at' => $this->dateTime(6)->null()->comment('Record creation time'),

            // Record update timestamp
            'updated_at' => $this->dateTime(6)->null()->comment('Record update time'),
        ]);

        // Create an index for client_id to speed up offline message lookups by device
        $this->createIndex('client_id', '{{%mqtt_offline_message}}', 'client_id');

        // Create an index for topic to speed up offline message lookups by topic
        $this->createIndex('topic', '{{%mqtt_offline_message}}', 'topic');

        // Create an index for delivered to speed up filtering by delivery status
        $this->createIndex('delivered', '{{%mqtt_offline_message}}', 'delivered');

        // Composite index for (client_id, packet_id) to look up buffered rows by
        // the down-leg packet id when an ack finalizes them.
        $this->createIndex('idx_client_packet', '{{%mqtt_offline_message}}', ['client_id', 'packet_id']);

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_offline_message}}');

        return true;
    }
}
