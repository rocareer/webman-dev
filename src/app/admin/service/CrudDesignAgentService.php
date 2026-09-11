<?php
/**
 * AI 模块设计生成服务（FACTORY P1，软依赖 rocareer/agent）
 *
 * 形态（对齐 dataio MappingAgentService）：
 *   suggest（web/CLI，校验 + 落 pending 草稿 + 投递队列，立即返回 request_id）
 *   → CrudDesignConsumer → execute（协程）
 *   → AgentGateway::chat 一次性 LLM 调用（max_tokens=8192，JSON 输出）
 *   → stripJsonFence → CrudDesignService::sanitize（反幻觉白名单）
 *     → ::validate（结构化错误）→ 不通过回灌错误让 LLM 自修复一轮
 *   → 落草稿 status=suggested + happ 推送
 *   → confirm（人工确认，唯一出码入口）→ CrudDesignGenerator 落盘出码
 *
 * 红线（三层表达，同 dataio）：
 *   ① 本类头注释声明「LLM 只产设计草稿，必须人工确认后才出码」；
 *   ② suggest() 只落草稿，AI 无任何直达 generate() 的路径——出码仅在 confirm() 内；
 *   ③ 前端/CLI 显式提示「AI 结果仅填充待确认设计」。
 * 未安装 agent 包时 gatewayExists()=false，调用方给出明确错误。
 */

namespace app\admin\service;

use app\admin\model\CrudDesignDraft;
use Rocareer\WebmanDev\support\CrudDesignGenerator;
use Rocareer\WebmanDev\support\CrudDesignService;
use support\Log;
use Throwable;
use Webman\RedisQueue\Redis;

class CrudDesignAgentService
{
    /** 队列名（消费者 app/queue/redis/CrudDesignConsumer.php） */
    public const QUEUE = 'crud-design';

    /** LLM 自修复轮数上限（含首轮） */
    protected const MAX_ROUNDS = 2;

    /**
     * agent 包网关是否存在（软依赖探测）
     */
    public static function gatewayExists(): bool
    {
        return class_exists(\app\agent\support\AgentGateway::class);
    }

    /**
     * 发起设计建议（校验 + 落草稿 + 投递队列，立即返回）
     *
     * @return array{request_id: string, status: string}
     */
    public function suggest(string $prompt, string $tableName, int $adminId, string $agentKey = ''): array
    {
        if (!static::gatewayExists()) {
            throw new \RuntimeException('AI 生成设计需要安装 rocareer/agent');
        }
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new \RuntimeException('请描述要生成的模块（自然语言需求）');
        }
        $tableName = strtolower(trim($tableName));
        if ($tableName !== '' && !preg_match('/^[a-z][a-z0-9_]*$/', $tableName)) {
            throw new \RuntimeException('表名不合法（小写蛇形，如 erp_customer）');
        }

        $requestId = function_exists('uuid7') ? uuid7() : bin2hex(random_bytes(16));
        $draft = CrudDesignDraft::create([
            'request_id' => $requestId,
            'table_name' => $tableName,
            'prompt' => $prompt,
            'agent_key' => $agentKey,
            'status' => CrudDesignDraft::STATUS_PENDING,
            'rounds' => 0,
            'admin_id' => $adminId,
        ]);

