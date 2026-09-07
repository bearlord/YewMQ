<?php

/**
 * Yew framework
 * @author Bearlord <565364226@qq.com>
 */

namespace App\Plugins\Pack\PackTool;

use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Core\Server\Config\PortConfig;
use Yew\Core\Server\Server;
use Yew\Plugins\Pack\ClientData;
use Yew\Plugins\Pack\PackTool\AbstractPack;
use Yew\Yii\Helpers\Json;
use Yew\Yii\Yii;

/**
 * Class StreamPack
 * @package Yew\Plugins\Pack\PackTool
 */
class JsonRpcPack extends AbstractPack
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
        printf(json_encode($buffer) . $this->portConfig->getPackageEof() . "\n");
        return json_encode($buffer) . $this->portConfig->getPackageEof();
    }

    /**
     * Packet decode
     *
     * @param $buffer
     * @return string
     */
    public function decode($buffer)
    {
        printf($buffer . "\n");
        $data = str_replace($this->portConfig->getPackageEof(), '', $buffer);
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
        $clientData = new ClientData($fd, $portConfig->getBaseType(), 'process', $value);
        return $clientData;
    }

    /**
     * Change port config
     *
     * @param PortConfig $portConfig
     * @return bool
     */
    public static function changePortConfig(PortConfig $portConfig)
    {
        return true;
    }
}