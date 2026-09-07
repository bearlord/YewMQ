<?php

namespace App\Modules\Mqtt\Controllers;

use App\Modules\Mqtt\Services\MqttClientService;
use App\Modules\Mqtt\Services\MqttMessageService;
use App\Modules\Mqtt\Services\MqttOfflineMessageService;
use App\Modules\Mqtt\Services\MqttPublishService;
use App\Modules\Mqtt\Services\MqttRetainedMessageService;
use App\Modules\Mqtt\Services\MqttSubscriptionService;
use Carbon\Carbon;
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
    use GetConnection;

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
        // "session_start" mirrors the MQTT clean_session / clean_start flag.
        $sessionStart = $clientData["data"]['clean_session'] ?? false;
        $ipAddress = $this->clientData->getClientInfo()->getRemoteIp();
        $keepAlive = $clientData["data"]['keep_alive'] ?? null;
        $isActive = 1;

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

        // Register session state: map fd -> uid and clientId -> uid / session_start.
        $this->setFdSession($fd, 'uid', $clientPKId);
        $this->setClientSession($clientId, 'uid', $clientPKId);
        $this->setClientSession($clientId, 'session_start', $sessionStart);

        // Bind the connection fd to the uid for Topic/Uid plugin routing.
        $this->bindUid($fd, $clientPKId);
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

        // Send a DISCONNECT packet back to the client to acknowledge the close.
        $disConnectMessage = new DisConnect();
        $disConnectMessage->setProtocolLevel($protocolLevel);
        $this->autoBoostSend($fd, $disConnectMessage->getContents());

        // Remove the session state held for this connection and client.
        Server::clearFdSession($fd);
        Server::clearClientSession($clientId);

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
    }

    /**
     * @RequestMapping("publish")
     */
    public function actionPublish(): void
    {
        $fd = $this->clientData->getFd();
        $clientData = $this->clientData->getData();

        $protocolLevel = $clientData['protocol_level'];

        $clientId = $clientData['client_id'];

        $qos = $clientData["data"]['qos'] ?? 0;

        $retain = $clientData["data"]['retain'] ?? 0;

        $topic = $clientData["data"]['topic'] ?? null;

        $message = $clientData["data"]['message'] ?? null;

        if (empty($topic) || empty($message)) {
            Server::$instance->closeFd($fd);
            return;
        }

        (new MqttPublishService())->publishProcess(
            $protocolLevel,
            $clientId,
            $topic,
            $message,
            $qos,
            $retain
        );
    }

    /**
     * @RequestMapping("pubrel")
     */
    public function actionPubrel()
    {

    }
}