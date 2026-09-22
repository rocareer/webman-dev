<?php

namespace app\admin\service;

use app\admin\model\DevAuditProject;
use app\admin\model\DevAuditResult;
use app\admin\model\DevAuditRule;

/**
 * 工程质量审计引擎（webman-dev）
 *
 * rocareer:audit CLI 与后台「开发运维 → 工程质量审计」管理页共用同一套规则实现。
 * 规则结果结构统一：
 *   ['code','title','status','pass','skipped','count','issues','note','skip']
 * status：pass / fail / not_applicable；skipped=true 仅表示规则不适用，不能替代覆盖失败；
 * pass 仅对已执行规则有意义；note 为通过行的补充说明（文件数/方法数等）。
 *
 * 源码根目录约定：命令 --root 与后台 audit_root 配置都指向「包目录所在的 src 根」
 * （即同时含 radmin/、ai/ 等包目录的目录，工作区为 <Rocareer>/src）；自动探测兼容新旧布局
 * （工作区根 <Rocareer> 传入时自动落到 <Rocareer>/src）。
 */
class AuditService
{
    /** 内置规则元数据（code => 名称/说明；与迁移种子 radmin_dev_audit_rule 保持一致） */
    public const RULES = [
        'php_syntax' => ['title' => 'PHP 语法检查', 'description' => 'php -l 全量语法校验（批量子进程，单次调用）'],
        'controller' => ['title' => '控制器规范', 'description' => '继承 Backend、: Response 签名、initialize 调 parent::initialize()、public 方法返回类型'],
        'permission' => ['title' => '权限节点匹配', 'description' => '控制器方法 routePath 与迁移注册的按钮名比对：缺失/错名/孤儿按钮全部报出；迁移之外的按钮名字面量清单（种子/常量）可在业务端 config/audit.php 的 button_name_globs 声明为额外扫描源'],
        'migration' => ['title' => '迁移命名与查重（精确到秒）', 'description' => '全工作区 migrations/pg-migrations 迁移文件形态门禁（与 webman-migration v2.4.0 运行时强检同口径）：版本号撞号（撞号会阻断全家桶 migrate:run）、数字前缀非 14 位时间戳（8 位「年月日就完了」风会被 Phinx 照常加载且前缀即版本号，撞号高危）、14 位裸版本号缺名字段、不匹配 Phinx 正则的静默忽略文件（永不执行，造成已迁移假象）全部报错；「年月日+000000」存量只计数进 note 不报错（新建禁止）。新建迁移一律 php webman migrate:create 生成（真实时间戳精确到秒 + 全局查重自动顺延）'],
        'residue' => ['title' => '残留扫描', 'description' => 'CRUD 脚手架死代码（Test 控制器/模型/验证器）+ TODO/FIXME 计数'],
        'version' => ['title' => '版本同步', 'description' => 'CHANGELOG 头部版本 vs dev/full composer.json path 钉版'],
        'web_page' => ['title' => '前端页面规范', 'description' => 'Vue 页面模板一致性：禁止自创依赖注入/裸 axios//src/ 导入、baTable 体系页面必须经 baTable、弹窗提交走 onSubmit、TableHeader 顶部自定义按钮必须用标准样式类 table-header-operate（radmin 同步树跳过）'],
        'happ_frontend' => ['title' => 'happ 前端接入规范', 'description' => '全域 happ 前端接入门禁（见 AGENTS.md 异步铁律 5/6 与 rocareer/happ-client README）：浏览器实时接入只认 happ-client SDK 两条路径——JS 版 /@/utils/happClient（useHapp 单例）与 Vue 版 /@/composables/useHappConnection（响应式状态+组件作用域订阅自动退订）；各包 web/src 与全域盲区（dev 宿主工程 web 树、super/web/src、skyline 小程序——radmin 条目承载 sweepHappFrontendBlind 每轮一次）内出现 new WebSocket(、wx.connectSocket 或裸 ws://、wss:// 字面量即手写连接（绕过 HMAC 凭证认证/心跳/指数退避重连），全部报错；SDK 真源文件（utils/happClient.ts、composables/useHappConnection.ts）与标注 @audit-ignore happ_frontend 豁免'],
        'async_blocking' => ['title' => '异步阻塞扫描', 'description' => '异步铁律：常驻进程代码（src/app，排除 CLI command/）内禁止 BRPOP 长拉、同步 Guzzle HTTP、同步 SMTP、curl_exec、usleep/sleep 阻塞事件循环；文件显式声明协程回退（Coroutine::isCoroutine / inCoroutine / Fiber::getCurrent）或标注 @audit-ignore async_blocking 即视为已实现 CLI 回退，跳过'],
        'fqcn_dup' => ['title' => '同名类冲突', 'description' => '全工作区 namespace+class 对去重：同一 FQCN 被多文件定义（含 PSR-4 加载不到的死副本）即报；文件标注 @audit-ignore fqcn_dup 视为有意的真源同步副本'],
        'superglobal' => ['title' => '超全局直读', 'description' => 'webman worker 内直读 $_COOKIE/$_SERVER 不可靠（不自动填充/命名不可配），应走 support\\Context + Request；文件标注 @audit-ignore superglobal 视为已声明 CLI/回退路径人工确认'],
        'dead_code' => ['title' => '死类检测', 'description' => '全工作区零引用（无 new/静态调用/::class/配置字符串/use 导入引用）的非框架类 = 死代码候选；SDK 包（无 src/app/admin，公共 API 供外部消费）与标注 @audit-ignore dead_code 的文件跳过'],
        'cross_copy' => ['title' => '跨包文件重复', 'description' => '不同包内容逐字节相同的 .php 文件 = 复制粘贴实现（应下沉共享，防接口漂移）'],
        'dto_contract' => ['title' => 'DTO 分层规范', 'description' => 'DTO 分层门禁：公开 API 控制器（非 admin）手拼多字段数组输出 = 契约未固化，应引入 app/<模块>/dto/ typed DTO 或 Model accessor；dto/ 目录内纯搬运类（toArray 原样返回入参、无整形/强转/脱敏）= 过度设计，直接用数组；目录命名用 dto 不用 data（data 与"数据/数据库"歧义）；文件标注 @audit-ignore dto_contract 显式豁免'],
        'llm_gate' => ['title' => '全域 LLM 门禁（智能体出口）', 'description' => '全域 LLM 业务必须经 agent 包 AgentGateway（无智能体不开工）：业务代码禁止直接实例化 AiRouterService 调用 LLM/向量化；ai（底层提供者）与 agent（网关）豁免；文件标注 @audit-ignore llm_gate 显式豁免（如 ai 调试/开放 API 运维接口）'],
        'orm_migrated' => ['title' => 'think 生态残留门禁（think-orm + think-validate 等清零）', 'description' => 'think 生态残留门禁：src 内禁止任何 think 类引用（think\facade\Db / think\db\exception / think\model\relation / think\Validate / think\Facade / think\exception\ValidateException / think\Paginator / think\File / think\Exception）与 config(\'think-orm...\') 调用、composer 依赖 webman/think-orm 或 topthink/*（v5.0.0 起验证框架已切 webman/validation，think-validate/think-container 白名单随 radmin v5.0.0 移除）；文件标注 @audit-ignore orm_migrated 显式豁免'],
        'event_standard' => ['title' => '事件规范（webman/event）', 'description' => 'webman/event 使用规范门禁（见 docs/webman-event-standard.md）：事件发射一律用 Event::dispatch（不吞异常，监听器异常上抛），禁止 Event::emit（吞异常掩盖监听器故障）；事件名必须 <提供方>.<领域>.<动作> 全小写点分（禁驼峰/连字符/下划线分隔/无前缀裸名）；业务代码禁止散落 Event::on()（监听器集中 config/plugin/*/event.php 或 config/event.php 声明，唯一例外 radmin EventRegister 内置 member.*）；静态事件名应在本包/跨包/宿主有对应监听器（孤儿事件=发射即空转，纯日志应直写日志）；**监听登记文件的布局由业务端在 config/audit.php 的 event_registry_globs 声明**（内建 glob 只认家族布局约定；rolling 等自有布局的登记源不声明即误报孤儿）；app/listener 监听器方法签名 (array $data): void + 自身 try/catch；文件标注 @audit-ignore event_standard 显式豁免'],
        'common_utils' => ['title' => '通用工具真源门禁（禁止重复造轮子）', 'description' => '通用工具真源门禁（见 docs/common-utils-registry.md）：已知手写重复模式必须用 radmin 全局函数——max(1, min(100 → clamp_limit、分页 max(1, (int) → clamp_page、keyword/quickSearch 兼容链 → request_keyword、where 闭包多字段 like → keyword_like、json_encode(UNICODE|SLASHES) → json_unicode、strtr(base64_encode → base64url_encode、md5(uniqid → uuid7、固定四星掩码 → mask_secret；真源定义文件（radmin functions.php）与审计引擎自身源文件豁免；文件标注 @audit-ignore common_utils 显式豁免'],
        'comsearch_contract' => ['title' => '高级检索契约门禁（comSearch 基础能力）', 'description' => '高级检索/排序是 radmin 基础能力（Backend::applyListQueryContract 一行接入）：admin 控制器禁止手写解析 comSearch 的 search 数组（->input(\'search\')）——各处自研解析是静默腐烂高发区（print-erp 16 控制器全灭、crontab Log val/value 键错位对标准 comSearch 无效且 int/enum 列收非法串 500、slides Deck $limit 未定义变量分页大小恒默认等实证）；文件标注 @audit-ignore comsearch_contract 豁免（跨表字段别名等正当映射场景，须注释理由）'],
        'install_standard' => ['title' => 'Install.php 标准化', 'description' => 'Install.php 标准化门禁（见 docs/install-standard.md）：WEBMAN_PLUGIN 常量、install/update/uninstall 三钩子齐全、install 签名兼容官方 Install::install(true)（禁强类型参数）、禁官方骨架残留 copy_dir/remove_dir（显式 overwrite=true 的 copy_dir 除外）与 array() 语法、类前中文头注释；文件标注 @audit-ignore install_standard 显式豁免'],
        'icon_attr' => ['title' => 'el 组件 icon 属性禁传 CSS 类名', 'description' => 'Element Plus 组件 icon 类属性（icon/:icon）按组件渲染：传 fa fa-* 等类名字符串会 createElement(类名) 抛 InvalidCharacterError，页面白屏且此后所有菜单点击空白（dataio 导入向导与 print-erp 双实证）；.vue 内 icon="fa / :icon="fa / :icon="形式即报；合法形态 = <Icon name="fa fa-*" /> 子节点（Icon 经 common.ts 全局注册）或已注册组件名；radmin 同步树跳过（真源在各包 web/），dev 宿主工程 web 树、super/web/src、skyline 盲区由 radmin 条目承载 sweep；文件标注 @audit-ignore icon_attr 显式豁免'],
        'vue_theme_hardcode' => ['title' => 'Vue 主题色硬编码门禁（EP 调色板）', 'description' => 'Element Plus 官方默认调色板色值（#409eff/#67c23a/#e6a23c/#f56c6c/#909399/#ecf5ff/#d9ecff）写死在 .vue 的 <style>/<template> 段 = 本该走主题变量的语义色被固化——用户切换主题色/暗色模式后与全站脱节（print-erp flow 节点状态色实证，2026-09-17 前端全域样式审计）；合法形态 = var(--el-color-*, 色值) 带 fallback 双写（扫描前剔除再匹配）；<script> 段不扫（ECharts/SVG 画布色板属运行时配置，主题跟随可选 getComputedStyle 快照）；打印纸张预览区白底语义属有意设计，注释声明即可；扫描范围 = dev 宿主工程 web 树（radmin 条目承载 sweep），相对路径在 radmin/web/src 存在同路径文件的「全家桶继承页」跳过（真源在 radmin，上游存量不由宿主修），src 各包 web 树存量待自行收口后开启；文件标注 @audit-ignore vue_theme_hardcode 显式豁免'],
        'frontend_raw_fetch' => ['title' => '前端禁裸 fetch/XHR（业务码信封唯一出口）', 'description' => '全栈 API 契约 = HTTP 200 + 业务码信封 {code,msg,time,data}（见 radmin support/StatusCode.php 头注释「业务码≠HTTP 码」），createAxios 拦截器按业务码分诊（409 续期/303 回登录/非 1 红错）——页面裸 fetch/XHR 绕过信封解析只看 res.ok，HTTP 恒 200 时把业务错误当成功（print-erp 导出「假成功」实证：wh1 无导出权限，HTTP 200+code 401 的错误 JSON 被当 CSV 下载，绿 toast 打开全是 error_trace，20260922 收口）；前端调 admin API 一律走 createAxios，文件流下载一律走 utils/download.ts（downloadByToken/runDownload 单一真源：token 头+内容类型判错误体+blob 保存）；扫描各包 web/src 与全域盲区（dev 宿主工程 web 树、super/web/src——skyline 小程序无 fetch API 不扫；radmin 同步树跳过由 radmin 条目承载 sweep）内出现 fetch( 或 new XMLHttpRequest 即报（扫描前剥离单行/块级/HTML 三类注释防自指假命中）；utils/download.ts 与 utils/happClient.ts（SDK 真源）与标注 @audit-ignore frontend_raw_fetch 豁免，且豁免文件内已无裸调用时反向报「请收敛豁免清单」（例外清单双向断言）；radmin/web/src 存在同路径文件的「全家桶继承页」跳过（真源在 radmin/BuildAdmin 上游，宿主不修，同 vue_theme_hardcode 先例）'],
    ];

    /** 问题明细入库/返回上限（完整数量在 count） */
    public const MAX_ISSUES = 50;

    /** 本轮工作区迁移扫描结果（避免每个包重复扫描） */
    protected ?array $migrationScan = null;

    /** 此轮全域前端盲区扫描结果（radmin 条目承载，audit() 内重置） */
    protected ?array $happBlindScan = null;

    /** 此轮 icon 属性盲区扫描结果（radmin 条目承载，audit() 内重置） */
    protected ?array $iconBlindScan = null;

    /** 此轮 Vue 主题色硬编码盲区扫描结果（radmin 条目承载，audit() 内重置） */
    protected ?array $themeBlindScan = null;

    /** 此轮前端裸 fetch/XHR 盲区扫描结果（radmin 条目承载，audit() 内重置） */
    protected ?array $rawFetchBlindScan = null;

    /** 本轮已归属过迁移冲突的时间戳（避免全量审计重复报错） */
    protected array $reportedMigrationStamps = [];

    /**
     * 源码布局（audit() 每轮按 root 判定；规则内 `src/` 前缀统一走 srcPath）：
     *   family  —— 家族包布局（`<包>/src/app/**`，缺省；dev/full 宿主与各 rocareer 包）
     *   rolling —— Rolling 工作区布局（`app/` 主应用 + `plugin/<名>/app/**` + 单库 `database/migrations`）
     * 缺省 family，规则产物与历史逐字节一致。
     */
    protected string $layout = 'family';

    /** rolling 布局的工作区根（`plugins/app/webman` 三件套命中的目录） */
    protected string $rollingRoot = '';

    /** 包内类名 -> 文件路径索引（extends 链解析用；按包目录缓存） */
    protected array $classFileIndex = [];

    /**
     * 业务端审计适配（config/audit.php 协议，v3.27.0 起）——分工口径：**基础设施出引擎，业务端出规则**。
     *
     * 两级声明，glob 键相对**该配置所约束的目录**（工作区级 = root；包级 = 包目录——不是 config/ 目录本身）：
     *   - 工作区级 `<root>/config/audit.php`：布局知识与全域适配（rolling = 工作区根；family = 包目录根，通常无）
     *   - 包级 `<dir>/config/audit.php`（rolling = plugin/<名>/config/audit.php）：包自有适配，只作用于本包
     * 协议键（全部可选）：
     *   event_registry_globs => string[] 事件监听登记文件 glob（event 规则「谁消费谁登记」的扫描源——
     *                          登记文件不必叫 event.php，业务端自己的布局自己声明）
     *   button_name_globs    => string[] 权限节点字面量的额外扫描源（迁移之外集中维护按钮名清单时用）
     *   rules                => class-string[] 业务自定义规则，实现 AuditRuleContract（workspace 级按
     *                          packages() 圈定适用单元；包级只作用于本包）
     *   skip                 => 豁免：workspace 级 [单元 => [规则码 => 理由]]；包级 [规则码 => 理由]
     *                          （豁免不是失败，但必须写理由、审计报告留痕）
     * 合并语义：globs/rules 取并集；skip 包级优先于工作区级同名键。
     */

    /** 审计规则契约（业务端自定义规则的判定口径） */
    public const AUDIT_RULE_CONTRACT = AuditRuleContract::class;

    /** 工作区级声明 */
    public function workspaceAuditConfig(string $root): array
    {
        $key = $root . ':audit-config:ws';
        if (!isset(self::$rootScanCache[$key])) {
            self::$rootScanCache[$key] = $this->loadAuditConfigFile($root);
        }
        return self::$rootScanCache[$key];
    }

    /** 包级声明 */
    public function packageAuditConfig(string $dir): array
    {
        $key = rtrim($dir, '/') . ':audit-config:pkg';
        if (!isset(self::$rootScanCache[$key])) {
            self::$rootScanCache[$key] = $this->loadAuditConfigFile(rtrim($dir, '/'));
        }
        return self::$rootScanCache[$key];
    }

