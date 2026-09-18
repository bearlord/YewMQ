<?php

namespace App\Modules\Mqtt\Services;

use App\Models\Extension\MqttSubscription;
use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Coroutine\Server\Server;
use Yew\Mqtt\Hex\ReasonCode;
use Yew\Mqtt\Message\SubAck;
use Yew\Mqtt\Message\UnSubAck;
use Yew\Mqtt\Tools\ProtocolLevel;
use Yew\Mqtt\Tools\TopicValidator;
use Yew\Plugins\Mqtt\Connection\GetMqttConnection;
use Yew\Plugins\Pack\GetBoostSend;
use Yew\Plugins\Topic\GetTopic;

class MqttSubscriptionService
{
    use GetBoostSend;
    use GetLogger;
    use GetMqttConnection;
    use GetTopic;

    /**
     * @param array $data
     * @return bool
     */
    public function saveSubscription(array $data): bool
    {
        $model = new MqttSubscription();
        $model->setAttributes($data);
        $model->save(false);

        return true;
    }

    /**
     * @param $clientId
     * @return bool
     */
    public function deleteSubscriptionsByClientId($clientId): bool
    {
        MqttSubscription::deleteAll([
            'client_id' => $clientId
        ]);

        return true;

    }

    /**
     * Return this client's persisted subscriptions as a topic-filter => options map
     * suitable for deliverOfflineMessages(). Used to replay buffered offline
     * messages on CONNECT for a restored (persistent) session.
     *
     * @param string $clientId
     * @return array Map of topic filter => ['qos' => int]
     */
    public function getSubscriptionsByClientId(string $clientId): array
    {
        $rows = MqttSubscription::find()
            ->where(['client_id' => $clientId])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->topic] = ['qos' => (int)$row->qos];
        }

        return $map;
    }

    /**
     * Delete a single (client_id, topic) subscription record.
     *
     * @param string $clientId
     * @param string $topic
     * @return bool
     */
    public function deleteSubscription(string $clientId, string $topic): bool
    {
        MqttSubscription::deleteAll([
            'client_id' => $clientId,
            'topic' => $topic,
        ]);

        return true;
    }

    /**
     * Handle a SUBSCRIBE request: validate topic filters, compute the granted
     * QoS (capped at 1), persist each subscription, register topic routing
     * (topic -> uid) and fire the $events/client_subscribe rule event, then
     * send the SUBACK response.
     *
     * @param int $fd Connection file descriptor (used to send SUBACK + read session).
     * @param int|null $protocolLevel MQTT protocol version (3.1.1 / 5).
     * @param string $clientId Subscriber client identifier.
     * @param int|null $messageId Packet identifier echoed back in the SUBACK.
     * @param array $topics Map of topic filter => subscribe options (e.g. qos).
     * @return array The per-topic reason codes that were sent in the SUBACK.
     */
    public function subscribeProcess(int $fd, ?int $protocolLevel, string $clientId, ?int $messageId, array $topics): array
    {
        // Resolve subscriber context from the fd session in a single IPC round-trip
        // (uid + rule-engine metadata), instead of one IPC call per field.
        $sess = $this->getFdSessionMulti($fd);
        $uid = $sess['uid'] ?? null;

        // 1. Pick the reason code used for an invalid topic, per protocol level.
        $invalidReasonCode = match ($protocolLevel) {
            ProtocolLevel::PROTOCOL_LEVEL_V3_1_1 => ReasonCode::UNSPECIFIED_ERROR,
            ProtocolLevel::PROTOCOL_LEVEL_V5 => ReasonCode::TOPIC_FILTER_INVALID,
            default => null,
        };
        if ($invalidReasonCode === null) {
            return [];
        }

        // 2. Validate each topic filter and compute the granted QoS (capped at 1).
        $codes = [];
        $topicValidator = new TopicValidator();
        foreach ($topics as $topic => $options) {
            if (!$topicValidator->validateFilter($topic)) {
                $codes[] = $invalidReasonCode;
                continue;
            }
            $codes[] = min($options['qos'] ?? 0, 1);
        }

        // 3. Persist each subscription and register topic routing (topic -> uid).
        foreach ($topics as $topic => $options) {
            $this->saveSubscription([
                'client_id' => $clientId,
                'topic' => $topic,
                'qos' => $options['qos'] ?? 0,
                // MQTT 5.0 No Local: persisted so deliverToSubscribers() can
                // later skip self-delivery. Absent for MQTT 3.1.1 (defaults 0).
                'no_local' => !empty($options['no_local']) ? 1 : 0,
            ]);
            $this->addSubscription($topic, (string)$uid);
        }

        // 3b. Replay any messages buffered while this client was offline and
        //     matching the filters it just subscribed to (QoS handshakes honoured).
        (new MqttPublishService())->deliverOfflineMessages($fd, $protocolLevel, $clientId, $topics);

        // 4. Rule engine: fire $events/client_subscribe once per filter.
        try {
            foreach (array_keys($topics) as $subTopic) {
                RuleEngine::instance()->onEvent('$events/client_subscribe', [
                    'protocol_level' => $protocolLevel,
                    'client_id'      => $clientId,
                    'username'       => $sess['username'] ?? '',
                    'peerhost'       => $sess['peerhost'] ?? '',
                    'keep_alive'     => $sess['keep_alive'] ?? 0,
                    'mountpoint'     => $sess['mountpoint'] ?? '',
                    'topic'          => $subTopic,
                    'source'         => '$events/client_subscribe',
                ]);
            }
        } catch (\Throwable $e) {
            $this->warn('RuleEngine client_subscribe failed: ' . $e->getMessage());
        }

        // 5. Build and send the SUBACK carrying one reason code per requested topic.
        $subAck = (new SubAck())
            ->setProtocolLevel($protocolLevel)
            ->setMessageId($messageId)
            ->setCodes($codes);
        $this->autoBoostSend($fd, $subAck->getContents());

        return $codes;
    }

    /**
     * Handle an UNSUBSCRIBE request: remove each topic from the routing table,
     * delete the persisted subscription record and fire the
     * $events/client_unsubscribe rule event, then send the UNSUBACK response.
     *
     * @param int $fd Connection file descriptor (used to send UNSUBACK + read session).
     * @param int|null $protocolLevel MQTT protocol version (3.1.1 / 5).
     * @param string $clientId Subscriber client identifier.
     * @param mixed $messageId Packet identifier echoed back in the UNSUBACK.
     * @param array $topics Plain list of topic filters to unsubscribe from.
     * @return void
     */
    public function unsubscribeProcess(int $fd, ?int $protocolLevel, string $clientId, ?int $messageId, array $topics): void
    {
        // Resolve subscriber context from the fd session in a single IPC round-trip.
        $sess = $this->getFdSessionMulti($fd);
        $uid = $sess['uid'] ?? null;

        // Remove each topic from the routing table and delete the persisted record.
        foreach ($topics as $topic) {
            $this->removeSubscription($topic, (string)$uid);
            $this->deleteSubscription($clientId, $topic);
        }

        // Build and send the UNSUBACK carrying the same packet id. For MQTT 5 one
        // success reason code (0x00) is returned per unsubscribed filter.
        $unSubAck = (new UnSubAck())
            ->setProtocolLevel($protocolLevel)
            ->setMessageId($messageId);
        if ($protocolLevel === ProtocolLevel::PROTOCOL_LEVEL_V5) {
            $unSubAck->setCodes(array_fill(0, count($topics), ReasonCode::SUCCESS));
        }
        $this->autoBoostSend($fd, $unSubAck->getContents());

        // Rule engine: fire $events/client_unsubscribe once per filter.
        try {
            foreach ($topics as $unsubTopic) {
                RuleEngine::instance()->onEvent('$events/client_unsubscribe', [
                    'protocol_level' => $protocolLevel,
                    'client_id'      => $clientId,
                    'username'       => $sess['username'] ?? '',
                    'peerhost'       => $sess['peerhost'] ?? '',
                    'keep_alive'     => $sess['keep_alive'] ?? 0,
                    'mountpoint'     => $sess['mountpoint'] ?? '',
                    'topic'          => $unsubTopic,
                    'source'         => '$events/client_unsubscribe',
                ]);
            }
        } catch (\Throwable $e) {
            $this->warn('RuleEngine client_unsubscribe failed: ' . $e->getMessage());
        }
    }

    /**
     * Entry point for an inbound SUBSCRIBE. Refreshes keepalive, validates the
     * required fields and protocol level (closing the connection on rejection),
     * and then runs subscribeProcess().
     *
     * Only the controller's clientData object is passed in.
     *
     * @param object $clientData ClientData (has getFd/getData).
     * @return void
     */
    public function subscribeInboundProcess(object $clientData): void
    {
        $fd = $clientData->getFd();
        $payload = $clientData->getData();
        $protocolLevel = $payload['protocol_level'] ?? null;
        $clientId = $payload['client_id'] ?? null;

        // Any inbound packet proves liveness; refresh the keepalive watchdog.
        $this->touchActivity($fd);

        $messageId = $payload['data']['message_id'] ?? null;
        $topics = $payload['data']['topics'] ?? null;

        // Reject when a required field is missing or the protocol level is unsupported.
        if (empty($messageId) || empty($topics)) {
            Server::$instance->closeFd($fd);
            return;
        }
        if (!in_array($protocolLevel, [
            ProtocolLevel::PROTOCOL_LEVEL_V3_1_1,
            ProtocolLevel::PROTOCOL_LEVEL_V5,
        ], true)) {
            $this->warn('Unsupported protocol level: ' . $protocolLevel);
            Server::$instance->closeFd($fd);
            return;
        }

        $this->subscribeProcess($fd, $protocolLevel, $clientId, $messageId, $topics);
    }

    /**
     * Entry point for an inbound UNSUBSCRIBE (mirrors subscribeInboundProcess).
     *
     * @param object $clientData ClientData (has getFd/getData).
     * @return void
     */
    public function unsubscribeInboundProcess(object $clientData): void
    {
        $fd = $clientData->getFd();
        $payload = $clientData->getData();
        $protocolLevel = $payload['protocol_level'] ?? null;
        $clientId = $payload['client_id'] ?? null;

        $this->touchActivity($fd);

        $messageId = $payload['data']['message_id'] ?? null;
        $topics = $payload['data']['topics'] ?? null;

        if (empty($messageId) || empty($topics)) {
            Server::$instance->closeFd($fd);
            return;
        }
        if (!in_array($protocolLevel, [
            ProtocolLevel::PROTOCOL_LEVEL_V3_1_1,
            ProtocolLevel::PROTOCOL_LEVEL_V5,
        ], true)) {
            $this->warn('Unsupported protocol level: ' . $protocolLevel);
            Server::$instance->closeFd($fd);
            return;
        }

        $this->unsubscribeProcess($fd, $protocolLevel, $clientId, $messageId, $topics);
    }

    /**
     * Determine whether a client has opted out of receiving its own messages
     * on a given topic (MQTT 5.0 "No Local").
     *
     * The client may hold several overlapping subscriptions that match $topic.
     * Per spec each subscription carries its own No Local flag, so we skip
     * self-delivery only when at least one filter matches AND none of the
     * matching filters explicitly opted into receiving own publications.
     *
     * @param string $clientId Publisher / subscriber client identifier.
     * @param string $topic Concrete topic the message was published to.
     * @return bool True when every matching subscription set No Local.
     */
    public function isNoLocal(string $clientId, string $topic): bool
    {
        $subs = MqttSubscription::find()
            ->where(['client_id' => $clientId])
            ->all();
        if (empty($subs)) {
            return false;
        }

        $matched = false;
        $allowSelf = false; // a matched subscription wants its own messages
        foreach ($subs as $sub) {
            if ($this->topicFilterMatches($sub->topic, $topic)) {
                $matched = true;
                if (empty($sub->no_local)) {
                    $allowSelf = true;
                }
            }
        }

        return $matched && !$allowSelf;
    }

    /**
     * Match a topic filter (may contain '+' / '#' wildcards) against a
     * concrete topic name, per MQTT 4.7.1.
     *
     * @param string $filter Topic filter (subscription expression).
     * @param string $topic  Concrete topic name (publish target).
     * @return bool True when $topic matches $filter.
     */
    private function topicFilterMatches(string $filter, string $topic): bool
    {
        $f = explode('/', $filter);
        $t = explode('/', $topic);
        $fn = count($f);
        $tn = count($t);

        for ($i = 0; $i < $fn; $i++) {
            $level = $f[$i];
            if ($level === '#') {
                // Multi-level wildcard matches the remaining levels (incl. none).
                return true;
            }
            if ($i >= $tn) {
                return false;
            }
            if ($level === '+') {
                // Single-level wildcard matches exactly one level.
                continue;
            }
            if ($level !== $t[$i]) {
                return false;
            }
        }

        return $fn === $tn;
    }
}
