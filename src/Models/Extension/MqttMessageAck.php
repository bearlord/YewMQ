<?php

namespace App\Models\Extension;

use Carbon\Carbon;
use Yew\Framework\Behaviors\AttributeTypecastBehavior;
use Yew\Framework\Behaviors\TimestampBehavior;
use Yew\Framework\Db\BaseActiveRecord;

class MqttMessageAck extends \App\Models\MqttMessageAck
{
    public function behaviors(): array
    {
        return [
            'timestamp' => [
                'class' => TimestampBehavior::class,
                'attributes' => [
                    BaseActiveRecord::EVENT_BEFORE_INSERT => ['created_at', 'updated_at'],
                    BaseActiveRecord::EVENT_BEFORE_UPDATE => ['updated_at'],
                ],
                // Generates a microsecond-precision timestamp matching the DATETIME(6) column, e.g. 2026-07-28 14:23:45.123456
                'value' => function () {
                    return Carbon::now()->format('Y-m-d H:i:s.u');
                },
            ],
            'typecast' => [
                'class' => AttributeTypecastBehavior::class,
                'attributeTypes' => [
                    // Primary key
                    'id' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // Related message
                    'mqtt_message_id' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // 1: up, 2: down
                    'direction' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // Receiver client identifier
                    'receiver_id' => AttributeTypecastBehavior::TYPE_STRING,
                    // MQTT packet identifier
                    'packet_id' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // Ack stage (1: published, 2: pubrec, 3: completed)
                    'stage' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // 0: pending, 1: acked
                    'ack_status' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // QoS level (1 or 2)
                    'qos' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // DATETIME(6) timestamps kept as strings
                    'published_at' => AttributeTypecastBehavior::TYPE_STRING,
                    'pubrec_at' => AttributeTypecastBehavior::TYPE_STRING,
                    'pubrel_at' => AttributeTypecastBehavior::TYPE_STRING,
                    'completed_at' => AttributeTypecastBehavior::TYPE_STRING,
                    'created_at' => AttributeTypecastBehavior::TYPE_STRING,
                    'updated_at' => AttributeTypecastBehavior::TYPE_STRING,
                ],
                // Typecast attributes after successful validation (convert request string inputs to proper types)
                'typecastAfterValidate' => true,
                // No need to typecast before save; the DB column type enforces the cast itself
                'typecastBeforeSave' => false,
                // Key: typecast after find so integers in JSON responses are emitted without quotes
                'typecastAfterFind' => true,
            ],
        ];
    }
}
