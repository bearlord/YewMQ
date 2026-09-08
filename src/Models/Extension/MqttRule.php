<?php

namespace App\Models\Extension;

use Carbon\Carbon;
use Yew\Framework\Behaviors\AttributeTypecastBehavior;
use Yew\Framework\Behaviors\TimestampBehavior;
use Yew\Framework\Db\BaseActiveRecord;

class MqttRule extends \App\Models\MqttRule
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
                    // Enabled flag (0/1)
                    'enabled' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // Execution priority (ascending)
                    'priority' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // Strings
                    'name' => AttributeTypecastBehavior::TYPE_STRING,
                    'source' => AttributeTypecastBehavior::TYPE_STRING,
                    'filter' => AttributeTypecastBehavior::TYPE_STRING,
                    'actions' => AttributeTypecastBehavior::TYPE_STRING,
                    // DATETIME(6) timestamps kept as strings
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

    /**
     * Decode the JSON action list into an array.
     *
     * @return array
     */
    public function getActionsDecoded(): array
    {
        $decoded = json_decode($this->actions, true);

        return is_array($decoded) ? $decoded : [];
    }
}
