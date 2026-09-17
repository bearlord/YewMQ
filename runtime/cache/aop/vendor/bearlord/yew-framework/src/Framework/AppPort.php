<?php
/**
 * Yew framework
 * @author bearlord <565364226@qq.com>
 */

namespace Yew\Framework;

use Yew\Core\Server\Beans\Request;
use Yew\Core\Server\Beans\Response;
use Yew\Core\Server\Beans\WebSocketFrame;
use Yew\Core\Server\Port\ServerPort;

class AppPort__AopProxied extends ServerPort
{
    /**
     * @param int $fd
     * @param int $reactorId
     * @return void
     */
    public function onTcpConnect(int $fd, int $reactorId)
    {
        // TODO: Implement onTcpConnect() method.
    }

    /**
     * @param int $fd
     * @param int $reactorId
     */
    public function onTcpClose(int $fd, int $reactorId)
    {
        // TODO: Implement onTcpClose() method.
    }

    /**
     * @param int $fd
     * @param int $reactorId
     * @param string $data
     */
    public function onTcpReceive(int $fd, int $reactorId, string $data)
    {
        // TODO: Implement onTcpReceive() method.
    }

    /**
     * @param string $data
     * @param array $client_info
     */
    public function onUdpPacket(string $data, array $client_info)
    {
        // TODO: Implement onUdpPacket() method.
    }

    /**
     * @param Request $request
     * @param Response $response
     * @return mixed|void
     */
    public function onHttpRequest(Request $request, Response $response)
    {
    }

    /**
     * @param WebSocketFrame $frame
     * @return mixed|void
     */
    public function onWsMessage(WebSocketFrame $frame)
    {

    }

    /**
     * @param Request $request
     * @return mixed|void
     */
    public function onWsOpen(Request $request)
    {

    }

    /**
     * @param Request $request
     * @return bool
     */
    public function onWsPassCustomHandshake(Request $request): bool
    {
        return true;
    }

    /**
     * @param int $fd
     * @param int $reactorId
     */
    public function onWsClose(int $fd, int $reactorId)
    {

    }
}
include_once AOP_CACHE_DIR . '/_proxies/vendor/bearlord/yew-framework/src/Framework/AppPort.php/Yew/Framework/AppPort.php';