<?php

namespace App\Modules\Mqtt\Services;

use App\Modules\Mqtt\Services\MqttClientService;
use App\Modules\Mqtt\Services\MqttPublishService;
use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Plugins\Mqtt\Connection\GetMqttConnection;
use Yew\Plugins\Pack\GetBoostSend;

class MqttCloseService
{
    use GetBoostSend;
    use GetLogger;
    use GetMqttConnection;

    /**
     * Handle a WebSocket close: publish the client's pending Will on abnormal
     * disconnect (abrupt drop or keepalive timeout). A normal DISCONNECT already
     * cleared the Will via cancelWill(), so nothing is published then.
     *
     * The close event only carries the connection fd (there is no clientData
     * object on teardown), so the fd is passed in directly.
     *
     * @param int $fd Connection file descriptor being closed.
     * @return void
     */
    public function closeInboundProcess(int $fd): void
    {
        $clientId = $this->getFdSession($fd, 'client_id');
        if (empty($clientId)) {
            return;
        }

        $will = $this->getWill($clientId);

        // Mark the client disconnected and stamp last_disconnected_time.
        // Clean-session clients also drop their subscriptions / offline messages,
        // mirroring the normal DISCONNECT path (MqttDisconnectService), so the
        // two teardown paths stay consistent. Must run before clearClientSession()
        // because disconnectProcess() reads the client session's session_start flag.
        (new MqttClientService())->disconnectProcess($clientId);

        // The fd session is no longer needed once the connection is gone.
        $this->clearFdSession($fd);
        // A normal DISCONNECT clears the clientId-keyed session via disconnectProcess(),
        // but an abnormal drop (TCP reset / keepalive timeout / abrupt network loss)
        // skips that path. Without this, clientSession leaks one entry per dropped
        // connection and eventually OOM-kills the single mqtt-connection process,
        // which in turn makes every getFdSession() IPC call time out (see IpcProxy).
        $this->clearClientSession($clientId);

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
//        \Swoole\Timer::after($delay * 1000, function () use ($clientId, $will) {
//            $pending = $this->getWill($clientId);
//            if (empty($pending) || empty($pending['topic'])) {
//                return; // Cancelled by a normal disconnect / reconnect.
//            }
//            $this->cancelWill($clientId);
//            $this->publishWill($pending, $clientId);
//        });
    }

    /**
     * Deliver the Will through the existing publish pipeline (routing, QoS,
     * retain and persistence all handled by MqttPublishService).
     *
     * @param array<string, mixed> $will
     * @param string $clientId Client identifier (Will topic publisher).
     * @return void
     */
    private function publishWill(array $will, string $clientId): void
    {
        (new MqttPublishService())->publishProcess(
            (int)($will['protocol_level'] ?? 5),
            $clientId,
            $will['topic'],
            $will['payload'] ?? $will['message'] ?? '',
            (int)($will['qos'] ?? 0),
            (int)($will['retain'] ?? 0)
        );
    }
}
