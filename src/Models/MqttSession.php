<?php

namespace App\Models;

use Yew\Yew;

/**
 * This is the model class for table "{{%mqtt_session}}".
 *
 * @property int $id Primary key
 * @property string $client_id MQTT client identifier
 * @property int|null $clean_start MQTT v5 clean start flag (1: clean session, 0: resume session)
 * @property int|null $session_expiry Session expiry interval in seconds (0 = never expire)
 * @property int|null $will_id Reference to mqtt_will_messages.id
 * @property string|null $created_at Session creation time
 */
class MqttSession extends \Yew\Framework\Db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%mqtt_session}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['client_id'], 'required'],
            [['clean_start', 'session_expiry', 'will_id'], 'integer'],
            [['created_at'], 'safe'],
            [['client_id'], 'string', 'max' => 128],
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
            'clean_start' => 'Clean Start',
            'session_expiry' => 'Session Expiry',
            'will_id' => 'Will ID',
            'created_at' => 'Created At',
        ];
    }
}
