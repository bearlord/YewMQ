<?php

namespace App\Modules\Mqtt\Controllers;

use App\Modules\Mqtt\Services\MqttClientService;
use App\Modules\Mqtt\Services\MqttPublishService;
use App\Modules\Mqtt\Services\MqttSubscriptionService;
use App\Modules\Mqtt\Services\RuleEngine;
use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Coroutine\Server\Server;
use Yew\Framework\Controller;
use Yew\Mqtt\Hex\ReasonCode;
use Yew\Mqtt\Message\ConnAck;
use Yew\Mqtt\Message\DisConnect;
use Yew\Mqtt\Message\PingResp;
use Yew\Mqtt\Message\SubAck;
use Yew\Mqtt\Tools\ProtocolLevel;
use Yew\Mqtt\Tools\TopicValidator;
use Yew\Plugins\Connection\GetConnection;
use Yew\Plugins\Mqtt\Connection\GetMqttConnection;
use Yew\Plugins\Pack\GetBoostSend;
use Yew\Plugins\Route\Annotation\RequestMapping;
use Yew\Plugins\Route\Annotation\WsController;
use Yew\Plugins\Topic\GetTopic;
use Yew\Plugins\Uid\GetUid;

/**
 * @WsController("mqtt-websocket")
 */
class MqttWebsocketController extends Controller
{

    use GetBoostSend;
    use GetLogger;
    use GetUid;
    use GetTopic;
    use GetMqttConnection;

