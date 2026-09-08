<?php

namespace App\Models;

use Yew\Yew;

/**
 * This is the model class for table "{{%topic_subscriptions}}".
 *
 * @property int $id
 * @property int $uid
 * @property string $topic
 * @property int|null $created_at
 * @property int|null $updated_at
 */
class TopicSubscriptions extends \Yew\Framework\Db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%topic_subscriptions}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['uid', 'topic'], 'required'],
            [['uid', 'created_at', 'updated_at'], 'integer'],
            [['topic'], 'string', 'max' => 240],
        ];
    }

    /**
     * @return array
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'uid' => 'Uid',
            'topic' => 'Topic',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }
}
