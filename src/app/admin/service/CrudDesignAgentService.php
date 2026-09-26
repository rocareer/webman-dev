<?php
/**
 * AI 模块设计生成服务（FACTORY P1，软依赖 rocareer/agent）
 *
 * 形态（对齐 dataio MappingAgentService）：
 *   suggest（web/CLI，校验 + 落 pending 草稿 + 投递队列，立即返回 request_id）
 *   → CrudDesignConsumer → execute()（同步档，回退路径）/ executeAsync()（**回调档**，2026-09-27）
 *   → AgentGateway::chat / chatAsync 一次性 LLM 调用（max_tokens=16384，JSON 输出）
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
     *
     * **同步档**（回退路径）：宿主未走回调档（队列不在 `consumer.async_queues` / 消费类未实现
     * `AsyncConsumer`）时走它。回调档孪生见 {@see self::executeAsync()}——两档共用
     * {@see self::settleDesign()} / {@see self::failDesign()}（**同一份落库与推送**）。
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
            $this->failDesign($draft, $requestId, $e);

            return;
        }

        $this->settleDesign($draft, $requestId, $result);
    }

    /**
     * 消费者入口（**回调档**，2026-09-27 去协程化收口）：与 {@see self::execute()} **逐字同源**——
     * 同一份 `buildDesign*` 两档腿、同一份 {@see self::settleDesign()} / {@see self::failDesign()}，
     * 差别只在等待方式：回调档下**返回 ≠ 完成**，收口由 `$ok`/`$fail` 决定（宿主消费类经
     * `app\queue\service\DeferredAck` 延后 ack）。
     *
     * 失败语义与同步档同款：**LLM 失败 / 输出非法都在本类内落 `status=failed` + 推送，随后 `$ok()`**
     * ——同步档那里 catch 后 `return`、消费类照常返回 = 队列 ack（不重试），两档必须一致。
     * `$fail` 只接**收口环节自身**的意外（落库/推送抛出，罕见），那才交队列重试/死信。
     *
     * ★ 时限归调用方：回调档下等待语义从 HTTP 客户端转移到续体，宿主消费类包
     * `app\support\Async::timeout()`；本层不自设时限（包侧不引宿主 `Async`）。
     *
     * @param callable $ok   `fn (): void`——任务真完成（含"业务失败已落账"）
     * @param callable $fail `fn (\Throwable $e): void`——收口环节意外，交队列重试/死信
     */
    public function executeAsync(string $requestId, callable $ok, callable $fail): void
    {
        $draft = CrudDesignDraft::where('request_id', $requestId)->first();
        if (!$draft || $draft->status !== CrudDesignDraft::STATUS_PENDING) {
            $ok(); // 已终态/不存在：免做收口（与同步档 `return` 同口径）

            return;
        }

        try {
            $this->buildDesignAsync(
                $draft,
                fn (array $result) => $this->closeOut(fn () => $this->settleDesign($draft, $requestId, $result), $ok, $fail),
                fn (Throwable $e) => $this->closeOut(fn () => $this->failDesign($draft, $requestId, $e), $ok, $fail)
            );
        } catch (Throwable $e) {
            // 派发期意外（同步抛，如网关缺失）：与同步档外层 catch 同口径 —— 落 failed 后照常收口
            $this->closeOut(fn () => $this->failDesign($draft, $requestId, $e), $ok, $fail);
        }
    }

    /**
     * 收口环节守卫（回调档专用）：业务落账已完成 ⇒ `$ok()`；落账自身抛出 ⇒ 交队列重试/死信。
     */
    private function closeOut(callable $work, callable $ok, callable $fail): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            $fail($e);

            return;
        }
        $ok();
    }

    /**
     * 失败落账（**两档共用**，唯一实现）：记日志 + 草稿置 failed + happ 推送。
     *
     * 注意调用方语义：同步档 catch 后直接 `return`、回调档随后 `$ok()` ——两档都**不进重试**
     * （LLM 失败是业务态失败，草稿已留痕，重投只会再烧一次调用）。
     */
    protected function failDesign(CrudDesignDraft $draft, string $requestId, Throwable $e): void
    {
        Log::channel('Radmin')->error('crud.design.failed', ['request_id' => $requestId, 'exception' => (string) $e]);
        $draft->update([
            'status' => CrudDesignDraft::STATUS_FAILED,
            'errors' => ['message' => mb_substr($e->getMessage(), 0, 300)],
        ]);
        CrudDesignPusher::toAdmin((int) $draft->admin_id, CrudDesignPusher::EVENT_SUGGESTED, [
            'request_id' => $requestId, 'ok' => false,
            'error' => mb_substr($e->getMessage(), 0, 200),
        ]);
    }

    /**
     * 成功/校验不通过的落账（**两档共用**，唯一实现）：三分支与改造前 `execute()` 内联代码逐字一致。
     *
     * @param array{design: array, errors: array, warnings: array, rounds: int} $result
     */
    protected function settleDesign(CrudDesignDraft $draft, string $requestId, array $result): void
    {
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
     * 调 LLM 产设计 + 净化 + 校验（含自修复轮）——**回调档**
     *
     * 与 {@see self::buildDesign()} **逐字同源**：同一份提示词装配（`systemPrompt`/`userPrompt`）、
     * 同一份 `decodeDesign`/`sanitize`/`validate`、同一份轮数上限 `MAX_ROUNDS` 与同一套回灌文案；
     * 差别只在"下一轮"从 `while` 变成续体续跑（`$attempt` 自递归）。
     *
     * 续体自身抛出的异常（解析/校验的意外）**就地改道 `$fail`**——`AgentGateway` 的 `asyncScope`
     * 只做队列标记携带、不套 `Async::guard`，回调里外抛会落进驱动的事件循环（消息会一直挂在
     * deferred 槽上直到租约超时），故本层自己兜。
     *
     * @param callable $ok   `fn (array{design:array,errors:array,warnings:array,rounds:int} $result): void`
     * @param callable $fail `fn (\Throwable $e): void`——LLM 腿失败（与同步档 `callLlm()` 抛出同口径）
     */
    protected function buildDesignAsync(CrudDesignDraft $draft, callable $ok, callable $fail): void
    {
        $designSvc = new CrudDesignService();
        $agentKey = $draft->agent_key !== '' ? (string) $draft->agent_key : $this->defaultAgentKey();
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt((string) $draft->prompt, (string) $draft->table_name)],
        ];

        $lastDesign = [];
        $lastErrors = [];
        $lastWarnings = [];
        $round = 0;

        // 一轮 = 一次 LLM 调用 + 净化校验；未收敛且还有轮数 ⇒ 回灌错误续下一轮（= 同步档的 while）
        $attempt = function () use (&$attempt, &$messages, &$lastDesign, &$lastErrors, &$lastWarnings, &$round, $designSvc, $agentKey, $ok, $fail): void {
            $round++;
            $this->callLlmAsync(
                $agentKey,
                $messages,
                function (string $content) use (&$attempt, &$messages, &$lastDesign, &$lastErrors, &$lastWarnings, &$round, $designSvc, $ok, $fail): void {
                    try {
                        $decoded = $this->decodeDesign($content);
                        if ($decoded === null) {
                            // 非法 JSON：回灌要求只输出 JSON（文案同同步档）
                            $lastErrors = [['field' => '', 'code' => 'LLM_BAD_JSON', 'message' => 'AI 输出不是合法 JSON']];
                            $messages[] = ['role' => 'assistant', 'content' => mb_substr($content, 0, 2000)];
                            $messages[] = ['role' => 'user', 'content' => '你的输出不是合法 JSON。请只输出一个 JSON 对象（设计态契约 v2），不要任何解释文字或代码围栏。'];
                            $this->nextRoundOrClose($attempt, $round, $lastDesign, $lastErrors, $lastWarnings, $ok);

                            return;
                        }
                        $san = $designSvc->sanitize($decoded);
                        $lastDesign = $san['design'];
                        $lastWarnings = $san['warnings'];
                        $lastErrors = $designSvc->validate($san['design']);
                        if (!$lastErrors) {
                            $ok(['design' => $lastDesign, 'errors' => [], 'warnings' => $lastWarnings, 'rounds' => $round]);

                            return;
                        }
                        // 回灌结构化错误让 LLM 自修复（文案同同步档）
                        $messages[] = ['role' => 'assistant', 'content' => mb_substr($content, 0, 2000)];
                        $messages[] = ['role' => 'user', 'content' => "设计存在以下问题，请修正后重新只输出完整 JSON：\n"
                            . json_unicode($lastErrors, JSON_UNESCAPED_UNICODE)];
                        $this->nextRoundOrClose($attempt, $round, $lastDesign, $lastErrors, $lastWarnings, $ok);
                    } catch (Throwable $e) {
                        // 续体内意外（净化/校验抛出）：与同步档"外抛 ⇒ 归失败"同口径
                        $fail($e);
                    }
                },
                $fail
            );
        };

        $attempt();
    }

    /**
     * 续轮闸（两档共用的**同一判据**）：还有轮数 ⇒ 续跑，用尽 ⇒ 按同步档 `while` 退出后的同一形态收口。
     *
     * @param callable $attempt 下一轮续体
     * @param array<string, mixed> $design
     * @param list<array<string, mixed>> $errors
     * @param list<string> $warnings
     * @param callable $ok `fn (array): void`
     */
    private function nextRoundOrClose(callable $attempt, int $round, array $design, array $errors, array $warnings, callable $ok): void
    {
        if ($round < self::MAX_ROUNDS) {
            $attempt();

            return;
        }
        $ok(['design' => $design, 'errors' => $errors, 'warnings' => $warnings, 'rounds' => $round]);
    }

    /**
     * 单次 LLM 调用（**回调档**；一次性，JSON 输出）——与 {@see self::callLlm()} 同源同参
     * （同一 `$agentKey` / `$messages` / `max_tokens=16384` / `source=queue` / `bizType=agent`）。
     *
     * 空串与同步档同口径：**归失败**（同步档抛 `RuntimeException`）——这里显式走 `$fail`，
     * 不在续体里外抛（理由见 {@see self::buildDesignAsync()} 头注）。
     *
     * @param callable $ok   `fn (string $content): void`
     * @param callable $fail `fn (\Throwable $e): void`
     */
    protected function callLlmAsync(string $agentKey, array $messages, callable $ok, callable $fail): void
    {
        $gatewayClass = '\\app\\agent\\support\\AgentGateway';
        (new $gatewayClass())->chatAsync(
            $agentKey,
            $messages,
            ['max_tokens' => 16384],
            \app\agent\support\AgentGateway::SOURCE_QUEUE,   // 与同步档 'queue' 同值
            \app\admin\service\AiRouterService::BIZ_AGENT,   // = 同步档 chat() 的缺省 bizType
            '',
            static function (array $out) use ($ok, $fail): void {
                try {
                    $content = (string) ($out['result']['choices'][0]['message']['content'] ?? '');
                    if ($content === '') {
                        throw new \RuntimeException('LLM 返回为空（content 空串，建议放大预算排查渠道）');
                    }
                    $ok($content);
                } catch (Throwable $e) {
                    $fail($e);
                }
            },
            $fail
        );
    }

    /**
     * 单次 LLM 调用（一次性，JSON 输出）
     *
     * **同步档 = 回退路径**：未开闸（队列不在 `consumer.async_queues`）/ 未迁宿主走它；
     * 回退路径与 {@see self::callLlmAsync()} 同源同参。
     */
    protected function callLlm(string $agentKey, array $messages): string
    {
        $gatewayClass = '\\app\\agent\\support\\AgentGateway';
        // async-rule-exempt: 同步档回退腿（回调档孪生 callLlmAsync() 已就位；全站收口时随 vendor 同步口一起撤）
        $response = (new $gatewayClass())->chat($agentKey, $messages, ['max_tokens' => 16384], 'queue');
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
        return "你是 radmin 后台模块设计助手。根据用户需求产出「设计态契约 v3」的 JSON。\n"
            . "硬性要求：\n"
            . "1. 只输出一个 JSON 对象，禁止代码围栏、注释或任何解释文字。\n"
            . "2. 结构：{\"version\":3,\"table\":{\"name\":\"小写蛇形表名\",\"comment\":\"中文表名\",\"quick_search\":[...],\"form_layout\":[{\"group\":\"分组名\",\"fields\":[...]}]},\"fields\":[{\"name\":\"小写蛇形\",\"comment\":\"中文列名\",\"design_type\":\"控件类型\",...}]}。\n"
            . "3. design_type 只能取：{$types}。\n"
            . "4. 关联字段用 remote_select/remote_selects，并给 remote:{table,pk,field,relation_fields}；不确定就用 input，不要编造。\n"
            . "5. 枚举字段（select/radio/checkbox/selects）必须给 options:[{label,value}]，不要编造选项。\n"
            . "6. 不要声明 id/create_time/update_time（系统自动注入）；主键固定 id。\n"
            . "7. 字段若列入 form_layout 必须先声明；不确定就不给 form_layout。\n"
            . "8. 顶层可选 target 落位块（需求里出现「插件/落位/菜单目录或父级/图标」等落位信息时**必须**原样体现；"
            . "需求没提就整块省略——省略=落宿主 app/ 的默认形态）："
            . "{\"profile\":\"rolling-plugin\",\"plugin\":\"插件名(小写蛇形)\",\"controller_dir\":\"控制器目录段(缺省=插件名)\","
            . "\"model_scope\":\"domain\",\"menu\":{\"icon\":\"fa fa-xxx\",\"parent\":\"父级菜单 name（如 system）\",\"parent_title\":\"目录中文标题\",\"title\":\"菜单标题\"}}。"
            . "profile=rolling-plugin 表示模块落该插件（后端 plugin/<plugin>/app/**、路由/菜单种子/语言包一并生成）；"
            . "icon 必须是请求里给的名字（宿主按 Font Awesome 4.7 校验，不确定就用 fa fa-circle-o）；parent/parent_title 不许编造，请求没给就不写这两个键。";
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
