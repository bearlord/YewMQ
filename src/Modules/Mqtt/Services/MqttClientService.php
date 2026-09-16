<?php

namespace App\Modules\Mqtt\Services;

use App\Models\Extension\MqttClient;
use Carbon\Carbon;
use Yew\Mqtt\Message\PingResp;
use Yew\Mqtt\Tools\ProtocolLevel;
use Yew\Plugins\Mqtt\Connection\GetMqttConnection;
use Yew\Plugins\Pack\GetBoostSend;

class MqttClientService
{
    use GetBoostSend;
    use GetMqttConnection;

    /**
     * @param int $id
     * @return array|null
     */
    public function getItemById(int $id): ?array
    {
        $model = MqttClient::find()
            ->where([
                'id' => $id
            ])
            ->one();
        if (empty($model)) {
            return null;
        }
        return $model->toArray();
    }

    /**
     * @param int $id
     * @return string|null
     */
    public function getClientIdById(int $id): ?string
    {
        $item = $this->getItemById($id);
        if (empty($item)) {
            return null;
        }
        return $item['client_id'];
    }

    /**
     * @param string $clientId
     * @return array|null
     */
    public function getItemByClientId(string $clientId): ?array
    {
        $model = MqttClient::find()
            ->where([
                'client_id' => $clientId
            ])
            ->one();
        if (empty($model)) {
            return null;
        }
        return $model->toArray();
    }

    /**
     * @param string $clientId
     * @param array $data
     * @return int
     */
    public function saveOrUpdateMqttClient(string $clientId, array $data): int
    {
        $model = MqttClient::find()
            ->where([
                'client_id' => $clientId
            ])
            ->one();
        if (empty($model)) {
            $model = new MqttClient();
            $model->setAttributes([
                'client_id' => $clientId,
            ], false);
        }

        $protocolLevel = $data['protocol_level'] ?? ProtocolLevel::PROTOCOL_LEVEL_V3_1_1;

        $cleanStart = $data['clean_start'] ?? 0;

        $sessionExpiry = null;
        if ($protocolLevel == ProtocolLevel::PROTOCOL_LEVEL_V5) {
            $sessionExpiry = $data['properties']['session_expiry'] ?? null;
        }

        $currentTime6 = (new Carbon())->format('Y-m-d H:i:s.u');

        $model->setAttributes([
            'username' => $data['username'] ?? null,
            'protocol_level' => $protocolLevel,
            'clean_start' => $cleanStart,
            'session_expiry' => $sessionExpiry,
            'is_active' => $data['is_active'] ?? 1,
            'ip_address' => $data['ip_address'] ?? null,
            'keep_alive' => $data['keep_alive'] ?? 60,
            'last_connected_time' => $currentTime6,
        ], false);
        $model->save(false);

        if ($cleanStart) {
            (new MqttSubscriptionService)->deleteSubscriptionsByClientId($clientId);
            (new MqttOfflineMessageService())->deleteOfflineMessageByClientId($clientId);
        }

        return $model->id;
    }

    /**
     * @param string $clientId
     * @param array $data
     * @return bool
     */
    public function updateMqttClient(string $clientId, array $data): bool
    {
        $model = MqttClient::find()
            ->where([
                'client_id' => $clientId
            ])
            ->one();
        if (empty($model)) {
            return false;
        }
        $model->setAttributes($data, false);
        $model->save(false);

        return true;
    }

    /**
     * @param string $clientId
     * @return bool
     */
    public function disconnectProcess(string $clientId): bool
    {
        $sessionStart = $this->getClientSession($clientId, 'session_start');
        if ($sessionStart) {
            (new MqttSubscriptionService)->deleteSubscriptionsByClientId($clientId);
            (new MqttOfflineMessageService())->deleteOfflineMessageByClientId($clientId);
        }

        return $this->updateMqttClient($clientId, [
            'is_active' => 0,
            'last_disconnected_time' => (new Carbon())->format('Y-m-d H:i:s.u')
        ]);
    }

    public function pingreqProcess(string $clientId): bool
    {
        return $this->updateMqttClient($clientId, [
            'is_active' => 1,
            'last_connected_time' => (new Carbon())->format('Y-m-d H:i:s.u')
        ]);
    }

    /**
     * Handle an inbound PINGREQ: refresh the keep-alive watchdog, update the
     * client's liveness state in the business layer, and reply with PINGRESP.
     *
     * Only the controller's clientData object is passed in; the service
     * resolves the fd / protocol level / clientId from it.
     *
     * @param object $clientData ClientData (has getFd/getData).
     * @return void
     */
    public function pingreqInboundProcess(object $clientData): void
    {
        $fd = $clientData->getFd();
        $payload = $clientData->getData();
        $protocolLevel = $payload['protocol_level'] ?? null;
        $clientId = $payload['client_id'] ?? null;

        // Any inbound packet proves liveness; refresh the keepalive watchdog.
        $this->touchActivity($fd);

        // Business: refresh the keep-alive / liveness state for this client.
        $this->pingreqProcess($clientId);

        // Reply with a PINGRESP packet to acknowledge the heartbeat.
        $pingResp = new PingResp();
        $pingResp->setProtocolLevel($protocolLevel);
        $this->autoBoostSend($fd, $pingResp->getContents());
    }
}
