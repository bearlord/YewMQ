<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_message_ack}}`.
 *
 * Tracks the QoS 1 / QoS 2 acknowledgement state of a message for each
 * receiver. A single published message may be delivered to many subscribers,
 * so one mqtt_message row can produce several mqtt_message_ack rows (one per
 * receiver + direction pair).
 *
 * MQTT acknowledgement flow:
 *  - QoS 1: PUBLISH -> PUBACK
 *  - QoS 2: PUBLISH -> PUBREC -> PUBREL -> PUBCOMP
 */
class m260801_090000_create_mqtt_message_ack_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_message_ack}}', [
            'id' => $this->bigPrimaryKey()->comment('primary key'),

            // The message this acknowledgement belongs to.
            'mqtt_message_id' => $this->bigInteger()->notNull()->comment('related mqtt_message.id'),

            // 1: up (client -> broker), 2: down (broker -> subscriber).
            // For QoS acknowledgement we mostly care about the down (broker -> subscriber) leg.
            'direction' => $this->smallInteger()->notNull()->defaultValue(2)->comment('1: up, 2: down'),

            // The subscriber client that must acknowledge the message (down leg).
            'receiver_id' => $this->string(128)->null()->comment('receiver client id'),

            // MQTT packet identifier used on the wire for this leg.
            'packet_id' => $this->integer()->null()->comment('MQTT packet identifier'),

            // Current acknowledgement stage:
            //  1: published (PUBLISH sent, awaiting PUBACK / PUBREC)
            //  2: pubrec    (QoS2 PUBREC received, PUBREL sent, awaiting PUBCOMP)
            //  3: completed (PUBACK or PUBCOMP received)
            'stage' => $this->smallInteger()->notNull()->defaultValue(1)->comment('1: published, 2: pubrec, 3: completed'),

            // 0: not yet acknowledged, 1: acknowledged (terminal).
            'ack_status' => $this->smallInteger()->notNull()->defaultValue(0)->comment('0: pending, 1: acked'),

            // QoS level of the message (1 or 2).
            'qos' => $this->smallInteger()->notNull()->defaultValue(1)->comment('qos 1 or 2'),

            // Timestamps for each leg of the flow.
            'published_at' => $this->dateTime(6)->null()->comment('time PUBLISH was sent'),
            'pubrec_at' => $this->dateTime(6)->null()->comment('time PUBREC was received (QoS2)'),
            'pubrel_at' => $this->dateTime(6)->null()->comment('time PUBREL was sent (QoS2)'),
            'completed_at' => $this->dateTime(6)->null()->comment('time PUBACK/PUBCOMP was received'),

            'created_at' => $this->dateTime(6)->null()->comment('created at'),
            'updated_at' => $this->dateTime(6)->null()->comment('updated at'),
        ]);

        // A message + receiver + direction pair is unique for the in-flight leg.
        $this->createIndex('uk_message_receiver_direction', '{{%mqtt_message_ack}}', ['mqtt_message_id', 'receiver_id', 'direction'], true);
        $this->createIndex('idx_receiver_id', '{{%mqtt_message_ack}}', 'receiver_id');
        $this->createIndex('idx_ack_status', '{{%mqtt_message_ack}}', 'ack_status');

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_message_ack}}');

        return true;
    }
}
