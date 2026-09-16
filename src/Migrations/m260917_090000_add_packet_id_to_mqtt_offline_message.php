<?php

use Yew\Framework\Db\Migration;

/**
 * Adds a `packet_id` column to {{%mqtt_offline_message}} so a buffered message
 * that is replayed to a (re)subscribed client can be linked back to its
 * mqtt_message_ack row. The QoS 1/2 handshake for the replayed message is then
 * finalized by the normal ack-completion path, which clears the buffered row.
 */
class m260917_090000_add_packet_id_to_mqtt_offline_message extends Migration
{
    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%mqtt_offline_message}}', 'packet_id', $this->integer()->null()->comment(
            'Down-leg MQTT packet id assigned when the buffered message is delivered (links the ack record back to this row)'
        ));

        $this->createIndex('idx_client_packet', '{{%mqtt_offline_message}}', ['client_id', 'packet_id']);

        return true;
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropIndex('idx_client_packet', '{{%mqtt_offline_message}}');
        $this->dropColumn('{{%mqtt_offline_message}}', 'packet_id');

        return true;
    }
}
