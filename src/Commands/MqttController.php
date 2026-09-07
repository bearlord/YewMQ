<?php

namespace App\Commands;


use Carbon\Carbon;
use Swoole\Server;
use Swoole\Timer;
use Yew\Plugins\MQTT\Client\Config\ClientConfig;
use Yew\Plugins\MQTT\Protocol\ProtocolInterface;

class MqttController extends \Yew\Framework\Console\Controller
{

    public function actionReceive()
    {

        error_reporting(E_ERROR);
        ini_set('display_errors', 1);

        $host = 'broker.emqx.io';
        $port = 1883;
        $clientId = 'mqttx_1a5d8865000';

        $topics = [
            'device/notice/#' => [
                'qos' => 0,
            ],
        ];


        $clientConfig = new ClientConfig();
        $clientConfig->setClientId($clientId);
        $clientConfig->setProtocolLevel(ProtocolInterface::MQTT_PROTOCOL_LEVEL_3_1_1);

        $mqttClient = new \Yew\Plugins\MQTT\Client\Client($host, $port, $clientConfig, 2);
        $mqttClient->connect();
        $mqttClient->subscribe($topics);

        Timer::tick(1000 * 5, function () use ($mqttClient, $topics) {
            do {
                $message = $mqttClient->recv();
                printf("%s, %s\n", (new Carbon())->toDateTimeString('millisecond'), json_encode($message, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

            } while (!empty($message) && is_array($message));
        });
    }
}