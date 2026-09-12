<?php

namespace App\Modules\Mqtt\Controllers;

use App\Models\Extension\MqttRule;
use App\Models\Extension\MqttRuleStat;
use Yew\Framework\Controller;
use Yew\Plugins\Route\Annotation\DelMapping;
use Yew\Plugins\Route\Annotation\GetMapping;
use Yew\Plugins\Route\Annotation\PostMapping;
use Yew\Plugins\Route\Annotation\PutMapping;
use Yew\Plugins\Route\Annotation\ResponseBody;
use Yew\Plugins\Route\Annotation\RestController;

/**
 * RESTful API for the config-driven MQTT rule engine, consumed by the Web Dashboard.
 *
 * Routes (prefix /api/mqtt-rule):
 *   GET  /list    -> list rules (ordered by priority)
 *   GET  /stats   -> per-rule runtime counters (from mqtt_rule_stat)
 *   POST /add     -> create a rule
 *   PUT  /edit    -> update a rule (id in body or ?id=)
 *   DEL  /delete  -> delete a rule (id in body or ?id=)
 *
 * Auth: optional API token. Export MQTT_RULE_API_TOKEN; requests then must carry
 * the same value via header `X-Api-Token` or query `?token=`. When the env var is
 * empty the endpoint is open (trusted/internal networks only). CSRF is disabled for
 * this token-authenticated API.
 *
 * @RestController("/api/mqtt-rule")
 */
class MqttRuleApiController extends Controller
{
    /**
     * Disable CSRF for token-authenticated API calls (CSRF is for browser forms).
     */
    public function initialization(?string $controllerName, ?string $methodName): void
    {
        parent::initialization($controllerName, $methodName);
        if (isset($this->request) && property_exists($this->request, 'enableCsrfValidation')) {
            $this->request->enableCsrfValidation = false;
        }
    }

    private function authorized(): bool
    {
        $expected = getenv('MQTT_RULE_API_TOKEN');
        if ($expected === false || $expected === '') {
            return true; // no token configured -> open endpoint
        }
        $got = (string)($this->request->getHeaderLine('x-api-token') ?? '');
        if ($got === '') {
            $got = (string)($this->request->getQueryParams()['token'] ?? '');
        }
        return is_string($expected) && hash_equals($expected, $got);
    }

    private function deny(): string
    {
        return $this->json(['code' => 401, 'message' => 'unauthorized', 'data' => new \stdClass()], 401);
    }

    private function json($data, int $status = 200): string
    {
        $this->response->withStatus($status);
        $this->response->withHeader('Content-Type', 'application/json;charset=UTF-8');
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function queryParams(): array
    {
        return (array)$this->request->getQueryParams();
    }

    private function bodyParams(): array
    {
        $body = $this->request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }
        if (is_object($body)) {
            return (array)$body;
        }
        if (is_string($body)) {
            $dec = json_decode($body, true);
            return is_array($dec) ? $dec : [];
        }
        return [];
    }

