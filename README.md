# YewMQ

> A high-performance **MQTT Broker** (PHP implementation) built on the [Yew](https://github.com/bearlord/yew-framework) framework and Swoole coroutines.
>
> Other languages: [中文文档 (Chinese)](docs/zh-CN/README.md)

YewMQ is an MQTT message broker written in PHP and running on the Swoole resident-memory runtime. While guaranteeing standard MQTT semantics, it persists subscriptions, sessions, offline messages, retained messages, will messages, ACLs and authentication to a relational database, and provides message bridging, forwarding and interception through a built-in **rule engine**. It is well suited to IoT, instant messaging, device ingestion and similar scenarios.

---

## 1. Core Features

- **Protocol support**: Full dual-protocol stack of **MQTT 3.1.1** and **MQTT 5.0**.
- **Full QoS levels**: QoS 0 / 1 / 2 are all supported. QoS 2 uses an "persist first, deliver after PUBREL" exactly-once implementation, avoiding premature forwarding before the handshake completes.
- **Sessions & offline messages**: Clean sessions and persistent sessions are both supported. When a subscriber is offline, QoS > 0 messages are buffered automatically and redelivered on reconnect / re-subscribe according to the subscription filters, reusing the same PUBACK / PUBCOMP acknowledgement chain.
- **Retained messages**: The last message on a topic is retained and delivered immediately to new subscribers.
- **Will messages**: Will messages and their MQTT 5.0 properties are supported. A will is published automatically on abnormal disconnection (including WebSocket close).
- **Subscription persistence & efficient matching**: Subscriptions are written to the database and indexed in a dedicated Topic process using a **Trie tree**, resolving wildcard matches (`+` single-level, `#` multi-level) in O(levels) at publish time.
- **Authentication & authorization**:
  - Username / password authentication (`mqtt_user` table);
  - ACL access control (`mqtt_acl` table);
  - Token-based rule API (`MQTT_RULE_API_TOKEN`).
- **Rule Engine**: Event-driven (`message_publish` / `client_connected` / `client_disconnected` / `client_subscribe`), supporting filter expressions on JSON fields, topics, client attributes, etc. Actions include `republish` / `http` / `log` / `drop` / `mysql` / `pgsql` (data bridging). Rules are hot-reloaded and take effect within seconds — **no restart required**.
- **WebSocket access**: A built-in WebSocket controller (path `/mqtt-websocket`) lets browsers / web clients use MQTT over WebSocket directly.
- **Message tracing**: Optionally records every message's upstream / downstream, retained and offline-buffering stages into the `mqtt_message_trace` table for production troubleshooting.
- **Acknowledgement audit**: `mqtt_message_ack` records downstream delivery and acknowledgement state.
- **MQTT 5.0 feature — No Local**: Setting No Local on a subscription means the client will **no longer receive messages it published itself** (self-delivery exclusion), per the MQTT 5.0 standard.

---

## 2. Tech Stack

| Dimension | Choice |
|---|---|
| Runtime | PHP 7.4+ / 8.x, **Swoole extension required** |
| Framework | Yew (PHP resident-memory coroutine framework, built on Swoole) |
| Database | MySQL (persisted via Doctrine ORM / DBAL) |
| Protocol codec | Built-in `Yew\Mqtt` packet (supports 3.1.1 and 5.0) |

---

## 3. Feature Details

### 3.1 Protocol & Connection
- Listens on both **MQTT over TCP (default port 1883)** and **MQTT over WebSocket (`/mqtt-websocket`)**.
- A client session is written after a successful CONNECT handshake; it is cleared or retained on disconnect depending on `clean_start`.

### 3.2 QoS & Reliability
- **QoS 1**: The publisher receives PUBACK first, then the message is forwarded (the forward includes the self echo), so the client experiences "sent first, echo received later".
- **QoS 2**: The inbound message is persisted and staged first, and is only actually delivered after PUBREL is received, guaranteeing exactly-once.

### 3.3 Rule Engine
Rules live in the `mqtt_rule` table. Each rule consists of an "event source + filter expression + ordered action list". Example filter expressions:

```
payload.temp > 30
topic matches 'device/+/telemetry'
client_id LIKE 'sensor-%'
(qos >= 1) AND (payload.region == 'cn')
```

Example actions:
- `republish`: Forward the message (or a templated variant) to another topic;
- `http`: Call an external HTTP endpoint as a coroutine (Webhook);
- `mysql` / `pgsql`: Write the context into a specified database table (data bridging);
- `drop`: Discard this publish (the publisher still receives a normal ACK per QoS);
- `log`: Write to the log.

Rules are managed via the console (`php yew mqtt-rule/add ...`); each worker probes `updated_at` for changes and hot-reloads every 5 seconds.

### 3.4 Authentication & ACL
- `mqtt_user`: Username / password verification;
- `mqtt_acl`: Topic-level publish / subscribe permission control;
- `MQTT_RULE_API_TOKEN`: Access token for the rule management API.

### 3.5 MQTT 5.0 No Local (self-delivery control)
When a client connects over **MQTT 5.0** and sets the subscription option `No Local` to 1, it completely excludes receiving messages it published itself. This is a standard protocol-level parameter and requires no code changes:

```text
SUBSCRIBE
  Topic Filter: device/notice/#
  Options: No Local = 1   ← messages published by yourself are no longer echoed back
```

> For connections that do not set it (default 0) or that use MQTT 3.1.1, the standard echo semantics are preserved (in combination with the QoS 1 "sent first, echo later" ordering).

---

## 4. Advantages

1. **Pure PHP + Swoole coroutines**: Resident memory, high concurrency, low latency. It fits into a PHP technology stack without a separate message-middleware process.
2. **Fully persistent & auditable data**: Subscriptions, sessions, offline messages, retained messages, will messages, ACLs, message acknowledgements and tracing are all stored in the database — observable and traceable for operations.
3. **Business decoupling via the rule engine**: Bridging, forwarding, interception and external HTTP triggers are available out of the box and support hot updates, so business iteration never interrupts service.
4. **Modern protocol coverage**: MQTT 5.0 (No Local, etc.) and WebSocket access adapt to diverse clients — browsers, mobile and embedded devices.
5. **Modular design**: MQTT capabilities are centralized in `src/Modules/Mqtt`, making them easy to extend and customize.

---

## 5. Quick Start

### Requirements
- PHP `>= 7.4` (8.x recommended) with the **Swoole extension** installed (a normal CLI `php` cannot start the console / broker because it lacks `swoole_version()`).
- A MySQL database.

### Install
```bash
git clone <repo> YewMQ
cd YewMQ
composer install
```

### Configure
- Specify the listening port (default `1883`) and the database connection (Doctrine connection) in the configuration.
- If you need the rule API, set the environment variable `MQTT_RULE_API_TOKEN`.

### Initialize the database
```bash
# In the project root (a PHP environment with swoole)
php yew migrate              # apply all pending migrations (idempotent)
php yew migrate/new          # list pending migrations
php yew migrate/history      # list applied migrations
```

### Start
```bash
php server.php start -c -d                 # start the broker
```

### Verify
- Connect with any MQTT client (e.g. MQTTX) at `tcp://<host>:1883` or WebSocket `ws://<host>:port/mqtt-websocket`.
- The `test/` directory provides several WebSocket self-test pages (`mqtt3.1.1-websocket-yew.html`, `mqtt5-websocket-yew.html`, etc.) that can be opened directly in a browser to verify.

---

## 6. Directory Structure (brief)

```
src/
├── Modules/Mqtt/           # MQTT broker core
│   ├── Controllers/        # TCP / WebSocket ingress controllers
│   ├── Services/           # connection, subscription, publish, rule engine, tracing services
│   └── PackTool/           # protocol pack/unpack adapters
├── Models/                 # data models (subscription/client/message/ACL/user/rule…)
├── Migrations/             # database migrations
└── Commands/               # console commands (rule management, debug client, etc.)
docs/                       # design documents
test/                       # frontend self-test pages
```

---

## 7. Development Status

The project is in **active continuous development** (migration timeline roughly 2025-09 to 2026-09), and the core broker capabilities are already fairly complete:

- [x] MQTT 3.1.1 / 5.0 dual protocol
- [x] QoS 0 / 1 / 2 and QoS 2 exactly-once
- [x] Sessions, offline message buffering and redelivery
- [x] Retained messages, will messages (including 5.0 properties)
- [x] Subscription persistence + Trie wildcard matching
- [x] Username / password authentication, ACL
- [x] Rule engine (republish / http / mysql / pgsql / drop / log, hot-reload)
- [x] WebSocket access
- [x] Message tracing and acknowledgement audit
- [x] MQTT 5.0 No Local (self-delivery exclusion)

---

## 8. Roadmap (outlook)

The following are planned directions, not all yet implemented — contributions are welcome:

- **Shared Subscription**: The load-balancing subscription of MQTT 5.0.
- **More complete MQTT 5.0 features**: Message Expiry, Flow Control, User Property pass-through, Subscription Identifier, etc.
- **Enhanced bridging**: Bidirectional bridging to Kafka / AMQP / other MQTT brokers (EMQX, Mosquitto).
- **Monitoring & metrics**: Expose Prometheus-style metrics and integrate with Grafana dashboards.
- **Admin console / Web UI**: A visual control panel for subscriptions, clients, rules and ACLs.
- **Test coverage**: Add unit and integration tests to improve stability.
- **TLS secure access**: Provide encrypted ports such as 8883.

---

## 9. License

This project is open source under the **Apache License 2.0**.

- Full license text: [https://www.apache.org/licenses/LICENSE-2.0](https://www.apache.org/licenses/LICENSE-2.0)
- You may freely use, modify and distribute it (including commercial use) in compliance with the license terms, provided that you retain the copyright and license notices and clearly mark any modified files.