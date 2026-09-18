# YewMQ

> 基于 [Yew](https://github.com/bearlord/yew-framework) 框架与 Swoole 协程的高性能 **MQTT Broker**(PHP 实现)。
>
> 其他语言: [English](../../README.md)

YewMQ 是一个用 PHP 编写、运行于 Swoole 常驻内存运行时的 MQTT 消息 broker。它在保证 MQTT 标准语义的同时,把订阅、会话、离线消息、保留消息、遗嘱、ACL、认证等全部持久化到关系型数据库,并通过内置的**规则引擎**实现消息桥接、转发与拦截,适合物联网(IoT)、即时消息、设备接入等场景。

---

## 一、核心特性

- **协议支持**:完整支持 **MQTT 3.1.1** 与 **MQTT 5.0** 双协议栈。
- **QoS 全等级**:QoS 0 / 1 / 2 全支持。QoS 2 采用「先持久化、PUBREL 后再投递」的 exactly-once 实现,避免握手完成前提前转发。
- **会话与离线消息**:支持干净会话与持久会话;订阅者离线时,QoS > 0 消息自动缓冲,重连 / 重新订阅时按订阅过滤器重投,并重用同一套 PUBACK / PUBCOMP 确认链路。
- **保留消息(Retained)**:主题保留最后一条消息,新订阅者上线即获。
- **遗嘱消息(Will)**:支持遗嘱消息及其 MQTT 5.0 属性;连接异常断开(含 WebSocket 关闭)时自动发布遗嘱。
- **订阅持久化与高效匹配**:订阅写入数据库,并在独立的 Topic 进程内以 **Trie 树**索引,发布时 O(层级数) 完成通配符(`+` 单级、`#` 多级)匹配。
- **认证与鉴权**:
  - 用户名 / 密码认证(`mqtt_user` 表);
  - ACL 访问控制(`mqtt_acl` 表);
  - 基于 Token 的规则 API(`MQTT_RULE_API_TOKEN`)。
- **规则引擎(Rule Engine)**:事件驱动(`message_publish` / `client_connected` / `client_disconnected` / `client_subscribe`),支持基于 JSON 字段、Topic、客户端属性等的过滤表达式,动作包括 `republish` / `http` / `log` / `drop` / `mysql` / `pgsql`(数据桥接)。规则热加载,修改后数秒内生效,**无需重启**。
- **WebSocket 接入**:内置 WebSocket 控制器(路径 `/mqtt-websocket`),浏览器 / Web 客户端可直接使用 MQTT over WebSocket。
- **消息追踪(Trace)**:可开关地把每条消息的上行 / 下行、保留、离线缓冲等环节写入 `mqtt_message_trace` 表,便于线上排查。
- **消息确认审计**:`mqtt_message_ack` 记录下行投递与确认状态。
- **MQTT 5.0 特性 — No Local**:订阅时设置 No Local,客户端将**不再收到自己发布的消息**(自收排除),遵循 MQTT 5.0 标准。

---

## 二、技术栈

| 维度 | 选型 |
|---|---|
| 运行环境 | PHP 7.4+ / 8.x,**必须安装 Swoole 扩展** |
| 框架 | Yew(PHP 常驻内存协程框架,基于 Swoole) |
| 数据库 | MySQL(通过 Doctrine ORM / DBAL 持久化) |
| 协议编解码 | 内置 `Yew\Mqtt` 协议包(支持 3.1.1 与 5.0) |

---

## 三、功能详解

### 3.1 协议与连接
- 同时监听 **MQTT over TCP(默认端口 1883)** 与 **MQTT over WebSocket(`/mqtt`)**。
- CONNECT 握手成功后写入客户端会话;断开时清理或保留会话(依 `clean_start`)。

### 3.2 QoS 与可靠性
- **QoS 1**:发布者先收到 PUBACK,再转发(转发含自收回声),客户端表现为「先已发送、后收到回声」。
- **QoS 2**:上行先持久化并暂存,直到收到 PUBREL 后才真正投递,保证 exactly-once。

### 3.3 规则引擎
规则存于 `mqtt_rule` 表,每条规则由「事件源 + 过滤表达式 + 有序动作列表」组成。示例过滤表达式:

```
payload.temp > 30
topic matches 'device/+/telemetry'
client_id LIKE 'sensor-%'
(qos >= 1) AND (payload.region == 'cn')
```

动作示例:
- `republish`:把消息(或模板变体)转发到另一主题;
- `http`:以协程方式调用外部 HTTP 接口(Webhook);
- `mysql` / `pgsql`:把上下文写入指定库表(数据桥接);
- `drop`:丢弃本次发布(发布者仍按 QoS 正常收到 ACK);
- `log`:写入日志。

规则通过控制台(`php yew mqtt-rule/add ...`)管理,worker 每 5 秒探测 `updated_at` 变化并热重载。

### 3.4 认证与 ACL
- `mqtt_user`:用户名 / 密码校验;
- `mqtt_acl`:主题级发布 / 订阅权限控制;
- `MQTT_RULE_API_TOKEN`:规则管理 API 的访问令牌。

### 3.5 MQTT 5.0 No Local(自收控制)
客户端以 **MQTT 5.0** 连接,订阅时把订阅选项 `No Local` 位置 1,即可彻底排除接收自己发布的消息。这是标准的协议级参数,无需修改代码:

```text
SUBSCRIBE
  Topic Filter: device/notice/#
  Options: No Local = 1   ← 自己发的消息不再回显给自己
```

> 未设置(默认 0)或 MQTT 3.1.1 连接,保持标准回显语义(配合 QoS 1 的「先已发送后回声」顺序)。

---

## 四、优点

1. **纯 PHP + Swoole 协程**:常驻内存、高并发、低延迟,无需单独的消息中间件进程即可融入 PHP 技术栈。
2. **数据全部可持久化与可审计**:订阅、会话、离线、保留、遗嘱、ACL、消息确认、追踪统一落库,运维可观测、可回溯。
3. **规则引擎解耦业务**:桥接、转发、拦截、外部 HTTP 触发等能力开箱即用,且支持热更新,业务迭代不中断服务。
4. **现代协议覆盖**:MQTT 5.0(No Local 等)与 WebSocket 接入,适配浏览器、移动端、嵌入式等多样客户端。
5. **模块化设计**:MQTT 能力集中于 `src/Modules/Mqtt`,易于扩展与二次开发。

---

## 五、快速开始

### 环境要求
- PHP `>= 7.4`(推荐 8.x),并安装 **Swoole 扩展**(普通 CLI `php` 因缺少 `swoole_version()` 无法启动控制台 / broker)。
- MySQL 数据库。

### 安装
```bash
git clone <repo> YewMQ
cd YewMQ
composer install
```

### 配置
- 在配置中指定监听端口(默认 `1883`)与数据库连接(Doctrine 连接)。
- 如需规则 API,设置环境变量 `MQTT_RULE_API_TOKEN`。

### 初始化数据库
```bash
# 在项目根目录(带 swoole 的 php 环境)
php yew migrate              # 应用全部待执行迁移(幂等)
php yew migrate/new          # 查看待执行迁移
php yew migrate/history      # 查看已应用迁移
```

### 启动
```bash
php server.php start -c -d                 # 启动 broker
```

### 验证
- 使用任意 MQTT 客户端(如 MQTTX)连接 `tcp://<host>:1883` 或 WebSocket `ws://<host>:端口/mqtt-websocket`。
- 项目 `test/` 目录提供多份 WebSocket 自测页面(`mqtt3.1.1-websocket-yew.html`、`mqtt5-websocket-yew.html` 等),可直接在浏览器打开验证。

---

## 六、目录结构(简)

```
src/
├── Modules/Mqtt/           # MQTT broker 核心
│   ├── Controllers/        # TCP / WebSocket 接入控制器
│   ├── Services/           # 连接、订阅、发布、规则引擎、追踪等服务
│   └── PackTool/           # 协议打包/解包适配
├── Models/                 # 数据模型(订阅/客户端/消息/ACL/用户/规则…)
├── Migrations/             # 数据库迁移
└── Commands/               # 控制台命令(规则管理、调试客户端等)
docs/                       # 设计文档
test/                       # 前端自测页面
```

---

## 七、开发进度

项目处于**持续活跃开发**阶段(迁移时间跨度约 2025-09 至 2026-09),核心 broker 能力已较完整:

- [x] MQTT 3.1.1 / 5.0 双协议
- [x] QoS 0 / 1 / 2 与 QoS 2 exactly-once
- [x] 会话、离线消息缓冲与重投
- [x] 保留消息、遗嘱消息(含 5.0 属性)
- [x] 订阅持久化 + Trie 通配符匹配
- [x] 用户名 / 密码认证、ACL
- [x] 规则引擎(republish / http / mysql / pgsql / drop / log,热加载)
- [x] WebSocket 接入
- [x] 消息追踪与确认审计
- [x] MQTT 5.0 No Local(自收排除)

---

## 八、未来规划(展望)

以下功能为规划方向,尚未全部落地,欢迎共建:

- **共享订阅(Shared Subscription)**:MQTT 5.0 的负载均衡订阅。
- **更完整的 MQTT 5.0 特性**:消息过期(Message Expiry)、流控(Flow Control)、用户属性透传、订阅标识符等。
- **桥接能力增强**:对接 Kafka / AMQP / 其它 MQTT Broker(EMQX、Mosquitto)的双向桥接。
- **监控与指标**:暴露 Prometheus 等指标,接入 Grafana 看板。
- **管理后台 / Web UI**:订阅、客户端、规则、ACL 的可视化管控台。
- **测试覆盖**:补充单元测试与集成测试,提升稳定性。
- **TLS 安全接入**:提供 8883 等加密端口。

---

## 九、许可证

本项目基于 **Apache License 2.0** 开源。

- 许可证全文：[https://www.apache.org/licenses/LICENSE-2.0](https://www.apache.org/licenses/LICENSE-2.0)
- 在遵循许可证条款的前提下,可自由使用、修改与分发(含商业用途),但须保留版权与许可证声明,并对修改文件作显著标注。
