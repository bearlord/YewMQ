<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_user}}`.
 */
class m260728_025937_create_mqtt_user_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_user}}', [
            // Primary key
            'id' => $this->bigPrimaryKey()->comment('Primary key'),

            // MQTT login username (unique per client)
            'user_name' => $this->string(64)->notNull()->comment('MQTT login username'),

            // Hashed password for authentication
            'password_hash' => $this->string(240)->notNull()->comment('Hashed password'),

            // Account status: 1 = active, 0 = disabled
            'is_active' => $this->tinyInteger(1)->notNull()->defaultValue(1)->comment('Account status: 1 = active, 0 = disabled'),

            // Record creation timestamp
            'created_at' => $this->dateTime(6)->notNull()->comment('Record creation time'),

            // Record update timestamp
            'updated_at' => $this->dateTime(6)->notNull()->comment('Record update time'),
        ]);

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_user}}');

        return true;
    }
}
