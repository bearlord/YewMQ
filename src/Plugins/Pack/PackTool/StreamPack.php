<?php
/**
 * Created by PhpStorm.
 * User: 白猫
 * Date: 16-7-15
 * Time: 下午2:43
 */

namespace APP\Plugins\Pack\PackTool;

use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Core\Server\Config\PortConfig;
use Yew\Core\Server\Server;
use Yew\Plugins\Pack\ClientData;
use Yew\Plugins\Pack\PackTool\AbstractPack;
/**
 * Class StreamPack
 * @package Yew\Plugins\Pack\PackTool
 */
class StreamPack extends AbstractPack
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
        return !empty($buffer) ? hex2bin($buffer) : '';
//        return !empty($buffer) ? hex2bin($buffer . '1B') : '';
    }

    /**
     * Packet decode
     *
     * @param $buffer
     * @return string
     */
    public function decode($buffer)
    {
        $data = bin2hex($buffer);
        return $data;
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
     * @param int $fd
     * @param $data
     * @param PortConfig $portConfig
     * @return ClientData|null
     */
    public function unPack(int $fd, $data, PortConfig $portConfig): ?ClientData
    {
        printf("unPack: ||%s||\n", $data);

        $this->portConfig = $portConfig;
        //Value can be empty
        $value = $this->decode($data);

        return new ClientData($fd, $portConfig->getBaseType(), 'onReceive', $value);
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
