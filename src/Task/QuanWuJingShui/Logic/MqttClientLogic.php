<?php

namespace App\Task\QuanWuJingShui\Logic;

use Yew\Plugins\MQTT\Config\ClientConfig;

class MqttClientLogic
{

    /**
     * @throws \Throwable
     */
    public function receiveMessage(string $clientId, array $topics)
    {

        //$mqttConnection = MqttConnectionLogic::getInstance($clientId)->getMqttClient();
        $mqttConnection = (new MqttConnectionLogic($clientId))->getMqttClient();
        if (!$mqttConnection) {
            throw new \RuntimeException("mqtt client not connected");
        }

        $mqttConnection->subscribe($topics);

        $messageRes = $mqttConnection->recv();

        var_dump($messageRes);
    }
}