<?php

namespace app\admin\service;

/**
 * 工程质量审计引擎（webman-dev）
 *
 * rocareer:audit CLI 与后台「开发运维 → 工程质量审计」管理页共用同一套规则实现。
 * 规则结果结构统一：
 *   ['code','title','pass','skipped','count','issues','note','skip']
 * skipped=true 表示规则不适用/资源缺失（CLI 打印 [SKIP]、页面显示「未执行」，skip 为原因）；
 * pass 仅对已执行（skipped=false）的规则有意义；note 为通过行的补充说明（文件数/方法数等）。
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
        'permission' => ['title' => '权限节点匹配', 'description' => '控制器方法 routePath 与迁移注册的按钮名比对：缺失/错名/孤儿按钮全部报出'],
        'migration' => ['title' => '迁移时间戳查重', 'description' => 'Phinx 迁移文件时间戳冲突（撞号会阻断全家桶 migrate:run）'],
        'residue' => ['title' => '残留扫描', 'description' => 'CRUD 脚手架死代码（Test 控制器/模型/验证器）+ TODO/FIXME 计数'],
        'version' => ['title' => '版本同步', 'description' => 'CHANGELOG 头部版本 vs dev/full composer.json path 钉版'],
        'web_page' => ['title' => '前端页面规范', 'description' => 'Vue 页面模板一致性：禁止自创依赖注入/裸 axios//src/ 导入、baTable 体系页面必须经 baTable、弹窗提交走 onSubmit、TableHeader 顶部自定义按钮必须用标准样式类 table-header-operate（radmin 同步树跳过）'],
        'async_blocking' => ['title' => '异步阻塞扫描', 'description' => '异步铁律：常驻进程代码（src/app，排除 CLI command/）内禁止 BRPOP 长拉、同步 Guzzle HTTP、同步 SMTP、curl_exec、usleep/sleep 阻塞事件循环；文件显式声明协程回退（Coroutine::isCoroutine / inCoroutine / Fiber::getCurrent）或标注 @audit-ignore async_blocking 即视为已实现 CLI 回退，跳过'],
        'fqcn_dup' => ['title' => '同名类冲突', 'description' => '全工作区 namespace+class 对去重：同一 FQCN 被多文件定义（含 PSR-4 加载不到的死副本）即报；文件标注 @audit-ignore fqcn_dup 视为有意的真源同步副本'],
        'superglobal' => ['title' => '超全局直读', 'description' => 'webman worker 内直读 $_COOKIE/$_SERVER 不可靠（不自动填充/命名不可配），应走 support\\Context + Request；文件标注 @audit-ignore superglobal 视为已声明 CLI/回退路径人工确认'],
        'dead_code' => ['title' => '死类检测', 'description' => '全工作区零引用（无 new/静态调用/::class/配置字符串/use 导入引用）的非框架类 = 死代码候选；SDK 包（无 src/app/admin，公共 API 供外部消费）与标注 @audit-ignore dead_code 的文件跳过'],
        'cross_copy' => ['title' => '跨包文件重复', 'description' => '不同包内容逐字节相同的 .php 文件 = 复制粘贴实现（应下沉共享，防接口漂移）'],
        'dto_contract' => ['title' => 'DTO 分层规范', 'description' => 'DTO 分层门禁：公开 API 控制器（非 admin）手拼多字段数组输出 = 契约未固化，应引入 app/<模块>/dto/ typed DTO 或 Model accessor；dto/ 目录内纯搬运类（toArray 原样返回入参、无整形/强转/脱敏）= 过度设计，直接用数组；目录命名用 dto 不用 data（data 与"数据/数据库"歧义）；文件标注 @audit-ignore dto_contract 显式豁免'],
        'llm_gate' => ['title' => '全域 LLM 门禁（智能体出口）', 'description' => '全域 LLM 业务必须经 agent 包 AgentGateway（无智能体不开工）：业务代码禁止直接实例化 AiRouterService 调用 LLM/向量化；ai（底层提供者）与 agent（网关）豁免；文件标注 @audit-ignore llm_gate 显式豁免（如 ai 调试/开放 API 运维接口）'],
        'orm_migrated' => ['title' => 'ORM 迁移门禁（think-orm 残留）', 'description' => 'ORM 迁移门禁：src 内禁止 think-orm 类引用（think\facade\Db / think\db\exception / think\model\relation / think\Paginator / think\File / think\Exception）与 config(\'think-orm...\') 调用、composer 依赖 webman/think-orm；白名单保留 think-validate / think-helper / think-container 类；文件标注 @audit-ignore orm_migrated 显式豁免'],
        'event_standard' => ['title' => '事件规范（webman/event）', 'description' => 'webman/event 使用规范门禁（见 docs/webman-event-standard.md）：事件发射一律用 Event::dispatch（不吞异常，监听器异常上抛），禁止 Event::emit（吞异常掩盖监听器故障）；事件名必须 <提供方>.<领域>.<动作> 全小写点分（禁驼峰/连字符/下划线分隔/无前缀裸名）；业务代码禁止散落 Event::on()（监听器集中 config/plugin/*/event.php 或 config/event.php 声明，唯一例外 radmin EventRegister 内置 member.*）；静态事件名应在本包/跨包/宿主有对应监听器（孤儿事件=发射即空转，纯日志应直写日志）；app/listener 监听器方法签名 (array $data): void + 自身 try/catch；文件标注 @audit-ignore event_standard 显式豁免'],
    ];

    /** 问题明细入库/返回上限（完整数量在 count） */
    public const MAX_ISSUES = 50;

    /** 默认审计包列表（与 rocareer:audit 命令一致；MCP quality_audit 工具缺省使用） */
    public const DEFAULT_PACKAGES = [
        'radmin', 'ai', 'memory', 'chat', 'agent', 'knowledge', 'asset',
        'OIDC', 'oidc-client', 'channel', 'channel-client', 'happ', 'webman-migration',
        'crontab', 'tiktoken', 'mcp', 'webman-status-code', 'webman-dev',
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
     * 或「含 src/radmin 的工作区根」，统一返回 src 根；无效返回空串
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
        return '';
    }

    /**
     * 定位包目录（大小写不敏感：OIDC 等目录名与包名大小写可能不一致）
     */
    public function pkgDir(string $root, string $name): string
    {
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
     * 运行一轮审计
     *
     * @param array $pkgs 包名列表
     * @param array $codes 规则 code 列表（空 = 全部）
     * @return array ['root' => string, 'packages' => [['name','dir','rules' => 规则结果...]]]
     */
    public function audit(string $root, array $pkgs, array $codes = []): array
    {
        $codes = $codes ?: array_keys(self::RULES);
        $skipMap = [
            'php_syntax' => '',
            'controller' => 'controllers: none',
            'permission' => 'permission nodes: skipped',
            'migration' => 'migrations: none',
            'residue' => 'no src dir',
            'version' => 'version sync: changelog/dev json missing',
            'web_page' => 'web pages: none',
            'async_blocking' => 'no src/app dir',
            'fqcn_dup' => 'no classes',
            'superglobal' => 'no src/app dir',
            'dead_code' => 'no src dir',
            'cross_copy' => 'no php files',
            'dto_contract' => 'no public api controllers',
            'llm_gate' => 'no src/app dir',
            'event_standard' => 'no src/app dir',
        ];
        $packages = [];
        foreach ($pkgs as $name) {
            $dir = $this->pkgDir($root, $name);
            if (!is_dir($dir)) {
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
                $rules[] = $this->wrap($code, $rule['title'], $this->$method($root, $name, $dir), $skipMap[$code] ?? '');
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
                'code' => $code, 'title' => $title, 'pass' => true, 'skipped' => true,
                'count' => 0, 'issues' => [], 'note' => '', 'skip' => $skip,
            ];
        }
        $issues = $result['issues'] ?? [];
        return [
            'code' => $code, 'title' => $title,
            'pass' => count($issues) === 0, 'skipped' => false,
            'count' => count($issues),
            'issues' => array_slice($issues, 0, self::MAX_ISSUES),
            'note' => (string) ($result['note'] ?? ''),
            'skip' => '',
        ];
    }

    /* ---------- 1. PHP 语法检查（批量子进程） ---------- */

    protected function checkPhpSyntax(string $root, string $pkg, string $dir): array
    {
        $files = $this->phpFiles($dir);
        $cmd = 'find ' . escapeshellarg($dir) . " -name '*.php' -not -path '*/vendor/*' -print0 2>/dev/null | xargs -0 -n1 php -l 2>&1";
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
        $ctrlDir = "$dir/src/app/admin/controller";
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
            $count++;
            $rel = str_replace($root . '/', '', $file);
            if ($info['extends'] !== 'Backend') {
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
        $ctrlDir = "$dir/src/app/admin/controller";
        $migDir = "$dir/database/migrations";
        if (!is_dir($ctrlDir) || !is_dir($migDir)) {
            return null;
        }
        // 迁移中注册的按钮名（x/y/z 三段；按钮名可含驼峰如 security/dataRecycleLog/index——统一小写比对）
        $buttons = [];
        foreach ($this->phpFiles($migDir) as $mf) {
            $msrc = file_get_contents($mf);
            if (preg_match_all("~['\"]([a-zA-Z_]+/[a-zA-Z_]+/[a-zA-Z_-]+)['\"]~", $msrc, $m)) {
                foreach ($m[1] as $name) {
                    $buttons[strtolower($name)] = true;
                }
            }
        }
        $issues = [];
        $methodCount = 0;
        foreach ($this->phpFiles($ctrlDir) as $file) {
            $info = $this->parseClass($file);
            if (!$info) {
                continue;
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
                if (!isset($buttons[$routePath])) {
                    $issues[] = "$rel::$method -> missing button node '$routePath'";
                }
            }
        }
        return ['issues' => $issues, 'note' => $methodCount . ' methods match routePath'];
    }

    /* ---------- 4. 迁移时间戳查重 ---------- */

    protected function checkMigration(string $root, string $pkg, string $dir): ?array
    {
        $migDir = "$dir/database/migrations";
        if (!is_dir($migDir)) {
            return null;
        }
        $seen = [];
        $dups = [];
        foreach (glob("$migDir/???????????????_*.php") ?: [] as $f) {
            $stamp = substr(basename($f), 0, 14);
            if (isset($seen[$stamp])) {
                $dups[] = $stamp;
            }
            $seen[$stamp] = basename($f);
        }
        $issues = array_map(fn($t) => "duplicate timestamp $t", array_unique($dups));
        return ['issues' => $issues, 'note' => count($seen) . ' files'];
    }

    /* ---------- 5. 残留扫描 ---------- */

    protected function checkResidue(string $root, string $pkg, string $dir): ?array
    {
        if (!is_dir("$dir/src")) {
            return null;
        }
        $issues = [];
        foreach (['controller/Test.php', 'model/Test.php', 'validate/Test.php'] as $residue) {
            if (is_file("$dir/src/app/admin/$residue")) {
                $issues[] = "scaffold residue: src/app/admin/$residue";
            }
        }
        $todo = 0;
        foreach ($this->phpFiles("$dir/src") as $f) {
            if (basename($f) === 'AuditService.php') {
                continue; // 审计引擎自身含探测器模式字面量（TODO|FIXME|HACK 正则），自扫必误报
            }
            $todo += preg_match_all('~(TODO|FIXME|HACK)~', file_get_contents($f));
        }
        if ($todo > 0) {
            $issues[] = "$todo TODO/FIXME/HACK in src";
        }
        return ['issues' => $issues, 'note' => 'none (TODO/FIXME count: 0)'];
    }

    /* ---------- 6. 版本同步 ---------- */

    protected function checkVersion(string $root, string $pkg, string $dir): ?array
    {
        $changelog = "$dir/CHANGELOG.md";
        $devJson = '';
        foreach (["$root/../dev/full/composer.json", "$root/dev/full/composer.json"] as $candidate) {
            if (is_file($candidate)) {
                $devJson = $candidate;
                break;
            }
        }
        if (!is_file($changelog) || $devJson === '') {
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
        $json = json_decode(file_get_contents($devJson), true);
        $pin = '';
        foreach (($json['repositories'] ?? []) as $repo) {
            if (($repo['type'] ?? '') === 'path') {
                $v = ($repo['options']['versions'] ?? [])["rocareer/$compkg"] ?? '';
                if ($v !== '') {
                    $pin = $v;
                    break;
                }
            }
        }
        if ($pin === '') {
            return null;
        }
        $norm = fn($v) => strtolower(trim(str_replace(['v', 'V', '[', ']'], '', $v)));
        if ($norm($pkgVer) !== $norm($pin)) {
            return ['issues' => ["changelog $pkgVer != dev pin $pin"], 'note' => $pkgVer];
        }
        return ['issues' => [], 'note' => $pkgVer];
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
        $appDir = "$dir/src/app";
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
        if (!is_dir("$dir/src")) {
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
        $appDir = "$dir/src/app";
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
        if (!is_dir("$dir/src")) {
            return null;
        }
        $issues = [];
        if (!is_dir("$dir/src/app/admin")) {
            // SDK 包（无后台管理端，如 channel-client/oidc-client）：类为公共 API，由外部宿主/调用方消费，
            // 工作区内零引用是常态，跳过死类判定
            return ['issues' => [], 'note' => 'SDK 公共 API 包（无 src/app/admin）跳过'];
        }
        foreach ($this->rootClassRefs($root)['defs'] as $def) {
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
        if (!is_dir("$dir/src") && !is_dir("$dir/config")) {
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
        $appDir = "$dir/src/app";
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
                    if ($pairs >= 2 && str_contains($body, "\n") && !$isPaginationEnvelope) {
                        $ln = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
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
                    if ($pairs >= 2 && str_contains($body, "\n")) {
                        $ln = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
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
        $appDir = "$dir/src/app";
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
                if ($imports) {
                    foreach ($imports as $name => $importFqcn) {
                        if ($importFqcn === $fqcn && preg_match('~\b' . preg_quote($name, '~') . '(?=\s*::|\s*\()~', $src)) {
                            $used = true;
                            break 2;
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

    protected function parseClass(string $file): ?array
    {
        $src = file_get_contents($file);
        $tokens = token_get_all($src);
        $namespace = '';
        $class = '';
        $extends = '';
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

    protected function checkOrmMigrated(string $root, string $pkg, string $dir): ?array
    {
        if (!is_dir("$dir/src")) {
            return null;
        }
        // think-orm 残留模式（白名单之外）；webman-dev 自身种子迁移/CLI 工具里历史文件
        // 用 @audit-ignore orm_migrated 豁免
        $forbidden = [
            'think\\facade\\Db',
            'think\\db\\exception',
            'think\\model\\relation',
            'think\\Paginator',
            'use think\\File;',
            'use think\\Exception;',
            "config('think-orm",
            'config("think-orm',
            // think 查询语义残留（v4.0.0 收敛后禁止回退）
            '->whereLike(',
            '->withJoin(',
            '->withoutField(',
            '->whereOr(',
            '->saveAll(',
            '->startTrans(',
            'Db::name(',
            'Db::query(',
            'Db::execute(',
            '->getQuery()->getTableFields(',
            '->getData(',
            'onBeforeInsert',
            'onBeforeUpdate',
            'onAfterInsert',
            'onBeforeDelete',
            'onBeforeWrite',
            'protected $autoWriteTimestamp',
            'protected $createTime',
            'protected $updateTime',
        ];
        // 行级语义残留（正则精确匹配，避免误报 Eloquent 原生 API 与控制器方法调用）
        $lineForbidden = [
            '/->order\(/' => '->order(',
            '/->field\(/' => '->field(',
            '/->alias\(/' => '->alias(',
            '/->column\(/' => '->column(',
            '/->group\(/' => '->group(',
            '/(?<!\$this)->select\(\)/' => '->select() 无参（think 语义执行查询）',
            '/(?<!\$this)->find\(\)/' => '->find() 无参（think 语义取首行）',
            '/function (get|set)[A-Za-z0-9_]+Attr\(/' => 'getXxxAttr/setXxxAttr 访问器（应改 getXxxAttribute）',
            '/protected \$type\s*=\s*\[/' => '模型 $type（应改 $casts）',
            '/protected \$name\s*=\s*[\'"]/' => '模型 $name（应改 $table）',
        ];
        $issues = [];
        foreach ($this->phpFiles("$dir/src") as $f) {
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
        $composer = $dir . '/composer.json';
        if (is_file($composer) && str_contains((string) file_get_contents($composer), 'webman/think-orm')) {
            $issues[] = 'composer.json: webman/think-orm';
        }
        return ['issues' => $issues, 'note' => 'think-orm 残留清零'];
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
        $appDir = "$dir/src/app";
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
                        $bad[] = "返回类型应为 : void（当前 : $ret）";
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
        if (preg_match_all("~\$listeners\s*\[\s*['\"]([a-z][a-z0-9_.]*)['\"]\s*\]~i", $src, $m2)) {
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
}