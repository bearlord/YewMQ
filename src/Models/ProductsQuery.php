<?php

namespace App\Models;

/**
 * This is the ActiveQuery class for [[Products]].
 *
 * @see Products
 */
class ProductsQuery extends \Yew\Framework\Db\ActiveQuery
{
    /*public function active()
    {
        return $this->andWhere('[[status]]=1');
    }*/

    /**
     * {@inheritdoc}
     * @return Products[]|array
     */
    public function all($db = null): array
    {
        return parent::all($db);
    }

    /**
     * {@inheritdoc}
     * @return Products|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