        if (!Redis::send(self::QUEUE, ['request_id' => $requestId])) {
            $draft->update(['status' => CrudDesignDraft::STATUS_FAILED, 'errors' => ['message' => '建议任务投递失败']]);
            throw new \RuntimeException('设计建议投递失败，请稍后重试');
        }
        return ['request_id' => $requestId, 'status' => 'suggesting'];
    }

    /**
     * 消费者入口：调 LLM 产设计 → 净化校验 → 落草稿 → 推送
     */
    public function execute(string $requestId): void
    {
        $draft = CrudDesignDraft::where('request_id', $requestId)->first();
        if (!$draft || $draft->status !== CrudDesignDraft::STATUS_PENDING) {
            return;
        }

        try {
            $result = $this->buildDesign($draft);
        } catch (Throwable $e) {
            Log::channel('Radmin')->error('crud.design.failed', ['request_id' => $requestId, 'exception' => (string) $e]);
            $draft->update([
                'status' => CrudDesignDraft::STATUS_FAILED,
                'errors' => ['message' => mb_substr($e->getMessage(), 0, 300)],
            ]);
            CrudDesignPusher::toAdmin((int) $draft->admin_id, CrudDesignPusher::EVENT_SUGGESTED, [
                'request_id' => $requestId, 'ok' => false,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
            return;
        }

        // 校验不通过：落失败草稿（保留 design 供人工参考/修改）
        if (!empty($result['errors'])) {
            $draft->update([
                'status' => CrudDesignDraft::STATUS_FAILED,
                'design' => $result['design'],
                'errors' => $result['errors'],
                'warnings' => $result['warnings'],
                'rounds' => $result['rounds'],
            ]);
            Log::channel('Radmin')->warning('crud.design.invalid', ['request_id' => $requestId, 'errors' => $result['errors']]);
            CrudDesignPusher::toAdmin((int) $draft->admin_id, CrudDesignPusher::EVENT_SUGGESTED, [
                'request_id' => $requestId, 'ok' => false,
                'error' => 'AI 设计未通过校验', 'errors' => $result['errors'],
            ]);
            return;
        }

        $draft->update([
            'status' => CrudDesignDraft::STATUS_SUGGESTED,
            'table_name' => (string) ($result['design']['table']['name'] ?? $draft->table_name),
            'design' => $result['design'],
            'errors' => [],
            'warnings' => $result['warnings'],
            'rounds' => $result['rounds'],
        ]);
        Log::channel('Radmin')->info('crud.design.suggested', [
            'request_id' => $requestId,
            'table' => $result['design']['table']['name'] ?? '',
            'rounds' => $result['rounds'],
        ]);
        CrudDesignPusher::toAdmin((int) $draft->admin_id, CrudDesignPusher::EVENT_SUGGESTED, [
            'request_id' => $requestId, 'ok' => true,
            'draft_id' => (int) $draft->id,
            'table' => (string) ($result['design']['table']['name'] ?? ''),
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * 人工确认后出码（唯一执行入口）
     *
     * @param array $designOverride 人工在草稿基础上编辑后的设计（可选，覆盖 AI 草稿）
     * @return array 结构化回执（CrudDesignGenerator 形状）
     */
    public function confirm(int $draftId, bool $force = false, array $designOverride = []): array
    {
        $draft = CrudDesignDraft::find($draftId);
        if (!$draft) {
            throw new \RuntimeException('草稿不存在');
        }
        if (!in_array($draft->status, [CrudDesignDraft::STATUS_SUGGESTED, CrudDesignDraft::STATUS_FAILED], true)) {
            throw new \RuntimeException('仅 suggested/failed 草稿可确认出码（当前：' . $draft->status . '）');
        }
        $design = $designOverride ?: (array) $draft->design;
        if (!$design) {
            throw new \RuntimeException('草稿无设计内容');
        }

        $receipt = (new CrudDesignGenerator())->generate($design, false, $force, false);
        if (empty($receipt['ok'])) {
            // 出码失败不改状态，保留草稿供人工修正后重试
            return $receipt;
        }
        $draft->update([
            'status' => CrudDesignDraft::STATUS_CONFIRMED,
            'design' => $receipt['design'] ?? $design,
            'confirmed_at' => time(),
        ]);
        return $receipt;
    }

    /**
     * 弃用草稿
     */
    public function reject(int $draftId): void
    {
        $draft = CrudDesignDraft::find($draftId);
        if ($draft && $draft->status !== CrudDesignDraft::STATUS_CONFIRMED) {
            $draft->update(['status' => CrudDesignDraft::STATUS_REJECTED]);
        }
    }

    /**
     * 调 LLM 产设计 + 净化 + 校验（含自修复轮）
     *
     * @return array{design: array, errors: array, warnings: array, rounds: int}
     */
    protected function buildDesign(CrudDesignDraft $draft): array
    {
        $designSvc = new CrudDesignService();
        $agentKey = $draft->agent_key !== '' ? (string) $draft->agent_key : $this->defaultAgentKey();
        $system = $this->systemPrompt();
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $this->userPrompt((string) $draft->prompt, (string) $draft->table_name)],
        ];

        $lastDesign = [];
        $lastErrors = [];
        $lastWarnings = [];
        $round = 0;
        while ($round < self::MAX_ROUNDS) {
            $round++;
            $content = $this->callLlm($agentKey, $messages);
            $decoded = $this->decodeDesign($content);
            if ($decoded === null) {
                // 非法 JSON：回灌要求只输出 JSON
                $lastErrors = [['field' => '', 'code' => 'LLM_BAD_JSON', 'message' => 'AI 输出不是合法 JSON']];
                $messages[] = ['role' => 'assistant', 'content' => mb_substr($content, 0, 2000)];
                $messages[] = ['role' => 'user', 'content' => '你的输出不是合法 JSON。请只输出一个 JSON 对象（设计态契约 v2），不要任何解释文字或代码围栏。'];
                continue;
            }
            $san = $designSvc->sanitize($decoded);
            $lastDesign = $san['design'];
            $lastWarnings = $san['warnings'];
            $lastErrors = $designSvc->validate($san['design']);
            if (!$lastErrors) {
                return ['design' => $lastDesign, 'errors' => [], 'warnings' => $lastWarnings, 'rounds' => $round];
            }
            // 回灌结构化错误让 LLM 自修复
            $messages[] = ['role' => 'assistant', 'content' => mb_substr($content, 0, 2000)];
            $messages[] = ['role' => 'user', 'content' => "设计存在以下问题，请修正后重新只输出完整 JSON：\n"
                . json_unicode($lastErrors, JSON_UNESCAPED_UNICODE)];
        }
        return ['design' => $lastDesign, 'errors' => $lastErrors, 'warnings' => $lastWarnings, 'rounds' => $round];
    }

    /**
     * 单次 LLM 调用（一次性，JSON 输出）
     */
    protected function callLlm(string $agentKey, array $messages): string
    {
        $gatewayClass = '\\app\\agent\\support\\AgentGateway';
        $response = (new $gatewayClass())->chat($agentKey, $messages, ['max_tokens' => 8192], 'queue');
        $content = (string) ($response['result']['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            throw new \RuntimeException('LLM 返回为空（content 空串，建议放大预算排查渠道）');
        }
        return $content;
    }

    /**
     * 解析 LLM 输出的设计 JSON（剥围栏 + 解码；失败返回 null）
     */
    protected function decodeDesign(string $content): ?array
    {
        $decoded = json_decode($this->stripJsonFence($content), true);
        if (!is_array($decoded) || empty($decoded['table']) || empty($decoded['fields'])) {
            return null;
        }
        return $decoded;
    }

    protected function systemPrompt(): string
    {
        $types = implode('/', array_keys(\Rocareer\WebmanDev\support\CrudDesigner::DESIGN_TYPES));
        return "你是 radmin 后台模块设计助手。根据用户需求产出「设计态契约 v2」的 JSON。\n"
            . "硬性要求：\n"
            . "1. 只输出一个 JSON 对象，禁止代码围栏、注释或任何解释文字。\n"
            . "2. 结构：{\"version\":2,\"table\":{\"name\":\"小写蛇形表名\",\"comment\":\"中文表名\",\"quick_search\":[...],\"form_layout\":[{\"group\":\"分组名\",\"fields\":[...]}]},\"fields\":[{\"name\":\"小写蛇形\",\"comment\":\"中文列名\",\"design_type\":\"控件类型\",...}]}。\n"
            . "3. design_type 只能取：{$types}。\n"
            . "4. 关联字段用 remote_select/remote_selects，并给 remote:{table,pk,field,relation_fields}；不确定就用 input，不要编造。\n"
            . "5. 枚举字段（select/radio/checkbox/selects）必须给 options:[{label,value}]，不要编造选项。\n"
            . "6. 不要声明 id/create_time/update_time（系统自动注入）；主键固定 id。\n"
            . "7. 字段若列入 form_layout 必须先声明；不确定就不给 form_layout。";
    }

    protected function userPrompt(string $prompt, string $tableName): string
    {
        $hint = $tableName !== '' ? "（表名请用：{$tableName}）" : '';
        return "需求：{$prompt}{$hint}\n请据此产出完整的设计 JSON。";
    }

    protected function defaultAgentKey(): string
    {
        return 'chat';
    }

    /**
     * 剥 ```json 围栏
     */
    protected function stripJsonFence(string $content): string
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }
        return trim($content);
    }
}
