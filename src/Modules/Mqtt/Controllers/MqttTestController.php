<?php

namespace App\Modules\Mqtt\Controllers;

use Carbon\Carbon;
use Yew\Framework\Controller;
use Yew\Plugins\Mqtt\Connection\GetMqttConnection;
use Yew\Plugins\Route\Annotation\GetMapping;
use Yew\Plugins\Route\Annotation\ResponseBody;
use Yew\Plugins\Route\Annotation\RestController;

/**
 * @RestController("/mqtt/test")
 */
class MqttTestController extends Controller
{
    use GetMqttConnection;

    /**
     * @GetMapping("ipc1")
     * @return array
     */
    public function actionIpc1()
    {
        $fd = 100;
        $clientPKId = 101;
        $clientId = 'test101';

        $username = 'user101';
        $ipAddress = '192.168.108.101';
        $keepAlive = 10;

        $sessionStart = 1;

        $time = (new Carbon())->format('Y-m-d H:i:s.u');
        printf("%s: ipc1\n", $time);

        // Register session state: map fd -> uid and clientId.
        $this->setFdSession($fd, 'uid', $clientPKId);
        // Keep clientId on the fd session so the close handler can resolve the Will.
        $this->setFdSession($fd, 'client_id', $clientId);


        $this->setClientSessionMulti($clientId, [
            'uid' => $clientPKId,
            'session_start' => $sessionStart,
        ]);

        $res['uid']  = $this->getFdSession($fd, 'uid');
        $res['client_id'] = $this->getFdSession($fd, 'client_id');

        $res['client_session'] = $this->getClientSessionMulti($clientId);

        // Expose connection-level metadata on the fd session so later PUBLISH /
        // SUBSCRIBE rules can reference it (client_id is already stored upstream).
        $this->setFdSession($fd, 'username', $username);
        $this->setFdSession($fd, 'peerhost', $ipAddress);
        $this->setFdSession($fd, 'keep_alive', $keepAlive);
        $this->setFdSession($fd, 'mountpoint', '');


        // MQTT keepalive: arm the idle watchdog (0/empty disables enforcement).
        if ($keepAlive !== null) {
            $this->setKeepAlive($fd, (int)$keepAlive);
        }

        $res['fd_session'] = $this->getFdSessionMulti($fd);

        return $res;
    }

    /**
     * @GetMapping("ipc2")
     * @return array
     */
    public function actionIpc2()
    {
        $fd = 100;
        $res['fd_session'] = $this->getFdSessionMulti($fd);

        return $res;
    }
}