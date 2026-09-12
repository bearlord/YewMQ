<?php

namespace App\Modules\Mqtt\Services;

use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Mqtt\Message\DisConnect;
use Yew\Plugins\Mqtt\Connection\GetMqttConnection;
use Yew\Plugins\Pack\GetBoostSend;

class MqttDisconnectService
{
    use GetBoostSend;
    use GetLogger;
    use GetMqttConnection;

    /**
     * Handle a client-initiated DISCONNECT: tear down business state, cancel
     * the Will, fire the $events/client_disconnected rule event, send the
     * DISCONNECT acknowledgement and clear the session.
     *
     * Only the controller's clientData object is passed in; the service
     * resolves the fd / protocol level / clientId from it.
     *
     * @param object $clientData ClientData (has getFd/getData).
     * @return void
     */
    public function disconnectProcess(object $clientData): void
    {
        $fd = $clientData->getFd();
        $payload = $clientData->getData();
        $protocolLevel = $payload['protocol_level'] ?? null;
        $clientId = $payload['client_id'] ?? null;

        // Business-level teardown: for clean-session clients this deletes their
        // subscriptions and offline messages, and marks the client inactive.
        (new MqttClientService())->disconnectProcess($clientId);

        // Normal DISCONNECT: the Will must NOT be published.
        $this->cancelWill($clientId);

        // Resolve session metadata in a single IPC round-trip for the rule event
        // (instead of one getFdSession call per field).
        $sess = $this->getFdSessionMulti($fd);

        // Rule engine: fire $events/client_disconnected. Failures must not break.
        try {
            RuleEngine::instance()->onEvent('$events/client_disconnected', [
                'protocol_level' => $protocolLevel,
                'client_id'      => $clientId,
                'username'       => $sess['username'] ?? '',
                'peerhost'       => $sess['peerhost'] ?? '',
                'keep_alive'     => $sess['keep_alive'] ?? 0,
                'mountpoint'     => $sess['mountpoint'] ?? '',
                'source'         => '$events/client_disconnected',
            ]);
        } catch (\Throwable $e) {
            $this->warn('RuleEngine client_disconnected failed: ' . $e->getMessage());
        }

        // Acknowledge the close with a DISCONNECT packet, then tear down the
        // session state held for this connection / client.
        $disConnectMessage = new DisConnect();
        $disConnectMessage->setProtocolLevel($protocolLevel);
        $this->autoBoostSend($fd, $disConnectMessage->getContents());

        $this->clearFdSession($fd);
        $this->clearClientSession($clientId);
    }
}
