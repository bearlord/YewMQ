<?php

namespace App\Modules\Mqtt\Services;

use App\Models\Extension\MqttRule;
use Yew\Client\HttpClient;

/**
 * Config-driven MQTT rule engine.
 *
 * Rules live in the mqtt_rule table (see m260908_090000_create_mqtt_rule_table).
 * Each enabled rule has a `source` (event), a `filter` (boolean expression) and an
 * ordered list of `actions`. The engine is invoked from the broker entry points
 * (actionPublish / actionConnect / actionDisconnect / actionSubscribe) and evaluates
 * every enabled rule whose `source` matches the current event against the context.
 *
 * Supported events (source):
 *   $events/message_publish      inbound PUBLISH (has topic/message/qos/retain)
 *   $events/client_connected     on CONNECT handshake success
 *   $events/client_disconnected  on DISCONNECT / socket close
 *   $events/client_subscribe     on SUBSCRIBE (topic = one filter)
 *
 * Context keys: protocol_level, client_id, username, peerhost, keep_alive, mountpoint,
 *               topic, message, qos, retain, source
 *
 * Caching / reload: rules are kept in-memory per worker. On every probe (throttled to
 * $probeInterval seconds) the engine compares MAX(updated_at) of mqtt_rule with the value
 * seen at last load; a change triggers a full reload. This means edits made via the
 * MqttRuleController console take effect within a few seconds, without restarting the broker
 * and without any IPC plumbing (each worker reloads independently).
 *
 * Action types:
 *   - republish : re-publish the message (or a templated variant) to another topic.
 *                 args: topic, payload (supports ${message}/${topic}/${client_id}/${username}/
 *                 ${peerhost}/${qos}/${retain}/${protocol_level}/${payload.x}), qos, retain.
 *   - http      : fire an HTTP request (coroutine client). args: url, method, body, headers.
 *   - log       : write a line to the error log. args: message (supports interpolation).
 *   - drop      : discard this inbound publish (no store / no forward). The publisher is
 *                 still acked per QoS. Only meaningful for $events/message_publish.
 *
 * Filter expression grammar (loose, case-insensitive):
 *   "" or NULL                     -> match all
 *   topic matches 'a/b/+'          -> MQTT wildcard match
 *   payload.temp > 30              -> numeric/string compare on JSON-decoded payload field
 *   payload.a.b.c == 'x'           -> nested JSON path compare
 *   client_id == 'sensor-01'       -> compare client id
 *   qos >= 1                       -> compare qos
 *   payload.temp =~ '\d+'          -> regex match (also /pattern/ form)
 *   client_id LIKE 'sensor-%'      -> SQL LIKE (% any, _ single)
 *   qos IN (0, 1)                  -> membership
 *   (a) AND (b) OR NOT (c)         -> boolean combine, AND binds tighter than OR
 * Operators: == != > >= < <=
 */
class RuleEngine
{
    private static ?self $instance = null;

    /** @var array|null cached rules, ordered by priority asc */
    private ?array $rules = null;

    /** @var string|null MAX(updated_at) observed at last load */
    private ?string $lastMaxUpdatedAt = null;

    /** @var float last probe timestamp (microtime) */
    private float $lastProbe = 0.0;

    /** @var int seconds between updated_at probes */
    private int $probeInterval = 5;

    /** @var array tokens for the boolean filter parser */
    private array $filterTokens = [];

    /** @var int cursor position in $filterTokens */
    private int $filterPos = 0;

    /** @var bool set true when a `drop` action fires during run() */
    private bool $dropped = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Evaluate all enabled rules against an inbound publish context.
     *
     * @param array $context keys: protocol_level, client_id, username, peerhost, keep_alive,
     *                          mountpoint, topic, message, qos, retain, source
     * @return bool true if a `drop` action fired (caller should NOT store/forward the message)
     */
    public function onPublish(array $context): bool
    {
        $context['source'] = $context['source'] ?? '$events/message_publish';
        return $this->run($context);
    }

