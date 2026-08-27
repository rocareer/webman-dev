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
    ];

    /** 问题明细入库/返回上限（完整数量在 count） */
    public const MAX_ISSUES = 50;

    /** 默认审计包列表（与 rocareer:audit 命令一致；MCP quality_audit 工具缺省使用） */
    public const DEFAULT_PACKAGES = [
        'radmin', 'ai', 'memory', 'chat', 'agent', 'knowledge', 'asset',
        'OIDC', 'oidc-client', 'channel', 'channel-client', 'happ', 'webman-migration',
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
            if (str_contains($src, '?Response')) {
                $issues[] = "$rel: uses ?Response (should be : Response)";
            }
            if (str_contains($src, 'return null;') && str_contains($src, '?Response')) {
                $issues[] = "$rel: contains 'return null;'";
            }
            if (in_array('initialize', $info['methods'], true) && !str_contains($src, 'parent::initialize()')) {
                $issues[] = "$rel: initialize() missing parent::initialize()";
            }
            foreach ($info['methods'] as $method) {
                if ($method === 'initialize' || !$info['sigs'][$method]) {
                    continue;
                }
                if (!str_contains($info['sigs'][$method], ': Response')) {
                    $issues[] = "$rel::$method missing : Response";
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
        // 迁移中注册的按钮名（x/y/z 三段）
        $buttons = [];
        foreach ($this->phpFiles($migDir) as $mf) {
            $msrc = file_get_contents($mf);
            if (preg_match_all("~'([a-z_]+/[a-z_]+/[a-z_-]+)'~", $msrc, $m)) {
                foreach ($m[1] as $name) {
                    $buttons[$name] = true;
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
            foreach ($info['methods'] as $method) {
                if ($method === 'initialize') {
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
        ];
    }
}