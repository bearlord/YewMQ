<?php
namespace App\Task\QuanWuJingShui;

use App\Task\QuanWuJingShui\Logic\MqttClientLogic;
use Yew\Plugins\AnnotationsScan\Annotation\Component;
use Yew\Plugins\Scheduled\Annotation\Scheduled;

/**
 * @Component()
 */
class MqttClientTask
{
    /**
     * //@Scheduled(cron="@minutely")
     * //@Scheduled(cron="*\/5 * * * * *")
     * @throws \Throwable
     */
    public function receiveMessage()
    {
        printf("receiveMessage\n");

        $clientId = 'mqttx_1a5d8865000';

        $topics = [
            'sample2' => [
                'qos' => 0,
            ],
        ];

        (new MqttClientLogic())->receiveMessage($clientId, $topics);
    }
}