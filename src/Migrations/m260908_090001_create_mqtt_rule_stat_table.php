<?php

use Yew\Framework\Db\Migration;

/**
 * Persisted rule-engine counters.
 *
 * The console `mqtt-rule/stats` command runs in a separate (short-lived)
 * process and cannot see the in-memory counters of the long-running broker
 * workers. Each worker therefore merges its in-memory counters into this
 * table on every probe tick (~5s), so the stats command reads shared state.
 */
class m260908_090001_create_mqtt_rule_stat_table extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%mqtt_rule_stat}}', [
            'rule_id' => $this->bigPrimaryKey()->comment('mqtt_rule.id'),
            'hits' => $this->bigInteger()->notNull()->defaultValue(0)->comment('filter matched count'),
            'ok' => $this->bigInteger()->notNull()->defaultValue(0)->comment('actions succeeded count'),
            'fail' => $this->bigInteger()->notNull()->defaultValue(0)->comment('actions failed count'),
            'created_at' => $this->dateTime(6)->null()->comment('created time'),
            'updated_at' => $this->dateTime(6)->null()->comment('updated time'),
        ]);

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTable('{{%mqtt_rule_stat}}');

        return true;
    }
}