    /**
     * @RequestMapping("connect")
     *
     * Handle a client CONNECT request (the MQTT connection handshake).
     *
     * Validates the incoming connection, builds and sends the CONNACK
     * response, persists (or updates) the client record, and registers the
     * session state used by later subscribe / publish / disconnect actions.
     * For MQTT 5 the session-expiry-interval property is captured as well.
     *
     * @return void
     */
    public function actionConnect(): void
    {
        // Connection file descriptor and the decoded client payload.
        $fd = $this->clientData->getFd();
        $clientData = $this->clientData->getData();

        // MQTT Protocol version and client identifier (both required).
        $protocolLevel = $clientData['protocol_level'] ?? null;
        $clientId = $clientData['client_id'] ?? null;

        // Reject the connection when a required field is missing.
        if (empty($protocolLevel) || empty($clientId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        // Extract connection parameters from the payload.
        $username = $clientData['data']['username'] ?? null;
        $password = $clientData['data']['password'] ?? null;
        // "session_start" mirrors the MQTT clean_session / clean_start flag.
        $sessionStart = $clientData["data"]['clean_session'] ?? false;
        $ipAddress = $this->clientData->getClientInfo()->getRemoteIp();
        $keepAlive = $clientData["data"]['keep_alive'] ?? null;
        $isActive = 1;

        // Authentication hook: override authConnect() to enforce credentials.
        // A falsy return rejects the connection.
        if (!$this->authConnect($fd, $username, $password, $clientData)) {
            // Authentication failed: refuse the connection and close the socket.
            $connAck = new ConnAck();
            $connAck->setProtocolLevel($protocolLevel)->setSessionPresent(false);
            $this->autoBoostSend($fd, $connAck->getContents());
            Server::$instance->closeFd($fd);
            return;
        }

        // Assemble the row to persist for this client.
        $saveData = [
            'user_name' => $username,
            'protocol_level' => ProtocolLevel::getProtocolLevelName($protocolLevel),
            'client_id' => $clientId,
            'clean_start' => $sessionStart,
            'is_active' => $isActive,
            'ip_address' => $ipAddress,
            'keep_alive' => $keepAlive
        ];

        // Build the CONNACK: session present is false when starting clean.
        $connAck = new ConnAck();
        $connAck->setProtocolLevel($protocolLevel)->setSessionPresent(!$sessionStart);
        $this->autoBoostSend($fd, $connAck->getContents());

        // MQTT 5 carries an optional session-expiry-interval property.
        switch ($protocolLevel) {
            case ProtocolLevel::PROTOCOL_LEVEL_V5:
                $saveData['session_expiry_interval'] = $clientData['data']['properties']['session_expiry_interval'] ?? null;
                break;
            default:
                break;
        }

        // Persist or update the client record, getting back its primary key.
        $clientPKId = (new MqttClientService())->saveOrUpdateMqttClient($clientId, $saveData);

        // Register session state: map fd -> uid and clientId
        $this->setFdSession($fd, 'uid', $clientPKId);
        // Keep clientId on the fd session so the close handler can resolve the Will.
        $this->setFdSession($fd, 'client_id', $clientId);

        // Register session state: map clientId -> uid / session_start.
        $this->setClientSessionMulti($clientId, [
            'uid' => $clientPKId,
            'session_start' => $sessionStart
        ]);

        // Bind the connection fd to the uid for Topic/Uid plugin routing.
        $this->bindUid($fd, $clientPKId);

        // MQTT keepalive: arm the idle watchdog (0/empty disables enforcement).
        if ($keepAlive !== null) {
            $this->setKeepAlive($fd, (int)$keepAlive);
        }

        // MQTT 5 Will: (re)register on every CONNECT. A CONNECT without a will
        // clears any will left from a previous session (session takeover).
        $will = $this->extractWill($clientData, $protocolLevel);
        if ($will !== null) {
            $this->registerWill($clientId, $will);
        } else {
            $this->cancelWill($clientId);
        }

        // Expose connection-level metadata on the fd session so later PUBLISH /
        // SUBSCRIBE rules can reference it (client_id is already stored upstream).
        $this->setFdSession($fd, 'username', $username);
        $this->setFdSession($fd, 'peerhost', $ipAddress);
        $this->setFdSession($fd, 'keep_alive', $keepAlive);
        $this->setFdSession($fd, 'mountpoint', '');

        // Rule engine: fire $events/client_connected (failures must not break connect).
        try {
            RuleEngine::instance()->onEvent('$events/client_connected', [
                'protocol_level' => $protocolLevel,
                'client_id'      => $clientId,
                'username'       => $username,
                'peerhost'       => $ipAddress,
                'keep_alive'     => $keepAlive,
                'mountpoint'     => '',
                'source'         => '$events/client_connected',
            ]);
        } catch (\Throwable $e) {
            $this->warn('RuleEngine client_connected failed: ' . $e->getMessage());
        }
    }

    /**
     * @RequestMapping("disconnect")
     *
     * Handle a client-initiated DISCONNECT request.
     *
     * Runs the full teardown for an MQTT client disconnect:
     *  1. Executes the business-level disconnect process, which for
     *     clean-session clients removes their subscriptions and offline
     *     messages (see MqttClientService::disconnectProcess).
     *  2. Replies with a DISCONNECT control packet to acknowledge the close.
     *  3. Clears the per-connection (fd) and per-client (clientId) session maps.
     *
     * @return void
     */
    public function actionDisconnect(): void
    {
        // Connection file descriptor and the decoded client payload.
        $fd = $this->clientData->getFd();
        $clientData = $this->clientData->getData();

        // MQTT protocol version and client identifier carried in the payload.
        $protocolLevel = $clientData['protocol_level'];
        $clientId = $clientData['client_id'];

        // Business-level teardown: for clean-session clients this deletes
        // their subscriptions and offline messages (see MqttClientService).
        $mqttClientService = new MqttClientService();
        $mqttClientService->disconnectProcess($clientId);

        // Normal DISCONNECT: the Will must NOT be published.
        $this->cancelWill($clientId);

        // Rule engine: fire $events/client_disconnected BEFORE clearing the fd session
        // (metadata lives on it). Failures must not break the disconnect handshake.
        try {
            RuleEngine::instance()->onEvent('$events/client_disconnected', [
                'protocol_level' => $protocolLevel,
                'client_id'      => $clientId,
                'username'       => $this->getFdSession($fd, 'username') ?? '',
                'peerhost'       => $this->getFdSession($fd, 'peerhost') ?? '',
                'keep_alive'     => $this->getFdSession($fd, 'keep_alive') ?? 0,
                'mountpoint'     => $this->getFdSession($fd, 'mountpoint') ?? '',
                'source'         => '$events/client_disconnected',
            ]);
        } catch (\Throwable $e) {
            $this->warn('RuleEngine client_disconnected failed: ' . $e->getMessage());
        }

        // Send a DISCONNECT packet back to the client to acknowledge the close.
        $disConnectMessage = new DisConnect();
        $disConnectMessage->setProtocolLevel($protocolLevel);
        $this->autoBoostSend($fd, $disConnectMessage->getContents());

        // Remove the session state held for this connection and client.
        $this->clearFdSession($fd);
        $this->clearClientSession($clientId);
    }

    /**
     * @RequestMapping("pingreq")
     *
     * Handle a client PINGREQ request (MQTT keep-alive heartbeat).
     *
     * Refreshes the client's keep-alive state via the business layer and
     * replies with a PINGRESP control packet so the connection stays open.
     *
     * @return void
     */
    public function actionPingreq(): void
    {
        // Connection file descriptor and the decoded client payload.
        $fd = $this->clientData->getFd();
        $clientData = $this->clientData->getData();

        // MQTT protocol version and client identifier carried in the payload.
        $protocolLevel = $clientData['protocol_level'];
        $clientId = $clientData['client_id'];

        // Any inbound packet proves liveness; refresh the keepalive watchdog.
        $this->touchActivity($fd);

        // Business layer: refresh the keep-alive / liveness state for this client.
        $mqttClientService = new MqttClientService();
        $mqttClientService->pingreqProcess($clientId);

        // Reply with a PINGRESP packet to acknowledge the heartbeat.
        $pingRespMessage = new PingResp();
        $pingRespMessage->setProtocolLevel($protocolLevel);

        $this->autoBoostSend($fd, $pingRespMessage->getContents());
    }


    /**
     * @RequestMapping("subscribe")
     *
     * Handle a client SUBSCRIBE request (register interest in one or more topics).
     *
     * Flow:
     *  1. Reject the request when the packet id (message_id) or the topic list
     *     is missing, or when the MQTT protocol level is unsupported.
     *  2. Validate every topic filter and compute the granted QoS (capped at 1).
     *  3. Build and send the SUBACK response carrying one reason code per topic.
     *  4. Persist each subscription and register it into the Topic plugin's
     *     routing table (topic -> uid) so matching PUBLISHes can be delivered.
     *
     * @return void
     */
    public function actionSubscribe(): void
    {
        // Connection file descriptor and the decoded client payload.
        $fd = $this->clientData->getFd();
        $clientData = $this->clientData->getData();

        // MQTT protocol version carried in the payload.
        $protocolLevel = $clientData['protocol_level'];

        // Client identifier carried in the payload.
        $clientId = $clientData['client_id'];

        // Any inbound packet proves liveness; refresh the keepalive watchdog.
        $this->touchActivity($fd);

        // Packet identifier used to correlate this SUBSCRIBE with its SUBACK.
        $messageId = $clientData["data"]['message_id'] ?? null;

        // Map of topic filter => subscribe options (e.g. requested qos), keyed by topic.
        $topics = $clientData["data"]['topics'] ?? null;

        // Reject the subscribe request if any required field is missing.
        if (empty($messageId) || empty($topics)) {
            Server::$instance->closeFd($fd);
            return;
        }

        // 1. Pick the reason code used for an invalid topic, per protocol level.
        $invalidReasonCode = match ($protocolLevel) {
            ProtocolLevel::PROTOCOL_LEVEL_V3_1_1 => ReasonCode::UNSPECIFIED_ERROR,
            ProtocolLevel::PROTOCOL_LEVEL_V5 => ReasonCode::TOPIC_FILTER_INVALID,
            default => null,
        };

        // Close the connection if the protocol level is not supported.
        if ($invalidReasonCode === null) {
            $this->warn('Unsupported protocol level: ' . $protocolLevel);
            Server::$instance->closeFd($fd);
            return;
        }

        // 2. Common logic: iterate topics, validate each filter and compute the granted QoS.
        //    Granted QoS is capped at 1 (the broker does not support QoS 2).
        $codes = [];
        $topicValidator = new TopicValidator();
        foreach ($topics as $topic => $options) {
            $validateResult = $topicValidator->validateFilter($topic);
            if (!$validateResult) {
                // Invalid filter: report the protocol-specific failure code.
                $codes[] = $invalidReasonCode;
                continue;
            }
            $codes[] = min($options['qos'], 1);
        }

        // 3. Pack and send the SUBACK carrying one reason code per requested topic.
        $subAckMessage = (new SubAck());
        $subAckMessage->setProtocolLevel($protocolLevel)
            ->setMessageId($messageId)
            ->setCodes($codes);

        $this->autoBoostSend($fd, $subAckMessage->getContents());

        // 4. Common logic: persist each subscription and register topic routing.
        //    uid is the subscriber's primary key resolved from the fd session.
        $uid = $this->getFdSession($fd, 'uid');

        $mqttSubscriptionService = new MqttSubscriptionService();
        foreach ($topics as $topic => $options) {
            // Persist the subscription record (client_id, topic, qos) to storage.
            $mqttSubscriptionService->saveSubscription([
                'client_id' => $clientId,
                'topic' => $topic,
                'qos' => $options['qos'],
            ]);

            // Bind the topic to the subscriber uid for downstream PUBLISH routing.
            $this->addSubscription($topic, $uid);
        }

        // Rule engine: fire $events/client_subscribe once per subscribed filter
        // (EMQX emits one event per topic filter). Failures must not break subscribe.
        try {
            foreach (array_keys($topics) as $subTopic) {
                RuleEngine::instance()->onEvent('$events/client_subscribe', [
                    'protocol_level' => $protocolLevel,
                    'client_id'      => $clientId,
                    'username'       => $this->getFdSession($fd, 'username') ?? '',
                    'peerhost'       => $this->getFdSession($fd, 'peerhost') ?? '',
                    'keep_alive'     => $this->getFdSession($fd, 'keep_alive') ?? 0,
                    'mountpoint'     => $this->getFdSession($fd, 'mountpoint') ?? '',
                    'topic'          => $subTopic,
                    'source'         => '$events/client_subscribe',
                ]);
            }
        } catch (\Throwable $e) {
            $this->warn('RuleEngine client_subscribe failed: ' . $e->getMessage());
        }
    }

    /**
     * @RequestMapping("publish")
     *
     * Handle a client PUBLISH request (an inbound message from a client).
     *
     * Flow:
     *  1. Reject the request when the topic or payload is missing.
     *  2. Persist (and forward to subscribers) the message via the business layer.
     *  3. Acknowledge the publisher per the QoS level:
     *       - QoS 0: no acknowledgement.
     *       - QoS 1: reply with PUBACK.
     *       - QoS 2: reply with PUBREC (the publisher will then send PUBREL,
     *         which is answered by PUBCOMP in actionPubrel).
     *
     * @return void
     */
    public function actionPublish(): void
    {
        $fd = $this->clientData->getFd();
        $clientData = $this->clientData->getData();

        $protocolLevel = $clientData['protocol_level'];

        $clientId = $clientData['client_id'];

        // Any inbound packet proves liveness; refresh the keepalive watchdog.
        $this->touchActivity($fd);

        $qos = $clientData["data"]['qos'] ?? 0;

        $retain = $clientData["data"]['retain'] ?? 0;

        $topic = $clientData["data"]['topic'] ?? null;

        $message = $clientData["data"]['message'] ?? null;

        if (empty($topic) || empty($message)) {
            Server::$instance->closeFd($fd);
            return;
        }

        // Packet identifier used for QoS 1 / QoS 2 acknowledgement.
        $messageId = $clientData["data"]['message_id'] ?? null;

        // A QoS 1/2 PUBLISH must carry a packet identifier; reject it otherwise.
        if ($qos > 0 && empty($messageId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        // Rule engine: evaluate configured rules against this inbound publish.
        // A `drop` action returns true; we still ack the publisher per QoS but
        // do NOT store/forward the message. Any failure must NOT break publish.
        $mqttPublishService = new MqttPublishService();
        try {
            $dropped = RuleEngine::instance()->onPublish([
                'protocol_level' => $protocolLevel,
                'client_id'     => $clientId,
                'username'       => $this->getFdSession($fd, 'username') ?? '',
                'peerhost'       => $this->getFdSession($fd, 'peerhost') ?? '',
                'keep_alive'     => $this->getFdSession($fd, 'keep_alive') ?? 0,
                'mountpoint'     => $this->getFdSession($fd, 'mountpoint') ?? '',
                'topic'         => $topic,
                'message'       => $message,
                'qos'           => $qos,
                'retain'        => $retain,
                'source'        => '$events/message_publish',
            ]);
        } catch (\Throwable $e) {
            $this->warn('RuleEngine onPublish failed: ' . $e->getMessage());
            $dropped = false;
        }

        if ($dropped) {
            // Message discarded by a rule: acknowledge the publisher (QoS dependent)
            // but skip persistence and downstream forwarding.
            if ($qos == 1) {
                $mqttPublishService->pubAckProcess($fd, $protocolLevel, $messageId);
            } elseif ($qos == 2) {
                $mqttPublishService->pubRecProcess($fd, $protocolLevel, $messageId);
            }
            return;
        }

        $mqttPublishService->publishProcess(
            $protocolLevel,
            $clientId,
            $topic,
            $message,
            $qos,
            $retain
        );

        // 3. Acknowledge the publisher according to the QoS level (delegated
        //    to the publish service, mirroring publishProcess).
        if ($qos == 1) {
            // QoS 1: a single PUBACK completes the delivery to the broker.
            $mqttPublishService->pubAckProcess($fd, $protocolLevel, $messageId);
        } elseif ($qos == 2) {
            // QoS 2: PUBREC is the broker's acknowledgement; the publisher
            // will respond with PUBREL, which we answer with PUBCOMP.
            $mqttPublishService->pubRecProcess($fd, $protocolLevel, $messageId);
        }
    }

    /**
     * @RequestMapping("pubrec")
     *
     * Handle a subscriber PUBREC request (the outbound QoS 2 flow).
     *
     * When the broker delivers a QoS 2 message to a subscriber it sends
     * PUBLISH; the subscriber replies with PUBREC. The broker then responds
     * with a PUBREL carrying the same packet identifier, and the subscriber
     * completes the handshake with PUBCOMP (handled in actionPubcomp).
     *
     * @return void
     */
    public function actionPubrec(): void
    {
        // Connection file descriptor and the decoded client payload.
        $fd = $this->clientData->getFd();
        $clientData = $this->clientData->getData();

        // MQTT protocol version and client identifier (subscriber) carried in the payload.
        $protocolLevel = $clientData['protocol_level'];
        $clientId = $clientData['client_id'];

        // Any inbound packet proves liveness; refresh the keepalive watchdog.
        $this->touchActivity($fd);

        // Packet identifier that correlates this PUBREC with the earlier PUBLISH.
        $messageId = $clientData['data']['message_id'] ?? null;

        // A PUBREC without a packet identifier is malformed; close the connection.
        if (empty($messageId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        $mqttPublishService = new MqttPublishService();

        // Advance the outbound ack record to the PUBREC stage (stage 2).
        $mqttPublishService->markPubrecProcess($clientId, $messageId);

        // Reply with a PUBREL packet using the same packet identifier so the
        // subscriber can complete (and free) its QoS 2 in-flight state.
        $mqttPublishService->pubRelProcess($fd, $protocolLevel, $messageId);
    }

    /**
     * @RequestMapping("pubrel")
     *
     * Handle a client PUBREL request (the third step of the MQTT QoS 2 flow).
     *
     * In QoS 2 the publisher sends PUBLISH -> PUBREC -> PUBREL; the broker
     * acknowledges the PUBREL with a PUBCOMP packet carrying the same packet
     * identifier, completing the exactly-once delivery handshake.
     *
     * @return void
     */
    public function actionPubrel(): void
    {
        // Connection file descriptor and the decoded client payload.
        $fd = $this->clientData->getFd();
        $clientData = $this->clientData->getData();

        // MQTT protocol version carried in the payload.
        $protocolLevel = $clientData['protocol_level'];

        // Any inbound packet proves liveness; refresh the keepalive watchdog.
        $this->touchActivity($fd);

        // Packet identifier that correlates this PUBREL with the earlier PUBLISH/PUBREC.
        $messageId = $clientData['data']['message_id'] ?? null;

        // A PUBREL without a packet identifier is malformed; close the connection.
        if (empty($messageId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        // Reply with a PUBCOMP packet using the same packet identifier so the
        // publisher can complete (and free) its QoS 2 in-flight state.
        (new MqttPublishService())->pubCompProcess($fd, $protocolLevel, $messageId);
    }

    /**
     * @RequestMapping("pubcomp")
     *
     * Handle a subscriber PUBCOMP request (the terminal step of the outbound
     * QoS 2 flow, where the broker is the publisher).
     *
     * The broker previously sent PUBLISH -> received PUBREC (answered with
     * PUBREL). The subscriber now sends PUBCOMP to confirm receipt. PUBCOMP is
     * the final packet, so the broker sends no reply; it only finalizes the
     * in-flight acknowledgement record (stage -> completed) via the publish
     * service.
     *
     * @return void
     */
    public function actionPubcomp(): void
    {
        // Connection file descriptor and the decoded client payload.
        $fd = $this->clientData->getFd();
        $clientData = $this->clientData->getData();

        // Client identifier (subscriber) carried in the payload.
        $clientId = $clientData['client_id'];

        // Any inbound packet proves liveness; refresh the keepalive watchdog.
        $this->touchActivity($fd);

        // Packet identifier that correlates this PUBCOMP with the earlier PUBLISH/PUBREL.
        $messageId = $clientData['data']['message_id'] ?? null;

        // A PUBCOMP without a packet identifier is malformed; close the connection.
        if (empty($messageId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        // PUBCOMP is terminal: finalize the in-flight ack record (no reply sent).
        (new MqttPublishService())->completeAckProcess($clientId, $messageId);
    }

    /**
     * @RequestMapping("puback")
     *
     * Handle a subscriber PUBACK request (the terminal step of the outbound
     * QoS 1 flow, where the broker is the publisher).
     *
     * The broker previously sent a QoS 1 PUBLISH to the subscriber, which now
     * replies with PUBACK to confirm receipt. PUBACK is the final packet, so
     * the broker sends no reply; it only finalizes the in-flight acknowledgement
     * record (stage -> completed) via the publish service.
     *
     * @return void
     */
    public function actionPuback(): void
    {
        // Connection file descriptor and the decoded client payload.
        $fd = $this->clientData->getFd();
        $clientData = $this->clientData->getData();

        // Client identifier (subscriber) carried in the payload.
        $clientId = $clientData['client_id'];

        // Any inbound packet proves liveness; refresh the keepalive watchdog.
        $this->touchActivity($fd);

        // Packet identifier that correlates this PUBACK with the earlier PUBLISH.
        $messageId = $clientData['data']['message_id'] ?? null;

        // A PUBACK without a packet identifier is malformed; close the connection.
        if (empty($messageId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        // PUBACK is terminal: finalize the in-flight ack record (no reply sent).
        (new MqttPublishService())->completeAckProcess($clientId, $messageId);
    }

    /**
     * Authentication hook for the CONNECT handshake.
     *
     * Override this method to enforce username/password (or any other) auth.
     * Return true to accept the connection, false to reject it. The default
     * implementation is an open broker (no authentication).
     *
     * @param int $fd Connection file descriptor.
     * @param string|null $username Decoded CONNECT username.
     * @param string|null $password Decoded CONNECT password.
     * @param array $clientData Full decoded CONNECT payload.
     * @return bool
     */
    protected function authConnect(int $fd, ?string $username, ?string $password, array $clientData): bool
    {
        return true;
    }

    /**
     * Extract a Will message from the decoded CONNECT payload.
     *
     * The controller receives a WS/JSON CONNECT (app-defined), so the Will may
     * live either at the top level ($clientData['will']) or under the decoded
     * MQTT packet ($clientData['data']['will']). Returns null when no Will is
     * present; adjust the key paths if your client packs the Will differently.
     *
     * @param array $clientData decoded CONNECT payload
     * @param mixed $protocolLevel
     * @return array<string, mixed>|null
     */
    private function extractWill(array $clientData, ?int $protocolLevel): ?array
    {
        $raw = $clientData['will'] ?? ($clientData['data']['will'] ?? null);
        if (empty($raw) || empty($raw['topic'])) {
            return null;
        }
        return [
            'topic' => $raw['topic'],
            'payload' => $raw['message'] ?? ($raw['payload'] ?? ''),
            'qos' => (int)($raw['qos'] ?? 0),
            'retain' => (int)($raw['retain'] ?? 0),
            'will_delay_interval' => (int)(
                $raw['properties']['will_delay_interval'] ?? ($raw['will_delay_interval'] ?? 0)
            ),
            'protocol_level' => $protocolLevel,
        ];
    }

    /**
     * Handle WebSocket close: publish the client's pending Will on abnormal
     * disconnect (abrupt drop or keepalive timeout). A normal DISCONNECT already
     * cleared the Will via cancelWill(), so nothing is published then.
     *
     * @param int $fd
     * @param int $reactorId
     */
    public function onWsClose(int $fd, int $reactorId): void
    {
        $clientId = $this->getFdSession($fd, 'client_id');
        if (empty($clientId)) {
            return;
        }

        $will = $this->getWill($clientId);
        // The fd session is no longer needed once the connection is gone.
        $this->clearFdSession($fd);

        if (empty($will) || empty($will['topic'])) {
            return; // No (or already consumed) Will.
        }

        $delay = (int)($will['will_delay_interval'] ?? 0);
        if ($delay <= 0) {
            $this->cancelWill($clientId);
            $this->publishWill($will, $clientId);
            return;
        }

        // MQTT 5 delayed Will: re-check at fire time so a reconnect with the
        // same clientId (which calls cancelWill) can still cancel delivery.
        \Swoole\Timer::after($delay * 1000, function () use ($clientId, $will) {
            $pending = $this->getWill($clientId);
            if (empty($pending) || empty($pending['topic'])) {
                return; // Cancelled by a normal disconnect / reconnect.
            }
            $this->cancelWill($clientId);
            $this->publishWill($pending, $clientId);
        });
    }

    /**
     * Deliver the Will through the existing publish pipeline (routing, QoS,
     * retain and persistence all handled by MqttPublishService).
     *
     * @param array<string, mixed> $will
     */
    private function publishWill(array $will, string $clientId): void
    {
        $service = new MqttPublishService();
        $service->publishProcess(
            (int)($will['protocol_level'] ?? 5),
            $clientId,
            $will['topic'],
            $will['payload'] ?? $will['message'] ?? '',
            (int)($will['qos'] ?? 0),
            (int)($will['retain'] ?? 0)
        );
    }
}