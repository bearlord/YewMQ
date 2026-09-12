<?php

namespace App\Commands;

use App\Models\Extension\MqttRule;
use App\Modules\Mqtt\Services\RuleEngine;
use Yew\Framework\Console\Controller;
use Yew\Framework\Helpers\Console;

/**
 * Manage MQTT rule-engine rules stored in the mqtt_rule table.
 *
 * Usage:
 *   ./yew mqtt-rule                         # list rules
 *   ./yew mqtt-rule/add --name=bridge \
 *       --source='$events/message_publish' --filter="topic matches 'sensor/+/temp'" \
 *       --actions='[{"type":"republish","args":{"topic":"raw/sensor/temp"}}]' --priority=10
 *   ./yew mqtt-rule/update --rule-id=1 --enabled=0
 *   ./yew mqtt-rule/toggle --rule-id=1
 *   ./yew mqtt-rule/delete --rule-id=1
 *   ./yew mqtt-rule/stats                 # show per-rule hit/action counters
 *   ./yew mqtt-rule/reset-stats           # reset the counters
 *
 * Edits take effect at runtime within a few seconds (the engine probes
 * mqtt_rule.updated_at and reloads automatically).
 */
class MqttRuleController extends Controller
{
    /** @var int rule id for update/delete/toggle (0 = not provided) */
    public int $ruleId = 0;
    /** @var string */
    public string $name = '';
    /** @var string */
    public string $source = '';
    /** @var string */
    public string $filter = '';
    /** @var string JSON-encoded actions */
    public string $actions = '';
    /** @var bool */
    public bool $enabled = false;
    /** @var int */
    public int $priority = 0;

    public function options(string $actionID): array
    {
        return array_merge(parent::options($actionID), [
            'ruleId', 'name', 'source', 'filter', 'actions', 'enabled', 'priority',
        ]);
    }

    /**
     * List all rules ordered by priority (default action).
     */
    public function actionIndex(): int
    {
        $rows = MqttRule::find()->orderBy('priority ASC')->all();
        if (empty($rows)) {
            $this->stdout("No rules found.\n", Console::FG_YELLOW);
            return 0;
        }
        foreach ($rows as $r) {
            $this->stdout(sprintf(
                "#%-3d %-20s enabled=%d src=%-24s pri=%-3d filter=%-30s actions=%s\n",
                $r->id, $r->name, $r->enabled, $r->source, $r->priority, $r->filter, $r->actions
            ));
        }
        return 0;
    }

    /**
     * Add a new rule.
     */
    public function actionAdd(): int
    {
        $rule = new MqttRule();
        $this->applyInput($rule);
        if (!$rule->save()) {
            $this->stderr('Save failed: ' . json_encode($rule->getErrors(), JSON_UNESCAPED_SLASHES) . "\n");
            return 1;
        }
        $this->stdout("Rule #{$rule->id} created.\n");
        return 0;
    }

    /**
     * Update an existing rule by --rule-id.
     */
    public function actionUpdate(): int
    {
        if ($this->ruleId < 1) {
            $this->stderr("Missing required option --rule-id.\n");
            return 1;
        }
        $rule = MqttRule::findOne((int) $this->ruleId);
        if ($rule === null) {
            $this->stderr("Rule #{$this->ruleId} not found.\n");
            return 1;
        }
        $this->applyInput($rule);
        if (!$rule->save()) {
            $this->stderr('Save failed: ' . json_encode($rule->getErrors(), JSON_UNESCAPED_SLASHES) . "\n");
            return 1;
        }
        $this->stdout("Rule #{$this->ruleId} updated.\n");
        return 0;
    }

    /**
     * Delete a rule by --rule-id.
     */
    public function actionDelete(): int
    {
        if ($this->ruleId < 1) {
            $this->stderr("Missing required option --rule-id.\n");
            return 1;
        }
        $rule = MqttRule::findOne((int) $this->ruleId);
        if ($rule === null) {
            $this->stderr("Rule #{$this->ruleId} not found.\n");
            return 1;
        }
        $rule->delete();
        $this->stdout("Rule #{$this->ruleId} deleted.\n");
        return 0;
    }

    /**
     * Toggle the enabled flag of a rule by --rule-id.
     */
    public function actionToggle(): int
    {
        if ($this->ruleId < 1) {
            $this->stderr("Missing required option --rule-id.\n");
            return 1;
        }
        /** @var MqttRule $rule */
        $rule = MqttRule::findOne((int) $this->ruleId);
        if ($rule === null) {
            $this->stderr("Rule #{$this->ruleId} not found.\n");
            return 1;
        }
        $rule->enabled = $rule->enabled ? 0 : 1;
        if (!$rule->save()) {
            $this->stderr('Save failed: ' . json_encode($rule->getErrors(), JSON_UNESCAPED_SLASHES) . "\n");
            return 1;
        }
        $this->stdout("Rule #{$this->ruleId} enabled=" . $rule->enabled . ".\n");
        return 0;
    }

    /**
     * Show runtime hit/action stats for each rule (counters are per worker).
     *
     *   hits   : times the rule's filter matched an event
     *   actOK  : actions executed without throwing
     *   actFail: actions that threw (see the broker error log for details)
     */
    public function actionStats(): int
    {
        // Counters live in the mqtt_rule_stat table (flushed by the long-running
        // broker workers), so this short-lived console process reads the DB directly.
        $rows = MqttRuleStat::find()->orderBy('rule_id ASC')->all();
        if (empty($rows)) {
            $this->stdout("No rule stats recorded yet.\n", Console::FG_YELLOW);
            return 0;
        }
        $this->stdout(sprintf("%-6s %-10s %-10s %-10s\n", 'Rule', 'Hits', 'ActOK', 'ActFail'));
        foreach ($rows as $r) {
            $this->stdout(sprintf("%-6s %-10d %-10d %-10d\n", $r->rule_id, $r->hits, $r->ok, $r->fail));
        }
        return 0;
    }

    /**
     * Reset the in-memory rule stats counters.
     */
    public function actionResetStats(): int
    {
        RuleEngine::instance()->resetStats();
        $this->stdout("Rule stats reset.\n");
        return 0;
    }

    private function applyInput(MqttRule $rule): void
    {
        // 属性都有非 null 默认值，无法用 !== null 区分"未传"，必须用框架记录的传入选项
        $passed = array_flip($this->getPassedOptions());

        if (isset($passed['name'])) {
            $rule->name = $this->name;
        }
        if (isset($passed['source'])) {
            $rule->source = $this->source;
        }
        if (isset($passed['filter'])) {
            $rule->filter = $this->filter;
        }
        if (isset($passed['actions'])) {
            $decoded = json_decode($this->actions, true);
            $rule->actions = (json_last_error() === JSON_ERROR_NONE)
                ? json_encode($decoded, JSON_UNESCAPED_SLASHES)
                : $this->actions;
        }
        if (isset($passed['enabled'])) {
            $rule->enabled = (int) $this->enabled;
        }
        if (isset($passed['priority'])) {
            $rule->priority = (int) $this->priority;
        }
    }
}
