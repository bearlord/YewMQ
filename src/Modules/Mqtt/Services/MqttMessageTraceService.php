<?php

namespace App\Modules\Mqtt\Services;

use App\Models\Extension\MqttMessageTrace;
use Carbon\Carbon;

/**
 * Lifecycle tracing for MQTT messages (table {{%mqtt_message_trace}}).
 *
 * Every meaningful event in a message's path through the broker is appended as a
 * row, giving an end-to-end view: ingest -> store -> route -> per-subscriber
 * deliver -> ack stages -> offline buffering -> final delivery.
 *
 * trace_type values (mirrors the migration docblock):
 *   1 publish_received   2 stored   3 retained   4 offline_buffered
 *   5 delivered   6 puback   7 pubrec   8 pubrel   9 pubcomp   10 ack_completed
 *
 * Tracing is OFF by default and enabled via the MQTT_MESSAGE_TRACE_ENABLED env
 * var (any of "1"/"true"/"on"/"yes", case-insensitive; "0"/"false"/"off"/"no"
 * keep it disabled). It is intentionally best-effort: a tracing failure must
 * never break the message path, so all writes are wrapped in try/catch and are
 * skipped entirely when disabled.
 */
class MqttMessageTraceService
{
    public const TYPE_PUBLISH_RECEIVED  = 1;
    public const TYPE_STORED            = 2;
    public const TYPE_RETAINED          = 3;
    public const TYPE_OFFLINE_BUFFERED  = 4;
    public const TYPE_DELIVERED         = 5;
    public const TYPE_PUBACK            = 6;
    public const TYPE_PUBREC            = 7;
    public const TYPE_PUBREL            = 8;
    public const TYPE_PUBCOMP           = 9;
    public const TYPE_ACK_COMPLETED     = 10;

    private static ?bool $enabled = null;

    public static function isEnabled(): bool
    {
        if (self::$enabled === null) {
            $v = getenv('MQTT_MESSAGE_TRACE_ENABLED');
            self::$enabled = $v !== false
                && $v !== ''
                && !in_array(strtolower(trim($v)), ['0', 'false', 'off', 'no'], true);
        }

        return self::$enabled;
    }

    /**
     * Append a trace row. No-op when tracing is disabled or on any failure.
     *
     * @param int $mqttMessageId related mqtt_message.id (0 when not yet known)
     * @param int $traceType one of the TYPE_* constants
     * @param array $extra extra columns: direction, client_id, packet_id,
     *                     mqtt_message_ack_id, detail
     * @return void
     */
    public static function trace(int $mqttMessageId, int $traceType, array $extra = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $model = new MqttMessageTrace();
            $model->setAttributes(array_merge([
                'mqtt_message_id'      => $mqttMessageId,
                'trace_type'           => $traceType,
                'direction'            => 0,
                'mqtt_message_ack_id'  => null,
                'client_id'            => null,
                'packet_id'            => null,
                'detail'               => null,
                'created_at'           => (new Carbon())->format('Y-m-d H:i:s.u'),
            ], $extra), false);
            $model->save(false);
        } catch (\Throwable $e) {
            // Tracing is best-effort; never break the message path.
        }
    }
}
