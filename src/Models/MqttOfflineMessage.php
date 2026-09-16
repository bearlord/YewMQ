<?php

namespace App\Models;

use Yew\Yew;

/**
 * This is the model class for table "{{%mqtt_offline_message}}".
 *
 * @property int $id Primary key
 * @property string $client_id MQTT client identifier
 * @property string $topic Offline message topic
 * @property resource $payload Offline message payload
 * @property int $qos Offline message QoS level (0, 1, 2)
 * @property int $delivered Delivery status: 0 = not delivered, 1 = delivered
 * @property string|null $delivered_at Delivery time. Null if not yet delivered
 * @property string|null $created_at Record creation time
 * @property string|null $updated_at Record update time
 */
class MqttOfflineMessage extends \Yew\Framework\Db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%mqtt_offline_message}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['client_id', 'topic', 'payload'], 'required'],
            [['payload'], 'string'],
            [['qos', 'delivered', 'packet_id'], 'integer'],
            [['delivered_at', 'created_at', 'updated_at'], 'safe'],
            [['client_id'], 'string', 'max' => 128],
            [['topic'], 'string', 'max' => 240],
        ];
    }

    /**
     * @return array
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'client_id' => 'Client ID',
            'topic' => 'Topic',
            'payload' => 'Payload',
            'qos' => 'Qos',
            'delivered' => 'Delivered',
            'delivered_at' => 'Delivered At',
            'packet_id' => 'Packet ID',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }
}
