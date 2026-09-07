<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_message_trace}}`.
 *
 * Full-lifecycle tracing for a message: every meaningful event from the moment
 * a PUBLISH is received until it is acknowledged/delivered to each subscriber is
 * appended as a row. This gives an end-to-end view of a message's path through
 * the broker (ingest -> store -> route -> per-subscriber deliver -> ack stages ->
 * offline buffering -> final delivery).
 *
 * trace_type values (extend as needed):
 *   1: publish_received   - PUBLISH arrived at the broker (up leg)
 *   2: stored             - persisted to mqtt_message
 *   3: retained           - stored as retained message
 *   4: offline_buffered   - buffered as offline message (subscriber offline)
 *   5: delivered          - forwarded to a subscriber (down leg)
 *   6: puback             - QoS1 PUBACK received from subscriber
 *   7: pubrec             - QoS2 PUBREC received from subscriber
 *   8: pubrel             - QoS2 PUBREL received from subscriber / sent to subscriber
 *   9: pubcomp            - QoS2 PUBCOMP received from subscriber
 *  10: ack_completed      - acknowledgement terminal state reached
 */
class m260801_090001_create_mqtt_message_trace_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_message_trace}}', [
            'id' => $this->bigPrimaryKey()->comment('primary key'),

            // The traced message.
            'mqtt_message_id' => $this->bigInteger()->notNull()->comment('related mqtt_message.id'),

            // Optional link to the per-receiver acknowledgement row (QoS 1/2).
            'mqtt_message_ack_id' => $this->bigInteger()->null()->comment('related mqtt_message_ack.id'),

            // 1: up (client -> broker), 2: down (broker -> subscriber).
            'direction' => $this->smallInteger()->notNull()->defaultValue(0)->comment('1: up, 2: down'),

            // Event type, see class docblock for the enumerated values.
            'trace_type' => $this->smallInteger()->notNull()->comment('trace event type'),

            // The client involved in this step (sender on up, receiver on down).
            'client_id' => $this->string(128)->null()->comment('client id related to this trace step'),

            // MQTT packet identifier, when the step is tied to a packet.
            'packet_id' => $this->integer()->null()->comment('MQTT packet identifier'),

            // Free-form note / detail for the step (e.g. failure reason).
            'detail' => $this->string(512)->null()->comment('trace detail'),

            'created_at' => $this->dateTime(6)->null()->comment('event time'),
        ]);

        $this->createIndex('idx_message_id', '{{%mqtt_message_trace}}', 'mqtt_message_id');
        $this->createIndex('idx_ack_id', '{{%mqtt_message_trace}}', 'mqtt_message_ack_id');
        $this->createIndex('idx_trace_type', '{{%mqtt_message_trace}}', 'trace_type');
        $this->createIndex('idx_client_id', '{{%mqtt_message_trace}}', 'client_id');
        $this->createIndex('idx_created_at', '{{%mqtt_message_trace}}', 'created_at');

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_message_trace}}');

        return true;
    }
}
