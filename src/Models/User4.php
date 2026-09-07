<?php

namespace App\Models;

use Yew\Yew;

/**
 * This is the model class for table "{{%user}}".
 *
 * @property int $user_id
 * @property int $user_group_id
 * @property int $store_id
 * @property string $username
 * @property string $password
 * @property string|null $salt
 * @property string $fullname
 * @property string|null $email
 * @property string|null $image
 * @property string|null $code
 * @property string|null $ip
 * @property int|null $status
 * @property string|null $date_added
 */
class User4 extends \Yew\Framework\Db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%user4}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['user_group_id', 'store_id', 'status'], 'integer'],
            [['username', 'password', 'fullname'], 'required'],
            [['date_added'], 'safe'],
            [['username'], 'string', 'max' => 20],
            [['password', 'code', 'ip'], 'string', 'max' => 40],
            [['salt'], 'string', 'max' => 9],
            [['fullname'], 'string', 'max' => 32],
            [['email'], 'string', 'max' => 96],
            [['image'], 'string', 'max' => 255],
            [['store_id', 'username'], 'unique', 'targetAttribute' => ['store_id', 'username']],
        ];
    }

    /**
     * @return array
     */
    public function attributeLabels(): array
    {
        return [
            'user_id' => 'User ID',
            'user_group_id' => 'User Group ID',
            'store_id' => 'Store ID',
            'username' => 'Username',
            'password' => 'Password',
            'salt' => 'Salt',
            'fullname' => 'Fullname',
            'email' => 'Email',
            'image' => 'Image',
            'code' => 'Code',
            'ip' => 'Ip',
            'status' => 'Status',
            'date_added' => 'Date Added',
        ];
    }
}
