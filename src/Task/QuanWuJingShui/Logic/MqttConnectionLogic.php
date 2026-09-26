<?php

namespace App\Task\QuanWuJingShui\Logic;

use Yew\Plugins\MQTT\Config\ClientConfig;
use Yew\Plugins\MQTT\Protocol\ProtocolInterface;

class MqttConnectionLogic
{

    public static array $instances = [];

    protected ?\Yew\Plugins\MQTT\Client $mqttClient = null;

    public static function getInstance(string $clientId = null)
    {
        $clientId = $clientId ?? 'default';
        if (!isset(self::$instances[$clientId])) {
            self::$instances[$clientId] = new self($clientId);
        }

        return self::$instances[$clientId];
    }


    public function __construct(string $clientId)
    {
        $this->connect($clientId);
    }

    public function connect(string $clientId)
    {
        $host = 'broker.emqx.io';
        $port = 1883;

        $clientConfig = new ClientConfig();
        $clientConfig->setClientId($clientId);
        $clientConfig->setProtocolLevel(ProtocolInterface::MQTT_PROTOCOL_LEVEL_3_1_1);
        $clientConfig->setMaxAttempts(5);

        $this->mqttClient = new \Yew\Plugins\MQTT\Client($host, $port, $clientConfig, 2);


        //$this->mqttClient->connect();
    }


    public function getMqttClient(): ?\Yew\Plugins\MQTT\Client
    {
        return $this->mqttClient;
    }



}