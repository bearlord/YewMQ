<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_session}}`.
 */
class m260727_075533_create_mqtt_session_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_session}}', [
            'id' => $this->bigPrimaryKey()->comment('Primary key'),

            'client_id' => $this->string(128)->notNull()
                ->comment('MQTT client identifier'),

            'clean_start' => $this->tinyInteger(1)->null()
                ->comment('MQTT v5 clean start flag (1: clean session, 0: resume session)'),

            'session_expiry' => $this->integer()->unsigned()->null()
                ->comment('Session expiry interval in seconds (0 = never expire)'),

            'will_id' => $this->bigInteger()->unsigned()->null()
                ->comment('Reference to mqtt_will_messages.id'),

            'created_at' => $this->dateTime(6)->null()
                ->comment('Session creation time'),
        ]);

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_session}}');

        return true;
    }
}
