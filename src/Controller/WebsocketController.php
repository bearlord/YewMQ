<?php

namespace App\Controller;

use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Coroutine\Http\SwooleRequest;
use Yew\Framework\Controller;
use Yew\Plugins\Connection\GetConnection;
use Yew\Plugins\Pack\GetBoostSend;
use Yew\Plugins\Route\Annotation\RequestMapping;
use Yew\Plugins\Route\Annotation\WsController;
use Yew\Core\Server\Server;
use Yew\Plugins\Uid\GetUid;

/**
 * @WsController("/")
 * /
 */
class WebsocketController extends Controller
{

    use GetBoostSend;
    use GetLogger;
    use GetUid;
    use GetConnection;

    /**
     * @RequestMapping("onWsOpen")
     * @param int $fd
     * @param int $reactorId
     * @param SwooleRequest $request
     * @return void
     */
    public function actionOnWsOpen(int $fd, int $reactorId, SwooleRequest $request): void
    {
    }

    /**
     * @RequestMapping("beforeWsClose")
     * @param int $fd
     * @param int $reactorId
     * @return void
     */
    public function actionBeforeWsClose(int $fd, int $reactorId): void
    {
        $clientId = $this->getFdSession($fd, 'client_id') ?? null;

        $this->clearFdSession($fd);

        if (!empty($clientId)) {
            $this->clearClientSession($clientId);
        }

        $this->unBindUid($fd);
    }
}