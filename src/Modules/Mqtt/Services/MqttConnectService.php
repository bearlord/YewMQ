<?php

namespace App\Modules\Mqtt\Services;

use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Core\Server\Server;
use Yew\Mqtt\Message\ConnAck;
use Yew\Mqtt\Tools\ProtocolLevel;
use Yew\Plugins\Mqtt\Connection\GetMqttConnection;
use Yew\Plugins\Pack\GetBoostSend;
use Yew\Plugins\Uid\GetUid;

class MqttConnectService
{
    use GetBoostSend;
    use GetLogger;
    use GetMqttConnection;
    use GetUid;

    /**
     * Entry point for an inbound CONNECT. Parses the client payload (fd,
     * protocol level, clientId, credentials, peer IP), validates required
     * fields, runs the (overridable) authConnect() hook, sends a CONNACK and
     * closes the connection on rejection, and otherwise runs the full connect
     * flow via connectProcess().
     *
     * Only the controller's clientData object is passed in; the service
     * resolves everything it needs from it (no separate fd / ipAddress args).
     *
     * @param object $clientData ClientData (has getFd/getData/getClientInfo).
     * @return void
     */
    public function connectInboundProcess(object $clientData): void
    {
        $fd      = $clientData->getFd();
        $payload = $clientData->getData();

        $protocolLevel = $payload['protocol_level'] ?? null;
        $clientId      = $payload['client_id'] ?? null;

        // Reject the connection when a required field is missing.
        if (empty($protocolLevel) || empty($clientId)) {
            Server::$instance->closeFd($fd);
            return;
        }

        $username  = $payload['data']['username'] ?? null;
        $password  = $payload['data']['password'] ?? null;
        $ipAddress = $clientData->getClientInfo()->getRemoteIp();

        // Authentication hook (overridable in a subclass).
        if (!$this->authConnect($fd, $username, $password, $payload)) {
            $connAck = new ConnAck();
            $connAck->setProtocolLevel($protocolLevel)->setSessionPresent(false);
            $this->autoBoostSend($fd, $connAck->getContents());
            Server::$instance->closeFd($fd);
            return;
        }

        $this->connectProcess($fd, $payload, $protocolLevel, $clientId, $ipAddress);
    }

    /**
     * Handle a successful (already authenticated) CONNECT: build & send the
     * CONNACK, persist the client record, register session state (fd -> uid /
     * clientId, clientId -> uid), bind the fd to the uid, arm keepalive,
     * register (or clear) the Will, and fire the $events/client_connected rule
     * event.
     *
     * Authentication is handled by connectInboundProcess()/authConnect() before
     * this method is called, so it assumes the connection is accepted.
     *
     * @param int      $fd            Connection file descriptor.
     * @param array    $clientData    Decoded CONNECT payload (clientData->getData()).
     * @param int|null $protocolLevel MQTT protocol version.
     * @param string   $clientId      Client identifier.
     * @param string   $ipAddress     Remote peer IP.
     * @return void
     */
    public function connectProcess(int $fd, array $clientData, ?int $protocolLevel, string $clientId, string $ipAddress): void
    {
        // Connection parameters carried in the payload.
        $username = $clientData['data']['username'] ?? null;
        // "session_start" mirrors the MQTT clean_session / clean_start flag.
        $sessionStart = $clientData['data']['clean_session'] ?? false;
        $keepAlive    = $clientData['data']['keep_alive'] ?? null;

        // Build the CONNACK: session present is false when starting clean.
        $connAck = new ConnAck();
        $connAck->setProtocolLevel($protocolLevel)->setSessionPresent(!$sessionStart);
        $this->autoBoostSend($fd, $connAck->getContents());

        // MQTT 5 carries an optional session-expiry-interval property.
        $saveData = [
            'user_name' => $username,
            'protocol_level' => ProtocolLevel::getProtocolLevelName($protocolLevel),
            'client_id' => $clientId,
            'clean_start' => $sessionStart,
            'is_active' => 1,
            'ip_address' => $ipAddress,
            'keep_alive' => $keepAlive,
        ];
        if ($protocolLevel === ProtocolLevel::PROTOCOL_LEVEL_V5) {
            $saveData['session_expiry_interval'] = $clientData['data']['properties']['session_expiry_interval'] ?? null;
        }

        // Persist or update the client record, getting back its primary key.
        $clientPKId = (new MqttClientService())->saveOrUpdateMqttClient($clientId, $saveData);

        // Register session state: map fd -> uid and clientId.
        // Keep clientId on the fd session so the close handler can resolve the Will.
        $this->setFdSessionMulti($fd, [
            'uid' => $clientPKId,
            'client_id' => $clientId,
            'username' => $username,
            'peerhost' => $ipAddress,
            'mountpoint' => '',
        ]);

        // Register session state: map clientId -> uid / session_start.
        $this->setClientSessionMulti($clientId, [
            'uid' => $clientPKId,
            'fd' => $fd,
            'session_start' => $sessionStart,
        ]);

        // Bind the connection fd to the uid for Topic/Uid plugin routing.
        $this->bindUid($fd, $clientPKId);

        // MQTT keepalive: arm the idle watchdog (0/empty disables enforcement).
        if ($keepAlive !== null) {
            $this->setKeepAlive($fd, max(0, (int)$keepAlive));
        }

        // MQTT 5 Will: (re)register on every CONNECT. A CONNECT without a will
        // clears any will left from a previous session (session takeover).
        $will = $this->extractWill($clientData, $protocolLevel);
        if ($will !== null) {
            $this->registerWill($clientId, $will);
        } else {
            $this->cancelWill($clientId);
        }

        // Replay any messages buffered for this client's restored (persistent) session,
        // matching its restored subscriptions. QoS handshakes are honoured by
        // deliverOfflineMessages(); delivered rows are marked/cleared so a later
        // SUBSCRIBE will not re-deliver them. Clean sessions have their subscriptions
        // and offline buffer cleared upstream, so this is a no-op for them.
        $subs = (new MqttSubscriptionService())->getSubscriptionsByClientId($clientId);
        if ($subs !== []) {
            (new MqttPublishService())->deliverOfflineMessages($fd, $protocolLevel, $clientId, $subs);
        }

        // Rule engine: fire $events/client_connected (failures must not break connect).
        try {
            RuleEngine::instance()->onEvent('$events/client_connected', [
                'protocol_level' => $protocolLevel,
                'client_id' => $clientId,
                'username' => $username ?? '',
                'peerhost' => $ipAddress,
                'keep_alive' => $keepAlive ?? 0,
                'mountpoint' => '',
                'source' => '$events/client_connected',
            ]);
        } catch (\Throwable $e) {
            $this->warn('RuleEngine client_connected failed: ' . $e->getMessage());
        }
    }

    /**
     * Authentication hook. Override in a subclass of MqttConnectService to
     * enforce credentials (e.g. check against a user store). Returning false
     * rejects the connection; the default implementation is an open broker
     * that accepts any connection.
     *
     * @param int         $fd         Connection file descriptor.
     * @param string|null $username   Decoded CONNECT username.
     * @param string|null $password   Decoded CONNECT password.
     * @param array       $clientData Full decoded CONNECT payload.
     * @return bool
     */
    protected function authConnect(int $fd, ?string $username, ?string $password, array $clientData): bool
    {
        // Default: open broker, accept any connection.
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
     * @param array    $clientData decoded CONNECT payload
     * @param int|null $protocolLevel
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
}
