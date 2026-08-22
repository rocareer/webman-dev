<?php
/**
 * rocareer:audit — rocareer 基础设施包代码规范审计
 *
 * 检查项：
 *   1. php -l 语法
 *   2. 控制器规范：继承 Backend、: Response 签名、无 ?Response/return null、initialize 调 parent
 *   3. 权限节点与 routePath 匹配（radmin 规则：routePath = strtolower(完整类名去掉 controller\
 *      后末两段 / 连接) + '/' + strtolower(方法名)；按钮 name 必须精确相等，全小写无连字符）
 *   4. 迁移时间戳查重
 *   5. 残留扫描（Test 脚手架死代码、TODO/FIXME）
 *   6. 版本同步（CHANGELOG vs demo/full 钉版）
 *
 * 用法：php webman rocareer:audit [--root=工作区根] [--pkg=ai]
 */

namespace Rocareer\WebmanDev\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Audit extends Command
{
    protected static $defaultName = 'rocareer:audit';

    protected static $defaultDescription = 'Audit rocareer infrastructure packages';

    protected const DEFAULT_PACKAGES = [
        'radmin', 'ai', 'memory', 'chat', 'agent', 'knowledge', 'asset',
        'OIDC', 'oidc-client', 'channel', 'channel-client', 'happ', 'webman-migration',
    ];

    protected int $failCount = 0;

    protected function configure()
    {
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'Workspace root');
        $this->addOption('pkg', null, InputOption::VALUE_REQUIRED, 'Audit single package only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->detectRoot($input->getOption('root'));
        if (!$root) {
            $output->writeln('<error>workspace root not found</error>');
            return self::FAILURE;
        }
        $output->writeln('<info>rocareer:audit @ ' . $root . '</info>');
        $pkgs = $input->getOption('pkg') ? [$input->getOption('pkg')] : self::DEFAULT_PACKAGES;
        foreach ($pkgs as $pkg) {
            if (!is_dir("$root/$pkg")) {
                $output->writeln("<comment>[SKIP]</comment> $pkg");
                continue;
            }
            $output->writeln('');
            $output->writeln('<options=bold>===== Package: ' . $pkg . ' =====</>');
            $this->auditPhpSyntax($root, $pkg, $output);
            $this->auditControllers($root, $pkg, $output);
            $this->auditPermissionNodes($root, $pkg, $output);
            $this->auditMigrations($root, $pkg, $output);
            $this->auditResidue($root, $pkg, $output);
            $this->auditVersion($root, $pkg, $output);
        }
        $output->writeln('');
        if ($this->failCount > 0) {
            $output->writeln("<comment>Audit finished with {$this->failCount} issue(s)</comment>");
            return self::FAILURE;
        }
        $output->writeln('<info>Audit passed: all packages clean</info>');
        return self::SUCCESS;
    }

