<?php

namespace App\Modules\Mqtt\Services;

use Carbon\Carbon;
use Yew\Core\Server\Server;
use Yew\Mqtt\Message\Publish;
use Yew\Plugins\Pack\GetBoostSend;
use Yew\Plugins\Topic\GetTopic;
use Yew\Plugins\Uid\GetUid;

class MqttPublishService
{
    use GetUid;
    use GetTopic;
    use GetBoostSend;

    public const DIRECTION_UP = 1;

    public const DIRECTION_DOWN = 2;

    public function publishProcess(
        int    $protocolLevel,
        string $senderId,
        string $topic,
        string $message,
        int    $qos = 0,
        int    $retain = 0
    ): bool
    {
        $mqttMessageService = new MqttMessageService();
        $mqttMessageService->saveMessage([
            'direction' => self::DIRECTION_UP,
            'sender_id' => $senderId,
            'topic' => $topic,
            'payload' => $message,
            'qos' => $qos,
            'retain' => $retain,
            'published_time' => (new Carbon())->format('Y-m-d H:i:s.u')
        ]);

        if ($retain) {
            $mqttRetainMessageService = new MqttRetainedMessageService();
            $mqttRetainMessageService->saveRetainedMessage([
                'topic' => $topic,
                'payload' => $message,
                'qos' => $qos,
                'retain' => $retain,
            ]);
        }

        $publishMessage = (new Publish())
            ->setProtocolLevel($protocolLevel)
            ->setQos($qos)
            ->setTopic($topic)
            ->setMessage($message);

        $subscribers = $this->getSubscribers($topic);
        if (empty($subscribers)) {
            return true;
        }

        $mqttClientService = new MqttClientService();
        $mqttOfflineMessageService = new MqttOfflineMessageService();

        foreach ($subscribers as $uid) {
            $_fd = $this->getUidFd($uid);
            if (empty($_fd)) {
                continue;
            }

            $_clientId = $mqttClientService->getClientIdById($uid);

            if (!Server::$instance->isEstablished($_fd)) {
                if ($qos > 0) {
                    $mqttOfflineMessageService->saveOfflineMessage([
                        'client_id' => $_clientId,
                        'topic' => $topic,
                        'payload' => $message,
                        'qos' => $qos
                    ]);
                }
            } else {
                $mqttMessageService->saveMessage([
                    'direction' => self::DIRECTION_DOWN,
                    'sender_id' => $senderId,
                    'receiver_id' => $_clientId,
                    'topic' => $topic,
                    'payload' => $message,
                    'qos' => $qos,
                    'retain' => $retain,
                    'published_time' => (new Carbon())->format('Y-m-d H:i:s.u'),

                ]);
            }

        }


        $this->publish($topic, $publishMessage->getContents());

        return true;
    }
}