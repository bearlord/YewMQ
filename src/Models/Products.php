<?php

namespace App\Models;

use Yew\Yew;

/**
 * This is the model class for table "{{%products}}".
 *
 * @property int $id
 */
class Products extends \Yew\Framework\Db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%products}}';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
        ];
    }

    /**
     * @return ProductsQuery the active query used by this AR class.
     */
    public static function find(): \Yew\Framework\Db\ActiveQuery
    {
        return new ProductsQuery(get_called_class());
    }
}
