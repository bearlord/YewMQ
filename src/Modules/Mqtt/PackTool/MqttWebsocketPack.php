<?php
/**
 * Yew framework
 * @author tmtbe <896369042@qq.com>
 */

namespace App\Modules\Mqtt\PackTool;

use Yew\Core\Plugins\Logger\GetLogger;
use Yew\Core\Server\Config\PortConfig;
use Yew\Coroutine\Server\Server;

use Yew\Mqtt\Packet\PackV3;
use Yew\Mqtt\Packet\PackV5;
use Yew\Mqtt\Packet\UnPackV3;
use Yew\Mqtt\Packet\UnPackV5;
use Yew\Mqtt\Protocol\ProtocolV3;
use Yew\Mqtt\Protocol\ProtocolV5;
use Yew\Mqtt\Protocol\Types;

use Yew\Mqtt\Tools\UnPackTool;
use Yew\Plugins\Mqtt\Connection\GetMqttConnection;
use Yew\Plugins\Pack\ClientData;
use Yew\Plugins\Pack\GetBoostSend;
use Yew\Plugins\Pack\PackTool\AbstractPack;

use Yew\Plugins\Redis\GetRedis;
use Yew\Yew;

/**
 * Class MqttPack
 * @package App\Plugins\Mqtt
 */
class MqttWebsocketPack extends AbstractPack
{
    use GetBoostSend;
    use GetRedis;
    use GetLogger;
    use GetMqttConnection;

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
     * @var array Queue of fully reassembled MQTT packets that arrived inside the
     *            same WebSocket frame but were not dispatched yet. Keyed by fd;
     *            each value is a list of raw, complete packet strings.
     */
    private static array $pendingPackets = [];

    /**
     * MqttPack constructor.
     */
    public function __construct()
    {
        Server::$instance->getContainer()->injectOn($this);
    }



    /**
     * @param $protocolLevel
     * @return object|ProtocolV3|ProtocolV5
     */
    protected function getProtocolInstance($protocolLevel): object
    {
        $mapClass = $this->protocolMap[$protocolLevel];

        var_dump([
            'protocolLevel' => $protocolLevel,
            'mapClass' => $mapClass
        ]);
        return Yew::createObject($mapClass);
    }

    /**
     * @param $buffer
     * @return mixed
     */
    public function encode($buffer): mixed
    {
        return $buffer;
    }

    /**
     * @param $buffer
     * @return mixed
     */
    public function decode($buffer): mixed
    {
        return $buffer;
    }

    /**
     * @param mixed $data
     * @param PortConfig $portConfig
     * @param string|null $topic
     * @return mixed
     */
    public function pack($data, PortConfig $portConfig, ?string $topic = null): mixed
    {
        //printf("pack data: %s, %s\n", bin2hex($data), json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
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

        printf("time: %s, date:%s\n", date('Y-m-d H:i:s'), bin2hex($data));

        // If a previous frame delivered several MQTT packets at once, dispatch
        // the next queued one before consuming the new data.
        if (!empty(self::$pendingPackets[$fd])) {
            $data = array_shift(self::$pendingPackets[$fd]);
            if (empty(self::$pendingPackets[$fd])) {
                unset(self::$pendingPackets[$fd]);
            }
            return $this->parsePacket($fd, $data, $portConfig);
        }

        if ($portConfig->isOpenRecvBuffer()) {
            // Append the incoming frame to the per-connection receive buffer.
            if (!isset(Server::$buffers[$fd])) {
                Server::$buffers[$fd] = '';
            }
            Server::$buffers[$fd] .= $data;

            // Extract every complete MQTT packet now available in the buffer.
            // The first one is returned this call; any extras are queued for
            // subsequent calls so no reassembled packet is ever lost.
            $firstPacket = null;
            while (($packetLength = $this->decodeMqttPacketLength(Server::$buffers[$fd])) !== null
                && strlen(Server::$buffers[$fd]) >= $packetLength) {
                $packet = substr(Server::$buffers[$fd], 0, $packetLength);
                Server::$buffers[$fd] = substr(Server::$buffers[$fd], $packetLength);

                if ($firstPacket === null) {
                    $firstPacket = $packet;
                } else {
                    self::$pendingPackets[$fd][] = $packet;
                }
            }

            if ($firstPacket === null) {
                // Buffer does not yet hold a full packet; wait for next frame.
                return null;
            }
            $data = $firstPacket;
        }

        return $this->parsePacket($fd, $data, $portConfig);
    }

    /**
     * Parse a single, complete MQTT packet and build the ClientData for it.
     *
     * @param int $fd
     * @param string $data Complete MQTT packet bytes.
     * @param PortConfig $portConfig
     * @return ClientData
     */
    private function parsePacket(int $fd, string $data, PortConfig $portConfig): ClientData
    {
        $type = UnPackTool::getType($data);

        switch ($type) {
            case Types::CONNECT:
                // Protocol version
                $protocolLevel = UnPackTool::getLevel($data);
                // Unpack payload
                $unpackedData = call_user_func([$this->getProtocolInstance($protocolLevel), 'unpack'], $data);
                // Client identifier
                $clientId = $unpackedData['client_id'];

                // Save protocol level and client identifier in memory
                $this->setFdSession($fd, 'protocol_level', $protocolLevel);
                // Save client identifier in memory
                $this->setFdSession($fd, 'client_id', $clientId);
                // Save client identifier-keyed session property (the owning fd) in memory
                $this->setClientSession($clientId, 'fd', $fd);

                break;

            default:
                var_dump([
                    'fd' => $fd,
                    'worker_id' => Server::$instance->getServer()->worker_id,
                    'data' => $data,
                    'data-hex' => bin2hex($data),
                    'protocol_level' => $this->getFdSession($fd, 'protocol_level')
                ]);

                $fdSessionData = $this->getFdSessionMulti($fd);
                // Protocol level (already known for this connection)
                $protocolLevel = $fdSessionData['protocol_level'];
                // Client identifier
                $clientId = $fdSessionData['client_id'];
                // Unpack payload
                $unpackedData = call_user_func([$this->getProtocolInstance($protocolLevel), 'unpack'], $data);
        }

        $typeName = Types::getType($type);

        printf("unpack data: %s\n", json_encode([
            'type' => $typeName,
            'type_name' => $typeName,
            'data' => $unpackedData
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        return new ClientData(
            $fd,
            $portConfig->getBaseType(),
            sprintf("mqtt-websocket/%s", $typeName),
            [
                'type' => $type,
                'protocol_level' => $protocolLevel,
                'client_id' => $clientId,
                'data' => $unpackedData
            ]);
    }

    /**
     * Decode the MQTT Remaining Length field and return the total packet length
     * (1-byte fixed header + variable-length field + payload).
     *
     * Returns null when the buffer does not yet contain enough bytes to read
     * the (variable-length) Remaining Length field, or when the field is
     * malformed (exceeds the 4-byte maximum).
     *
     * @param string $buffer
     * @return int|null
     */
    private function decodeMqttPacketLength(string $buffer): ?int
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $multiplier = 1;
        $value = 0;
        $offset = 1; // skip the 1-byte fixed header

        do {
            if ($offset - 1 >= 4 || !isset($buffer[$offset])) {
                return null; // malformed or incomplete length field
            }
            $digit = ord($buffer[$offset]);
            $value += ($digit & 0x7F) * $multiplier;
            $multiplier *= 128;
            $offset++;
        } while (($digit & 0x80) !== 0);

        return 1 + ($offset - 1) + $value;
    }

    /**
     * @param PortConfig $portConfig
     */
    public static function changePortConfig(PortConfig $portConfig)
    {
    }

}