    /**
     * Evaluate rules for an arbitrary broker event (connect / disconnect / subscribe).
     *
     * @return bool true if a `drop` action fired (callers other than publish ignore this)
     */
    public function onEvent(string $event, array $context): bool
    {
        $context['source'] = $event;
        return $this->run($context);
    }

    private function run(array $context): bool
    {
        $this->dropped = false;
        foreach ($this->getRules() as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            if (!$this->matchSource((string)($rule['source'] ?? ''), $context['source'])) {
                continue;
            }
            if (!$this->matchFilter($rule['filter'] ?? null, $context)) {
                continue;
            }
            $this->runActions($rule['actions'] ?? [], $context);
        }
        return $this->dropped;
    }

    /**
     * Return the cached rule set, reloading when stale.
     */
    private function getRules(): array
    {
        $now = microtime(true);
        if ($this->rules === null || ($now - $this->lastProbe) > $this->probeInterval) {
            $this->reloadIfChanged();
            $this->lastProbe = $now;
        }
        return $this->rules ?? [];
    }

    /**
     * Force a reload from the database (also called by admin tooling).
     */
    public function reload(): void
    {
        $rows = MqttRule::find()->where(['enabled' => 1])->orderBy('priority ASC')->all();
        $rules = [];
        foreach ($rows as $r) {
            $rules[] = [
                'id' => $r->id,
                'source' => $r->source,
                'filter' => $r->filter,
                'actions' => $r->getActionsDecoded(),
                'enabled' => $r->enabled,
            ];
        }
        $this->rules = $rules;
        $this->lastMaxUpdatedAt = $this->maxUpdatedAt();
    }

    private function reloadIfChanged(): void
    {
        $max = $this->maxUpdatedAt();
        if ($this->rules === null || $max !== $this->lastMaxUpdatedAt) {
            $this->reload();
        }
    }

    private function maxUpdatedAt(): ?string
    {
        $row = MqttRule::find()->select(['mx' => 'MAX(updated_at)'])->asArray()->one();
        return ($row['mx'] ?? null) !== null ? (string)$row['mx'] : null;
    }

    private function matchSource(string $source, string $event): bool
    {
        if ($source === '') {
            return true;
        }
        return $source === $event;
    }

