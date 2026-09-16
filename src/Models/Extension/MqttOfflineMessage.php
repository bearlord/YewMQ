<?php

namespace App\Models\Extension;

use Carbon\Carbon;
use Yew\Framework\Behaviors\AttributeTypecastBehavior;
use Yew\Framework\Behaviors\TimestampBehavior;
use Yew\Framework\Db\BaseActiveRecord;

class MqttOfflineMessage extends \App\Models\MqttOfflineMessage
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
                    // Client identifier and topic
                    'client_id' => AttributeTypecastBehavior::TYPE_STRING,
                    'topic' => AttributeTypecastBehavior::TYPE_STRING,
                    // Payload (binary) kept as string
                    'payload' => AttributeTypecastBehavior::TYPE_STRING,
                    // Packet id assigned on delivery (links ack back to this row)
                    'packet_id' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // QoS level
                    'qos' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // Delivery status
                    'delivered' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // DATETIME(6) timestamps kept as strings
                    'delivered_at' => AttributeTypecastBehavior::TYPE_STRING,
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
