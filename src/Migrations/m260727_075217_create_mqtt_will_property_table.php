<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_will_property}}`.
 */
class m260727_075217_create_mqtt_will_property_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_will_property}}', [
            'id' => $this->bigPrimaryKey()->comment('Primary key'),

            'will_id' => $this->bigInteger()->unsigned()->notNull()
                ->comment('Reference to mqtt_will_messages.id'),

            'name' => $this->string(128)->notNull()
                ->comment('User property name'),

            'value' => $this->string(512)->null()
                ->comment('User property value'),

            'created_at' => $this->dateTime(6)->notNull()
                ->defaultExpression('CURRENT_TIMESTAMP(6)')
                ->comment('Record creation time'),
        ]);

        // Indexes
        $this->createIndex(
            'idx_will_id',
            '{{%mqtt_will_property}}',
            'will_id'
        );

        $this->createIndex(
            'idx_name',
            '{{%mqtt_will_property}}',
            'name'
        );

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_will_property}}');

        return true;
    }
}