    protected function loadAuditConfigFile(string $base): array
    {
        $file = rtrim($base, '/') . '/config/audit.php';
        if (!is_file($file)) {
            return [];
        }
        $cfg = include $file;
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * 解析声明文件里的 glob（相对 base；绝对路径原样）为具体文件清单
     *
     * @param string $base     glob 的基准目录（该配置所约束的目录）
     * @param array  $patterns glob 列表（相对 base）
     * @return list<string> 命中的文件绝对路径
     */
    protected function globFilesUnder(string $base, array $patterns): array
    {
        $out = [];
        foreach ($patterns as $g) {
            $g = (string) $g;
            if ($g === '') {
                continue;
            }
            $abs = str_starts_with($g, '/') ? $g : rtrim($base, '/') . '/' . ltrim($g, '/');
            foreach (glob($abs) ?: [] as $f) {
                if (is_file($f)) {
                    $out[] = $f;
                }
            }
        }
        return $out;
    }

    /** 本包生效的自定义规则实例（workspace 级按 packages() 圈定 + 包级全收；非法声明折成一条 FAIL） */
    protected function customRules(string $root, string $dir, string $pkg): array
    {
        $ws = $this->workspaceAuditConfig($root);
        $pkgCfg = $this->packageAuditConfig($dir);
        $classes = array_values(array_unique(array_merge(
            (array) ($ws['rules'] ?? []),
            (array) ($pkgCfg['rules'] ?? [])
        )));
        $valid = [];
        $invalid = [];
        foreach ($classes as $cls) {
            if (is_string($cls) && $cls !== '' && class_exists($cls) && is_a($cls, self::AUDIT_RULE_CONTRACT, true)) {
                $valid[] = new $cls();
                continue;
            }
            $invalid[] = (string) (is_object($cls) ? get_class($cls) : $cls);
        }
        $out = [];
        if ($invalid !== []) {
            $out[] = new class($invalid) implements AuditRuleContract {
                public function __construct(protected array $bad)
                {
                }

                public function code(): string
                {
                    return 'audit_config';
                }

                public function title(): string
                {
                    return '审计适配配置（config/audit.php）';
                }

                public function packages(): array
                {
                    return ['*'];
                }

                public function check(string $root, string $pkg, string $dir): ?array
                {
                    return [
                        'issues' => array_map(
                            static fn (string $c): string => "自定义规则 `{$c}` 不存在或未实现 " . AuditService::AUDIT_RULE_CONTRACT,
                            $this->bad
                        ),
                        'note' => 'rules 登记项必须实现 AuditRuleContract',
                    ];
                }
            };
        }
        foreach ($valid as $rule) {
            if (in_array('*', $rule->packages(), true) || in_array($pkg, $rule->packages(), true)) {
                $out[] = $rule;
            }
        }
        return $out;
    }

    /** 本包某规则的豁免理由（workspace [单元=>[码=>理由]] 与包级 [码=>理由]；null = 不豁免） */
    protected function auditSkipReason(string $root, string $dir, string $pkg, string $code): ?string
    {
        // 注意：不要写 `(array) ($ws[$pkg] ?? [])[$code] ?? null`——cast 比下标先吃操作数，
        // `??` 罩不到下标读取，空配置时直接 "Undefined array key"（v3.27.0 实测回归）。
        $wsSkip = (array) ($this->workspaceAuditConfig($root)['skip'] ?? []);
        $wsForPkg = isset($wsSkip[$pkg]) && is_array($wsSkip[$pkg]) ? $wsSkip[$pkg] : [];
        $reason = $wsForPkg[$code] ?? null;
        if (is_string($reason) && $reason !== '') {
            return $reason;
        }
        $pkgSkip = (array) ($this->packageAuditConfig($dir)['skip'] ?? []);
        $reason = $pkgSkip[$code] ?? null;
        return is_string($reason) && $reason !== '' ? $reason : null;
    }


    /** 默认审计包列表（与 rocareer:audit 命令一致；MCP quality_audit 工具缺省使用；覆盖全部 src/* 基础设施包） */
    public const DEFAULT_PACKAGES = [
        'radmin', 'ai', 'ai-client', 'memory', 'memory-client', 'chat', 'agent', 'knowledge', 'knowledge-client',
        'asset', 'asset-client', 'OIDC', 'oidc-client', 'channel', 'channel-client', 'happ', 'happ-client',
        'http', 'infrastructure', 'webman-migration', 'crontab', 'tiktoken', 'mcp', 'webman-status-code',
        'webman-dev', 'psyvoyage', 'slides', 'notify', 'dataio',
    ];

    /**
     * 解析源码根目录（包含各包目录的 src 根）
     *
     * 优先级：插件配置 audit_root > 自动探测（dev/full 宿主：<工作区>/src，兼容 <工作区> 根布局）。
     * 找不到返回空串，调用方报错提示配置 audit_root。
     */
    public function rootPath(): string
    {
        $root = (string) config('plugin.rocareer.webman-dev.app.audit_root', '');
        if ($root !== '') {
            $root = rtrim($root, '/');
            $resolved = $this->resolveCandidate($root);
            if ($resolved !== '') {
                return $resolved;
            }
        }
        $base = function_exists('base_path') ? base_path() : (defined('BASE_PATH') ? BASE_PATH : (getcwd() ?: ''));
        // 候选：1) 上上级/src（dev/full 宿主位于 workspace/dev/<host>，src 根在 workspace/src）
        //       2) 上级/src（宿主直接位于 src 根下） 3) 上级 4) 当前目录（兼容传工作区根）
        foreach ([dirname(dirname($base)) . '/src', dirname($base) . '/src', dirname($base), getcwd() ?: ''] as $dir) {
            $resolved = $this->resolveCandidate($dir);
            if ($resolved !== '') {
                return $resolved;
            }
        }
        return '';
    }

    /**
     * 目录候选解析（CLI --root/自动探测也复用）：接受「含 radmin 的 src 根」
     * 或「含 src/radmin 的工作区根」，统一返回 src 根；Rolling 工作区（`app/` + `plugin/` +
     * webman 入口三件套，无 src/ 布局）返回其工作区根；无效返回空串
     */
    public function resolveCandidate(string $dir): string
    {
        $dir = rtrim($dir, '/');
        if ($dir === '' || !is_dir($dir)) {
            return '';
        }
        if (is_dir("$dir/radmin")) {
            return $dir;
        }
        if (is_dir("$dir/src/radmin")) {
            return "$dir/src";
        }
        if ($this->isRollingWorkspace($dir)) {
            return $dir;
        }
        return '';
    }

    /**
     * Rolling 工作区判定（`app/` 主应用 + `plugin/` 业务插件 + webman 入口）
     */
    public function isRollingWorkspace(string $dir): bool
    {
        $dir = rtrim($dir, '/');
        return is_dir("$dir/plugin") && is_dir("$dir/app")
            && (is_file("$dir/start.php") || is_file("$dir/webman"));
    }

    /**
     * 规则内源码路径解析（布局适配唯一出口）
     *
     * family：`<unit>/src/<rel>`；rolling：`<unit>/<rel>`（rolling 的单元根即含 `app/` 的目录：
     * 主单元 = 工作区根、插件单元 = `plugin/<名>`，故 `app/admin/controller` 等相对段两边同形）。
     */
    protected function srcPath(string $dir, string $rel = ''): string
    {
        $rel = ltrim($rel, '/');
        $dir = rtrim($dir, '/');
        return $this->layout === 'rolling'
            ? ($rel === '' ? $dir : "$dir/$rel")
            : ($rel === '' ? "$dir/src" : "$dir/src/$rel");
    }

    /**
     * 迁移目录解析：rolling 布局是**单库单目录**（工作区根 `database/migrations`，不随插件分目录；
     * 迁移文件头注明归属插件），故所有单元共用一处；family 布局仍是各包自己的 `database/migrations`。
     */
    protected function migrationsDir(string $dir): string
    {
        return $this->layout === 'rolling'
            ? $this->rollingRoot . '/database/migrations'
            : rtrim($dir, '/') . '/database/migrations';
    }

    /**
     * 定位包目录（大小写不敏感：OIDC 等目录名与包名大小写可能不一致）
     *
     * rolling 布局：`app` = 主应用（单元根即工作区根），其余名字 = `plugin/<名>` 业务插件。
     */
    public function pkgDir(string $root, string $name): string
    {
        $root = rtrim($root, '/');
        if ($this->isRollingWorkspace($root)) {
            return strtolower($name) === 'app' ? $root : "$root/plugin/$name";
        }
        if (is_dir("$root/$name")) {
            return "$root/$name";
        }
        foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $dir) {
            if (strtolower(basename($dir)) === strtolower($name)) {
                return $dir;
            }
        }
        return "$root/$name";
    }

    /**
     * 缺省审计单元列表（按布局）
     *
     * family  → DEFAULT_PACKAGES（家族基础设施包名，历史行为）
     * rolling → 工作区自身单元：`app`（主应用）+ 各 `plugin/<名>`（业务插件）
     */
    public function defaultUnits(string $root): array
    {
        $root = rtrim($root, '/');
        if (!$this->isRollingWorkspace($root)) {
            return self::DEFAULT_PACKAGES;
        }
        $units = ['app'];
        foreach (glob("$root/plugin/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $units[] = basename($dir);
        }
        sort($units);
        return $units;
    }

    /**
     * 跑一轮项目审计并落库（后台「运行审计」的共享执行体）
     *
     * 控制器同步路径与队列异步消费（宿主 AuditRunConsumer）共用：取项目与启用规则 →
     * audit() 全量审计 → dev_audit_result 逐规则落库 + 项目快照回写 → 返回轮次汇总。
     * 执行体可达分钟级（php -l 批量子进程为主）——子进程执行带 fiber 协程回退（本类内），
     * 挂在 fiber 事件循环的消费进程里可安全让出；失败抛 RuntimeException（消息即页面提示）。
     *
     * @param array $projectIds 项目 id 白名单（空 = 全部启用项目）
     * @return array {run_at:int, total_issues:int, audited_projects:int, fail_projects:int, summary:list}
     * @throws \RuntimeException 没有可审计项目 / 未定位到源码根
     */
    public function runProjects(array $projectIds = []): array
    {
        $query = DevAuditProject::where('status', DevAuditProject::STATUS_ENABLED);
        if ($projectIds !== []) {
            $query = $query->whereIn('id', $projectIds);
        }
        $projects = $query->get();
        if (empty($projects)) {
            throw new \RuntimeException('没有可审计的项目（请先添加/启用项目）');
        }
        // 启用中的规则 code（页面可停用规则临时缩小审计范围）
        $codes = DevAuditRule::where('status', DevAuditRule::STATUS_ENABLED)->pluck('name')->all();
        $root = $this->rootPath();
        if ($root === '') {
            throw new \RuntimeException('未定位到工作区源码根目录（含 radmin 的 src 根），请在插件配置 plugin.rocareer.webman-dev.app.audit_root 设置');
        }
        $pkgs = [];
        $projectMap = [];
        foreach ($projects as $p) {
            $pkgs[] = $p->name;
            $projectMap[$p->name] = $p;
        }
        $result = $this->audit($root, $pkgs, $codes);

        $now = time();
        $summary = [];
        $totalIssues = 0;
        $failProjects = 0;
        foreach ($result['packages'] as $pkgData) {
            $project = $projectMap[$pkgData['name']] ?? null;
            if (!$project) {
                continue;
            }
            $failRules = [];
            $issueTotal = 0;
            foreach ($pkgData['rules'] as $rule) {
                if (!$rule['skipped'] && !$rule['pass']) {
                    $failRules[] = $rule['title'];
                    $issueTotal += $rule['count'];
                }
                $row = new DevAuditResult();
                $row->project_id = (int) $project->id;
                $row->project_name = (string) $project->name;
                $row->rule_code = (string) $rule['code'];
                $row->rule_title = (string) $rule['title'];
                $row->is_pass = $rule['pass'] ? DevAuditResult::PASS_YES : DevAuditResult::PASS_NO;
                $row->issue_count = (int) $rule['count'];
                $row->detail = json_unicode($rule['issues']);
                $row->run_at = $now;
                $row->create_time = $now;
                $row->save();
            }
            $p = DevAuditProject::find((int) $project->id);
            if ($p) {
                $p->last_run_at = $now;
                $p->last_issue_count = $issueTotal;
                $p->last_fail_rules = json_encode(array_slice($failRules, 0, 10), JSON_UNESCAPED_UNICODE);
                $p->update_time = $now;
                $p->save();
            }
            $totalIssues += $issueTotal;
            if ($issueTotal > 0) {
                $failProjects++;
            }
            $summary[] = [
                'name' => (string) $project->name,
                'title' => (string) $project->title,
                'issue_total' => $issueTotal,
                'fail_rules' => $failRules,
                'rules' => count($pkgData['rules']),
            ];
        }
        return [
            'run_at' => $now,
            'total_issues' => $totalIssues,
            'audited_projects' => count($summary),
            'fail_projects' => $failProjects,
            'summary' => $summary,
        ];
    }

    /**
     * 运行一轮审计
     *
     * @param array $pkgs 包名列表
     * @param array $codes 规则 code 列表（空 = 全部）
     * @return array ['root' => string, 'packages' => [['name','dir','rules' => 规则结果...]]]
     */
    public function audit(string $root, array $pkgs, array $codes = []): array
    {
        // 布局判定（每轮按 root 决定；family 缺省，规则产物与历史一致）
        $root              = rtrim($root, '/');
        $this->layout      = $this->isRollingWorkspace($root) ? 'rolling' : 'family';
        $this->rollingRoot = $this->layout === 'rolling' ? $root : '';
        $this->migrationScan = null;
        $this->happBlindScan = null;
        $this->iconBlindScan = null;
        $this->themeBlindScan = null;
        $this->rawFetchBlindScan = null;
        $this->reportedMigrationStamps = [];
        // 常驻进程（MCP worker / 后台管理页）跨轮次复用本引擎：每轮清空静态扫描缓存，
        // 否则改码后 quality_audit 仍读上一轮文件快照 → 假 PASS/假 FAIL
        self::$rootScanCache = [];
        $codes = $codes ?: array_keys(self::RULES);
        $skipMap = [
            'php_syntax' => '',
            'controller' => 'controllers: none',
            'permission' => 'permission nodes: skipped',
            'migration' => 'migrations: none',
            'residue' => 'no src dir',
            'version' => 'version sync: changelog/dev json missing',
            'web_page' => 'web pages: none',
            'happ_frontend' => 'frontend sources: none',
            'async_blocking' => 'no src/app dir',
            'fqcn_dup' => 'no classes',
            'superglobal' => 'no src/app dir',
            'dead_code' => 'no src dir',
            'cross_copy' => 'no php files',
            'dto_contract' => 'no public api controllers',
            'llm_gate' => 'no src/app dir',
            'event_standard' => 'no src/app dir',
            'common_utils' => 'no src dir / no radmin dependency (pure SDK)',
            'install_standard' => 'no src/Install.php',
            'comsearch_contract' => 'no admin controllers',
            'icon_attr' => 'web sources: none',
            'vue_theme_hardcode' => 'web sources: none',
            'frontend_raw_fetch' => 'frontend sources: none',
        ];
        $packages = [];
        foreach ($pkgs as $name) {
            $dir = $this->pkgDir($root, $name);
            if (!is_dir($dir)) {
                $packages[] = [
                    'name' => $name,
                    'dir' => $dir,
                    'coverage_failure' => true,
                    'rules' => [[
                        'code' => 'coverage',
                        'title' => '审计覆盖范围',
                        'status' => 'fail',
                        'pass' => false,
                        'skipped' => false,
                        'count' => 1,
                        'issues' => [[
                            'file' => $dir,
                            'line' => 0,
                            'message' => '运行单元/包目录不存在，不能将未扫描视为通过',
                        ]],
                        'note' => '',
                        'skip' => '',
                    ]],
                ];
                continue;
            }
            $rules = [];
            foreach ($codes as $code) {
                if (!isset(self::RULES[$code])) {
                    continue;
                }
                $method = 'check' . str_replace('_', '', ucwords($code, '_'));
                if (!method_exists($this, $method)) {
                    continue;
                }
                $rule = self::RULES[$code];
                // 业务端豁免（config/audit.php 的 skip，必须写理由）：跳过执行、按「留痕豁免」输出
                if (($reason = $this->auditSkipReason($root, $dir, $name, $code)) !== null) {
                    $rules[] = $this->wrap($code, $rule['title'], null, '业务端豁免: ' . $reason);
                    continue;
                }
                $rules[] = $this->wrap($code, $rule['title'], $this->$method($root, $name, $dir), $skipMap[$code] ?? '');
            }
            // 业务端自定义规则（基础设施代跑：业务端负责规则本体，引擎负责执行/汇总/退出码）
            foreach ($this->customRules($root, $dir, $name) as $custom) {
                $rules[] = $this->wrap($custom->code(), $custom->title(), $custom->check($root, $name, $dir), '');
            }
            $packages[] = ['name' => $name, 'dir' => $dir, 'rules' => $rules];
        }
        return ['root' => $root, 'packages' => $packages];
    }

    /**
     * 封装单条规则结果
     *
     * @param array|null $result 检查方法返回值：null = 跳过；['issues' => 问题列表, 'note' => 通过行补充说明]
     */
    protected function wrap(string $code, string $title, ?array $result, string $skip): array
    {
        if ($result === null) {
            return [
                'code' => $code, 'title' => $title, 'status' => 'not_applicable',
                'pass' => true, 'skipped' => true,
                'count' => 0, 'issues' => [], 'note' => '', 'skip' => $skip,
            ];
        }
        $issues = $result['issues'] ?? [];
        return [
            'code' => $code, 'title' => $title,
            'status' => count($issues) === 0 ? 'pass' : 'fail',
            'pass' => count($issues) === 0, 'skipped' => false,
            'count' => count($issues),
            'issues' => array_slice($issues, 0, self::MAX_ISSUES),
            'note' => (string) ($result['note'] ?? ''),
            'skip' => '',
        ];
    }

    /* ---------- 1. PHP 语法检查（批量子进程） ---------- */

    /**
     * 并行 php -l 子进程数（Linux nproc / macOS sysctl；常驻进程内固定 8，避免额外阻塞式 shell 检测）
     */
    protected static ?int $syntaxJobs = null;

    protected function syntaxJobs(): int
    {
        if (self::$syntaxJobs !== null) {
            return self::$syntaxJobs;
        }
        if (class_exists(\Workerman\Coroutine::class) && \Workerman\Coroutine::isCoroutine()) {
            return self::$syntaxJobs = 8; // 常驻进程：固定并行数
        }
        $cmd = PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n hw.ncpu 2>/dev/null' : 'nproc 2>/dev/null';
        $n = (int) trim((string) shell_exec($cmd) ?: '');
        return self::$syntaxJobs = max(2, min($n ?: 8, 32));
    }

    protected function checkPhpSyntax(string $root, string $pkg, string $dir): array
    {
        $files = $this->phpFiles($dir);
        $cmd = 'find ' . escapeshellarg($dir) . " -name '*.php' -not -path '*/vendor/*' -print0 2>/dev/null | xargs -0 -P" . $this->syntaxJobs() . " -n1 php -l 2>&1";
        // 常驻进程（webman worker）内禁止阻塞式子进程等待：fiber 协程上下文走非阻塞轮询，CLI 回退同步
        $out = class_exists(\Workerman\Coroutine::class) && \Workerman\Coroutine::isCoroutine()
            ? $this->runAsync($cmd)
            : (string) shell_exec($cmd);
        $bad = [];
        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            if ($line === '' || str_contains($line, 'No syntax errors')) {
                continue;
            }
            // 形如：PHP Parse error / Errors parsing <file>
            $bad[] = basename($line);
        }
        return ['issues' => $bad, 'note' => count($files) . ' files'];
    }

