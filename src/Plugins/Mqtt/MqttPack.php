<?php
/**
 * Yew framework
 * @author tmtbe <896369042@qq.com>
 */

namespace App\Plugins\MQTT;

use Yew\Core\Server\Config\PortConfig;
use Yew\Core\Server\Server;

use Yew\Mqtt\Packet\PackV3;
use Yew\Mqtt\Packet\PackV5;
use Yew\Mqtt\Packet\UnPackV3;
use Yew\Mqtt\Packet\UnPackV5;
use Yew\Mqtt\Protocol\ProtocolV3;
use Yew\Mqtt\Protocol\ProtocolV5;
use Yew\Mqtt\Protocol\Types;

use Yew\Mqtt\Tools\UnPackTool;
use Yew\Plugins\Pack\ClientData;
use Yew\Plugins\Pack\GetBoostSend;
use Yew\Plugins\Pack\PackTool\AbstractPack;

use Yew\Plugins\Redis\GetRedis;
use Yew\Yew;

/**
 * Class MqttPack
 * @package App\Plugins\Mqtt
 */
class MqttPack extends AbstractPack
{
    use GetBoostSend;
    use GetRedis;

    /**
     * @var array
     */
    protected array $packMap = [
        3 => PackV3::class,
        4 => PackV3::class,
        5 => PackV5::class
    ];

    /**
     * @var array
     */
    protected array $unpackMap = [
        3 => UnPackV3::class,
        4 => UnPackV3::class,
        5 => UnPackV5::class
    ];

    /**
     * @var array
     */
    protected array $protocolMap = [
        3 => ProtocolV3::class,
        4 => ProtocolV3::class,
        5 => ProtocolV5::class
    ];


    /**
     * MqttPack constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        Server::$instance->getContainer()->injectOn($this);
    }

    /**
     * 保存客户端协议版本到上下文
     *
     * @param int $fd
     * @param int $protocolLevel
     */
    protected function setFdProtocolLevel(int $fd, int $protocolLevel): void
    {
        $key = sprintf("MQTT_FD_PROTOCOL_LEVEL_%s", $fd);

        setContextValue($key, $protocolLevel);
        $this->redis()->set($key, $protocolLevel);
    }

    /**
     * 保存客户端协议版本到上下文
     *
     * @param int $fd
     * @return int|null
     */
    protected function getFdProtocolLevel(int $fd): ?int
    {
        $key = sprintf("MQTT_FD_PROTOCOL_LEVEL_%s", $fd);
        $value = getContextValue($key);

        if (!$value) {
            $value = $this->redis()->get($key);
            setContextValue($key, $value);
        }

        return $value;
    }

    /**
     * @param $protocolLevel
     * @return object|ProtocolV3|ProtocolV5
     */
    protected function getProtocolInstance($protocolLevel): object
    {
        $mapClass = $this->protocolMap[$protocolLevel];
        return Yew::createObject($mapClass);
    }

    /**
     * 保存 Fd 和 ClientId关系，fd是key
     *
     * @param int $fd
     * @param string $clientId
     */
    protected function setFdClientIdMap(int $fd, string $clientId): void
    {
        $key = sprintf("MQTT_Fd_ClientId_Map_%s", $fd);

        setContextValue($key, $clientId);
        $this->redis()->set($key, $clientId);
    }

    /**
     * 保存 ClientId 和 Fd关系，clientId是key
     *
     * @param string $clientId
     * @param int $fd
     */
    protected function setClientIdFdMap(string $clientId, int $fd): void
    {
        $key = sprintf("MQTT_ClientId_Fd_Map_%s", $clientId);

        setContextValue($key, $fd);
        $this->redis()->set($key, $fd);
    }

    /**
     * 根据 Fd 获取 ClientId
     *
     * @param int $fd
     * @return false|mixed|string
     */
    protected function getClientIdFromFd(int $fd)
    {
        $key = sprintf("MQTT_Fd_ClientId_Map_%s", $fd);
        $value = getContextValue($key);
        if (!$value) {
            $value = $this->redis()->get($key);
            setContextValue($key, $value);
        }
        return $value;
    }

    /**
     * 根据 ClientId 获取 Fd
     *
     * @param string $clientId
     * @return false|mixed|string
     */
    protected function getFdFromClientId(string $clientId)
    {
        $key = sprintf("MQTT_ClientId_Fd_Map_%s", $clientId);
        $value = getContextValue($key);
        if (!$value) {
            $value = $this->redis()->get($key);
            setContextValue($key, $value);
        }
        return $value;
    }

    /**
     * @param $clientId
     * @param $data
     */
    protected function setClientConnectionInfo($clientId, $data)
    {
        $key = sprintf("MQTT_CLIENT_CONNECTION_%s", $clientId);

        setContextValue($key, $data);
        $this->redis()->hMSet($key, $data);
    }

    /**
     * @param $buffer
     * @return mixed
     */
    public function encode($buffer)
    {
        return $buffer;
    }

    /**
     * @param $buffer
     * @return mixed
     */
    public function decode($buffer)
    {
        return $buffer;
    }

    /**
     * @param mixed $data
     * @param PortConfig $portConfig
     * @param string|null $topic
     * @return mixed
     */
    public function pack($data, PortConfig $portConfig, ?string $topic = null)
    {
        return $data;
    }

    /**
     * @param int $fd
     * @param mixed $data
     * @param PortConfig $portConfig
     * @return ClientData|null
     */
    public function unPack(int $fd, $data, PortConfig $portConfig): ?ClientData
    {
        error_reporting(E_ALL);
        ini_set("display_errors", "on");

        $type = UnPackTool::getType($data);

        switch ($type) {
            case Types::CONNECT:
                //协议版本
                $protocolLevel = UnPackTool::getLevel($data);
                //解包数据
                $unpackedData = call_user_func([$this->getProtocolInstance($protocolLevel), 'unpack'], $data);
                //客户端标识
                $clientId = $unpackedData['client_id'];
                //保存协议到上下文
                $this->setFdProtocolLevel($fd, $protocolLevel);
                //保存 fd 和 clientId 映射关系
                $this->setFdClientIdMap($fd, $clientId);
                //保存 clientId 和 fd 映射关系
                $this->setClientIdFdMap($clientId, $fd);
                //保存客户端的连接信息
                $this->setClientConnectionInfo($clientId, $unpackedData);

                break;

            default:
                //协议版本
                $protocolLevel = $this->getFdProtocolLevel($fd);
                //解包数据
                $unpackedData = call_user_func([$this->getProtocolInstance($protocolLevel), 'unpack'], $data);
                //客户端标识
                $clientId = $this->getClientIdFromFd($fd);
                }

        return new ClientData($fd, $portConfig->getBaseType(), 'onReceive', [
            'type' => $type,
            'level' => $protocolLevel,
            'client_id' => $clientId,
            'data' => $unpackedData
        ]);
    }

    /**
     * @param PortConfig $portConfig
     * @throws \Exception
     */
    public static function changePortConfig(PortConfig $portConfig)
    {
        return;
    }

}