    private function ruleArray(MqttRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'enabled' => $rule->enabled,
            'source' => $rule->source,
            'filter' => $rule->filter,
            'actions' => $rule->getActionsDecoded(),
            'priority' => $rule->priority,
            'created_at' => $rule->created_at,
            'updated_at' => $rule->updated_at,
        ];
    }

    private function applyInput(MqttRule $rule, array $input, bool $isNew): void
    {
        if (array_key_exists('name', $input)) {
            $rule->name = (string)$input['name'];
        }
        if (array_key_exists('enabled', $input)) {
            $rule->enabled = (int)$input['enabled'];
        }
        if (array_key_exists('source', $input)) {
            $rule->source = (string)$input['source'];
        } elseif ($isNew) {
            $rule->source = '$events/message_publish';
        }
        if (array_key_exists('filter', $input)) {
            $rule->filter = $input['filter'] === null ? null : (string)$input['filter'];
        }
        if (array_key_exists('priority', $input)) {
            $rule->priority = (int)$input['priority'];
        } elseif ($isNew) {
            $rule->priority = 0;
        }
        if (array_key_exists('actions', $input)) {
            if (is_array($input['actions'])) {
                $rule->actions = json_encode($input['actions'], JSON_UNESCAPED_SLASHES);
            } elseif (is_string($input['actions'])) {
                $rule->actions = $input['actions'];
            }
        } elseif ($isNew) {
            $rule->actions = '[]';
        }
    }

    /**
     * @GetMapping("/list")
     * @ResponseBody
     */
    public function index(): string
    {
        if (!$this->authorized()) {
            return $this->deny();
        }
        $rows = MqttRule::find()->orderBy('priority ASC')->all();
        $data = array_map([$this, 'ruleArray'], $rows);
        return $this->json(['code' => 0, 'message' => 'ok', 'data' => $data]);
    }

    /**
     * @GetMapping("/stats")
     * @ResponseBody
     */
    public function stats(): string
    {
        if (!$this->authorized()) {
            return $this->deny();
        }
        $rows = MqttRuleStat::find()->orderBy('rule_id ASC')->all();
        $data = array_map(function (MqttRuleStat $r) {
            return [
                'rule_id' => $r->rule_id,
                'hits' => $r->hits,
                'ok' => $r->ok,
                'fail' => $r->fail,
                'updated_at' => $r->updated_at,
            ];
        }, $rows);
        return $this->json(['code' => 0, 'message' => 'ok', 'data' => $data]);
    }

    /**
     * @GetMapping("/overview")
     * @ResponseBody
     */
    public function overview(): string
    {
        if (!$this->authorized()) {
            return $this->deny();
        }
        $rules = MqttRule::find()->all();
        $stats = MqttRuleStat::find()->all();

        $enabled = 0;
        $bridges = [];
        foreach ($rules as $r) {
            if ($r->enabled) {
                $enabled++;
            }
            foreach ($r->getActionsDecoded() as $a) {
                if (isset($a['type']) && in_array($a['type'], ['mysql', 'pgsql'], true)) {
                    $args = $a['args'] ?? [];
                    $bridges[] = [
                        'rule_id' => $r->id,
                        'rule_name' => $r->name,
                        'type' => $a['type'],
                        'table' => $args['table'] ?? '',
                        'host' => ($args['host'] ?? '') . ':' . ($args['port'] ?? ($a['type'] === 'pgsql' ? 5432 : 3306)),
                        'db' => $args['db'] ?? '',
                    ];
                }
            }
        }

        $hits = $ok = $fail = 0;
        foreach ($stats as $s) {
            $hits += (int)$s->hits;
            $ok += (int)$s->ok;
            $fail += (int)$s->fail;
        }

        return $this->json([
            'code' => 0,
            'message' => 'ok',
            'data' => [
                'rule_total' => count($rules),
                'rule_enabled' => $enabled,
                'rule_disabled' => count($rules) - $enabled,
                'bridge_total' => count($bridges),
                'hits' => $hits,
                'ok' => $ok,
                'fail' => $fail,
                'bridges' => $bridges,
            ],
        ]);
    }

    /**
     * @PostMapping("/add")
     * @ResponseBody
     */
    public function create(): string
    {
        if (!$this->authorized()) {
            return $this->deny();
        }
        $rule = new MqttRule();
        $this->applyInput($rule, $this->bodyParams(), true);
        if (!$rule->save()) {
            return $this->json(['code' => 1, 'message' => 'validation failed', 'data' => $rule->getErrors()], 400);
        }
        return $this->json(['code' => 0, 'message' => 'created', 'data' => $this->ruleArray($rule)], 201);
    }

    /**
     * @PutMapping("/edit")
     * @ResponseBody
     */
    public function update(): string
    {
        if (!$this->authorized()) {
            return $this->deny();
        }
        $input = $this->bodyParams();
        $id = (int)($input['id'] ?? $this->queryParams()['id'] ?? 0);
        $rule = MqttRule::findOne($id);
        if ($rule === null) {
            return $this->json(['code' => 1, 'message' => 'rule not found'], 404);
        }
        $this->applyInput($rule, $input, false);
        if (!$rule->save()) {
            return $this->json(['code' => 1, 'message' => 'validation failed', 'data' => $rule->getErrors()], 400);
        }
        return $this->json(['code' => 0, 'message' => 'updated', 'data' => $this->ruleArray($rule)]);
    }

    /**
     * @DelMapping("/delete")
     * @ResponseBody
     */
    public function remove(): string
    {
        if (!$this->authorized()) {
            return $this->deny();
        }
        $input = $this->bodyParams();
        $id = (int)($input['id'] ?? $this->queryParams()['id'] ?? 0);
        $rule = MqttRule::findOne($id);
        if ($rule === null) {
            return $this->json(['code' => 1, 'message' => 'rule not found'], 404);
        }
        $rule->delete();
        return $this->json(['code' => 0, 'message' => 'deleted', 'data' => ['id' => $id]]);
    }
}