    protected function detectRoot(?string $root): ?string
    {
        if ($root && is_dir("$root/radmin")) {
            return rtrim($root, '/');
        }
        $dir = getcwd() ?: '.';
        while (true) {
            if (is_dir("$dir/radmin") && is_dir("$dir/demo")) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

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

    /* ---------- 1. php -l ---------- */

    protected function auditPhpSyntax(string $root, string $pkg, OutputInterface $output): void
    {
        $files = $this->phpFiles("$root/$pkg");
        $bad = [];
        foreach ($files as $file) {
            $out = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
            if ($out && !str_contains($out, 'No syntax errors')) {
                $bad[] = basename($file);
            }
        }
        if ($bad) {
            $this->failCount += count($bad);
            $output->writeln('<fg=red>[FAIL]</fg=red> php -l: ' . implode(', ', $bad));
        } else {
            $output->writeln('<info>[PASS]</info> php -l (' . count($files) . ' files)');
        }
    }

    /* ---------- 2. 控制器规范 ---------- */

    protected function auditControllers(string $root, string $pkg, OutputInterface $output): void
    {
        $dir = "$root/$pkg/src/app/admin/controller";
        if (!is_dir($dir)) {
            $output->writeln('<info>[PASS]</info> controllers: none');
            return;
        }
        $issues = [];
        $count = 0;
        foreach ($this->phpFiles($dir) as $file) {
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
            if (str_contains($src, 'return null;')) {
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
        if ($issues) {
            $this->failCount += count($issues);
            $output->writeln('<fg=red>[FAIL]</fg=red> controllers:');
            foreach (array_slice($issues, 0, 15) as $i) {
                $output->writeln('       ' . $i);
            }
        } else {
            $output->writeln("<info>[PASS]</info> controllers ($count files, radmin style)");
        }
    }

    /* ---------- 3. 权限节点匹配 ---------- */

    protected function auditPermissionNodes(string $root, string $pkg, OutputInterface $output): void
    {
        $ctrlDir = "$root/$pkg/src/app/admin/controller";
        $migDir = "$root/$pkg/database/migrations";
        if (!is_dir($ctrlDir) || !is_dir($migDir)) {
            $output->writeln('<info>[PASS]</info> permission nodes: skipped');
            return;
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
        if ($issues) {
            $this->failCount += count($issues);
            $output->writeln('<fg=red>[FAIL]</fg=red> permission nodes:');
            foreach (array_slice($issues, 0, 20) as $i) {
                $output->writeln('       ' . $i);
            }
        } else {
            $output->writeln("<info>[PASS]</info> permission nodes ($methodCount methods match routePath)");
        }
    }

    /* ---------- 4. 迁移时间戳 ---------- */

    protected function auditMigrations(string $root, string $pkg, OutputInterface $output): void
    {
        $migDir = "$root/$pkg/database/migrations";
        if (!is_dir($migDir)) {
            $output->writeln('<info>[PASS]</info> migrations: none');
            return;
        }
        $seen = [];
        $dups = [];
        foreach (glob("$migDir/??????????????_*.php") ?: [] as $f) {
            $stamp = substr(basename($f), 0, 14);
            if (isset($seen[$stamp])) {
                $dups[] = $stamp;
            }
            $seen[$stamp] = basename($f);
        }
        if ($dups) {
            $this->failCount++;
            $output->writeln('<fg=red>[FAIL]</fg=red> migrations: duplicate timestamps ' . implode(',', array_unique($dups)));
        } else {
            $output->writeln('<info>[PASS]</info> migrations timestamps (' . count($seen) . ' files)');
        }
    }

    /* ---------- 5. 残留扫描 ---------- */

    protected function auditResidue(string $root, string $pkg, OutputInterface $output): void
    {
        $issues = [];
        foreach (['controller/Test.php', 'model/Test.php', 'validate/Test.php'] as $residue) {
            if (is_file("$root/$pkg/src/app/admin/$residue")) {
                $issues[] = "scaffold residue: src/app/admin/$residue";
            }
        }
        $todo = 0;
        foreach ($this->phpFiles("$root/$pkg/src") as $f) {
            $todo += preg_match_all('~(TODO|FIXME|HACK)~', file_get_contents($f));
        }
        if ($issues) {
            $this->failCount += count($issues);
            $output->writeln('<fg=red>[FAIL]</fg=red> residue: ' . implode('; ', $issues));
        } else {
            $output->writeln("<info>[PASS]</info> residue: none (TODO/FIXME count: $todo)");
        }
    }

    /* ---------- 6. 版本同步 ---------- */

    protected function auditVersion(string $root, string $pkg, OutputInterface $output): void
    {
        $changelog = "$root/$pkg/CHANGELOG.md";
        $demoJson = "$root/demo/full/composer.json";
        if (!is_file($changelog) || !is_file($demoJson)) {
            $output->writeln('<comment>[SKIP]</comment> version sync: changelog/demo json missing');
            return;
        }
        $head = file_get_contents($changelog);
        if (!preg_match('~^##s+([^s]+)~m', $head, $m)) {
            $output->writeln('<comment>[SKIP]</comment> version sync: no version heading');
            return;
        }
        $pkgVer = trim(str_replace(['[', ']', 'v', 'V'], '', $m[1]));
        $compkg = strtolower($pkg);
        $json = json_decode(file_get_contents($demoJson), true);
        $pin = '';
        foreach (($json['repositories'] ?? []) as $repo) {
            if (($repo['type'] ?? '') === 'path') {
                $v = ($repo['options']['versions'] ?? [])["rocareer/$compkg"] ?? '';
                if ($v) {
                    $pin = $v;
                    break;
                }
            }
        }
        if (!$pin) {
            $output->writeln('<comment>[SKIP]</comment> version sync: no demo pin for rocareer/' . $compkg);
            return;
        }
        $norm = fn($v) => strtolower(trim(str_replace(['v', 'V', '[', ']'], '', $v)));
        if ($norm($pkgVer) !== $norm($pin)) {
            $this->failCount++;
            $output->writeln("<fg=red>[FAIL]</fg=red> version sync: changelog $pkgVer != demo pin $pin");
        } else {
            $output->writeln("<info>[PASS]</info> version sync ($pkgVer)");
        }
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
            if ($t[0] === T_FUNCTION && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][1] === '&') {
                $sig = '';
                $name = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $v = $tokens[$j];
                    if ($v === '{') {
                        break;
                    }
                    if ($v === ';') {
                        break;
                    }
                    $sig .= is_array($v) ? $v[1] : $v;
                    if ($v === '(') {
                        break;
                    }
                }
                if (preg_match('~functions+(w+)~', $sig, $sm)) {
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
                            break;
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
