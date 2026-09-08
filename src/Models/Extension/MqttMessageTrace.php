<?php

namespace App\Models\Extension;

use Carbon\Carbon;
use Yew\Framework\Behaviors\AttributeTypecastBehavior;
use Yew\Framework\Behaviors\TimestampBehavior;
use Yew\Framework\Db\BaseActiveRecord;

class MqttMessageTrace extends \App\Models\MqttMessageTrace
{
    public function behaviors(): array
    {
        return [
            'timestamp' => [
                'class' => TimestampBehavior::class,
                'attributes' => [
                    BaseActiveRecord::EVENT_BEFORE_INSERT => ['created_at'],
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
                    // Related message / ack identifiers
                    'mqtt_message_id' => AttributeTypecastBehavior::TYPE_INTEGER,
                    'mqtt_message_ack_id' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // Direction (1: up, 2: down) and trace event type
                    'direction' => AttributeTypecastBehavior::TYPE_INTEGER,
                    'trace_type' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // MQTT packet identifier
                    'packet_id' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // Client id and trace detail
                    'client_id' => AttributeTypecastBehavior::TYPE_STRING,
                    'detail' => AttributeTypecastBehavior::TYPE_STRING,
                    // DATETIME(6) timestamp kept as string
                    'created_at' => AttributeTypecastBehavior::TYPE_STRING,
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
