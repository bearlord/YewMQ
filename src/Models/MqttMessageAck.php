<?php

namespace App\Models;

/**
 * This is the model class for table "{{%mqtt_message_ack}}".
 *
 * Tracks the QoS 1 / QoS 2 acknowledgement state of a message for each
 * receiver. A single published message may be delivered to many subscribers,
 * so one mqtt_message row can produce several mqtt_message_ack rows (one per
 * receiver + direction pair).
 *
 * MQTT acknowledgement flow:
 *  - QoS 1: PUBLISH -> PUBACK
 *  - QoS 2: PUBLISH -> PUBREC -> PUBREL -> PUBCOMP
 *
 * @property int $id primary key
 * @property int $mqtt_message_id related mqtt_message.id
 * @property int $direction 1: up (client -> broker), 2: down (broker -> subscriber)
 * @property string|null $receiver_id receiver client id
 * @property int|null $packet_id MQTT packet identifier
 * @property int $stage 1: published, 2: pubrec, 3: completed
 * @property int $ack_status 0: pending, 1: acked
 * @property int $qos qos 1 or 2
 * @property string|null $published_at time PUBLISH was sent
 * @property string|null $pubrec_at time PUBREC was received (QoS2)
 * @property string|null $pubrel_at time PUBREL was sent (QoS2)
 * @property string|null $completed_at time PUBACK/PUBCOMP was received
 * @property string|null $created_at created at
 * @property string|null $updated_at updated at
 */
class MqttMessageAck extends \Yew\Framework\Db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%mqtt_message_ack}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['mqtt_message_id', 'direction', 'stage', 'ack_status', 'qos'], 'integer'],
            [['packet_id'], 'integer'],
            [['receiver_id'], 'string', 'max' => 128],
            [['published_at', 'pubrec_at', 'pubrel_at', 'completed_at', 'created_at', 'updated_at'], 'safe'],
        ];
    }

    /**
     * @return array
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'mqtt_message_id' => 'Mqtt Message ID',
            'direction' => 'Direction',
            'receiver_id' => 'Receiver ID',
            'packet_id' => 'Packet ID',
            'stage' => 'Stage',
            'ack_status' => 'Ack Status',
            'qos' => 'Qos',
            'published_at' => 'Published At',
            'pubrec_at' => 'Pubrec At',
            'pubrel_at' => 'Pubrel At',
            'completed_at' => 'Completed At',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }
}
