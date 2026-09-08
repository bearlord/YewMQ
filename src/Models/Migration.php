<?php

namespace App\Models;

use Yew\Yew;

/**
 * This is the model class for table "{{%migration}}".
 *
 * @property string $version
 * @property int|null $apply_time
 */
class Migration extends \Yew\Framework\Db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%migration}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['version'], 'required'],
            [['apply_time'], 'integer'],
            [['version'], 'string', 'max' => 180],
            [['version'], 'unique'],
        ];
    }

    /**
     * @return array
     */
    public function attributeLabels(): array
    {
        return [
            'version' => 'Version',
            'apply_time' => 'Apply Time',
        ];
    }
}
