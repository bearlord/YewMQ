<?php

namespace App\Models;

use Yew\Yew;

/**
 * This is the model class for table "{{%mqtt_message_trace}}".
 *
 * @property int $id primary key
 * @property int $mqtt_message_id related mqtt_message.id
 * @property int|null $mqtt_message_ack_id related mqtt_message_ack.id
 * @property int $direction 1: up, 2: down
 * @property int $trace_type trace event type
 * @property string|null $client_id client id related to this trace step
 * @property int|null $packet_id MQTT packet identifier
 * @property string|null $detail trace detail
 * @property string|null $created_at event time
 */
class MqttMessageTrace extends \Yew\Framework\Db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%mqtt_message_trace}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['mqtt_message_id', 'trace_type'], 'required'],
            [['mqtt_message_id', 'mqtt_message_ack_id', 'direction', 'trace_type', 'packet_id'], 'integer'],
            [['created_at'], 'safe'],
            [['client_id'], 'string', 'max' => 128],
            [['detail'], 'string', 'max' => 512],
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
            'mqtt_message_ack_id' => 'Mqtt Message Ack ID',
            'direction' => 'Direction',
            'trace_type' => 'Trace Type',
            'client_id' => 'Client ID',
            'packet_id' => 'Packet ID',
            'detail' => 'Detail',
            'created_at' => 'Created At',
        ];
    }
}
