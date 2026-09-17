<?php
namespace Yew\Framework;

use Yew\Core\Server\Beans\Request as Request;
use Yew\Core\Server\Beans\Response as Response;
use Yew\Core\Server\Beans\WebSocketFrame as WebSocketFrame;
use Yew\Core\Server\Port\ServerPort as ServerPort;

class AppPort extends AppPort__AopProxied implements \Yew\Goaop\Aop\Proxy
{

    /**
     * Property was created automatically, do not change it manually
     */
    private static $__joinPoints = [
        'method' => [
            'onHttpRequest' => [
                'advisor.Yew\\Plugins\\Pack\\Aspect\\PackAspect->aroundHttpRequest',
                'advisor.Yew\\Plugins\\Whoops\\Aspect\\WhoopsAspect->aroundRequest',
                'advisor.Yew\\Plugins\\RateLimit\\Aspect\\RateLimitAspect->aroundHttpRequest',
                'advisor.Yew\\Plugins\\CircuitBreaker\\Aspect\\CircuitBreakerAspect->aroundHttpRequest',
                'advisor.Yew\\Plugins\\Route\\Aspect\\RouteAspect->aroundHttpRequest'
            ],
            'onTcpReceive' => [
                'advisor.Yew\\Plugins\\Pack\\Aspect\\PackAspect->aroundTcpReceive',
                'advisor.Yew\\Plugins\\Route\\Aspect\\RouteAspect->aroundTcpReceive'
            ],
            'onWsMessage' => [
                'advisor.Yew\\Plugins\\Pack\\Aspect\\PackAspect->aroundWsMessage',
                'advisor.Yew\\Plugins\\Route\\Aspect\\RouteAspect->aroundWsMessage'
            ],
            'onUdpPacket' => [
                'advisor.Yew\\Plugins\\Pack\\Aspect\\PackAspect->aroundUdpPacket',
                'advisor.Yew\\Plugins\\Route\\Aspect\\RouteAspect->aroundUdpPacket'
            ],
            'onTcpClose' => [
                'advisor.Yew\\Plugins\\Topic\\Aspect\\TopicAspect->afterTcpClose',
                'advisor.Yew\\Plugins\\Route\\Aspect\\RouteAspect->beforeTcpClose',
                'advisor.Yew\\Plugins\\Uid\\Aspect\\UidAspect->afterTcpClose',
                'advisor.Yew\\Plugins\\Route\\Aspect\\RouteAspect->afterTcpClose'
            ],
            'onWsClose' => [
                'advisor.Yew\\Plugins\\Topic\\Aspect\\TopicAspect->afterWsClose',
                'advisor.Yew\\Plugins\\Route\\Aspect\\RouteAspect->beforeWSClose',
                'advisor.Yew\\Plugins\\Uid\\Aspect\\UidAspect->afterWsClose',
                'advisor.Yew\\Plugins\\Route\\Aspect\\RouteAspect->afterWSClose'
            ],
            'onTcpConnect' => [
                'advisor.Yew\\Plugins\\Route\\Aspect\\RouteAspect->afterTcpConnect'
            ],
            'onWsOpen' => [
                'advisor.Yew\\Plugins\\Route\\Aspect\\RouteAspect->afterWsOpen'
            ]
        ]
    ];
    
    /**
     * @param Request $request
     * @param Response $response
     * @return mixed|void
     */
    public function onHttpRequest(\Yew\Core\Server\Beans\Request $request, \Yew\Core\Server\Beans\Response $response)
    {
        return self::$__joinPoints['method:onHttpRequest']->__invoke($this, [$request, $response]);
    }
    
    /**
     * @param int $fd
     * @param int $reactorId
     * @param string $data
     */
    public function onTcpReceive(int $fd, int $reactorId, string $data)
    {
        return self::$__joinPoints['method:onTcpReceive']->__invoke($this, [$fd, $reactorId, $data]);
    }
    
    /**
     * @param WebSocketFrame $frame
     * @return mixed|void
     */
    public function onWsMessage(\Yew\Core\Server\Beans\WebSocketFrame $frame)
    {
        return self::$__joinPoints['method:onWsMessage']->__invoke($this, [$frame]);
    }
    
    /**
     * @param string $data
     * @param array $client_info
     */
    public function onUdpPacket(string $data, array $client_info)
    {
        return self::$__joinPoints['method:onUdpPacket']->__invoke($this, [$data, $client_info]);
    }
    
    /**
     * @param int $fd
     * @param int $reactorId
     */
    public function onTcpClose(int $fd, int $reactorId)
    {
        return self::$__joinPoints['method:onTcpClose']->__invoke($this, [$fd, $reactorId]);
    }
    
    /**
     * @param int $fd
     * @param int $reactorId
     */
    public function onWsClose(int $fd, int $reactorId)
    {
        return self::$__joinPoints['method:onWsClose']->__invoke($this, [$fd, $reactorId]);
    }
    
    /**
     * @param int $fd
     * @param int $reactorId
     * @return void
     */
    public function onTcpConnect(int $fd, int $reactorId)
    {
        return self::$__joinPoints['method:onTcpConnect']->__invoke($this, [$fd, $reactorId]);
    }
    
    /**
     * @param Request $request
     * @return mixed|void
     */
    public function onWsOpen(\Yew\Core\Server\Beans\Request $request)
    {
        return self::$__joinPoints['method:onWsOpen']->__invoke($this, [$request]);
    }
    
}
\Yew\Goaop\Proxy\ClassProxy::injectJoinPoints(AppPort::class);