<?php

namespace App\Models;

use Yew\Yew;

/**
 * This is the model class for table "{{%mqtt_user}}".
 *
 * @property int $id Primary key
 * @property string $user_name MQTT login username
 * @property string $password_hash Hashed password
 * @property int $is_active Account status: 1 = active, 0 = disabled
 * @property string $created_at Record creation time
 * @property string $updated_at Record update time
 */
class MqttUser extends \Yew\Framework\Db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%mqtt_user}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['user_name', 'password_hash', 'created_at', 'updated_at'], 'required'],
            [['is_active'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
            [['user_name'], 'string', 'max' => 64],
            [['password_hash'], 'string', 'max' => 240],
        ];
    }

    /**
     * @return array
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'user_name' => 'User Name',
            'password_hash' => 'Password Hash',
            'is_active' => 'Is Active',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }
}
