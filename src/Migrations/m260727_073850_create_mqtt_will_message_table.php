<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_will_message}}`.
 */
class m260727_073850_create_mqtt_will_message_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_will_message}}', [
            'id' => $this->bigPrimaryKey()->comment('Primary key'),

            'client_id' => $this->string(128)->notNull()
                ->comment('MQTT client identifier'),

            'will_topic' => $this->string(240)->notNull()
                ->comment('Will topic'),

            'will_payload' => $this->getDb()->getSchema()->createColumnSchemaBuilder('LONGTEXT')
                ->null()
                ->comment('Will message payload'),

            'will_qos' => $this->tinyInteger()->notNull()
                ->comment('Will QoS level (0,1,2)'),

            'will_retain' => $this->tinyInteger(1)->notNull()->defaultValue(0)
                ->comment('Will retain flag (0: not retain, 1: retain)'),

            'will_delay_interval' => $this->integer()->unsigned()->notNull()->defaultValue(0)
                ->comment('MQTT v5 will delay interval in seconds'),

            'payload_format_indicator' => $this->tinyInteger()->null()
                ->comment('MQTT v5 payload format indicator (0: UTF-8, 1: binary)'),

            'content_type' => $this->string(128)->null()
                ->comment('MQTT v5 content type'),

            'response_topic' => $this->string(240)->null()
                ->comment('MQTT v5 response topic'),

            'correlation_data' => $this->binary()->null()
                ->comment('MQTT v5 correlation data'),

            'created_at' => $this->dateTime(6)->notNull()
                ->defaultExpression('CURRENT_TIMESTAMP(6)')
                ->comment('Record creation time'),
        ]);

        // Indexes
        $this->createIndex('uk_client_id', '{{%mqtt_will_message}}', 'client_id', true);
        $this->createIndex('idx_will_topic', '{{%mqtt_will_message}}', 'will_topic');

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_will_message}}');

        return true;
    }
}
