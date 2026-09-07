<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_acl}}`.
 */
class m260728_031927_create_mqtt_acl_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_acl}}', [
            'id' => $this->primaryKey(),

            // Foreign key linking to the MQTT user ID
            'user_id' => $this->integer()->notNull()->comment('Associated MQTT user ID'),

            // Optional: Restrict permissions to a specific Client ID. Null means no restriction.
            'client_id' => $this->string(128)->defaultValue(null)->comment('Bound Client ID. Null means no restriction'),

            // MQTT Topic. Supports standard MQTT wildcards like '+' (single-level) and '#' (multi-level)
            'topic' => $this->string(240)->notNull()->comment('MQTT Topic, supports + and # wildcards'),

            // Access Type: 1 = Subscribe (Read), 2 = Publish (Write), 3 = ReadWrite
            'access_type' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('Permission type: 1=Subscribe, 2=Publish, 3=ReadWrite'),

            // Record creation timestamp
            'created_at' => $this->dateTime()->defaultExpression('CURRENT_TIMESTAMP')->comment('Record creation time'),

            // Record update timestamp
            'updated_at' => $this->dateTime()->defaultExpression('CURRENT_TIMESTAMP')->comment('Record update time'),
        ]);

        // Create a composite index to accelerate ACL queries based on user and topic
        $this->createIndex('idx_mqtt_acl_user_topic', '{{%mqtt_acl}}', 'user_id');

        // Create an index for client_id to speed up device-specific permission lookups
        $this->createIndex('idx_mqtt_acl_client_id', '{{%mqtt_acl}}', 'client_id');

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_acl}}');

        return true;
    }
}
