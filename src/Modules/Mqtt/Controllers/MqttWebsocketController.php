<?php

namespace App\Modules\Mqtt\Controllers;

use App\Modules\Mqtt\Services\MqttClientService;
use App\Modules\Mqtt\Services\MqttCloseService;
use App\Modules\Mqtt\Services\MqttConnectService;
use App\Modules\Mqtt\Services\MqttDisconnectService;
use App\Modules\Mqtt\Services\MqttPublishService;
use App\Modules\Mqtt\Services\MqttSubscriptionService;
use Yew\Framework\Controller;
use Yew\Plugins\Route\Annotation\RequestMapping;
use Yew\Plugins\Route\Annotation\WsController;

/**
 * @WsController("mqtt-websocket")
 */
class MqttWebsocketController extends Controller
{
    /**
     * @RequestMapping("connect")
     * @return void
     */
    public function actionConnect(): void
    {
        (new MqttConnectService())->connectInboundProcess($this->clientData);
    }

    /**
     * @RequestMapping("disconnect")
     * @return void
     */
    public function actionDisconnect(): void
    {
        (new MqttDisconnectService())->disconnectProcess($this->clientData);
    }

    /**
     * @RequestMapping("pingreq")
     * @return void
     */
    public function actionPingreq(): void
    {
        (new MqttClientService())->pingreqInboundProcess($this->clientData);
    }

    /**
     * @RequestMapping("subscribe")
     * @return void
     */
    public function actionSubscribe(): void
    {
        (new MqttSubscriptionService())->subscribeInboundProcess($this->clientData);
    }

    /**
     * @RequestMapping("unsubscribe")
     * @return void
     */
    public function actionUnsubscribe(): void
    {
        (new MqttSubscriptionService())->unsubscribeInboundProcess($this->clientData);
    }

    /**
     * @RequestMapping("publish")
     * @return void
     */
    public function actionPublish(): void
    {
        (new MqttPublishService())->inboundPublishProcess($this->clientData);
    }

    /**
     * @RequestMapping("pubrec")
     * @return void
     */
    public function actionPubrec(): void
    {
        (new MqttPublishService())->pubrecInboundProcess($this->clientData);
    }

    /**
     * @RequestMapping("pubrel")
     * @return void
     */
    public function actionPubrel(): void
    {
        (new MqttPublishService())->pubrelInboundProcess($this->clientData);
    }

    /**
     * @RequestMapping("pubcomp")
     * @return void
     */
    public function actionPubcomp(): void
    {
        (new MqttPublishService())->pubcompInboundProcess($this->clientData);
    }

    /**
     * @RequestMapping("puback")
     * @return void
     */
    public function actionPuback(): void
    {
        (new MqttPublishService())->pubackInboundProcess($this->clientData);
    }

    /**
     * WebSocket close: publish pending Will (if any) and clear fd/client session.
     * @param int $fd
     * @param int $reactorId
     */
    public function onWsClose(int $fd, int $reactorId): void
    {
        (new MqttCloseService())->closeInboundProcess($fd);
    }
}
