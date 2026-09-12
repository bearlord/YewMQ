<?php

namespace App\Models;

use Yew\Framework\Db\ActiveRecord;

/**
 * This is the model class for table "{{%mqtt_rule_stat}}".
 *
 * @property int $rule_id mqtt_rule.id
 * @property int $hits filter matched count
 * @property int $ok actions succeeded count
 * @property int $fail actions failed count
 * @property string|null $created_at created time
 * @property string|null $updated_at updated time
 */
class MqttRuleStat extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%mqtt_rule_stat}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['rule_id'], 'required'],
            [['rule_id', 'hits', 'ok', 'fail'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
        ];
    }

    /**
     * @return array
     */
    public function attributeLabels(): array
    {
        return [
            'rule_id' => 'Rule ID',
            'hits' => 'Hits',
            'ok' => 'OK',
            'fail' => 'Fail',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }
}
