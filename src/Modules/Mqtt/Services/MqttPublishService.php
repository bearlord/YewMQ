<?php

namespace App\Modules\Mqtt\Services;

use App\Models\Extension\MqttMessageAck;
use Carbon\Carbon;
use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Core\Server\Server;
use Yew\Mqtt\Message\Publish;
use Yew\Mqtt\Message\PubAck;
use Yew\Mqtt\Message\PubComp;
use Yew\Mqtt\Message\PubRec;
use Yew\Mqtt\Message\PubRel;
use Yew\Plugins\Mqtt\Connection\GetMqttConnection;
use Yew\Plugins\Pack\GetBoostSend;
use Yew\Plugins\Topic\GetTopic;
use Yew\Plugins\Uid\GetUid;

class MqttPublishService
{
    use GetUid;
    use GetTopic;
    use GetBoostSend;
    use GetLogger;
    use GetMqttConnection;

    public const DIRECTION_UP = 1;

    public const DIRECTION_DOWN = 2;

    /**
     * Broker-side MQTT packet identifier sequence for outbound QoS 1/2 messages.
     * Packet ids are 16-bit (1..65535) and reused once an in-flight message completes.
     *
     * @var int
     */
    private static int $packetIdSeq = 0;

    /**
     * Allocate the next outbound packet identifier (1..65535, wrapping).
     *
     * @return int
     */
    private function nextPacketId(): int
    {
        self::$packetIdSeq = self::$packetIdSeq % 65535 + 1;

        return self::$packetIdSeq;
    }

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
        $upMessage = $mqttMessageService->saveMessage([
            'direction' => self::DIRECTION_UP,
            'sender_id' => $senderId,
            'topic' => $topic,
            'payload' => $message,
            'qos' => $qos,
            'retain' => $retain,
            'published_time' => (new Carbon())->format('Y-m-d H:i:s.u')
        ]);
        $upMessageId = $upMessage->id;

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
        $now = (new Carbon())->format('Y-m-d H:i:s.u');

        foreach ($subscribers as $uid) {
            $_fd = $this->getUidFd($uid);
            if (empty($_fd)) {
                continue;
            }

            $_clientId = $mqttClientService->getClientIdById($uid);

            if (!Server::$instance->isEstablished($_fd)) {
                // Offline subscriber: queue the message for later delivery
                // (QoS > 0 only; QoS 0 is fire-and-forget).
                if ($qos > 0) {
                    $mqttOfflineMessageService->saveOfflineMessage([
                        'client_id' => $_clientId,
                        'topic' => $topic,
                        'payload' => $message,
                        'qos' => $qos
                    ]);
                }
                continue;
            }

            // QoS > 0 requires a per-receiver packet identifier so the
            // subscriber can correlate its PUBACK / PUBREC / PUBCOMP.
            $packetId = 0;
            if ($qos > 0) {
                $packetId = $this->nextPacketId();
                $publishMessage->setMessageId($packetId);
            }

            // Deliver the PUBLISH to this subscriber directly (unicast). This
            // lets every QoS > 0 receiver obtain its own unique packet id,
            // replacing the previous single broadcast packet shared by all.
            $this->autoBoostSend($_fd, $publishMessage->getContents());

            // Record the down-leg delivery.
            $mqttMessageService->saveMessage([
                'direction' => self::DIRECTION_DOWN,
                'sender_id' => $senderId,
                'receiver_id' => $_clientId,
                'topic' => $topic,
                'payload' => $message,
                'qos' => $qos,
                'retain' => $retain,
                'published_time' => $now,
            ]);

            // QoS > 0: track the in-flight acknowledgement so PUBACK / PUBREC
            // (and PUBCOMP) can finalize it via the mqtt_message_ack table.
            if ($qos > 0) {
                $ack = new MqttMessageAck();
                $ack->setAttributes([
                    'mqtt_message_id' => $upMessageId,
                    'direction' => self::DIRECTION_DOWN,
                    'receiver_id' => $_clientId,
                    'packet_id' => $packetId,
                    'qos' => $qos,
                    'stage' => 1,
                    'ack_status' => 0,
                    'published_at' => $now,
                ], false);
                $ack->save(false);
            }
        }