    /**
     * Evaluate a filter expression. Empty / null = match everything.
     */
    public function matchFilter(?string $filter, array $context): bool
    {
        if ($filter === null || trim($filter) === '') {
            return true;
        }
        try {
            return $this->evalBool(trim($filter), $context);
        } catch (\Throwable $e) {
            error_log('[rule-engine] filter parse error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Boolean filter parser (recursive descent). AND binds tighter than OR,
     * NOT is prefix, parentheses group. Falls back to a single condition.
     */
    private function evalBool(string $expr, array $context): bool
    {
        $this->filterTokens = $this->tokenize($expr);
        $this->filterPos = 0;
        if ($this->filterTokens === []) {
            return true;
        }
        return $this->parseOr($context);
    }

    private function tokenize(string $s): array
    {
        $tokens = [];
        $n = strlen($s);
        $i = 0;
        $buf = '';
        $quote = null;
        while ($i < $n) {
            $c = $s[$i];
            if ($quote !== null) {
                $buf .= $c;
                if ($c === $quote) {
                    $quote = null;
                }
                $i++;
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
                $buf .= $c;
                $i++;
                continue;
            }
            if ($c === '(' || $c === ')') {
                if (trim($buf) !== '') {
                    $this->flushToken($buf, $tokens);
                }
                $tokens[] = $c;
                $buf = '';
                $i++;
                continue;
            }
            if (ctype_space($c) && $this->endsWithLogicWord(rtrim($buf))) {
                $this->flushToken($buf, $tokens);
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $c;
            $i++;
        }
        if (trim($buf) !== '') {
            $this->flushToken($buf, $tokens);
        }
        return $tokens;
    }

    private function endsWithLogicWord(string $trimmed): bool
    {
        if ($trimmed === '') {
            return false;
        }
        if (!preg_match('/(AND|OR|NOT)$/i', $trimmed, $mm)) {
            return false;
        }
        $len = strlen($mm[0]);
        return strlen($trimmed) === $len || $trimmed[strlen($trimmed) - $len - 1] === ' ';
    }

    private function flushToken(string $raw, array &$tokens): void
    {
        $trimmed = rtrim($raw);
        if ($this->endsWithLogicWord($trimmed)) {
            preg_match('/(AND|OR|NOT)$/i', $trimmed, $mm);
            $atom = rtrim(substr($trimmed, 0, -strlen($mm[0])));
            if ($atom !== '') {
                $tokens[] = $atom;
            }
            $tokens[] = strtoupper($mm[0]);
        } else {
            $tokens[] = $trimmed;
        }
    }

    private function parseOr(array $context): bool
    {
        $left = $this->parseAnd($context);
        while (isset($this->filterTokens[$this->filterPos]) && $this->filterTokens[$this->filterPos] === 'OR') {
            $this->filterPos++;
            $left = $left || $this->parseAnd($context);
        }
        return $left;
    }

    private function parseAnd(array $context): bool
    {
        $left = $this->parseNot($context);
        while (isset($this->filterTokens[$this->filterPos]) && $this->filterTokens[$this->filterPos] === 'AND') {
            $this->filterPos++;
            $left = $left && $this->parseNot($context);
        }
        return $left;
    }

    private function parseNot(array $context): bool
    {
        if (isset($this->filterTokens[$this->filterPos]) && $this->filterTokens[$this->filterPos] === 'NOT') {
            $this->filterPos++;
            return !$this->parseNot($context);
        }
        return $this->parsePrimary($context);
    }

    private function parsePrimary(array $context): bool
    {
        $tok = $this->filterTokens[$this->filterPos] ?? null;
        if ($tok === '(') {
            $this->filterPos++;
            $v = $this->parseOr($context);
            if (($this->filterTokens[$this->filterPos] ?? null) === ')') {
                $this->filterPos++;
            }
            return $v;
        }
        if (is_string($tok)) {
            $this->filterPos++;
            return $this->evalAtom($tok, $context);
        }
        return false;
    }

    /**
     * Evaluate a single condition atom.
     */
    private function evalAtom(string $expr, array $context): bool
    {
        // topic matches 'pattern'
        if (preg_match("/^topic\s+matches\s+'((?:[^'\\\\]|\\\\.)*)'$/i", $expr, $m)) {
            return $this->topicMatches((string)($context['topic'] ?? ''), $m[1]);
        }
        // field =~ 'regex' or /regex/
        if (preg_match("/^(topic|client_id|qos|payload(?:\.[\w-]+)+)\s*=~\s*('((?:[^'\\\\]|\\\\.)*)'|\/((?:[^\/\\\\]|\\\\.)*)\/)$/i", $expr, $m)) {
            $field = strtolower($m[1]);
            $pattern = $m[3] !== '' ? $m[3] : ($m[4] ?? '');
            $val = (string)$this->resolveField($field, $context);
            return @preg_match('@' . $pattern . '@', $val) === 1;
        }
        // field LIKE 'pattern' (% any, _ single)
        if (preg_match("/^(topic|client_id|qos|payload(?:\.[\w-]+)+)\s+LIKE\s+'((?:[^'\\\\]|\\\\.)*)'$/i", $expr, $m)) {
            $field = strtolower($m[1]);
            $val = (string)$this->resolveField($field, $context);
            return $this->likeMatch($m[3], $val);
        }
        // field IN (a, b, c)
        if (preg_match("/^(topic|client_id|qos|payload(?:\.[\w-]+)+)\s+IN\s*\(([^)]*)\)$/i", $expr, $m)) {
            $field = strtolower($m[1]);
            $val = $this->resolveField($field, $context);
            foreach ($this->parseList($m[2]) as $item) {
                if ($this->compare($val, '==', $item)) {
                    return true;
                }
            }
            return false;
        }
        // field op value
        if (preg_match("/^(topic|client_id|qos|payload(?:\.[\w-]+)+)\s*(==|!=|>=|<=|>|<)\s*(.+)$/i", $expr, $m)) {
            $field = strtolower($m[1]);
            $op = $m[2];
            $left = $this->resolveField($field, $context);
            $right = $this->parseValue($m[3]);
            return $this->compare($left, $op, $right);
        }
        return false;
    }

    private function likeMatch(string $pattern, string $val): bool
    {
        $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/s';
        return @preg_match($regex, $val) === 1;
    }

    private function parseList(string $raw): array
    {
        $items = [];
        foreach (explode(',', $raw) as $part) {
            $items[] = $this->parseValue($part);
        }
        return $items;
    }

    private function resolveField(string $field, array $context)
    {
        switch ($field) {
            case 'topic':
                return $context['topic'] ?? '';
            case 'client_id':
                return $context['client_id'] ?? '';
            case 'qos':
                return $context['qos'] ?? 0;
            case 'username':
                return $context['username'] ?? '';
            case 'peerhost':
                return $context['peerhost'] ?? '';
            case 'mountpoint':
                return $context['mountpoint'] ?? '';
            case 'keep_alive':
                return $context['keep_alive'] ?? 0;
            case 'retain':
                return $context['retain'] ?? 0;
            case 'protocol_level':
                return $context['protocol_level'] ?? 0;
            default:
                if (str_starts_with($field, 'payload.')) {
                    $cur = json_decode((string)($context['message'] ?? ''), true);
                    foreach (explode('.', substr($field, 8)) as $seg) {
                        if (is_array($cur) && array_key_exists($seg, $cur)) {
                            $cur = $cur[$seg];
                        } else {
                            return null;
                        }
                    }
                    return $cur;
                }
                return null;
        }
    }

    private function parseValue(string $raw)
    {
        $raw = trim($raw);
        $first = $raw[0] ?? '';
        $last = substr($raw, -1);
        if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
            return substr($raw, 1, -1);
        }
        if (is_numeric($raw)) {
            return $raw + 0;
        }
        return $raw;
    }

    private function compare($left, string $op, $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            $l = (float)$left;
            $r = (float)$right;
        } else {
            $l = (string)$left;
            $r = (string)$right;
        }
        switch ($op) {
            case '==': return $l == $r;
            case '!=': return $l != $r;
            case '>':  return $l > $r;
            case '>=': return $l >= $r;
            case '<':  return $l < $r;
            case '<=': return $l <= $r;
            default:   return false;
        }
    }

    /**
     * MQTT topic wildcard match (+ single level, # multi-level).
     */
    private function topicMatches(string $topic, string $pattern): bool
    {
        if ($pattern === '#') {
            return true;
        }
        $sub = explode('/', $topic);
        $pat = explode('/', $pattern);
        $slen = count($sub);
        $plen = count($pat);
        foreach ($pat as $i => $p) {
            if ($p === '#') {
                return true;
            }
            if ($i >= $slen) {
                return false;
            }
            if ($p === '+') {
                continue;
            }
            if ($p !== $sub[$i]) {
                return false;
            }
        }
        return $slen === $plen;
    }

    private function runActions(array $actions, array $context): void
    {
        foreach ($actions as $action) {
            if (!is_array($action) || empty($action['type'])) {
                continue;
            }
            $args = $action['args'] ?? [];
            switch ($action['type']) {
                case 'republish':
                    $this->actionRepublish($args, $context);
                    break;
                case 'http':
                    $this->actionHttp($args, $context);
                    break;
                case 'log':
                    $this->actionLog($args, $context);
                    break;
                case 'drop':
                    $this->dropped = true;
                    break;
                default:
                    break;
            }
        }
    }

    private function actionRepublish(array $args, array $context): void
    {
        $topic = $this->interpolate((string)($args['topic'] ?? ''), $context);
        if ($topic === '') {
            return;
        }
        $payload = $this->interpolate((string)($args['payload'] ?? '${message}'), $context);
        $qos = (int)($args['qos'] ?? 0);
        $retain = (int)($args['retain'] ?? 0);

        // Reuse the existing publish pipeline so the re-published message is persisted,
        // forwarded to subscribers and honoured for retain — exactly like a normal publish.
        // publishProcess does NOT re-enter the rule engine, so there is no recursion.
        (new MqttPublishService())->publishProcess(
            (int)($context['protocol_level'] ?? 4),
            (string)($context['client_id'] ?? ''),
            $topic,
            $payload,
            $qos,
            $retain
        );
    }

    private function actionHttp(array $args, array $context): void
    {
        $url = $this->interpolate((string)($args['url'] ?? ''), $context);
        if ($url === '') {
            return;
        }
        $method = strtoupper((string)($args['method'] ?? 'POST'));
        $body = $this->interpolate(
            (string)($args['body'] ?? json_encode($context, JSON_UNESCAPED_SLASHES)),
            $context
        );
        $headers = $args['headers'] ?? [];

        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return;
        }
        $ssl = ($parsed['scheme'] ?? '') === 'https';
        $port = $parsed['port'] ?? ($ssl ? 443 : 80);
        $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

        try {
            $client = new HttpClient($parsed['host'], (int)$port, $ssl);
            if ($method === 'GET') {
                $client->get($path, $headers);
            } else {
                $client->post($path, $body, $headers);
            }
            $client->close();
        } catch (\Throwable $e) {
            error_log('[rule-engine] http action failed: ' . $e->getMessage());
        }
    }

    private function actionLog(array $args, array $context): void
    {
        $msg = $this->interpolate((string)($args['message'] ?? 'rule fired'), $context);
        error_log('[rule-engine] ' . $msg);
    }

    /**
     * Replace ${message}, ${topic}, ${client_id}, ${qos} and ${payload.field} placeholders.
     */
    private function interpolate(string $tpl, array $context): string
    {
        return (string)preg_replace_callback('/\$\{\s*([\w.\-]+)\s*\}/', function ($m) use ($context) {
            $key = $m[1];
            switch ($key) {
                case 'message':
                    return (string)($context['message'] ?? '');
                case 'topic':
                    return (string)($context['topic'] ?? '');
                case 'client_id':
                    return (string)($context['client_id'] ?? '');
                case 'username':
                    return (string)($context['username'] ?? '');
                case 'peerhost':
                    return (string)($context['peerhost'] ?? '');
                case 'mountpoint':
                    return (string)($context['mountpoint'] ?? '');
                case 'keep_alive':
                    return (string)($context['keep_alive'] ?? '');
                case 'retain':
                    return (string)($context['retain'] ?? '');
                case 'protocol_level':
                    return (string)($context['protocol_level'] ?? '');
                case 'qos':
                    return (string)($context['qos'] ?? '');
                default:
                    if (str_starts_with($key, 'payload.')) {
                        $cur = json_decode((string)($context['message'] ?? ''), true);
                        foreach (explode('.', substr($key, 8)) as $seg) {
                            if (is_array($cur) && array_key_exists($seg, $cur)) {
                                $cur = $cur[$seg];
                            } else {
                                return $m[0];
                            }
                        }
                        return is_scalar($cur) ? (string)$cur : json_encode($cur, JSON_UNESCAPED_SLASHES);
                    }
                    return $m[0];
            }
        }, $tpl);
    }
}
