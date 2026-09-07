<?php
/**
 * Created by PhpStorm.
 * User: 白猫
 * Date: 16-7-15
 * Time: 下午2:43
 */

namespace App\Plugins\Pack\PackTool;

use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Core\Server\Config\PortConfig;
use Yew\Core\Server\Server;
use Yew\Plugins\Pack\ClientData;
use Yew\Plugins\Pack\PackTool\AbstractPack;
/**
 * Class StreamPack
 * @package Yew\Plugins\Pack\PackTool
 */
class UdpPlainPack extends AbstractPack
{
    use GetLogger;

    /**
     * Packet encode
     *
     * @param $buffer
     * @return string
     */
    public function encode($buffer): string
    {
        return $buffer;
    }

    /**
     * Packet decode
     *
     * @param $buffer
     * @return string
     */
    public function decode($buffer)
    {
        return $buffer;
    }

    /**
     * Data pack
     *
     * @param $data
     * @param PortConfig $portConfig
     * @param string|null $topic
     * @return string
     */
    public function pack($data, PortConfig $portConfig, ?string $topic = null): string
    {
        $this->portConfig = $portConfig;
        return $this->encode($data);
    }

    /**
     * Packet unpack
     *
     * @param int $fd
     * @param string $data
     * @param PortConfig $portConfig
     * @return mixed
     * @throws \Yew\Core\Plugins\Config\ConfigException
     */
    public function unPack(int $fd, $data, PortConfig $portConfig): ?ClientData
    {
        $this->portConfig = $portConfig;
        //Value can be empty
        $value = $this->decode($data);
        return new ClientData($fd, $portConfig->getBaseType(), 'onUdpPacket', $value);
    }

    /**
     * Change port config
     *
     * @param PortConfig $portConfig
     * @return bool
     * @throws \Exception
     */
    public static function changePortConfig(PortConfig $portConfig): bool
    {
        return true;
    }
}
