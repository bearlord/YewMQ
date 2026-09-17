# 离线消息机制 (Offline Messages)

MQTT broker 在订阅者不在线时,会把发往其订阅主题的 QoS > 0 消息缓冲下来,等订阅者重新连接 / 重新订阅时再重投。本文说明本项目的实现与依赖。

## 1. 何时缓冲

发布者 PUBLISH 入站时,若某订阅者当前无存活连接(`deliverToSubscribers` 找不到在线 subscriber),且该消息 QoS > 0,则在 `MqttPublishService::publishInboundProcess` 中调用:

```php
(new MqttOfflineMessageService())->saveOfflineMessage([ ... ]);
```

每条缓冲记录写入 `mqtt_offline_message` 表: `client_id` / `topic` / `payload` / `qos` / `delivered`(0=未投)。

> QoS 0 消息"即发即弃",不缓冲(订阅者离线即丢失),符合 MQTT 语义。

## 2. 何时重投 (三个触发点)

| 触发点 | 位置 | 说明 |
|---|---|---|
| 发布入站 | `MqttPublishService::publishInboundProcess` | 订阅者离线 → 写入缓冲 |
| 订阅时 | `MqttSubscriptionService::subscribeProcess` | 客户端 SUBSCRIBE 后,按本次订阅过滤器重投匹配缓冲 |
| 连接时 | `MqttConnectService::connectProcess` | 持久会话重连,按已恢复的订阅重投(标准"连接即重放") |

重投统一走私有方法 `MqttPublishService::deliverOfflineMessages(int $fd, ?int $protocolLevel, string $clientId, array $topics)`。它通过 `client_id + delivered=0` 拉取缓冲,再用 `topicMatch` 过滤出匹配订阅的消息。

## 3. QoS 处理与确认链路

- **QoS 0**:直接下发,缓冲行立即删除(`$row->delete()`)。
- **QoS 1 / 2**:下发时使用 `nextPacketId()` 分配下行 `packet_id`,写入下行 `mqtt_message_ack` 记录(`direction=down`, `stage=1`, `ack_status=0`),并把缓冲行的 `delivered=1`、`packet_id` 标出(保留,等确认后再清)。
  - 订阅者回 PUBACK(QoS1)/ PUBCOMP(QoS2)→ `MqttPublishService::completeAckProcess($receiverId, $packetId)`
  - 该方法先更新 `mqtt_message_ack` 为完成,再按 `client_id + packet_id` 删除对应的 `mqtt_offline_message` 缓冲行。
  - 重复重投由 `delivered=0` 过滤天然避免(已投行不会再被 `getUndeliveredByClientId` 选出)。

> 因 `packet_id` 是下行临时分配的,离线缓冲与实时下行共享同一套 ack 完成路径,无需为离线消息单独写确认逻辑。

## 4. 会话与清理边界

- **干净会话 (clean_start=1)**:`MqttClientService::saveOrUpdateMqttClient` 在连接时清掉订阅与离线缓冲;重连无缓冲可投,触发点自动为空操作。
- **持久会话 (clean_start=0)**:订阅与缓冲保留,连接时按已恢复订阅重投。
- **发布者 QoS2 未达 PUBREL**:上行暂存随 fd 关闭清除,`mqtt_message` 留下"已落库未投递"孤儿记录,属 MQTT "直到 PUBREL 前至少一次"的正常行为。

## 5. 依赖的迁移 (重要)

离线重投的正确运行**依赖** `mqtt_offline_message.packet_id` 列与 `idx_client_packet` 索引,由以下迁移提供:

```
src/Migrations/m260917_090000_add_packet_id_to_mqtt_offline_message.php
```

> 若该迁移未执行,`deliverOfflineMessages` 写入 `packet_id` 与 `completeAckProcess` 按 `packet_id` 删行都会失败,QoS>0 离线重投会中断。

其他相关表迁移(均已存在):`m250930_084859_create_mqtt_offline_message_table`、`m260801_090000_create_mqtt_message_ack_table`、`m250930_073920_create_mqtt_subscription_table`。

### 执行迁移

迁移需在**加载了 Swoole 扩展**的 broker 运行环境中执行(普通 CLI `php` 因缺少 `swoole_version()` 无法启动控制台):

```bash
# 在项目根目录(带 swoole 的 php 环境)
php yew migrate              # 应用全部待执行迁移(幂等)
# 或仅确认状态(只读)
php yew migrate/new          # 列出待执行迁移
php yew migrate/history      # 列出已应用迁移
```

### 直接核对数据库

```sql
SELECT version FROM migration
WHERE version = 'm260917_090000_add_packet_id_to_mqtt_offline_message';
-- 有记录 = 已应用;否则需先执行上面的迁移命令。
```
