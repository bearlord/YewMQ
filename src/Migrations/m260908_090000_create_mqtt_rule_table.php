<?php

use Yew\Framework\Db\Migration;

/**
 * Handles the creation of table `{{%mqtt_rule}}`.
 *
 * Config-driven rule engine storage. A rule is purely data (no business logic
 * baked into code); the RuleEngine loads enabled rows and evaluates them
 * against MQTT events (e.g. $events/message_publish) at the PUBLISH entry point.
 *
 * Rule shape (consumed by RuleEngine):
 *   - source : which event triggers evaluation (default '$events/message_publish')
 *   - filter : boolean expression evaluated against the message context
 *              (topic, message, client_id, qos, ...). Empty = match all.
 *   - actions: JSON array of action descriptors executed when filter passes, e.g.
 *              [{"type":"republish","args":{"topic":"alert/x","payload":"${message}"}},
 *               {"type":"http","args":{"url":"https://hook/alert","method":"POST"}}]
 *
 * Adding / editing / disabling a row takes effect after the engine reloads its
 * in-memory cache (no broker restart required).
 */
class m260908_090000_create_mqtt_rule_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_rule}}', [
            'id' => $this->bigPrimaryKey()->comment('primary key'),

            // Human readable rule name shown in admin / logs.
            'name' => $this->string(128)->notNull()->comment('rule name'),

            // 0: disabled, 1: enabled. Engine skips disabled rules.
            'enabled' => $this->smallInteger()->notNull()->defaultValue(1)->comment('0: disabled, 1: enabled'),

            // Event source that triggers this rule. Default MQTT publish event.
            'source' => $this->string(64)->notNull()->defaultValue('$events/message_publish')->comment('event source, e.g. $events/message_publish'),

            // Boolean condition over the message context (expression-language style).
            // NULL / empty means "match everything".
            'filter' => $this->text()->null()->comment('match condition (expression)'),

            // Ordered list of action descriptors, JSON encoded.
            'actions' => $this->text()->notNull()->comment('action list (JSON)'),

            // Lower number runs first when multiple rules fire on the same event.
            'priority' => $this->integer()->notNull()->defaultValue(0)->comment('execution order, ascending'),

            'created_at' => $this->dateTime(6)->null()->comment('created time'),
            'updated_at' => $this->dateTime(6)->null()->comment('updated time'),
        ]);

        $this->createIndex('idx_enabled', '{{%mqtt_rule}}', 'enabled');
        $this->createIndex('idx_source', '{{%mqtt_rule}}', 'source');
        $this->createIndex('idx_priority', '{{%mqtt_rule}}', 'priority');

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_rule}}');

        return true;
    }
}
