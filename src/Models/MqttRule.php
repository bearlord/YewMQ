<?php

namespace App\Models;

use Yew\Yew;

/**
 * This is the model class for table "{{%mqtt_rule}}".
 *
 * @property int $id primary key
 * @property string $name rule name
 * @property int $enabled 0: disabled, 1: enabled
 * @property string $source event source, e.g. $events/message_publish
 * @property string|null $filter match condition (expression)
 * @property string $actions action list (JSON)
 * @property int $priority execution order, ascending
 * @property string|null $created_at created time
 * @property string|null $updated_at updated time
 */
class MqttRule extends \Yew\Framework\Db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%mqtt_rule}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['name', 'actions'], 'required'],
            [['enabled', 'priority'], 'integer'],
            [['filter', 'actions'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
            [['name'], 'string', 'max' => 128],
            [['source'], 'string', 'max' => 64],
        ];
    }

    /**
     * @return array
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Name',
            'enabled' => 'Enabled',
            'source' => 'Source',
            'filter' => 'Filter',
            'actions' => 'Actions',
            'priority' => 'Priority',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }
}
