<?php

namespace App\Models\Extension;

use Carbon\Carbon;
use Yew\Framework\Behaviors\AttributeTypecastBehavior;
use Yew\Framework\Behaviors\TimestampBehavior;
use Yew\Framework\Db\BaseActiveRecord;

class TopicSubscriptions extends \App\Models\TopicSubscriptions
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
                // This table stores UNIX timestamps as INT columns
                'value' => function () {
                    return Carbon::now()->timestamp;
                },
            ],
            'typecast' => [
                'class' => AttributeTypecastBehavior::class,
                'attributeTypes' => [
                    // Primary key
                    'id' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // Owning client uid
                    'uid' => AttributeTypecastBehavior::TYPE_INTEGER,
                    // Subscribed topic filter
                    'topic' => AttributeTypecastBehavior::TYPE_STRING,
                    // UNIX timestamp columns kept as integers
                    'created_at' => AttributeTypecastBehavior::TYPE_INTEGER,
                    'updated_at' => AttributeTypecastBehavior::TYPE_INTEGER,
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