    /**
     * 非阻塞子进程执行（fiber 协程：proc_open + 轮询 + Timer::sleep 让出事件循环）
     */
    protected function runAsync(string $cmd): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return '';
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $sleep = static function (): void {
            if (class_exists(\Workerman\Timer::class)) {
                \Workerman\Timer::sleep(0.05);
            }
        };
        while (true) {
            $status = proc_get_status($proc);
            $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            $sleep();
        }
        $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($proc);
        return $out;
    }

    /** 全部 PHP 文件（排除 vendor） */
    protected function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '/vendor/')) {
                $out[] = $file->getPathname();
            }
        }
        return $out;
    }

    /* ---------- 2. 控制器规范 ---------- */

    protected function checkController(string $root, string $pkg, string $dir): ?array
    {
        $ctrlDir = $this->srcPath($dir, 'app/admin/controller');
        if (!is_dir($ctrlDir)) {
            return null;
        }
        $issues = [];
        $count = 0;
        foreach ($this->phpFiles($ctrlDir) as $file) {
            $info = $this->parseClass($file);
            if (!$info) {
                continue;
            }
            // 抽象基类（如 radmin LedgerLog）：无自身路由，继承与按钮检查均由具体子类承载
            if (!empty($info['abstract'])) {
                continue;
            }
            $count++;
            $rel = str_replace($root . '/', '', $file);
            if (!$this->extendsBackend($dir, $info['extends'])) {
                $issues[] = "$rel: extends {$info['extends']} != Backend";
            }
            $src = file_get_contents($file);
            if (in_array('initialize', $info['methods'], true) && !str_contains($src, 'parent::initialize()')) {
                $issues[] = "$rel: initialize() missing parent::initialize()";
            }
            foreach ($info['methods'] as $method) {
                if ($method === 'initialize' || !$info['sigs'][$method]) {
                    continue;
                }
                // 返回类型：: Response / : ?Response / : HttpResponse / : void 等均可——只拒绝完全无类型声明
                if (!preg_match('/:\s*\??[A-Za-z_\\\\]+(?:\s*$|\s*[\s(])/', $info['sigs'][$method])) {
                    $issues[] = "$rel::$method missing return type";
                }
            }
        }
        return ['issues' => $issues, 'note' => $count . ' files, radmin style'];
    }

    /* ---------- 3. 权限节点匹配 ---------- */

    protected function checkPermission(string $root, string $pkg, string $dir): ?array
    {
        $ctrlDir = $this->srcPath($dir, 'app/admin/controller');
        $migDir = $this->migrationsDir($dir);
        if (!is_dir($ctrlDir) || !is_dir($migDir)) {
            return null;
        }
        // 迁移中注册的按钮名（x/y/z 三段；按钮名可含驼峰/连字符——webman 路由 kebab→驼峰方法等价，
        // 如按钮 memory/snapshot/session-detail 对应方法 sessionDetail，比对统一小写+去连字符）
        $buttons = [];
        $collect = function (string $src) use (&$buttons): void {
            if (preg_match_all("~['\"]([a-zA-Z_]+/[a-zA-Z_]+/[a-zA-Z_-]+)['\"]~", $src, $m)) {
                foreach ($m[1] as $name) {
                    $buttons[str_replace('-', '', strtolower($name))] = true;
                }
            }
        };
        foreach ($this->phpFiles($migDir) as $mf) {
            $collect((string) file_get_contents($mf));
        }
        // v3.27.0 业务端适配：迁移之外的按钮名字面量来源（config/audit.php 的 button_name_globs，
        // 工作区级相对工作区根、包级相对包目录）——业务端把节点名集中在种子/常量清单时在此声明
        $wsCfg = $this->workspaceAuditConfig($root);
        $pkgCfg = $this->packageAuditConfig($dir);
        $extraSources = array_merge(
            $this->globFilesUnder($root, (array) ($wsCfg['button_name_globs'] ?? [])),
            $this->globFilesUnder($dir, (array) ($pkgCfg['button_name_globs'] ?? []))
        );
        foreach ($extraSources as $sf) {
            $collect((string) file_get_contents($sf));
        }
        $issues = [];
        $methodCount = 0;
        foreach ($this->phpFiles($ctrlDir) as $file) {
            $info = $this->parseClass($file);
            if (!$info || !empty($info['abstract'])) {
                continue; // 抽象基类无自身路由（按钮节点由具体子类的 routePath 承载）
            }
            $rel = str_replace($root . '/', '', $file);
            // routePath 前缀：完整类名去掉 controller\ 后末两段小写
            $fqcn = str_replace('controller\\', '', $info['namespace'] . '\\' . $info['class']);
            $parts = explode('\\', $fqcn);
            $prefix = strtolower(implode('/', array_slice($parts, -2)));
            // noNeedLogin / noNeedPermission 豁免：公开接口无需按钮节点（防过度设计）
            $skip = $this->permissionSkips($info);
            foreach ($info['methods'] as $method) {
                if ($method === 'initialize') {
                    continue;
                }
                if ($skip['all'] || in_array(strtolower($method), $skip['list'], true)) {
                    continue;
                }
                $methodCount++;
                $routePath = $prefix . '/' . strtolower($method);
                if (!isset($buttons[str_replace('-', '', $routePath)])) {
                    $issues[] = "$rel::$method -> missing button node '$routePath'";
                }
            }
        }
        return ['issues' => $issues, 'note' => $methodCount . ' methods match routePath'];
    }

    /* ---------- 4. 迁移时间戳查重 ---------- */

    protected function checkMigration(string $root, string $pkg, string $dir): array
    {
        $scan = $this->workspaceMigrations($root);
        $issues = [];
        // 归属前缀：family 按包目录（`radmin/...`）；rolling 是单库单目录（`database/migrations/...`）
        $mine = $this->layout === 'rolling' ? 'database/migrations/' : basename($dir) . '/';
        foreach ($scan['duplicates'] as $stamp => $files) {
            if (isset($this->reportedMigrationStamps[$stamp]) || !$this->migrationBelongsToPackage($files, $dir)) {
                continue;
            }
            $this->reportedMigrationStamps[$stamp] = true;
            $issues[] = "duplicate timestamp $stamp: " . implode(', ', $files);
        }
        foreach ($scan['malformed'] as $file) {
            if (str_starts_with($file, $mine)) {
                $issues[] = "malformed（数字前缀非 14 位时间戳/裸版本号缺名字段，Phinx 会照常加载且前缀即版本号）: $file";
            }
        }
        foreach ($scan['ignored'] as $file) {
            if (str_starts_with($file, $mine)) {
                $issues[] = "ignored（不匹配 Phinx 文件名正则，永远不会被执行）: $file";
            }
        }
        $note = $scan['count'] . ' workspace files';
        if ($scan['midnight'] > 0) {
            $note .= "；{$scan['midnight']} 个「年月日+000000」存量（不报错；新建禁止——一律 migrate:create 生成精确到秒）";
        }
        return ['issues' => $issues, 'note' => $note];
    }

    /**
     * 扫描工作区全部 Phinx 迁移（业务 migrations + 向量 pg-migrations）
     *
     * 形态分类与 webman-migration v2.4.0 运行时强检同口径：
     * - duplicates：14 位版本号撞号（阻断全家桶 migrate:run）；
     * - malformed：数字前缀非 14 位（8 位纯日期风，Phinx 会照常加载且前缀即版本号）
     *   或 14 位裸版本号缺名字段；
     * - ignored：不匹配 Phinx 文件名正则（静默忽略，永远不会被执行）；
     * - midnight：HHMMSS=000000 的「年月日就完了」存量（计数进 note，不报错）。
     */
    protected function workspaceMigrations(string $root): array
    {
        if ($this->migrationScan !== null) {
            return $this->migrationScan;
        }
        $groups = [];
        $malformed = [];
        $ignored = [];
        $midnight = 0;
        foreach (['migrations', 'pg-migrations'] as $set) {
            // 包源码（src/*/database）+ dev 宿主工程自身迁移（dev/*/database）——
            // Phinx 装载两者，跨包撞号与「包 vs 宿主工程」撞号都要拦（knowledge 20260908010000 实案）
            $candidates = [];
            foreach (glob("$root/*/database/$set/*.php") ?: [] as $file) {
                $candidates[$file] = str_replace($root . '/', '', $file);
            }
            // rolling 布局：工作区根的单库迁移目录（`<ws>/database/migrations`，插件迁移也落这里）
            if ($this->layout === 'rolling') {
                foreach (glob("$root/database/$set/*.php") ?: [] as $file) {
                    $candidates[$file] = str_replace($root . '/', '', $file);
                }
            }
            foreach (glob("$root/../dev/*/database/$set/*.php") ?: [] as $file) {
                $candidates[$file] = str_replace($root . '/../', '', $file);
            }
            foreach ($candidates as $file => $rel) {
                $base = basename($file);
                if (preg_match('/^(\d+)_/', $base, $match)) {
                    if (strlen($match[1]) !== 14 || !preg_match('/^\d{14}_[a-z][a-z\d]*(?:_[a-z\d]+)*\.php$/i', $base)) {
                        $malformed[] = $rel;
                        continue;
                    }
                    $groups[$match[1]][] = $rel;
                    if (substr($match[1], 8) === '000000') {
                        $midnight++;
                    }
                } elseif (preg_match('/^\d{14}\.php$/', $base)) {
                    $malformed[] = $rel;
                } else {
                    $ignored[] = $rel;
                }
            }
        }
        ksort($groups);
        $duplicates = array_filter($groups, static fn(array $files): bool => count($files) > 1);
        return $this->migrationScan = [
            'count' => array_sum(array_map('count', $groups)),
            'duplicates' => $duplicates,
            'malformed' => $malformed,
            'ignored' => $ignored,
            'midnight' => $midnight,
        ];
    }

    /**
     * 冲突归属到本轮最先涉及的包，避免同一时间戳在全量审计中重复报错
     */
    protected function migrationBelongsToPackage(array $files, string $dir): bool
    {
        $package = $this->layout === 'rolling' ? 'database/migrations/' : basename($dir) . '/';
        foreach ($files as $file) {
            if (str_starts_with($file, $package)) {
                return true;
            }
        }
        return false;
    }

    /* ---------- 5. 残留扫描 ---------- */

    protected function checkResidue(string $root, string $pkg, string $dir): ?array
    {
        if (!is_dir($this->srcPath($dir))) {
            return null;
        }
        $issues = [];
        foreach (['controller/Test.php', 'model/Test.php', 'validate/Test.php'] as $residue) {
            if (is_file($this->srcPath($dir, "app/admin/$residue"))) {
                $issues[] = "scaffold residue: src/app/admin/$residue";
            }
        }
        $todo = 0;
        foreach ($this->phpFiles($this->srcPath($dir)) as $f) {
            if (basename($f) === 'AuditService.php') {
                continue; // 审计引擎自身含探测器模式字面量（TODO|FIXME|HACK 正则），自扫必误报
            }
            $todo += preg_match_all('~(TODO|FIXME|HACK)~', file_get_contents($f));
        }
        if ($todo > 0) {
            $issues[] = "$todo TODO/FIXME/HACK in src";
        }
        return ['issues' => $issues, 'note' => 'clean (TODO/FIXME count: ' . $todo . ')'];
    }

    /* ---------- 6. 版本同步 ---------- */

    protected function checkVersion(string $root, string $pkg, string $dir): ?array
    {
        $changelog = "$dir/CHANGELOG.md";
        // 全部 dev 宿主（钉版散落在专属宿主：OIDC/happ/experiment/slides 等不在 dev/full）
        $hosts = array_merge(glob("$root/../dev/*/composer.json") ?: [], glob("$root/dev/*/composer.json") ?: []);
        if (!is_file($changelog) || !$hosts) {
            return null;
        }
        $head = file_get_contents($changelog);
        // 取首个「已发布」版本小节（跳过 未发布/Unreleased，避免把未发布段当版本号误报）
        if (!preg_match_all('~^##\s+([^\s]+)~m', $head, $mm)) {
            return null;
        }
        $pkgVer = '';
        foreach ($mm[1] as $heading) {
            if (preg_match('~未发布|Unreleased~', $heading)) {
                continue;
            }
            $pkgVer = trim(str_replace(['[', ']', 'v', 'V'], '', $heading));
            break;
        }
        if ($pkgVer === '') {
            return null;
        }
        $compkg = strtolower($pkg);
        $pins = [];
        foreach ($hosts as $devJson) {
            $json = json_decode((string) file_get_contents($devJson), true);
            foreach (($json['repositories'] ?? []) as $repo) {
                if (($repo['type'] ?? '') === 'path') {
                    $v = ($repo['options']['versions'] ?? [])["rocareer/$compkg"] ?? '';
                    if ($v !== '') {
                        $pins[basename(dirname($devJson))] = $v;
                        break;
                    }
                }
            }
        }
        if (!$pins) {
            return null;
        }
        $norm = fn($v) => strtolower(trim(str_replace(['v', 'V', '[', ']'], '', $v)));
        $issues = [];
        foreach ($pins as $host => $pin) {
            if ($norm($pkgVer) !== $norm($pin)) {
                $issues[] = "changelog $pkgVer != dev/$host pin $pin";
            }
        }
        return ['issues' => $issues, 'note' => $pkgVer . '（' . count($pins) . ' 宿主钉版）'];
    }

    /* ---------- 7. 前端页面规范（非标手写 Vue 页面审计） ---------- */

    /**
     * 前端页面规范检查（静态扫描 <pkg>/web/src/views/backend 下的 Vue 页面）
     *
     * 依据工作区「模板优先、禁止从零手写」前端硬性规范（六项，全部低误报）：
     *   1. 禁止自创依赖注入（本 fork 仅 baTable 有 provide；inject('config') 曾致弹窗渲染崩溃）
     *   2. 禁止裸 import axios（必须 /@/utils/axios 的 createAxios 统一封装）
     *   3. 引用 baTable 体系组件（TableHeader/Table/PopupForm）必须初始化 baTable（禁止自建表格绕过 baTable）
     *   4. 编辑弹窗必须走 baTable.onSubmit 提交（禁止绕过 baTable 手写请求）
     *   5. 禁止 /src/ 根路径导入（应使用 /@/ 别名）
     *   6. TableHeader 顶部自定义按钮必须用标准样式类 table-header-operate（2026 实战：审计项目页
     *      曾自创 table-header-audit-run 非标样式；crud/log 等内置页同款约定）
     * 说明：radmin 包的 web 树是各包/业务工程页面的同步汇聚区（真源在各包 web/），跳过避免归属错乱。
     * 表单字段与后端入参一致性暂为人工复核项（静态无法区分标准 CRUD 弹窗与特殊/透传弹窗，易误报）。
     */
    protected function checkWebPage(string $root, string $pkg, string $dir): ?array
    {
        if ($pkg === 'radmin') {
            return null;
        }
        $webDir = "$dir/web/src/views/backend";
        if (!is_dir($webDir)) {
            return null;
        }
        $issues = [];
        $vueFiles = $this->vueFiles($webDir);
        foreach ($vueFiles as $file) {
            $rel = str_replace($root . '/', '', $file);
            $src = file_get_contents($file);
            // 1) 自创依赖注入
            if (preg_match_all("~inject\(\s*['\"]([^'\"]+)['\"]\s*\)~", $src, $m)) {
                foreach ($m[1] as $key) {
                    if ($key !== 'baTable') {
                        $issues[] = "$rel: 自创依赖注入 inject('$key')（本 fork 无 provide，模板仅允许 inject('baTable')）";
                    }
                }
            }
            // 2) 裸 axios
            if (preg_match("~import\s+axios\s+from\s*['\"]axios['\"]~", $src)) {
                $issues[] = "$rel: 直接 import axios（应使用 /@/utils/axios 的 createAxios 统一封装）";
            }
            // 3) baTable 体系页面必须初始化 baTable（精确匹配导入，避免 onTableHeaderAction 等子串误报）
            $usesUi = (bool) preg_match("~from\s*['\"]\/@\/components\/table~", $src)
                || str_contains($src, "import PopupForm from './popupForm");
            $usesBt = str_contains($src, 'new baTableClass') || str_contains($src, 'baTableApi(');
            if ($usesUi && !$usesBt) {
                $issues[] = "$rel: 使用 baTable 体系组件（TableHeader/Table/PopupForm）但未初始化 baTable（自建表格不经过 baTable）";
            }
            // 4) 弹窗提交必须走 baTable.onSubmit
            if (str_ends_with($file, 'popupForm.vue')) {
                $hasForm = str_contains($src, 'el-form') || str_contains($src, 'baTable.form');
                if ($hasForm && !str_contains($src, 'baTable.onSubmit')) {
                    $issues[] = "$rel: 编辑弹窗未使用 baTable.onSubmit 提交（禁止绕过 baTable 手写请求）";
                }
            }
            // 5) /src/ 根路径导入
            if (preg_match("~from\s*['\"]\/src\/~", $src)) {
                $issues[] = "$rel: 使用 /src/ 根路径导入（应使用 /@/ 别名）";
            }
            // 6) TableHeader 顶部自定义按钮必须用标准样式类 table-header-operate
            //    （仅检查默认/具名插槽内 el-button，标签取引号感知整段，避免 :disabled="a > 0" 等截断）
            if (preg_match_all('~<TableHeader\b(?:"[^"]*"|\'[^\']*\'|[^"\'>])*>([\s\S]*?)</TableHeader>~', $src, $hdrBlocks)) {
                foreach ($hdrBlocks[1] as $block) {
                    if (!preg_match_all('~<el-button\b(?:"[^"]*"|\'[^\']*\'|[^"\'>])*>~', $block, $btnTags)) {
                        continue;
                    }
                    foreach ($btnTags[0] as $tag) {
                        if (preg_match('~class\s*=\s*"[^"]*\btable-header-operate\b~', $tag) !== 1) {
                            $issues[] = "$rel: TableHeader 顶部自定义按钮未使用标准样式类 table-header-operate（应复制 crud/log 等内置页同款，禁止自创类名/裸 <i> 图标/&nbsp; 拼接）";
                            break; // 每文件报一条即可，避免刷屏
                        }
                    }
                }
            }
        }
        return ['issues' => $issues, 'note' => count($vueFiles) . ' vue files'];
    }

    /** 全部 Vue 文件（web/src/views/backend 递归） */
    protected function vueFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $out[] = $file->getPathname();
            }
        }
        return $out;
    }

    /* ---------- 7.5 happ 前端接入规范（全域统一 WS SDK） ---------- */

    /**
     * happ 前端接入规范检查（静态扫描 <pkg>/web/src 下 vue/ts/js 的手写 WebSocket）
     *
     * 依据「全域统一 happ 前端接入规范」（AGENTS.md 异步铁律 5/6 + happ-client README）：
     * 浏览器实时通道只认 happ-client SDK——JS 版 useHapp / Vue 版 useHappConnection，SDK 内建
     * HMAC 凭证认证、服务端心跳应答与指数退避重连；页面手写 new WebSocket 即绕过认证与保活，
     * 硬编码 ws:// 地址则与服务端凭证接口下发的 endpoint 冲突，全部报错：
     *   1. new WebSocket(（SDK 真源文件豁免）
     *   2. 裸 ws:// / wss:// 地址字面量
     * 说明：radmin 包 web 树是各包页面的同步汇聚区（真源在各包 web/），跳过；
     * 文件标注 @audit-ignore happ_frontend 显式豁免。
     */
    protected function checkHappFrontend(string $root, string $pkg, string $dir): ?array
    {
        if ($pkg === 'radmin') {
            return $this->sweepHappFrontendBlind($root);
        }
        $webDir = "$dir/web/src";
        if (!is_dir($webDir)) {
            return null;
        }
        $exempt = ['utils/happClient.ts', 'composables/useHappConnection.ts'];
        $files = $this->webSourceFiles($webDir);
        if (count($files) === 0) {
            return null;
        }
        $issues = [];
        foreach ($files as $file) {
            $relInWeb = ltrim(str_replace($webDir . '/', '', $file), '/');
            if (in_array($relInWeb, $exempt, true)) {
                continue;
            }
            $rel = str_replace($root . '/', '', $file);
            $src = file_get_contents($file);
            if (str_contains($src, '@audit-ignore happ_frontend')) {
                continue;
            }
            if (preg_match('~new\\s+WebSocket\\s*\\(~', $src)) {
                $issues[] = "$rel: 手写 new WebSocket（实时接入统一走 happ-client SDK：JS 版 /@/utils/happClient 或 Vue 版 /@/composables/useHappConnection，禁自建连接/重连/心跳）";
            }
            if (preg_match("~['\"]wss?://~", $src)) {
                $issues[] = "$rel: 硬编码 ws:// 地址（endpoint 由服务端凭证接口下发，前端禁止自拼 WS 地址）";
            }
        }
        return ['issues' => $issues, 'note' => count($files) . ' frontend files'];
    }

    /** 全部前端源码文件（web/src 递归：vue/ts/js） */
    protected function webSourceFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['vue', 'ts', 'js'], true)) {
                $out[] = $file->getPathname();
            }
        }
        return $out;
    }

    /**
     * 全域前端盲区扫描（radmin 条目承载）：dev 宿主工程 web 源码树、super/web/src、skyline 小程序
     * 不属于任何 src 包，包级规则扫不到（与 migration 规则纳入 dev 各工程迁移目录同款盲区先例）。
     * 每轮审计只扫一次（实例缓存，audit() 内重置）；口径与包级一致：
     * new WebSocket( 与 wx.connectSocket、裸 ws:// 或 wss:// 字面量即报错；
     * node_modules、dist、public 等产物目录与 SDK 真源文件豁免，
     * 文件标注 @audit-ignore happ_frontend 显式豁免。
     */
    protected function sweepHappFrontendBlind(string $root): ?array
    {
        if ($this->happBlindScan !== null) {
            return $this->happBlindScan;
        }
        $ws = dirname($root);
        $trees = [];
        foreach (array_merge(
            glob($ws . '/dev/*/web/src') ?: [],
            glob($ws . '/super/web/src') ?: [],
            glob($ws . '/skyline') ?: []
        ) as $tree) {
            if (is_dir($tree)) {
                $trees[] = $tree;
            }
        }
        if (count($trees) === 0) {
            return null;
        }
        $exempt = ['utils/happClient.ts', 'composables/useHappConnection.ts'];
        $issues = [];
        $checked = 0;
        foreach ($trees as $tree) {
            foreach ($this->webSourceFiles($tree) as $file) {
                if (preg_match('#/(node_modules|dist|public|unpackage|miniprogram_npm|vendor)/#', $file)) {
                    continue;
                }
                $relInTree = ltrim(str_replace($tree . '/', '', $file), '/');
                if (in_array($relInTree, $exempt, true)) {
                    continue;
                }
                $src = file_get_contents($file);
                if (str_contains($src, '@audit-ignore happ_frontend')) {
                    continue;
                }
                $checked++;
                $rel = str_replace($ws . '/', '', $file);
                if (preg_match('~new\\s+WebSocket\\s*\\(|wx\\.connectSocket~', $src)) {
                    $issues[] = "$rel: 手写 WebSocket 连接（实时接入统一走 happ-client SDK：JS 版 useHapp / Vue 版 useHappConnection）";
                }
                if (preg_match("~['\"]wss?://~", $src)) {
                    $issues[] = "$rel: 硬编码 ws:// 地址（endpoint 由服务端凭证接口下发，前端禁止自拼 WS 地址）";
                }
            }
        }
        $result = ['issues' => $issues, 'note' => $checked . ' blind-tree files (dev/super/skyline)'];
        $this->happBlindScan = $result;
        return $result;
    }


    /* ---------- 7c. Element Plus icon 属性门禁 ---------- */

    /**
     * Element Plus 组件 icon 类属性禁传 CSS 类名（dataio 导入向导 / print-erp 双白屏实证规则）：
     * el-button 等组件的 icon / :icon 属性按组件渲染，传 'fa fa-*' 等类名字符串会
     * createElement('fa fa-...') 抛 InvalidCharacterError——页面白屏且此后所有菜单点击空白。
     * 扫描各包 web/src 的 .vue 模板；radmin 同步树（真源在各包 web/）跳过，
     * dev 宿主工程 web 树、super/web/src、skyline 盲区由 radmin 条目承载 sweepIconAttrBlind。
     * 文件标注 @audit-ignore icon_attr 显式豁免。
     */
    protected function checkIconAttr(string $root, string $pkg, string $dir): ?array
    {
        if ($pkg === 'radmin') {
            return $this->sweepIconAttrBlind($root);
        }
        $webDir = "$dir/web/src";
        if (!is_dir($webDir)) {
            return null;
        }
        $issues = [];
        $checked = 0;
        foreach ($this->webSourceFiles($webDir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'vue') {
                continue;
            }
            $src = file_get_contents($file);
            if (str_contains($src, '@audit-ignore icon_attr')) {
                continue;
            }
            $checked++;
            $rel = str_replace($root . '/', '', $file);
            if (preg_match('~(?<![\w.:-])\s*:?\s*icon\s*=\s*["\x27]["\x27]?(?:fa[bdrs]?)[\s-]~', $src)) {
                $issues[] = "$rel: el 组件 icon 属性传 CSS 类名（Element Plus 按组件渲染该字符串即 createElement 抛错：页面白屏且带崩 SPA；改 <Icon name=\"fa fa-*\" /> 子节点，Icon 全局注册）";
            }
        }
        return ['issues' => $issues, 'note' => $checked . ' vue files (web/src)'];
    }

    /**
     * icon 属性全域盲区扫描（radmin 条目承载）：dev 宿主工程 web 源码树、super/web/src、
     * skyline 不属于任何 src 包，包级规则扫不到（与 sweepHappFrontendBlind 同款盲区先例）。
     * 每轮审计只扫一次（实例缓存，audit() 内重置）；只扫 .vue，node_modules/dist/public 等
     * 产物目录豁免，文件标注 @audit-ignore icon_attr 显式豁免。
     */
    protected function sweepIconAttrBlind(string $root): ?array
    {
        if ($this->iconBlindScan !== null) {
            return $this->iconBlindScan;
        }
        $ws = dirname($root);
        $trees = [];
        foreach (array_merge(
            glob($ws . '/dev/*/web/src') ?: [],
            glob($ws . '/super/web/src') ?: [],
            glob($ws . '/skyline') ?: []
        ) as $tree) {
            if (is_dir($tree)) {
                $trees[] = $tree;
            }
        }
        if (count($trees) === 0) {
            return null;
        }
        $issues = [];
        $checked = 0;
        foreach ($trees as $tree) {
            foreach ($this->webSourceFiles($tree) as $file) {
                if (preg_match('#/(node_modules|dist|public|unpackage|miniprogram_npm|vendor)/#', $file)) {
                    continue;
                }
                if (pathinfo($file, PATHINFO_EXTENSION) !== 'vue') {
                    continue;
                }
                $src = file_get_contents($file);
                if (str_contains($src, '@audit-ignore icon_attr')) {
                    continue;
                }
                $checked++;
                $rel = str_replace($ws . '/', '', $file);
                if (preg_match('~(?<![\w.:-])\s*:?\s*icon\s*=\s*["\x27]["\x27]?(?:fa[bdrs]?)[\s-]~', $src)) {
                    $issues[] = "$rel: el 组件 icon 属性传 CSS 类名（Element Plus 按组件渲染该字符串即 createElement 抛错：页面白屏且带崩 SPA；改 <Icon name=\"fa fa-*\" /> 子节点）";
                }
            }
        }
        $result = ['issues' => $issues, 'note' => $checked . ' blind-tree vue files (dev/super/skyline)'];
        $this->iconBlindScan = $result;
        return $result;
    }

    /* ---------- 7d. Vue 主题色硬编码门禁（EP 调色板） ---------- */

    /**
     * Element Plus 官方默认调色板硬编码检查（print-erp 前端全域样式审计 2026-09-17 实证规则）：
     * .vue 的 <style>/<template> 段写死 #409eff 等主题语义色 = 用户切换主题色/暗色模式后与全站脱节
     * （print-erp flow 节点状态色实证）。合法形态 = var(--el-color-*, 色值) 带 fallback 双写（先剔除再扫）；
     * <script> 段不扫（ECharts/SVG 画布色板属运行时配置，主题跟随可选 getComputedStyle 快照）。
     * 扫描范围 = dev 宿主工程 web 树（radmin 条目承载 sweep，与 sweepIconAttrBlind 同款盲区先例）：
     * 相对路径在 radmin/web/src 存在同路径文件的「全家桶继承页」跳过（真源在 radmin，上游存量不由宿主修）；
     * src 各包 web 树存量（agent/ai/happ/mcp/psyvoyage 共 9 文件）待各包自行收口后开启。
     * 文件标注 @audit-ignore vue_theme_hardcode 显式豁免。
     */
    protected function checkVueThemeHardcode(string $root, string $pkg, string $dir): ?array
    {
        if ($pkg === 'radmin') {
            return $this->sweepVueThemeHardcodeBlind($root);
        }
        return null;
    }

    /**
     * 主题色硬编码全域盲区扫描（radmin 条目承载）：dev 宿主工程 web 源码树不属于任何 src 包，
     * 包级规则扫不到（与 sweepHappFrontendBlind 同款盲区先例）。每轮审计只扫一次（实例缓存，
     * audit() 内重置）；只扫 .vue，产物目录豁免，radmin 同源继承页与 @audit-ignore vue_theme_hardcode 豁免。
     */
    protected function sweepVueThemeHardcodeBlind(string $root): ?array
    {
        if ($this->themeBlindScan !== null) {
            return $this->themeBlindScan;
        }
        $ws = dirname($root);
        $radminWeb = $ws . '/src/radmin/web/src';
        $trees = [];
        foreach (glob($ws . '/dev/*/web/src') ?: [] as $tree) {
            if (is_dir($tree)) {
                $trees[] = $tree;
            }
        }
        if (count($trees) === 0) {
            return null;
        }
        $issues = [];
        $checked = 0;
        $skipped = 0;
        foreach ($trees as $tree) {
            foreach ($this->webSourceFiles($tree) as $file) {
                if (preg_match('#/(node_modules|dist|public|unpackage|miniprogram_npm|vendor)/#', $file)) {
                    continue;
                }
                if (pathinfo($file, PATHINFO_EXTENSION) !== 'vue') {
                    continue;
                }
                $src = file_get_contents($file);
                if (str_contains($src, '@audit-ignore vue_theme_hardcode')) {
                    continue;
                }
                // 全家桶继承页：相对路径在 radmin 真源树存在同路径文件 → 归属 radmin，不在宿主报
                $relToSrc = substr($file, strlen($tree) + 1);
                if (is_file($radminWeb . '/' . $relToSrc)) {
                    $skipped++;
                    continue;
                }
                $checked++;
                $hit = $this->vueThemeHardcodeHit($src);
                if ($hit !== '') {
                    $rel = str_replace($ws . '/', '', $file);
                    $issues[] = "$rel: $hit";
                }
            }
        }
        $result = ['issues' => $issues, 'note' => $checked . ' business vue files (' . $skipped . ' inherited-from-radmin skipped)'];
        $this->themeBlindScan = $result;
        return $result;
    }

    /**
     * 提取 <style>/<template> 段、剔除 var(--el-*, #fallback) 合格双写形态后匹配 EP 默认调色板；
     * 命中返回问题描述，无命中返回空串。
     */
    protected function vueThemeHardcodeHit(string $src): string
    {
        if (!preg_match_all('~<(style|template)\b[^>]*>([\s\S]*?)</\1>~is', $src, $m)) {
            return '';
        }
        $palette = '~#(?:409eff|67c23a|e6a23c|f56c6c|909399|ecf5ff|d9ecff)~i';
        foreach ($m[2] as $body) {
            $body = preg_replace('~var\(--el-[a-z0-9-]+,\s*#[0-9a-fA-F]{3,8}\)~', '', $body);
            if (preg_match($palette, $body)) {
                return '样式段写死 EP 默认调色板色值（主题色/暗色切换后与全站脱节）：改 var(--el-color-*, 色值) 双写；打印纸张预览区白底语义属有意设计，请加注释声明';
            }
        }
        return '';
    }


    /* ---------- 7f. 前端禁裸 fetch/XHR（业务码信封唯一出口） ---------- */

    /** 裸调用探测模式（扫描前已剥注释，注释里的示例/文档不会假命中） */
    protected const RAW_FETCH_PATTERNS = [
        'fetch(' => '~\bfetch\s*\(~',
        'new XMLHttpRequest' => '~new\s+XMLHttpRequest~',
    ];

    /** 下载助手单一真源 + happ-client SDK 真源（各 web 树相对路径一致；仅这些文件允许裸 fetch） */
    protected const RAW_FETCH_EXEMPT = ['utils/download.ts', 'utils/happClient.ts'];

    /**
     * 前端裸 fetch/XHR 检查：src 包扫自家 web/src；radmin 同步树（真源在各包 web/）跳过，
     * 由 radmin 条目承载全域盲区 sweep（同 happ_frontend/icon_attr 先例）
     */
    protected function checkFrontendRawFetch(string $root, string $pkg, string $dir): ?array
    {
        if ($pkg === 'radmin') {
            return $this->sweepFrontendRawFetchBlind($root);
        }
        $webDir = "$dir/web/src";
        if (!is_dir($webDir)) {
            return null;
        }
        return $this->scanFrontendRawFetchTree($webDir, $root);
    }

    /**
     * 裸 fetch/XHR 全域盲区扫描（radmin 条目承载）：dev 宿主工程 web 源码树、super/web/src
     * 不属于任何 src 包，包级规则扫不到（与 sweepHappFrontendBlind 同款盲区先例）。
     * skyline 小程序不扫：小程序运行时没有 fetch/XHR API（网络面是 wx.request，归平台规范管）。
     * 每轮审计只扫一次（实例缓存，audit() 内重置）；产物目录豁免、utils/download.ts 豁免、
     * @audit-ignore frontend_raw_fetch 文件豁免；豁免文件双向断言（无裸调用即报收敛）。
     */
    protected function sweepFrontendRawFetchBlind(string $root): ?array
    {
        if ($this->rawFetchBlindScan !== null) {
            return $this->rawFetchBlindScan;
        }
        $ws = dirname($root);
        $trees = [];
        foreach (array_merge(
            glob($ws . '/dev/*/web/src') ?: [],
            glob($ws . '/super/web/src') ?: []
        ) as $tree) {
            if (is_dir($tree)) {
                $trees[] = $tree;
            }
        }
        if (count($trees) === 0) {
            return null;
        }
        $issues = [];
        $checked = 0;
        $radminWeb = $ws . '/src/radmin/web/src';
        foreach ($trees as $tree) {
            $res = $this->scanFrontendRawFetchTree($tree, $ws, $radminWeb);
            $issues = array_merge($issues, $res['issues']);
            $checked += (int) ($res['checked'] ?? 0);
        }
        $result = ['issues' => $issues, 'note' => $checked . ' blind-tree files (dev/super，radmin 同路径继承页已跳过)'];
        $this->rawFetchBlindScan = $result;
        return $result;
    }

    /**
     * 扫一棵 web/src 树：裸 fetch( / new XMLHttpRequest 即报
     *
     * 判据口径（静态Discipline 门禁五坑教训）：①扫描前剥离单行、块级与 HTML 三类注释，
     * 文档/示例里的字面量不假命中；②豁免文件（download/happClient SDK 真源）双向断言——
     * 存在但剥注释后已无裸调用 → 报「请收敛豁免清单」，防豁免变陈旧摆设；③命中必须给出
     * 替换指引；④radmin 同路径继承文件（全家桶 web 全量拷贝宿主的上游存量）跳过不报
     * （真源在 radmin/BuildAdmin 上游，宿主不修——同 vue_theme_hardcode 先例）。
     *
     * @return array{issues: string[], checked: int}
     */
    protected function scanFrontendRawFetchTree(string $webDir, string $root, ?string $radminWeb = null): array
    {
        $issues = [];
        $checked = 0;
        $exemptHit = [];
        foreach ($this->webSourceFiles($webDir) as $file) {
            if (preg_match('#/(node_modules|dist|public|unpackage|miniprogram_npm|vendor)/#', $file)) {
                continue;
            }
            $relInWeb = ltrim(str_replace($webDir . '/', '', $file), '/');
            if (in_array($relInWeb, self::RAW_FETCH_EXEMPT, true)) {
                $src = $this->stripFrontendComments((string) file_get_contents($file));
                // 双向断言的另一半：豁免文件必须真有裸调用，否则豁免已陈旧
                foreach (self::RAW_FETCH_PATTERNS as $label => $pattern) {
                    if (preg_match($pattern, $src)) {
                        $exemptHit[$relInWeb] = true;
                    }
                }
                continue;
            }
            if (str_contains((string) file_get_contents($file), '@audit-ignore frontend_raw_fetch')) {
                continue;
            }
            // 全家桶继承页：相对路径在 radmin 真源树存在同路径文件 → 归属上游，不在宿主报
            if ($radminWeb !== null && is_file($radminWeb . '/' . $relInWeb)) {
                continue;
            }
            $src = $this->stripFrontendComments((string) file_get_contents($file));
            $rel = str_replace($root . '/', '', $file);
            foreach (self::RAW_FETCH_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $src)) {
                    $issues[] = "{$rel}: 裸 {$label}（调 admin API 走 createAxios——业务码信封拦截器是唯一出口；文件流下载走 /@/utils/download 的 downloadByToken/runDownload；确属例外请 @audit-ignore frontend_raw_fetch 并注明理由）";
                }
            }
            $checked++;
        }
        // 豁免清单收敛断言：树里有豁免文件但一个裸调用都没有 → 豁免已陈旧
        foreach (self::RAW_FETCH_EXEMPT as $exempt) {
            if (!isset($exemptHit[$exempt]) && is_file($webDir . '/' . $exempt)) {
                $issues[] = "$webDir/$exempt: 豁免文件已无裸 fetch/XHR 调用，请收敛 frontend_raw_fetch 豁免清单（例外清单双向断言）";
            }
        }
        return ['issues' => $issues, 'checked' => $checked];
    }

    /**
     * 剥前端源码注释（JS 单行/块注释 + HTML 注释），供裸调用正则扫描防自指假命中。
     * 字符串字面量不剥（如 'https://x' 会被截短成 'https:，但只会减少可见文本、不会制造命中；
     * 真命中（fetch( 形态）不会出现在合法字符串里。
     */
    protected function stripFrontendComments(string $src): string
    {
        $src = preg_replace('~<!--.*?-->~s', ' ', $src) ?? $src;
        $src = preg_replace('~/\*.*?\*/~s', ' ', $src) ?? $src;
        return preg_replace('~(?<!:)//[^\n]*~', ' ', $src) ?? $src;
    }


    /* ---------- 8. 异步阻塞扫描（异步铁律） ---------- */

    /**
     * 常驻进程代码内同步阻塞 IO 检查（依据「异步铁律」沉淀规则，审计 2026-08-28 实战）：
     *   1. 阻塞休眠：usleep(/sleep(（排除 Timer::sleep 封装与封装类自身）
     *   2. Redis 阻塞长拉：->brpop(
     *   3. curl_exec（同步）
     *   4. SMTP 同步发送：$mailer->send( / ->send($email)
     *   5. 同步 Guzzle：文件 import GuzzleHttp 时的 new Client( / ->post( / ->get( / ->request(
     * 排除：src/app/command（CLI 一次性脚本）、Install.php、templates/（复制模板）。
     */
    protected function checkAsyncBlocking(string $root, string $pkg, string $dir): ?array
    {
        $appDir = $this->srcPath($dir, 'app');
        if (!is_dir($appDir)) {
            return null;
        }
        $issues = [];
        foreach ($this->phpFiles($appDir) as $file) {
            $rel = str_replace($root . '/', '', $file);
            $base = basename($file);
            if ($base === 'Install.php') {
                continue; // Install 钩子（composer 触发、框架未加载）
            }
            if ($base === 'AuditService.php') {
                continue; // 审计引擎自身源文件：含各探测模式字面量（brpop/curl_exec 等），自扫必误报
            }
            if (str_contains($rel, '/command/')) {
                continue;
            }
            $src = file_get_contents($file);
            if (str_contains($src, '@audit-ignore async_blocking')) {
                continue; // 显式豁免标注（如队列消费者内同步 SMTP：web 请求已投递 redis-queue，见 OidcMailerService）
            }
            if (preg_match('~(?:Coroutine::isCoroutine\(\)|Fiber::getCurrent\(\)|function\s+inCoroutine)~', $src)) {
                continue; // 文件显式声明协程回退（CLI 才用同步实现）——混合模式，符合异步铁律
            }
            $lines = file($file) ?: [];
            $usesGuzzle = false;
            foreach ($lines as $line) {
                if (preg_match('~use\s+[\\\\A-Za-z]*GuzzleHttp[\\\\A-Za-z]*\\\\Client\s*;~', $line)) {
                    $usesGuzzle = true;
                    break;
                }
            }
            foreach ($lines as $i => $line) {
                $ln = $i + 1;
                $t = trim($line);
                if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
                    continue;
                }
                // 阻塞休眠（排除 $var()/->/:: 方法形式与 function 声明；Timer 封装安全）
                if (preg_match('~(?<![\w$:>-])(?:usleep|sleep)\s*\(~', $line) && !preg_match('~function\s+(?:usleep|sleep)~', $line)) {
                    $issues[] = "$rel:$ln: 阻塞休眠 usleep/sleep（应使用 Workerman\Timer::sleep 挂起协程）";
                }
                if (str_contains($line, '->brpop(')) {
                    $issues[] = "$rel:$ln: 同步 BRPOP 长拉（占死 worker；长轮询应改客户端驱动轮询）";
                }
                if (str_contains($line, 'curl_exec(')) {
                    $issues[] = "$rel:$ln: curl_exec 同步阻塞（应走 workerman/http-client 协程）";
                }
                if (preg_match('~\$mailer->send\(|->send\(\s*\$email~', $line)) {
                    $issues[] = "$rel:$ln: 同步 SMTP 发送（应投递 redis-queue 异步执行）";
                }
                if ($usesGuzzle && preg_match('~\$client->(?:post|get|request)\s*\(|new\s+Client\s*\(~', $line)) {
                    $issues[] = "$rel:$ln: 同步 Guzzle HTTP（常驻进程应走 workerman/http-client 协程；CLI 才回退 Guzzle）";
                }
            }
        }
        return ['issues' => $issues, 'note' => 'src/app scanned'];
    }

    /* ---------- 9. 同名类冲突（全工作区 FQCN 去重） ---------- */

    protected function checkFqcnDup(string $root, string $pkg, string $dir): ?array
    {
        if (!is_dir($this->srcPath($dir))) {
            return null;
        }
        $issues = [];
        foreach ($this->rootClasses($root) as $fqcn => $locations) {
            // 文件标注 @audit-ignore fqcn_dup = 有意的真源同步副本（如 webman-status-code 与 radmin 同名 StatusCode）
            $locations = array_values(array_filter($locations, fn($loc) => !str_contains((string) @file_get_contents($root . '/' . $loc['rel']), '@audit-ignore fqcn_dup')));
            if (count($locations) < 2) {
                continue;
            }
            $involved = false;
            $paths = [];
            foreach ($locations as $loc) {
                $paths[] = $loc['rel'];
                if ($loc['pkg'] === strtolower($pkg) || $loc['pkg'] === strtolower(basename($dir))) {
                    $involved = true;
                }
            }
            if ($involved) {
                $issues[] = "FQCN $fqcn 被多文件定义：" . implode(' 与 ', $paths);
            }
        }
        return ['issues' => $issues, 'note' => 'workspace FQCN scan'];
    }

    /* ---------- 10. 超全局直读（worker 内） ---------- */

    protected function checkSuperglobal(string $root, string $pkg, string $dir): ?array
    {
        $appDir = $this->srcPath($dir, 'app');
        if (!is_dir($appDir)) {
            return null;
        }
        $issues = [];
        foreach ($this->phpFiles($appDir) as $file) {
            $rel = str_replace($root . '/', '', $file);
            if (basename($file) === 'Install.php' || str_contains($rel, '/command/')) {
                continue;
            }
            $src = file_get_contents($file);
            if (str_contains($src, '@audit-ignore superglobal')) {
                continue; // 显式豁免标注（如 CLI/非 webman 上下文的 $_COOKIE 回退，Web 请求已优先走 Request）
            }
            if (preg_match_all('~\$_(?:COOKIE|SERVER)\s*\[~', $src, $mm, PREG_OFFSET_CAPTURE)) {
                $lines = [];
                foreach ($mm[0] as $hit) {
                    $lines[] = substr_count(substr($src, 0, (int) $hit[1]), "\n") + 1;
                }
                foreach (array_unique($lines) as $ln) {
                    $issues[] = "$rel:$ln: 直读超全局（webman worker 不自动填充，应走 support\\Context + Request；CLI 场景人工确认）";
                }
            }
        }
        return ['issues' => $issues, 'note' => 'src/app scanned'];
    }

    /* ---------- 11. 死类检测（全工作区零引用） ---------- */

    protected function checkDeadCode(string $root, string $pkg, string $dir): ?array
    {
        if (!is_dir($this->srcPath($dir))) {
            return null;
        }
        $issues = [];
        if (!is_dir($this->srcPath($dir, 'app/admin'))) {
            // SDK 包（无后台管理端，如 channel-client/oidc-client）：类为公共 API，由外部宿主/调用方消费，
            // 工作区内零引用是常态，跳过死类判定
            return ['issues' => [], 'note' => 'SDK 公共 API 包（无 src/app/admin）跳过'];
        }
        foreach ($this->rootClassRefs($root)['defs'] as $def) {
            // rolling 布局：框架自动发现类不算死代码——命令（`app/command/`、`command/`）由 webman console
            // 扫描注册、无显式引用；任务处理器（`app/task/`）由 rocareer/crontab 按 DB 任务表实例化
            if ($this->layout === 'rolling'
                && preg_match('~^(.*/)?(app/)?(command|task)/~', (string) ($def['rel'] ?? ''))) {
                continue;
            }
            if ($def['pkg'] !== strtolower($pkg) && $def['pkg'] !== strtolower(basename($dir))) {
                continue;
            }
            if ($def['abstract'] || $def['kind'] !== 'class') {
                continue; // 抽象类/接口/枚举无直接实例化语义
            }
            if (preg_match('~(Install|Migration|Model|Consumer|Tool|Interface|Trait|Exception|Controller)$~', $def['short'])) {
                continue; // 框架反射/注册表字符串实例化，无法静态判定
            }
            if (str_contains($def['rel'], '/controller/')) {
                continue; // 控制器由 webman 默认路由按类名约定反射加载（非 new 实例化）
            }
            if (str_contains((string) @file_get_contents($root . '/' . $def['rel']), '@audit-ignore dead_code')) {
                continue; // 显式豁免标注
            }
            if (preg_match('~/(middleware|process|validate|upload|support)/~', $def['rel'])) {
                continue; // 中间件/自定义进程/验证器/上传驱动/框架支撑类：配置或 alias/容器字符串引用，静态无法判定
            }
            $fqcn = $def['fqcn'];
            if ($this->rootClassRefs($root)['refs'][$fqcn] ?? false) {
                continue;
            }
            $issues[] = "{$def['rel']}: 类 {$def['short']} 全工作区零引用（无 new/静态调用/::class/配置字符串/use 导入引用）——死代码候选，人工确认后删除";
        }
        return ['issues' => $issues, 'note' => 'workspace ref scan'];
    }

    /* ---------- 12. 跨包文件重复（内容逐字相同） ---------- */

    protected function checkCrossCopy(string $root, string $pkg, string $dir): ?array
    {
        if (!is_dir($this->srcPath($dir)) && !is_dir("$dir/config")) {
            return null;
        }
        $issues = [];
        foreach ($this->rootFileHashes($root) as $hash => $files) {
            if (count($files) < 2) {
                continue;
            }
            $involved = false;
            foreach ($files as $f) {
                if ($f['pkg'] === strtolower($pkg) || $f['pkg'] === strtolower(basename($dir))) {
                    $involved = true;
                }
            }
            if (!$involved) {
                continue;
            }
            $issues[] = '逐字重复：' . implode(' 与 ', array_map(fn($f) => $f['rel'], $files)) . '（应下沉共享实现，防复制漂移）';
        }
        return ['issues' => $issues, 'note' => 'workspace hash scan'];
    }

    /* ---------- 13. DTO 分层规范（公开契约门禁） ---------- */

    /**
     * DTO 分层门禁（依据「编码规范 · DTO 分层规范」沉淀规则）：
     *   1. 公开 API 控制器（app 下非 admin 的 controller）内 `$this->success('', [ ...多字段... ])`
     *      或 `$items[] = [ ...多字段... ]` 手拼数组输出 = 契约未固化，应引入 app/<模块>/dto/ typed DTO
     *      或改用 Model accessor（admin CRUD 除外，不检查）；
     *   2. dto/ 目录下纯搬运类：toArray() 原样返回构造入参数组、无字段整形/强转/脱敏 = 过度设计，直接用数组；
     *   3. 目录命名用 dto，禁止 data/（与"数据/数据库"歧义）。
     * 豁免：文件标注 @audit-ignore dto_contract 显式声明。
     */
    protected function checkDtoContract(string $root, string $pkg, string $dir): ?array
    {
        $appDir = $this->srcPath($dir, 'app');
        if (!is_dir($appDir)) {
            return null;
        }
        $issues = [];
        $ctrlCount = 0;

        // ---- 1) 公开 API 控制器手拼数组输出（排除 admin/ 后台控制器与 common/controller 基类）----
        foreach ($this->phpFiles($appDir) as $file) {
            if (!str_contains($file, '/controller/') || str_contains($file, '/admin/')
                || str_contains($file, '/common/controller/')) {
                continue; // 跳过非控制器 / admin 后台 / 公共基类控制器（Backend/Frontend 等非路由公开端点，避免误计）
            }
            $rel = str_replace($root . '/', '', $file);
            $src = (string) file_get_contents($file);
            if (!preg_match('~\bextends\s+[\w\\\\]*Api\b~', $src)) {
                continue; // 非公开 API 控制器（未继承 Api 基类）
            }
            if (str_contains($src, '@audit-ignore dto_contract')) {
                continue; // 显式豁免标注
            }
            $ctrlCount++;
            // 1a) $this->success('', [ ... ]) 手拼多字段输出
            $offset = 0;
            while (preg_match('~\$this->success\(\s*[\'"][^\'"]*[\'"]\s*,\s*\[~', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $bracket = strpos($src, '[', $m[0][1]);
                $scan = $this->scanBalanced($src, $bracket);
                if ($scan !== null) {
                    $body = substr($src, $bracket + 1, $scan - $bracket - 1);
                    $pairs = preg_match_all('~=>~', $body);
                    // 标准分页信封 {list,total,page,limit} 为平台级通用契约（各列表接口统一），
                    // 不属于模块契约，豁免不报（模块契约是 items 形状，由 1b 项检查）
                    $isPaginationEnvelope = str_contains($body, "'list' =>")
                        && str_contains($body, "'total' =>")
                        && str_contains($body, "'page' =>")
                        && str_contains($body, "'limit' =>");
                    $ln = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
                    if ($pairs >= 2 && str_contains($body, "\n") && !$isPaginationEnvelope
                        && $this->lineInPublicMethod($src, $ln)) {
                        $issues[] = "$rel:$ln: 公开 API 手拼 {$pairs} 字段数组输出（契约未固化）——应引入 app/<模块>/dto/ typed DTO 或 Model accessor";
                    }
                }
                $offset = $m[0][1] + strlen($m[0][0]);
            }
            // 1b) $items[] = [ ...多字段... ] 列表项手拼
            $offset = 0;
            while (preg_match('~\$[A-Za-z_][A-Za-z0-9_]*\[\]\s*=\s*\[~', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $literalStart = $m[0][1] + strlen($m[0][0]) - 1;
                $scan = $this->scanBalanced($src, $literalStart);
                if ($scan !== null) {
                    $body = substr($src, $literalStart + 1, $scan - $literalStart - 1);
                    $pairs = preg_match_all('~=>~', $body);
                    $ln = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
                    if ($pairs >= 2 && str_contains($body, "\n") && $this->lineInPublicMethod($src, $ln)) {
                        $issues[] = "$rel:$ln: 公开 API 列表项手拼 {$pairs} 字段数组（契约未固化）——应引入 app/<模块>/dto/ typed DTO 固化列表项形状";
                    }
                }
                $offset = $m[0][1] + strlen($m[0][0]);
            }
        }

        // ---- 2) dto/ 目录纯搬运类 + 3) 目录命名门禁 ----
        foreach ($this->phpFiles($appDir) as $file) {
            if (str_contains($file, '/dto/')) {
                $rel = str_replace($root . '/', '', $file);
                $src = (string) file_get_contents($file);
                if (str_contains($src, '@audit-ignore dto_contract')) {
                    continue;
                }
                // 纯搬运：toArray() 方法体只 return 单个属性（构造入参原样），无字段处理
                if (preg_match('~function\s+toArray\s*\([^)]*\)\s*(?::\s*array)?\s*\{\s*return\s+\$this->(\w+)\s*;\s*\}~s', $src, $pm)) {
                    $ln = substr_count(substr($src, 0, strpos($src, 'function toArray')), "\n") + 1;
                    $issues[] = "$rel:$ln: 纯搬运 DTO（toArray 原样返回 \$this->{$pm[1]}，无字段整形/强转/脱敏）= 过度设计，直接用数组";
                }
                continue;
            }
            // 3) app 下存在 data/ 目录（与 dto 同级位置）——命名门禁
            if (str_contains($file, '/data/')) {
                $rel = str_replace($root . '/', '', $file);
                if (str_contains($rel, '/app/') && !str_contains($rel, '/lang/')) {
                    $issues[] = "$rel: 目录命名用了 data/（与「数据/数据库」歧义）——应改名为 dto/";
                }
            }
        }

        return ['issues' => $issues, 'note' => $ctrlCount . ' public api controllers'];
    }

    /**
     * 命中行是否位于 public 方法体内
     *
     * dto_contract 只约束对外契约（public 路由方法）；私有/保护方法内的协议转换
     * 中间格式（如 ai Responses↔Chat 线格式互转）不是对外契约，DTO 化属过度设计。
     * 归属判定：命中行属于「起始行 ≤ 命中行的最后一个函数」。
     */
    protected function lineInPublicMethod(string $src, int $line): bool
    {
        static $spanCache = [];
        $key = md5($src);
        if (!isset($spanCache[$key])) {
            $funcs = [];
            $tokens = token_get_all($src);
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                if (!is_array($t) || $t[0] !== T_FUNCTION) {
                    continue;
                }
                $vis = 'public';
                for ($k = $i - 1, $scan = 0; $k >= 0 && $scan < 8; $k--) {
                    $pk = $tokens[$k];
                    if (!is_array($pk) || in_array($pk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $scan++;
                    if (in_array($pk[0], [T_PRIVATE, T_PROTECTED], true)) {
                        $vis = 'non-public';
                        break;
                    }
                }
                $funcs[] = ['line' => (int) $t[2], 'vis' => $vis];
            }
            $spanCache[$key] = $funcs;
        }
        $owner = null;
        foreach ($spanCache[$key] as $f) {
            if ($f['line'] <= $line) {
                $owner = $f;
            } else {
                break;
            }
        }
        return $owner !== null && $owner['vis'] === 'public';
    }

    /**
     * 全域 LLM 门禁（v3.8.0，智能体出口）：业务代码禁止直接实例化 AiRouterService。
     *
     * 背景（rocareer/agent v2.0 无智能体不开工）：全域 LLM 业务统一经 agent 包
     * AgentGateway（校验智能体 -> ai 调度/熔断/计费）。直接 `new AiRouterService` 调
     * chat/chatStream/embeddings 的代码 = 绕过智能体门禁。
     *
     * 豁免：
     * - ai（底层提供者）与 agent（网关实现）两包跳过；
     * - 文件标注 @audit-ignore llm_gate（如 ai 调试/开放 API 运维接口）；
     * - 仅 use/常量引用（AiRouterService::BIZ_* / SOURCE_*）不报，只报实例化。
     */
    protected function checkLlmGate(string $root, string $pkg, string $dir): ?array
    {
        $appDir = $this->srcPath($dir, 'app');
        if (!is_dir($appDir)) {
            return null;
        }
        if (in_array($pkg, ['ai', 'agent'], true)) {
            return ['issues' => [], 'note' => 'provider/gateway 包豁免'];
        }
        $issues = [];
        $checked = 0;
        foreach ($this->phpFiles($appDir) as $file) {
            $rel = str_replace($root . '/', '', $file);
            $src = (string) file_get_contents($file);
            if (str_contains($src, '@audit-ignore llm_gate')) {
                continue;
            }
            $checked++;
            foreach (file($file) ?: [] as $i => $line) {
                $ln = $i + 1;
                // 实例化形态：new AiRouterService( 或 new \app\admin\service\AiRouterService(
                if (preg_match('~new\s+(?:\\\\app\\\\admin\\\\service\\\\)?AiRouterService\s*\(~', $line)) {
                    $issues[] = "$rel:$ln: 直接实例化 AiRouterService（应经 agent 包 AgentGateway：智能体门禁，无智能体不开工）——" . trim($line);
                }
            }
        }
        return ['issues' => $issues, 'note' => $checked . ' files scanned'];
    }

    /** 从 $start（'[' 位置）起扫描平衡数组字面量，返回右括号 ] 的位置；未闭合返回 null */
    protected function scanBalanced(string $src, int $start): ?int
    {
        $depth = 0;
        $len = strlen($src);
        $inStr = null; // null / "'" / '"'
        $esc = false;
        for ($i = $start; $i < $len; $i++) {
            $c = $src[$i];
            if ($inStr !== null) {
                if ($esc) {
                    $esc = false;
                    continue;
                }
                if ($c === '\\') {
                    $esc = true;
                    continue;
                }
                if ($c === $inStr) {
                    $inStr = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $inStr = $c;
                continue;
            }
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /* ---------- root 级扫描辅助（单轮静态缓存） ---------- */

    /** @var array<string, mixed> 单轮 root 级扫描缓存（root+type => 结果） */
    private static array $rootScanCache = [];

    /** 全 root 类定义：fqcn => [{pkg, rel, short, abstract, kind}]（排除 vendor/web/tests/database/templates） */
    protected function rootClasses(string $root): array
    {
        $key = $root . '|classes';
        if (isset(self::$rootScanCache[$key])) {
            return self::$rootScanCache[$key];
        }
        $out = [];
        foreach ($this->rootScanFiles($root) as $file) {
            $info = $this->parseClass($file['path']);
            if (!$info || !$info['class']) {
                continue;
            }
            $fqcn = ($info['namespace'] !== '' ? $info['namespace'] . '\\' : '') . $info['class'];
            $loc = ['pkg' => $file['pkg'], 'rel' => $file['rel'], 'short' => $info['class'], 'abstract' => false, 'kind' => 'class'];
            if (preg_match('~abstract\s+class~', file_get_contents($file['path']))) {
                $loc['abstract'] = true;
            }
            $out[$fqcn][] = $loc;
        }
        return self::$rootScanCache[$key] = $out;
    }

    /** 全 root 类定义与引用表：['defs' => 类定义列表, 'refs' => fqcn => true]
     *
     * 引用来源 = 全部 src/config 文件 + 各包 templates/（复制模板，宿主安装后即运行代码）+ 文件内 use 导入表：
     * - 完整 FQCN：new <FQCN>( / <FQCN>:: / 配置字符串 '<FQCN>'
     * - 短名/别名（use X / use X as Y）：<短名>:: / new <短名>( / <短名>::class
     */
    protected function rootClassRefs(string $root): array
    {
        $key = $root . '|refs';
        if (isset(self::$rootScanCache[$key])) {
            return self::$rootScanCache[$key];
        }
        $classes = $this->rootClasses($root);
        $refs = [];
        foreach ($classes as $fqcn => $locs) {
            foreach ($locs as $loc) {
                $refs[$fqcn] = false;
            }
        }
        foreach ($this->rootRefFiles($root) as $file) {
            $src = file_get_contents($file['path']);
            // 文件级 use 导入表：短名/别名 => 完整 FQCN（含 use X as Y 别名）
            $imports = [];
            if (preg_match_all('~use\s+([\\\\\\w]+)\s*(?:as\s+(\w+))?\s*;~', $src, $im, PREG_SET_ORDER)) {
                foreach ($im as $u) {
                    $fqcn = ltrim($u[1], '\\');
                    $short = (string) substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
                    $imports[($u[2] ?? '') !== '' ? $u[2] : $short] = $fqcn;
                }
            }
            foreach ($refs as $fqcn => &$used) {
                if ($used) {
                    continue;
                }
                $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
                $suffix = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
                $classPart = '\\\\' . preg_quote($suffix, '~') . '(\s*[(:;]|\s*::|::class)';
                $nsPart = str_replace('\\', '\\\\', preg_quote($fqcn, '~'));
                if (preg_match('~new\s+' . $nsPart . '\s*\(~', $src)
                    || preg_match('~' . $nsPart . '::~', $src)
                    || preg_match("~'?" . $nsPart . "~", $src)
                    || preg_match('~new\s+' . $classPart . '~', $src)
                    || preg_match('~' . $classPart . '~', $src)) {
                    $used = true;
                    continue;
                }
                // use 导入的短名/别名引用（:: / ::class / new 构造）
                // 注意只能 break 本层导入循环：break 2 会连带终止外层文件扫描，
                // 其他类先命中即让后续文件的引用永远扫不到 → 死类误报（v3.20.2 实证）
                if ($imports) {
                    foreach ($imports as $name => $importFqcn) {
                        if ($importFqcn === $fqcn && preg_match('~\b' . preg_quote($name, '~') . '(?=\s*::|\s*\()~', $src)) {
                            $used = true;
                            break;
                        }
                    }
                }
                // 同包裸短名引用（同命名空间无需 use，如 ai DriverManager::class / happ Events Message::make）
                if (str_starts_with($file['rel'], ($classes[$fqcn][0]['pkg'] ?? '') . '/')
                    && preg_match('~\b' . preg_quote($short, '~') . '(?=\s*::|\s*\()~', $src)) {
                    $used = true;
                }
            }
            unset($used);
        }
        $defs = [];
        foreach ($classes as $fqcn => $locs) {
            foreach ($locs as $loc) {
                $loc['fqcn'] = $fqcn;
                $defs[] = $loc;
            }
        }
        return self::$rootScanCache[$key] = ['defs' => $defs, 'refs' => $refs];
    }

    /**
     * 引用扫描文件集 = rootScanFiles（src/ + config/）+ 各包 templates/ 复制模板
     * （宿主安装后即运行的真实代码：模板对业务类的引用属于有效引用）
     */
    protected function rootRefFiles(string $root): array
    {
        $key = $root . '|refiles';
        if (isset(self::$rootScanCache[$key])) {
            return self::$rootScanCache[$key];
        }
        $out = $this->rootScanFiles($root);
        foreach (glob("$root/*/templates", GLOB_ONLYDIR) ?: [] as $tplDir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tplDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $path = $file->getPathname();
                    $rel = str_replace($root . '/', '', $path);
                    $pkg = strtolower(explode('/', $rel)[0] ?? '');
                    $out[] = ['path' => $path, 'rel' => $rel, 'pkg' => $pkg];
                }
            }
        }
        return self::$rootScanCache[$key] = $out;
    }

    /** 全 root .php 文件内容 hash 分组（src/ + config/，排除 vendor/web/tests/database/templates） */
    protected function rootFileHashes(string $root): array
    {
        $key = $root . '|hashes';
        if (isset(self::$rootScanCache[$key])) {
            return self::$rootScanCache[$key];
        }
        $groups = [];
        foreach ($this->rootScanFiles($root) as $file) {
            if (str_contains($file['rel'], '/lang/')) {
                continue; // 多应用语言包同步是常规做法（user/api 双应用共用文案），不算复制漂移
            }
            $size = filesize($file['path']);
            if ($size < 60) {
                continue;
            }
            $hash = md5_file($file['path']);
            $groups[$hash][] = ['pkg' => $file['pkg'], 'rel' => $file['rel']];
        }
        // 只保留确实跨路径重复的组（过滤同文件自身）
        $dup = array_filter($groups, fn($g) => count($g) >= 2 && count(array_unique(array_column($g, 'rel'))) >= 2);
        return self::$rootScanCache[$key] = $dup;
    }

    /** 全 root 待扫 .php 文件（src/ 与 config/，排除 vendor/web/tests/database/templates/） */
    protected function rootScanFiles(string $root): array
    {
        $key = $root . '|files';
        if (isset(self::$rootScanCache[$key])) {
            return self::$rootScanCache[$key];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (preg_match('~/(vendor|web|tests|database|templates|node_modules)/~', $path)) {
                continue;
            }
            $rel = str_replace($root . '/', '', $path);
            $pkg = strtolower(explode('/', $rel)[0] ?? '');
            $out[] = ['path' => $path, 'rel' => $rel, 'pkg' => $pkg];
        }
        return self::$rootScanCache[$key] = $out;
    }

    /* ---------- 解析工具（token_get_all，无正则转义） ---------- */

    /**
     * extends 链是否最终到达 Backend（中间抽象基类合法：如 radmin LedgerLog -> Backend）
     *
     * 包内父类按「类名 = 文件名」约定在 <pkg>/src 下定位，逐级向上解析（上限 5 层防环）；
     * 直接 extends Backend 或链上任意一级为 Backend 均通过；父类在包外（radmin 基类）时
     * 仅认 Backend 本身，其余（如直接 extends Api）仍按规范报出。
     */
    protected function extendsBackend(string $dir, string $extends): bool
    {
        $current = $extends;
        for ($i = 0; $i < 5 && $current !== ''; $i++) {
            if ($current === 'Backend') {
                return true;
            }
            $parentFile = $this->classFile($dir, $current);
            if ($parentFile === null) {
                return false;
            }
            $parentInfo = $this->parseClass($parentFile);
            $current = $parentInfo['extends'] ?? '';
        }
        return false;
    }

    /**
     * 包内类文件定位（类名 = 文件名约定；索引按包目录缓存一轮）
     */
    protected function classFile(string $dir, string $class): ?string
    {
        $key = rtrim($dir, '/');
        if (!isset($this->classFileIndex[$key])) {
            $index = [];
            if (is_dir($this->srcPath($dir))) {
                foreach ($this->phpFiles($this->srcPath($dir)) as $f) {
                    $index[basename($f, '.php')][] = $f;
                }
            }
            $this->classFileIndex[$key] = $index;
        }
        $hits = $this->classFileIndex[$key][$class] ?? [];
        return $hits ? $hits[0] : null;
    }

    protected function parseClass(string $file): ?array
    {
        $src = file_get_contents($file);
        $tokens = token_get_all($src);
        $namespace = '';
        $class = '';
        $extends = '';
        $abstract = false;
        $methods = [];
        $sigs = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $v = $tokens[$j];
                    if ($v === ';') {
                        break;
                    }
                    $ns .= is_array($v) ? $v[1] : $v;
                }
                $namespace = trim($ns);
            }
            if ($t[0] === T_CLASS && !$class) {
                $class = trim($tokens[$i + 2][1] ?? '');
                // 抽象基类标记（向后扫描修饰符位置）
                for ($k = $i - 1, $scan = 0; $k >= 0 && $scan < 4; $k--) {
                    $pk = $tokens[$k];
                    if (!is_array($pk) || in_array($pk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $scan++;
                    if ($pk[0] === T_ABSTRACT) {
                        $abstract = true;
                        break;
                    }
                }
                for ($j = $i + 2; $j < $n && $j < $i + 8; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_EXTENDS) {
                        $extends = trim($tokens[$j + 2][1] ?? '');
                        break;
                    }
                }
            }
            if ($t[0] === T_FUNCTION) {
                // 仅统计 public 方法（protected/private 辅助方法无按钮节点）
                $vis = false;
                $scan = 0;
                for ($k = $i - 1; $k >= 0 && $scan < 8; $k--) {
                    $pk = $tokens[$k];
                    if (!is_array($pk)) {
                        continue;
                    }
                    if (in_array($pk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $scan++;
                    if (in_array($pk[0], [T_PRIVATE, T_PROTECTED], true)) {
                        $vis = true;
                        break;
                    }
                }
                if ($vis) {
                    continue;
                }
                $sig = '';
                $name = '';
                for ($j = $i; $j < $n; $j++) {
                    $v = $tokens[$j];
                    if ($v === '{' || $v === ';') {
                        break;
                    }
                    $sig .= is_array($v) ? $v[1] : $v;
                    if ($v === '(') {
                        break;
                    }
                }
                if (preg_match('~function\s+(\w+)~', $sig, $sm)) {
                    $name = $sm[1];
                }
                if (!$name) {
                    continue;
                }
                // 收集到 ) 为止的完整签名
                $full = $sig;
                $depth = 0;
                for ($j = $i + 1; $j < $n; $j++) {
                    $v = $tokens[$j];
                    if ($v === '(') {
                        $depth++;
                        $full .= '(';
                        continue;
                    }
                    if ($v === ')') {
                        $depth--;
                        $full .= ')';
                        if ($depth === 0) {
                            continue; // 继续收集返回类型（: Response）
                        }
                        continue;
                    }
                    if ($v === '{' || $v === ';') {
                        break;
                    }
                    $full .= is_array($v) ? $v[1] : $v;
                }
                $methods[] = $name;
                $sigs[$name] = $full;
            }
        }
        if (!$class) {
            return null;
        }
        return [
            'namespace' => $namespace,
            'class' => $class,
            'extends' => $extends,
            'abstract' => $abstract,
            'methods' => $methods,
            'sigs' => $sigs,
            'props' => static::parseProps($src),
        ];
    }

    /**
     * 提取公开接口豁免属性（$noNeedLogin / $noNeedPermission）
     *
     * 支持：= true / = ['*']（全部方法免检）、= ['login','logout']（指定方法免检）
     */
    protected static function parseProps(string $src): array
    {
        $props = [];
        foreach (['noNeedLogin', 'noNeedPermission'] as $name) {
            if (preg_match('/protected\s+(?:static\s+)?(?:array|bool|mixed)?\s*\$' . $name . '\s*(?:=\s*(.*?))?;/s', $src, $m)) {
                $expr = trim($m[1] ?? '');
                if ($expr === 'true') {
                    $props[$name] = true;
                } elseif ($expr === 'false' || $expr === '') {
                    $props[$name] = false;
                } elseif (preg_match_all("/['\"]([^'\"]+)['\"]/", $expr, $vm)) {
                    $props[$name] = $vm[1];
                } else {
                    $props[$name] = false;
                }
            }
        }
        return $props;
    }

    /**
     * 权限豁免集：['all' => bool, 'list' => string[]]
     */
    protected function permissionSkips(array $info): array
    {
        $skip = ['all' => false, 'list' => []];
        foreach (['noNeedLogin', 'noNeedPermission'] as $name) {
            $v = $info['props'][$name] ?? false;
            if ($v === true) {
                $skip['all'] = true;
            } elseif (is_array($v)) {
                foreach ($v as $item) {
                    $item = strtolower(trim((string) $item));
                    if ($item === '*' || $item === 'true') {
                        $skip['all'] = true;
                    } else {
                        $skip['list'][] = $item;
                    }
                }
            }
        }
        return $skip;
    }

    /* ---------- 14. ORM 迁移门禁（think-orm 残留反向扫描） ---------- */

    /* ---------- comSearch 检索契约门禁 ---------- */

    /**
     * comSearch 检索契约门禁：admin 控制器禁止手写解析 search 数组（高级检索/排序是
     * radmin 基础能力，Backend::applyListQueryContract 一行接入）。命中定义 function index
     * 且直接 ->input('search') 解析的控制器；已接契约（applyListQueryContract/queryBuilder）
     * 的文件残留解析段同样报（防双轨双写）。文件标注 @audit-ignore comsearch_contract 豁免。
     */
    protected function checkComsearchContract(string $root, string $pkg, string $dir): ?array
    {
        $ctrlDir = $this->srcPath($dir, 'app/admin/controller');
        if (!is_dir($ctrlDir)) {
            return null;
        }
        $issues = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($ctrlDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $content = @file_get_contents($path) ?: '';
            if (!str_contains($content, 'function index')) {
                continue;
            }
            if (str_contains($content, '@audit-ignore comsearch_contract')) {
                continue;
            }
            if (!preg_match('/->input\(\s*[\'"]search[\'"]/', $content, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $rel = str_replace(chr(92), '/', substr($path, strlen($dir) + 1));
            $line = substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
            $usesContract = str_contains($content, 'applyListQueryContract(') || str_contains($content, 'queryBuilder()');
            $issues[] = $usesContract
                ? $rel . ':' . $line . ' 已接入列表契约仍手写解析 search 数组——删除手写段防双轨双写；跨表字段别名等正当场景标注 @audit-ignore comsearch_contract 并注释理由'
                : $rel . ':' . $line . ' 手写解析 comSearch search 数组——高级检索/排序是 radmin 基础能力，改用 applyListQueryContract() 一行接入；正当场景标注 @audit-ignore comsearch_contract 并注释理由';
        }
        return $issues ? ['issues' => $issues] : ['issues' => [], 'note' => 'admin 控制器契约全覆盖'];
    }

    protected function checkOrmMigrated(string $root, string $pkg, string $dir): ?array
    {
        if (!is_dir($this->srcPath($dir))) {
            return null;
        }
        // think-orm 残留模式；webman-dev 自身种子迁移/CLI 工具里历史文件
        // 用 @audit-ignore orm_migrated 豁免
        $forbidden = [
            'think\\facade\\Db',
            'think\\db\\exception',
            'think\\model\\relation',
            'think\\Paginator',
            'use think\\File;',
            'use think\\Exception;',
            // think-validate / think-container 残留（radmin v5.0.0 起验证框架切 webman/validation，类不存在即崩）
            'use think\\Validate',
            'use think\\Facade',
            'think\\facade\\Validate',
            'think\\exception\\ValidateException',
            // 场景设置器残留：radmin BaseValidate 已无 scene() 设置器（官方基类 scene() 为 getter），
            // 变量/字面形态调用会落 __call → "Validator method not found: scene"（合法内部调用 $this->scene() 无参不匹配）
            '->scene(\'',
            '->scene($',
            // support\think\* 门面（webman/think-orm 专属路径，Eloquent 下类不存在）
            'support\\think\\',
            "config('think-orm",
            'config("think-orm',
            // think 查询语义残留（v4.0.0 收敛后禁止回退）
            '->whereLike(',
            // withJoin 需覆盖换行形态（`->\nwithJoin(` 换行写法逃过 '->withJoin(' 字面匹配，
            // 故用裸 'withJoin('：不会误伤 withJoinRelations(/withJoinTable/withJoinType 配置属性）
            'withJoin(',
            '->withoutField(',
            '->whereOr(',
            '->saveAll(',
            '->startTrans(',
            'Db::connect(',
            'Db::name(',
            'Db::query(',
            'Db::execute(',
            '->getQuery()->getTableFields(',
            '->getTableFields(',
            '->getData(',
            '->cache(',
            '->inc(',
            '->dec(',
            '->setInc(',
            '->setDec(',
            'onBeforeInsert',
            'onBeforeUpdate',
            'onAfterInsert',
            'onBeforeDelete',
            'onBeforeWrite',
            'protected $autoWriteTimestamp',
            'protected $createTime',
            'protected $updateTime',
            'protected $dateFormat = false',
            'ensureThinkOrmPgConnection',
            'DataNotFoundException',
            'ThinkModel',
        ];
        // 行级语义残留（正则精确匹配，避免误报 Eloquent 原生 API 与控制器方法调用）
        $lineForbidden = [
            '/->order\(/' => '->order(',
            '/->field\(/' => '->field(',
            '/->alias\(/' => '->alias(',
            '/->column\(/' => '->column(',
            '/->group\(/' => '->group(',
            // :: 静态形态（think 专属静态调用，如 Model::order()/Model::field() 等；Eloquent 无
            // 此形态会 BadMethodCallException 或静默失效，2026-08-31 曾漏检 DriverService/ChatService）
            '/::order\(/' => '::order( 静态（think 语义，应改 orderBy）',
            '/::field\(/' => '::field( 静态（think 语义，应改 select）',
            '/::alias\(/' => '::alias( 静态（think 语义，应改 from ... as）',
            '/::column\(/' => '::column( 静态（think 语义，应改 pluck）',
            '/::whereLike\(/' => '::whereLike( 静态（think 语义，应改 where like）',
            '/(?<!\$this)->select\(\)/' => '->select() 无参（think 语义执行查询）',
            '/(?<!\$this)->find\(\)/' => '->find() 无参（think 语义取首行）',
            '/(?<!\$this)::select\(\)/' => '::select() 无参（think 语义执行查询）',
            '/(?<!\$this)::find\(\)/' => '::find() 无参（think 语义取首行）',
            '/function (get|set)[A-Za-z0-9_]+Attr\(/' => 'getXxxAttr/setXxxAttr 访问器（应改 getXxxAttribute）',
            '/protected \$type\s*=\s*\[/' => '模型 $type（应改 $casts）',
            '/protected \$name\s*=\s*[\'"]/' => '模型 $name（应改 $table）',
            // think 三参数操作符形态（Eloquent 静默翻成 "col" = 'in'，数组值丢失只失效不报错）
            '/->where\([^,]+,[^,]+,\s*[\'"](in|not in|between|not between|null|not null|find in set)[\'"]\s*,/i' => 'where 三参数操作符（in/between/null/find in set，应改 whereIn/whereBetween/whereNull/whereRaw）',
        ];
        $issues = [];
        // 扫描 src/ 业务代码 + database/ 迁移（迁移同样禁 think 语义残留，2026-08-31 曾漏 version202 的 Db::startTrans）
        $scanDirs = array_filter([$dir . '/src', $dir . '/database'], 'is_dir');
        foreach ($scanDirs as $scanDir) {
            foreach ($this->phpFiles($scanDir) as $f) {
                $content = (string) file_get_contents($f);
                if (str_contains($content, '@audit-ignore orm_migrated')) {
                    continue;
                }
                if (basename($f) === 'AuditService.php') {
                    continue; // 审计引擎自身的模式字面量自扫必误报
                }
                $file = ltrim(str_replace("$dir/", '', $f), '/');
                $hit  = false;
                foreach ($forbidden as $pat) {
                    if (str_contains($content, $pat)) {
                        $issues[] = "$file: " . str_replace('\\', '\\', $pat);
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    continue; // 已命中禁用模式，不再重复报告行级残留
                }
                foreach ($lineForbidden as $pattern => $label) {
                    if (preg_match($pattern, $content)) {
                        $issues[] = "$file: $label";
                        break;
                    }
                }
            }
        }
        $composer = $dir . '/composer.json';
        $composerContent = is_file($composer) ? (string) file_get_contents($composer) : '';
        if ($composerContent !== '' && str_contains($composerContent, 'topthink/')) {
            $issues[] = 'composer.json: topthink/* 残留（think 生态已清零，radmin v5.0.0 起禁装）';
        } elseif ($composerContent !== '' && str_contains($composerContent, 'webman/think-orm')) {
            $issues[] = 'composer.json: webman/think-orm';
        }
        return ['issues' => $issues, 'note' => 'think 生态残留清零（think-orm + think-validate 等）'];
    }

    /* ---------- 15. 事件规范（webman/event） ---------- */

    /**
     * webman/event 使用规范门禁（依据 docs/webman-event-standard.md 沉淀规则）：
     *   1. 事件发射一律用 Event::dispatch（不吞异常）：禁止 Event::emit（吞异常，
     *      监听器异常被 catch 后仅记日志，掩盖故障）；
     *   2. 事件名格式：Event::dispatch/Event::on 的静态事件名必须「提供方.领域.动作」
     *      全小写点分（禁驼峰/连字符/下划线分隔/无前缀裸名）；
     *   3. 业务代码禁止散落 Event::on()：监听器必须集中在 config/plugin/包名/event.php
     *      或 config/event.php 声明（唯一例外：radmin support\member\EventRegister
     *      内置化 member.* 生命周期事件，防宿主重复登记）；
     *   4. 孤儿事件检测：静态事件名在「全工作区监听器注册表」中找不到
     *      任何监听（本包 event.php / 跨包 event.php / radmin EventRegister / dev 宿主
     *      config/event.php / 前缀通配 happ.message.* 均计入）= 发射即空转，
     *      纯日志应直写日志（support\Log），否则标记；
     *   5. 监听器方法签名：app/listener 下 on* 方法必须 (array $data): void。
     * 豁免：文件标注 @audit-ignore event_standard 显式声明（如网关动态分发等）。
     */
    protected function checkEventStandard(string $root, string $pkg, string $dir): ?array
    {
        $appDir = $this->srcPath($dir, 'app');
        if (!is_dir($appDir)) {
            return null;
        }
        $issues = [];
        $emitCount = 0;
        $registry = $this->workspaceEventRegistry($root);
        // happ 包：WS 网关提供方（businessworker 上下文），happ.* 为宿主消费的协议事件
        // （dev/happ-host 注册 happ.auth / happ.message.*，业务应用按需订阅）——孤儿检测豁免
        $skipOrphan = in_array($pkg, ['happ'], true);

        // 1) 发射点扫描：命名格式 + 孤儿事件
        foreach ($this->phpFiles($appDir) as $file) {
            $rel = str_replace($root . '/', '', $file);
            $src = (string) file_get_contents($file);
            if (str_contains($src, '@audit-ignore event_standard')) {
                continue;
            }
            $lines = file($file) ?: [];
            foreach ($lines as $i => $line) {
                $ln = $i + 1;
                $t = ltrim($line);
                // 跳过注释/空行（//、*、/* 开头的文档与注释内不应被当作发射点）
                if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
                    continue;
                }
                // 1a) 禁止 emit（吞异常）：一律 Event::dispatch
                if (preg_match('~Event::emit\s*\(~', $line)) {
                    $issues[] = "$rel:$ln: 使用了 Event::emit（吞异常，监听器故障被掩盖）——事件发射一律用 Event::dispatch";
                    continue;
                }
                // 事件名提取：静态字面量 / 字面量前缀拼接（'happ.message.' . $type）
                if (!preg_match("~Event::(?:dispatch|on)\(\s*(['\"])([^'\"]*)~", $line, $m)) {
                    continue;
                }
                $name = $m[2];
                // 跳过完全动态（无字面量前缀）
                if ($name === '') {
                    continue;
                }
                $emitCount++;
                $isDynamic = substr_count($line, "' . ") > 0 || str_contains($line, '{$');
                $base = $isDynamic ? $this->eventBase($name) : $name;

                // 1a) 命名格式（仅字面量部分可判）
                $formatIssues = $this->eventNameIssues($name);
                if ($formatIssues !== '') {
                    $issues[] = "$rel:$ln: 事件名 `$name` 不合规：$formatIssues";
                }

                // 1b) 孤儿事件：静态名或动态基础名在注册表无任何监听（发射即空转）
                if (!$skipOrphan && $base !== '' && !$this->eventHasListener($registry, $base)) {
                    $issues[] = "$rel:$ln: 事件 `$name` 在全工作区无任何监听器（发射即空转）——纯日志请直写日志，需扩展请先在 event.php 注册监听";
                }
            }
        }

        // 2) 业务代码散落 Event::on()（除 radmin EventRegister 内置 member.*）
        $listenerDir = "$appDir/listener";
        $scanOn = true;
        if ($pkg === 'radmin') {
            // radmin 仅允许 support\member\EventRegister 一处 Event::on（内置 member.*）
            foreach ($this->phpFiles($appDir) as $file) {
                $rel = str_replace($root . '/', '', $file);
                if (str_contains($rel, '/support/member/EventRegister.php')) {
                    continue;
                }
                $src = (string) file_get_contents($file);
                if (str_contains($src, '@audit-ignore event_standard')) {
                    continue;
                }
                foreach (file($file) ?: [] as $i => $line) {
                    $t = ltrim($line);
                    if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
                        continue;
                    }
                    if (preg_match('~Event::on\s*\(~', $line)) {
                        $issues[] = "$rel:" . ($i + 1) . ": 业务代码散落 Event::on()——监听器必须集中在 config/plugin/*/event.php（唯一例外 radmin EventRegister 内置 member.*）";
                    }
                }
            }
            $scanOn = false;
        }

        // 3) 监听器方法签名：(array $data): void
        $listenerCount = 0;
        if (is_dir($listenerDir)) {
            foreach ($this->phpFiles($listenerDir) as $file) {
                $listenerCount++;
                $rel = str_replace($root . '/', '', $file);
                $src = (string) file_get_contents($file);
                if (str_contains($src, '@audit-ignore event_standard')) {
                    continue;
                }
                foreach (file($file) ?: [] as $i => $line) {
                    if (!preg_match('~function\s+(on[A-Za-z0-9_]+)\s*\(([^)]*)\)\s*(?::\s*([A-Za-z0-9_\\\?]+))?~', $line, $m)) {
                        continue;
                    }
                    $method = $m[1];
                    $params = trim($m[2]);
                    $ret = trim($m[3] ?? '');
                    $bad = [];
                    if (!preg_match('~\barray\s+\$data\b~', $params)) {
                        $bad[] = '参数应为 (array $data)';
                    }
                    if ($ret === '' || $ret === '?void') {
                        $bad[] = '缺少返回类型 : void';
                    } elseif ($ret !== 'void') {
                        $bad[] = "返回类型应为 : void（当前 : {$ret}）";
                    }
                    if ($bad) {
                        $issues[] = "$rel:" . ($i + 1) . ": 监听器 {$method}() " . implode('；', $bad);
                    }
                }
            }
        }

        return ['issues' => $issues, 'note' => $emitCount . ' emits, ' . $listenerCount . ' listeners' . ($scanOn ? '' : ', radmin EventRegister 豁免')];
    }

    /** 动态事件名取基础段（去掉末段动态部分，如 'happ.message.' . $type -> happ.message） */
    protected function eventBase(string $name): string
    {
        $name = rtrim($name, '.');
        if (str_ends_with($name, '.')) {
            $name = substr($name, 0, -1);
        }
        $pos = strrpos($name, '.');
        return $pos === false ? $name : substr($name, 0, $pos);
    }

    /** 事件名格式问题（返回空串=合规）：<提供方>.<领域>.<动作> 全小写点分 */
    protected function eventNameIssues(string $name): string
    {
        $problems = [];
        if (preg_match('~[A-Z]~', $name)) {
            $problems[] = '含大写（禁驼峰）';
        }
        if (str_contains($name, '-')) {
            $problems[] = '含连字符';
        }
        if (preg_match('~_{2,}|^_|_$~', $name)) {
            $problems[] = '下划线分隔（应用点分）';
        }
        if (!str_contains($name, '.')) {
            $problems[] = '无提供方前缀（应为 <提供方>.<领域>.<动作>）';
        }
        // 结尾点号为动态拼接形态（'happ.message.' . $type），属网关白名单合法前缀，不算空段
        if (preg_match('~(^\.|\.\.)~', $name)) {
            $problems[] = '点号位置异常（空段/连续点）';
        }
        return implode('；', $problems);
    }

    /**
     * 全工作区监听器注册表（src 各包 + dev 各宿主）
     * 收集：config/plugin 下各包的 event.php 与 config/event.php 的数组键、
     * EventRegister Event::on 事件名。
     * 前缀通配（happ.message.* 等）单独收集用于动态事件匹配。
     *
     * v3.27.0 业务端适配：内建 glob 是**家族布局约定**；业务工作区自己的监听登记布局
     * 由 config/audit.php 的 event_registry_globs 声明（相对该配置所约束的目录：工作区级 = root，
     * 包级 = 包目录）——工作区级 + 各包级（rolling = plugin/<名>/config/audit.php；family = <包>/config/audit.php）均并入。
     *
     * @return array ['events' => 静态事件名集合, 'prefixes' => 通配前缀集合]
     */
    protected function workspaceEventRegistry(string $root): array
    {
        if (isset(static::$rootScanCache[$root . ':events'])) {
            return static::$rootScanCache[$root . ':events'];
        }
        $events = [];
        $prefixes = [];
        $scanDir = function (string $base) use (&$events, &$prefixes): void {
            foreach (glob("$base/*/config/event.php") ?: [] as $f) {
                $this->collectEventNames($f, $events, $prefixes);
            }
            foreach (glob("$base/*/config/plugin/*/*/event.php") ?: [] as $f) {
                $this->collectEventNames($f, $events, $prefixes);
            }
            foreach (glob("$base/*/src/support/**/EventRegister.php") ?: [] as $f) {
                $this->collectEventOnNames($f, $events);
            }
        };
        $scanDir($root);
        // 业务端声明的监听登记源（config/audit.php 的 event_registry_globs）。
        // glob 相对**该配置所约束的目录**解析（工作区级 = root；包级 = 包目录）——
        // 配置文件物理上在 config/ 子目录里，dirname() 少算一层会让全部 glob 落空（v3.27.1 实测）。
        $auditConfigs = [$root . '/config/audit.php'];
        $pkgPattern = ($this->layout === 'rolling' ? "$root/plugin/*" : "$root/*") . '/config/audit.php';
        foreach (glob($pkgPattern) ?: [] as $f) {
            $auditConfigs[] = $f;
        }
        foreach ($auditConfigs as $cf) {
            if (!is_file($cf)) {
                continue;
            }
            $cfg = include $cf;
            if (is_array($cfg) && ($cfg['event_registry_globs'] ?? []) !== []) {
                $base = preg_replace('~[\\\\/]config$~', '', dirname($cf)) ?: dirname($cf);
                foreach ($this->globFilesUnder($base, (array) $cfg['event_registry_globs']) as $f) {
                    $this->collectEventNames($f, $events, $prefixes);
                }
            }
        }
        // dev 宿主（root 的上一级/dev）
        $devRoot = dirname(rtrim($root, '/')) . '/dev';
        if (is_dir($devRoot)) {
            $scanDir($devRoot);
        }
        return static::$rootScanCache[$root . ':events'] = ['events' => $events, 'prefixes' => $prefixes];
    }

    /** 从 event.php 数组键收集事件名（含 $listeners['x'] = 动态赋值形态） */
    protected function collectEventNames(string $file, array &$events, array &$prefixes): void
    {
        $src = (string) file_get_contents($file);
        // 键形态1：'xxx.yyy' => [（return 数组 / $listeners['x'] =）
        if (preg_match_all("~['\"]([a-z][a-z0-9_.]*(?:\.[a-z][a-z0-9_.]*)?)['\"]\s*=>~i", $src, $m)) {
            foreach ($m[1] as $name) {
                $this->addEventName($name, $events, $prefixes);
            }
        }
        // 键形态2：$listeners['xxx.yyy'] = [
        // 注意：双引号字符串中 \\\$listeners 解析为 \$listeners（正则匹配字面 $，
        // 若写成 \$listeners 则 $ 成为行尾锚点，动态赋值形态从未被识别——2026-09-05 修复）
        if (preg_match_all("~\\\$listeners\s*\[\s*['\"]([a-z][a-z0-9_.]*)['\"]\s*\]~i", $src, $m2)) {
            foreach ($m2[1] as $name) {
                $this->addEventName($name, $events, $prefixes);
            }
        }
        // 键形态3：Event::on('xxx.yyy', ...)
        $this->collectEventOnNames($file, $events);
    }

    protected function addEventName(string $name, array &$events, array &$prefixes): void
    {
        if (str_ends_with($name, '.*')) {
            $prefixes[rtrim($name, '*')] = true;
            return;
        }
        $events[$name] = true;
    }

    /** 从文件收集 Event::on('xxx.yyy', ...) 静态注册（radmin EventRegister 等） */
    protected function collectEventOnNames(string $file, array &$events): void
    {
        $src = (string) file_get_contents($file);
        if (preg_match_all("~Event::on\s*\(\s*(['\"])([^'\"]+)~", $src, $m)) {
            foreach ($m[2] as $name) {
                $events[$name] = true;
            }
        }
    }

    /** 事件名是否有监听（静态名精确命中 / 动态名基础命中通配或精确） */
    protected function eventHasListener(array $registry, string $name): bool
    {
        if (isset($registry['events'][$name])) {
            return true;
        }
        foreach ($registry['prefixes'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        // 动态基础名（happ.message）再向上取一段尝试命中（happ.* 之类）
        if (str_contains($name, '.')) {
            $upper = substr($name, 0, strrpos($name, '.'));
            if (isset($registry['events'][$upper])) {
                return true;
            }
            foreach ($registry['prefixes'] as $prefix) {
                if (str_starts_with($upper, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 通用工具真源门禁：已知手写重复模式必须用 radmin 全局函数
     * （真源清单见 docs/common-utils-registry.md）。
     *
     * 检测模式与建议替换：
     *   - max(1, min(100, ...))                       -> clamp_limit()
     *   - 分页 max(1, (int) ...input('page'...))      -> clamp_page()
     *   - keyword/quickSearch 兼容链                  -> request_keyword()
     *   - where(function ($q) use ($keyword) {...})   -> keyword_like()
     *   - json_encode($x, UNICODE | SLASHES)          -> json_unicode()
     *   - strtr(base64_encode(...))                   -> base64url_encode()
     *   - md5(uniqid(...))                            -> uuid7()
     *   - 固定四星掩码 mb_substr(...).'****'.mb_substr(...) -> mask_secret()
     *
     * 豁免：radmin functions.php（真源定义）、审计引擎自身源文件、
     * 文件标注 @audit-ignore common_utils 显式声明。
     */
    protected function checkCommonUtils(string $root, string $pkg, string $dir): ?array
    {
        $srcDir = $this->srcPath($dir);
        if (!is_dir($srcDir)) {
            return null;
        }
        // 适用域：仅约束依赖 rocareer/radmin 的包——纯 PHP SDK（ai-client/http 等，零 radmin 依赖）
        // 不可能调用 radmin 全局函数，机械套用即误报（global-helper-contract-versioning 模式）
        // 无 composer.json 的单元（rolling 插件目录等）无法判定依赖 → 跳过而非崩/误报
        if (!is_file("$dir/composer.json")) {
            return null;
        }
        $composer = json_decode((string) file_get_contents("$dir/composer.json"), true);
        if (!isset($composer['require']['rocareer/radmin'])) {
            return null;
        }
        $issues = [];
        foreach ($this->phpFiles($srcDir) as $file) {
            $rel = str_replace($root . '/', '', $file);
            $base = basename($file);
            // 真源定义文件与审计引擎自身（含各探测模式字面量）豁免
            if ($pkg === 'radmin' && $base === 'functions.php') {
                continue;
            }
            if ($base === 'AuditService.php') {
                continue;
            }
            $src = file_get_contents($file);
            if (str_contains($src, '@audit-ignore common_utils')) {
                continue; // 显式豁免标注
            }
            $lines = file($file) ?: [];
            foreach ($lines as $i => $line) {
                $ln = $i + 1;
                $t = trim($line);
                if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
                    continue;
                }
                $hit = '';
                if (preg_match('~max\(1,\s*min\(100~', $line)) {
                    $hit = '手写 limit clamp（应使用 radmin clamp_limit()）';
                } elseif (preg_match('~max\(1,\s*\(int\)\s*(?:\$this->request->|\$request->)input\(\'page\'~', $line)) {
                    $hit = '手写 page clamp（应使用 radmin clamp_page()）';
                } elseif (preg_match('~input\(\'keyword\',\s*(?:\$this->request->|\$request->)input\(\'quickSearch\'~', $line)) {
                    $hit = '手写 keyword/quickSearch 兼容链（应使用 radmin request_keyword()）';
                } elseif (preg_match('~where\(function\s*\(\$q\)\s*use\s*\(\$keyword\)~', $line)) {
                    $hit = '手写多字段 like 闭包（应使用 radmin keyword_like()）';
                } elseif (preg_match('~json_encode\([^,]+,\s*JSON_UNESCAPED_UNICODE\s*\|\s*JSON_UNESCAPED_SLASHES\s*\)~', $line)) {
                    $hit = '手写 JSON flag 组合（应使用 radmin json_unicode()）';
                } elseif (str_contains($line, 'strtr(base64_encode(')) {
                    $hit = '手写 URL-safe base64（应使用 radmin base64url_encode()）';
                } elseif (preg_match('~md5\(uniqid\(~', $line)) {
                    $hit = '手写伪随机 ID（应使用 radmin uuid7()）';
                } elseif (preg_match("~\.\s*'\*{4}'\s*\.~", $line) || str_contains($line, 'str_repeat(\'*\'')) {
                    $hit = '手写固定掩码（应使用 radmin mask_secret()，等长掩码不泄漏长度）';
                }
                if ($hit !== '') {
                    $issues[] = "$rel:$ln: $hit";
                }
            }
        }
        return ['issues' => $issues, 'note' => 'src scanned（真源豁免：radmin functions.php）'];
    }

    /* ---------- 17. Install.php 标准化（docs/install-standard.md） ---------- */

    /**
     * Install.php 标准化门禁（依据 docs/install-standard.md 沉淀规则）：
     *   1. const WEBMAN_PLUGIN = true 必须存在（webman 基础插件声明）；
     *   2. 三钩子齐全：install/update/uninstall 方法都必须存在
     *      （缺 update：官方 Plugin::update 回退 install(false)，升级专属动作无处安放）；
     *   3. install 签名必须兼容官方机制调用 Install::install(true)：
     *      禁止强类型参数（string/int/bool 等）——官方传 bool，强类型会 TypeError
     *      （channel v1.0.0 曾因此宿主 start.php 从未被注入）；
     *   4. 禁止官方骨架残留语法：copy_dir(/remove_dir(（webman-status-code/webman-dev 曾残留；
     *      copy_dir 显式带 overwrite=true 的属有意全量刷新语义，放行，如 webman-migration）、
     *      array( 数组语法（PHP 8.1 应 []）；
     *   5. 类前必须有中文头注释块（/** 开头，职责/安装步骤/卸载说明）。
     * 豁免：文件标注 @audit-ignore install_standard 显式声明。
     */
    protected function checkInstallStandard(string $root, string $pkg, string $dir): ?array
    {
        $file = $this->srcPath($dir, 'Install.php');
        if (!is_file($file)) {
            return null;
        }
        $src = (string) file_get_contents($file);
        if (str_contains($src, '@audit-ignore install_standard')) {
            return ['issues' => [], 'note' => '豁免（@audit-ignore install_standard）'];
        }
        $rel = ltrim(str_replace("$dir/", '', $file), '/');
        $issues = [];

        // 1) WEBMAN_PLUGIN 常量
        if (!preg_match('/const\s+WEBMAN_PLUGIN\s*=\s*true/i', $src)) {
            $issues[] = "$rel: 缺少 const WEBMAN_PLUGIN = true（webman 基础插件声明）";
        }

        // 2) 三钩子齐全
        foreach (['install', 'update', 'uninstall'] as $hook) {
            if (!preg_match('/function\s+' . $hook . '\s*\(/i', $src)) {
                $issues[] = "$rel: 缺少 {$hook}() 钩子（docs/install-standard.md：install/update/uninstall 三钩子必须齐全）";
            }
        }

        // 3) install 签名兼容官方（禁止强类型参数）
        if (preg_match('/function\s+install\s*\(\s*(?:string|int|float|bool|array|object)\s+\$/', $src)) {
            $issues[] = "$rel: install() 参数为强类型（官方机制调用 Install::install(true) 会 TypeError，应改为 install(\$isFirst = true)）";
        }

        // 4) 官方骨架残留语法（排除注释行；copy_dir 显式 overwrite=true 属有意刷新语义放行）
        foreach (file($file) ?: [] as $i => $line) {
            $t = ltrim($line);
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
                continue;
            }
            $ln = $i + 1;
            if (preg_match('/\bcopy_dir\s*\(/', $line) && !preg_match('/,\s*true\s*\)/', $line)) {
                $issues[] = "$rel:$ln: 使用官方骨架 copy_dir()（默认不覆盖，升级配置不刷新；应自实现 installByRelation(bool \$isFirst) 或显式 overwrite=true）";
            } elseif (preg_match('/\bremove_dir\s*\(/', $line)) {
                $issues[] = "$rel:$ln: 使用官方骨架 remove_dir()（应自实现 uninstallByRelation()）";
            } elseif (preg_match('/\barray\s*\(/', $line)) {
                $issues[] = "$rel:$ln: 使用 array() 数组语法（PHP 8.1 应使用 []）";
            }
        }

        // 5) 类前中文头注释
        if (!preg_match('~/\*\*[\s\S]*?class\s+Install\b~', $src)) {
            $issues[] = "$rel: 类前缺少中文头注释（职责/安装步骤/卸载说明，照抄 radmin/ai 先例）";
        }

        return ['issues' => $issues, 'note' => 'Install.php 已检查'];
    }
}