        return true;
    }

    /**
     * Acknowledge an inbound QoS 1 PUBLISH from a client with a PUBACK.
     *
     * @param int $fd Connection file descriptor of the publisher.
     * @param int $protocolLevel MQTT protocol version (3.1.1 / 5).
     * @param int $messageId Packet identifier of the PUBLISH being acknowledged.
     * @return void
     */
    public function pubAckProcess(int $fd, int $protocolLevel, int $messageId): void
    {
        $pubAck = (new PubAck())
            ->setProtocolLevel($protocolLevel)
            ->setMessageId($messageId);

        $this->autoBoostSend($fd, $pubAck->getContents());
    }

    /**
     * Acknowledge an inbound QoS 2 PUBLISH from a client with a PUBREC.
     *
     * After this the publisher sends PUBREL, which is answered by PUBCOMP
     * (see pubCompProcess) to complete the exactly-once handshake.
     *
     * @param int $fd Connection file descriptor of the publisher.
     * @param int $protocolLevel MQTT protocol version (3.1.1 / 5).
     * @param int $messageId Packet identifier of the PUBLISH being acknowledged.
     * @return void
     */
    public function pubRecProcess(int $fd, int $protocolLevel, int $messageId): void
    {
        $pubRec = (new PubRec())
            ->setProtocolLevel($protocolLevel)
            ->setMessageId($messageId);

        $this->autoBoostSend($fd, $pubRec->getContents());
    }

    /**
     * Reply to a subscriber's PUBREC (outbound QoS 2) with a PUBREL.
     *
     * Used when the broker is the publisher and a subscriber has acknowledged
     * the delivered QoS 2 message. The subscriber then completes the handshake
     * with PUBCOMP (see pubCompProcess).
     *
     * @param int $fd Connection file descriptor of the subscriber.
     * @param int $protocolLevel MQTT protocol version (3.1.1 / 5).
     * @param int $messageId Packet identifier of the in-flight QoS 2 message.
     * @return void
     */
    public function pubRelProcess(int $fd, int $protocolLevel, int $messageId): void
    {
        $pubRel = (new PubRel())
            ->setProtocolLevel($protocolLevel)
            ->setMessageId($messageId);

        $this->autoBoostSend($fd, $pubRel->getContents());
    }

    /**
     * Acknowledge an inbound QoS 2 PUBREL (or a subscriber's outbound PUBCOMP)
     * with a PUBCOMP, completing the exactly-once delivery handshake.
     *
     * @param int $fd Connection file descriptor of the peer.
     * @param int $protocolLevel MQTT protocol version (3.1.1 / 5).
     * @param int $messageId Packet identifier of the in-flight QoS 2 message.
     * @return void
     */
    public function pubCompProcess(int $fd, int $protocolLevel, int $messageId): void
    {
        $pubComp = (new PubComp())
            ->setProtocolLevel($protocolLevel)
            ->setMessageId($messageId);

        $this->autoBoostSend($fd, $pubComp->getContents());
    }

    /**
     * Complete an outbound QoS 1 / QoS 2 handshake ack record.
     *
     * For QoS 1 the terminal packet is PUBACK; for QoS 2 it is PUBCOMP. Both are
     * received from the subscriber (the broker is the sender in the outbound
     * flow), and neither requires a reply. We only mark the in-flight ack record
     * (down leg, by receiver + packet id) as completed.
     *
     * @param string $receiverId Subscriber client id that sent the PUBACK/PUBCOMP.
     * @param int $packetId MQTT packet identifier of the completed QoS message.
     * @return void
     */
    public function completeAckProcess(string $receiverId, int $packetId): void
    {
        $ack = MqttMessageAck::findOne([
            'receiver_id' => $receiverId,
            'packet_id' => $packetId,
            'direction' => self::DIRECTION_DOWN,
        ]);

        // No matching in-flight ack record: nothing to finalize.
        if ($ack === null) {
            return;
        }

        $ack->setAttributes([
            'stage' => 3,
            'ack_status' => 1,
            'completed_at' => (new Carbon())->format('Y-m-d H:i:s.u'),
        ], false);
        $ack->save(false);
    }

    /**
     * Advance an outbound QoS 2 ack record to the PUBREC stage.
     *
     * Called when a subscriber acknowledges the broker's PUBLISH with PUBREC
     * (before the broker replies with PUBREL). Records the packet as "pubrec"
     * (stage 2) with the receive timestamp.
     *
     * @param string $receiverId Subscriber client id that sent the PUBREC.
     * @param int $packetId MQTT packet identifier of the in-flight QoS 2 message.
     * @return void
     */
    public function markPubrecProcess(string $receiverId, int $packetId): void
    {
        $ack = MqttMessageAck::findOne([
            'receiver_id' => $receiverId,
            'packet_id' => $packetId,
            'direction' => self::DIRECTION_DOWN,
        ]);

        // No matching in-flight ack record: nothing to advance.
        if ($ack === null) {
            return;
        }

        $ack->setAttributes([
            'stage' => 2,
            'pubrec_at' => (new Carbon())->format('Y-m-d H:i:s.u'),
        ], false);
        $ack->save(false);
    }

    /**
     * Handle an inbound PUBLISH from a client (the client is the publisher).
     *
     * Refreshes the keepalive watchdog, enforces entry-level rejects (missing
     * topic/payload, missing QoS 1/2 packet id), evaluates the
     * $events/message_publish rule engine, and either drops the message (still
     * acking the publisher per QoS) or persists + forwards it. The publisher is
     * acknowledged with PUBACK (QoS 1) / PUBREC (QoS 2) as required.
     *
     * Only the controller's clientData object is passed in; the service resolves
     * the fd / protocol level / clientId / payload from it.
     *
     * @param object $clientData ClientData (has getFd/getData).
     * @return void
     */
    public function inboundPublishProcess(object $clientData): void
    {
        $fd = $clientData->getFd();
        $payload = $clientData->getData();
        $protocolLevel = $payload['protocol_level'] ?? null;
        $clientId = $payload['client_id'] ?? null;
        $data = $payload['data'] ?? [];

        // Any inbound packet proves liveness; refresh the keepalive watchdog.
        $this->touchActivity($fd);

        $qos = $data['qos'] ?? 0;
        $retain = $data['retain'] ?? 0;
        $topic = $data['topic'] ?? null;
        $message = $data['message'] ?? null;

        // A PUBLISH without a topic or payload is malformed; close the connection.
        if (empty($topic) || empty($message)) {
            Server::$instance->closeFd($fd);
            return;
        }

        // Packet identifier used for QoS 1 / QoS 2 acknowledgement.
        $messageId = $data['message_id'] ?? null;

        // A QoS 1/2 PUBLISH must carry a packet identifier; reject it otherwise.
        if ($qos > 0 && empty($messageId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        // Resolve session metadata once (single IPC) for the rule event.
        $sess = $this->getFdSessionMulti($fd) ?? [];

        // Rule engine: evaluate configured rules against this inbound publish.
        // A `drop` action returns true; we still ack the publisher per QoS but
        // do NOT store/forward the message. Any failure must NOT break publish.
        try {
            $dropped = RuleEngine::instance()->onPublish([
                'protocol_level' => $protocolLevel,
                'client_id'      => $clientId,
                'username'       => $sess['username'] ?? '',
                'peerhost'       => $sess['peerhost'] ?? '',
                'keep_alive'     => $sess['keep_alive'] ?? 0,
                'mountpoint'     => $sess['mountpoint'] ?? '',
                'topic'          => $topic,
                'message'        => $message,
                'qos'            => $qos,
                'retain'         => $retain,
                'source'         => '$events/message_publish',
            ]);
        } catch (\Throwable $e) {
            $this->warn('RuleEngine onPublish failed: ' . $e->getMessage());
            $dropped = false;
        }

        if ($dropped) {
            // Message discarded by a rule: acknowledge the publisher (QoS dependent)
            // but skip persistence and downstream forwarding.
            if ($qos == 1) {
                $this->pubAckProcess($fd, $protocolLevel, $messageId);
            } elseif ($qos == 2) {
                $this->pubRecProcess($fd, $protocolLevel, $messageId);
            }
            return;
        }

        $this->publishProcess($protocolLevel, $clientId, $topic, $message, $qos, $retain);

        // Acknowledge the publisher according to the QoS level.
        if ($qos == 1) {
            $this->pubAckProcess($fd, $protocolLevel, $messageId);
        } elseif ($qos == 2) {
            $this->pubRecProcess($fd, $protocolLevel, $messageId);
        }
    }

    /**
     * Handle a subscriber PUBREC (outbound QoS 2 flow): mark the in-flight ack
     * record at stage 2 and reply with a PUBREL carrying the same packet id.
     *
     * Only the controller's clientData object is passed in; the service resolves
     * the fd / protocol level / clientId / packet id from it.
     *
     * @param object $clientData ClientData (has getFd/getData).
     * @return void
     */
    public function pubrecInboundProcess(object $clientData): void
    {
        $fd = $clientData->getFd();
        $payload = $clientData->getData();
        $protocolLevel = $payload['protocol_level'] ?? null;
        $clientId = $payload['client_id'] ?? null;
        $messageId = $payload['data']['message_id'] ?? null;

        $this->touchActivity($fd);

        if (empty($messageId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        $this->markPubrecProcess($clientId, $messageId);
        $this->pubRelProcess($fd, $protocolLevel, $messageId);
    }

    /**
     * Handle a client PUBREL (inbound QoS 2 flow): reply with a PUBCOMP to free
     * the publisher's in-flight state.
     *
     * Only the controller's clientData object is passed in; the service resolves
     * the fd / protocol level / packet id from it.
     *
     * @param object $clientData ClientData (has getFd/getData).
     * @return void
     */
    public function pubrelInboundProcess(object $clientData): void
    {
        $fd = $clientData->getFd();
        $payload = $clientData->getData();
        $protocolLevel = $payload['protocol_level'] ?? null;
        $messageId = $payload['data']['message_id'] ?? null;

        $this->touchActivity($fd);

        if (empty($messageId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        $this->pubCompProcess($fd, $protocolLevel, $messageId);
    }

    /**
     * Handle a subscriber PUBCOMP (terminal step of the outbound QoS 2 flow):
     * finalize the in-flight ack record. No reply is sent.
     *
     * Only the controller's clientData object is passed in; the service resolves
     * the fd / clientId / packet id from it.
     *
     * @param object $clientData ClientData (has getFd/getData).
     * @return void
     */
    public function pubcompInboundProcess(object $clientData): void
    {
        $fd = $clientData->getFd();
        $payload = $clientData->getData();
        $clientId = $payload['client_id'] ?? null;
        $messageId = $payload['data']['message_id'] ?? null;

        $this->touchActivity($fd);

        if (empty($messageId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        $this->completeAckProcess($clientId, $messageId);
    }

    /**
     * Handle a subscriber PUBACK (terminal step of the outbound QoS 1 flow):
     * finalize the in-flight ack record. No reply is sent.
     *
     * Only the controller's clientData object is passed in; the service resolves
     * the fd / clientId / packet id from it.
     *
     * @param object $clientData ClientData (has getFd/getData).
     * @return void
     */
    public function pubackInboundProcess(object $clientData): void
    {
        $fd = $clientData->getFd();
        $payload = $clientData->getData();
        $clientId = $payload['client_id'] ?? null;
        $messageId = $payload['data']['message_id'] ?? null;

        $this->touchActivity($fd);

        if (empty($messageId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        $this->completeAckProcess($clientId, $messageId);
    }
}
