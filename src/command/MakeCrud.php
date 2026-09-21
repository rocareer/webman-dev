<?php
/**
 * rocareer:make-crud — 用系统 CRUD 引擎快速建立标准模块（数据表 + 五件套 + 页面 + 菜单）
 *
 * 用法：
 *   php webman rocareer:make-crud --design=/path/design.json     # 按设计 JSON 生成
 *   php webman rocareer:make-crud --check --design=...           # 只做预检（落位/冲突/图标/字段），不落盘
 *   php webman rocareer:make-crud --demo                         # 打印示例设计 JSON（含 v3 target 落位块）
 *
 * 选项：
 *   --design=路径     简化设计 JSON（格式见 --demo；AI 可直接产出；v3 支持 target 落位目标块）
 *   --check           只做净化/校验/落位/冲突/图标/字段预检（等价 --dry-run 的体检报告，不写盘）
 *   --no-migration    不写幂等迁移文件（表已自行准备好时用）
 *   --force           目标文件已存在时仍覆盖（默认冲突即中止）
 *   --json            以结构化 JSON 回执输出（供 AI/脚本消费；成功=设计+文件+迁移+门禁）
 *   --dry-run         只做净化+校验+预览，不写盘不生成（配合 --json 供 AI 自校验）
 *   --migrate         生成后自动执行 php webman migrate:run 建表（target.ddl=migration-first 时管线自带此步）
 *   --menu=方式       菜单写入方式覆盖：migration（种子迁移，推荐）| now（即时写库）| skip（不写菜单）
 *   --no-gates        跳过生成后的宿主门禁（语法/图标/前端 typecheck；缺省「存在即跑」）
 *   --audit           生成后自动跑工程质量审计（rocareer:audit；缺省全工作区门禁）
 *   --audit-pkg=包名  配合 --audit：只审计指定包（如 webman-dev），不传则全工作区
 *
 * 流程：设计 JSON -> 净化/校验 -> 冲突预检 -> 渲染 database/migrations/<ts>_<table>_crud.php（PG 幂等建表）
 *       -> [target.ddl=migration-first 时先 migrate:run 建表] -> 调 radmin app\admin\service\CrudService
 *       生成 控制器/模型/验证器 + 前端 index.vue/popupForm.vue + 语言包 + 菜单；target 为插件形态时
 *       另出 路由块（plugin/<p>/config/route.php）+ 菜单种子迁移 + 后端 PHP 语言包
 *       -> 宿主门禁（bin/lint.php / check-menu-icons.mjs / web typecheck）-> [--audit] 审计。
 * 退出码：0 成功（含门禁全绿）｜1 失败（入参/校验/生成异常）｜2 待人工裁决（冲突清单/门禁红/--check 不合格）。
 * 注意：生成目标是「运行本命令的宿主工程」（app/ 与 web/src/ 相对宿主根）；
 *       需宿主安装 rocareer/radmin v5.1.0+（target 落位目标需 v5.10.0+，缺 class 时给出升级提示）。
 */

namespace Rocareer\WebmanDev\command;

use Rocareer\WebmanDev\support\CrudDesigner;
use Rocareer\WebmanDev\support\CrudDesignGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MakeCrud extends Command
{
    protected static $defaultName = 'rocareer:make-crud';

    protected static $defaultDescription = 'Scaffold a standard radmin CRUD module (migration + controller/model/validate + vue pages + menu) via radmin CrudService';

    /** 简化设计示例（--demo 输出，AI 直接参考生成） */
    protected const DEMO_JSON = <<<'JSON'
{
    "version": 3,
    "table": {
        "name": "memory_probe",
        "comment": "记忆探针管理",
        "quick_search": ["name"]
    },
    "fields": [
        { "name": "name", "comment": "名称", "design_type": "input", "length": 50, "required": true },
        { "name": "kind", "comment": "类型: 1=召回,2=写入", "design_type": "select", "default": "1" },
        { "name": "status", "comment": "状态: 0=禁用,1=启用", "design_type": "switch", "default": "1" },
        { "name": "remark", "comment": "备注", "design_type": "textarea" },
        { "name": "weigh", "comment": "排序", "design_type": "weigh" }
    ],
    "target": {
        "profile": "rolling-plugin",
        "plugin": "memory",
        "menu": { "icon": "fa fa-flask", "parent": "system", "parent_title": "系统运维" }
    }
}
JSON;

    /** v1 最小示例（不带 target = 历史 app/ 落位，兼容旧宿主） */
    protected const DEMO_JSON_MIN = <<<'JSON'
{
    "table": { "name": "cc_student", "comment": "学员管理", "quick_search": ["name", "mobile"] },
    "fields": [
        { "name": "name", "comment": "姓名", "design_type": "input", "length": 50, "required": true },
        { "name": "mobile", "comment": "手机号", "design_type": "input", "length": 20 },
        { "name": "status", "comment": "状态: 0=禁用,1=启用", "design_type": "switch", "default": "1" },
        { "name": "weigh", "comment": "排序", "design_type": "weigh" }
    ]
}
JSON;

    protected function configure(): void
    {
        $this->addOption('design', null, InputOption::VALUE_REQUIRED, 'Path to design JSON file');
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Preflight only: sanitize/validate/location/conflict/icon checks, no files written');
        $this->addOption('no-migration', null, InputOption::VALUE_NONE, 'Skip writing migration file (table already prepared)');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing target files');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output a structured JSON receipt (for AI/scripts)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sanitize+validate only, no files written');
        $this->addOption('migrate', null, InputOption::VALUE_NONE, 'Run migrate:run after generation (close the loop)');
        $this->addOption('menu', null, InputOption::VALUE_REQUIRED, 'Menu write mode override: migration|now|skip (needs design target block)');
        $this->addOption('no-gates', null, InputOption::VALUE_NONE, 'Skip host gates after generation (php -l / menu icons / web typecheck)');
        $this->addOption('audit', null, InputOption::VALUE_NONE, 'Run rocareer:audit after generation');
        $this->addOption('audit-pkg', null, InputOption::VALUE_REQUIRED, 'Limit --audit to one package (default: whole workspace)');
        $this->addOption('demo', null, InputOption::VALUE_NONE, 'Print example design JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');

        if ($input->getOption('demo')) {
            $io->writeln(self::DEMO_JSON);
            $io->writeln('');
            $io->writeln('// 不带 target = 历史 app/ 落位（兼容旧宿主）：');
            $io->writeln(self::DEMO_JSON_MIN);
            return self::SUCCESS;
        }

        $designPath = (string) $input->getOption('design');
        if ($designPath === '' || !is_file($designPath)) {
            return $this->fail($io, $asJson, 'no_design', '缺少设计文件：--design=/path/design.json（先跑 --demo 看示例格式）');
        }
        $design = json_decode((string) file_get_contents($designPath), true);
        if (!is_array($design)) {
            return $this->fail($io, $asJson, 'bad_json', '设计 JSON 解析失败：' . json_last_error_msg());
        }

        $generator = new CrudDesignGenerator();
        $checkOnly  = (bool) $input->getOption('check');
        $menuMode   = $input->getOption('menu') !== null ? (string) $input->getOption('menu') : null;
        $receipt = $generator->generate(
            $design,
            (bool) $input->getOption('no-migration'),
            (bool) $input->getOption('force'),
            (bool) $input->getOption('dry-run') || $checkOnly,
            $menuMode
        );

        if (empty($receipt['ok'])) {
            $code = ($receipt['error_code'] ?? '') === 'file_conflict' ? 2 : 1;
            return $this->fail($io, $asJson, (string) ($receipt['error_code'] ?? 'failed'),
                (string) ($receipt['message'] ?? '生成失败'), $receipt, $code);
        }

        $isDryRun = (bool) ($receipt['dry_run'] ?? false);

        // 预检报告（--check 与正式生成的 dry-run 都跑）：落位/冲突/图标可判性
        $receipt['checks'] = $generator->preflight($receipt);

        // 进度行在 --json 模式写 stderr，避免污染 stdout 的 JSON 回执
        $progress = function (string $msg) use ($io, $asJson, $output): void {
            $asJson ? $output->getErrorOutput()->writeln($msg) : $io->text($msg);
        };

        // --check：只出体检报告，不落盘
        if ($checkOnly) {
            $failed = $this->printChecks($io, $asJson, $receipt, $progress);
            if ($asJson) {
                return $this->ok($io, true, $receipt, $failed ? 2 : self::SUCCESS);
            }
            return $failed ? 2 : self::SUCCESS;
        }

        // 闭环编排：建表 → 门禁 → 审计（仅真正生成时执行）
        if (!$isDryRun && $input->getOption('migrate') && ($receipt['migration'] ?? '') !== '') {
            $progress('执行 migrate:run 建表……');
            $migrate = $generator->migrate();
            $receipt['migrate'] = $migrate;
            if ($migrate['ok']) {
                $receipt['next_step'] = '';
            }
        }
        $gatesRed = false;
        if (!$isDryRun && !empty($receipt['migrate_menu']) && empty($receipt['migrate_menu']['ok'])) {
            $gatesRed = true;
        }
        if (!$isDryRun && !$input->getOption('no-gates')) {
            $progress('执行宿主门禁（php -l / 菜单图标 / 前端 typecheck）……');
            $receipt['gates'] = $this->runGates($generator, (array) ($receipt['written_files'] ?? []));
            foreach ($receipt['gates'] as $gate) {
                if (($gate['ran'] ?? false) && empty($gate['ok'])) {
                    $gatesRed = true;
                }
            }
        }
        if (!$isDryRun && $input->getOption('audit')) {
            $progress('执行工程质量审计（rocareer:audit）……');
            $receipt['audit'] = $this->runAudit((string) $input->getOption('audit-pkg'));
            if (empty($receipt['audit']['pass'])) {
                $gatesRed = true;
            }
        }

        // 输出
        if ($asJson) {
            return $this->ok($io, true, $receipt, $gatesRed ? 2 : self::SUCCESS);
        }

        foreach (($receipt['warnings'] ?? []) as $warn) {
            $io->warning($warn);
        }
        if ($isDryRun) {
            $io->success('设计校验通过（dry-run，未写盘）');
            $io->writeln('  表：' . $receipt['table'] . '，表单字段 ' . count($receipt['form_fields'] ?? []) . ' 项');
            $this->printChecks($io, false, $receipt, $progress);
            return self::SUCCESS;
        }
        $io->success('标准模块生成完成');
        $io->writeln('  表：' . $receipt['table'] . '（comment=' . $receipt['comment'] . '）');
        $io->writeln('  落位：' . ($receipt['target']['profile'] ?? 'host-app') . ($receipt['target']['plugin'] ?? '' ? '（插件：' . $receipt['target']['plugin'] . '）' : '') . '，DDL 归属：' . ($receipt['ddl'] ?? 'engine'));
        if (($receipt['migration'] ?? '') !== '') {
            $io->writeln('  迁移：' . $receipt['migration'] . ($receipt['migration_reused'] ?? false ? '（同名迁移已存在，跳过写）' : ''));
        }
        if (($receipt['route_file'] ?? '') !== '') {
            $io->writeln('  路由块：' . $receipt['route_file'] . '（双变体注册，标记块幂等替换）');
        }
        if (($receipt['menu_migration'] ?? '') !== '') {
            $io->writeln('  菜单种子：' . $receipt['menu_migration'] . ($receipt['menu_migration_reused'] ?? false ? '（已存在，复用）' : '') . '，菜单 name=' . ($receipt['menu_name'] ?? ''));
        } else {
            $io->writeln('  菜单：' . ($receipt['menu'] ?? '') . '（含 index/add/edit/del/sortable 权限）');
        }
        foreach ((array) ($receipt['backend_langs'] ?? []) as $lang) {
            $io->writeln('  后端语言包：' . $lang);
        }
        if (!empty($receipt['crud_log_id'])) {
            $io->writeln('  生成记录：#' . $receipt['crud_log_id'] . '（后台 CRUD 代码生成页可回溯/删除）');
        }
        if (isset($receipt['migrate'])) {
            $io->writeln('  migrate:run：' . ($receipt['migrate']['ok'] ? '已建表' : '失败'));
            if (!$receipt['migrate']['ok']) {
                $io->writeln('    ' . mb_substr($receipt['migrate']['output'], 0, 500));
            }
        }
        foreach ((array) ($receipt['gates'] ?? []) as $gate) {
            $state = empty($gate['ran']) ? '跳过（宿主无此门禁）' : (empty($gate['ok']) ? '失败' : '通过');
            $io->writeln('  门禁·' . $gate['name'] . '：' . $state);
            if (!empty($gate['ran']) && empty($gate['ok'])) {
                $io->writeln('    ' . mb_substr((string) ($gate['output_tail'] ?? ''), -600));
            }
        }
        if (isset($receipt['audit'])) {
            $io->writeln('  审计：' . ($receipt['audit']['pass'] ? '全部通过' : '存在问题（见上）'));
        }
        if (!empty($receipt['next_step'])) {
            $io->writeln('  > ' . $receipt['next_step']);
        }
        if ($gatesRed) {
            $io->error('产物已落盘，但门禁/审计未全绿——请按上方输出修复后重跑门禁（本命令退出码 2）');
            return 2;
        }
        return self::SUCCESS;
    }

    /**
     * 打印预检报告（--check / dry-run）；返回是否不合格
     */
    protected function printChecks(SymfonyStyle $io, bool $asJson, array $receipt, callable $progress): bool
    {
        $failed = false;
        if ($asJson) {
            foreach ((array) ($receipt['checks'] ?? []) as $check) {
                if (empty($check['ok'])) {
                    $progress('[预检·' . $check['name'] . '] ' . ($check['message'] ?? ''));
                }
            }
            return false;
        }
        $io->section('预检报告');
        foreach ((array) ($receipt['checks'] ?? []) as $check) {
            $state = empty($check['ran']) ? '跳过' : (empty($check['ok']) ? 'FAIL' : 'OK');
            $io->writeln('  [' . $state . '] ' . $check['name'] . '：' . ($check['message'] ?? ''));
            if (empty($check['ok']) && !empty($check['ran'])) {
                $failed = true;
            }
        }
        $io->writeln('  落位目标文件 ' . count((array) ($receipt['target_files'] ?? [])) . ' 个：');
        foreach ((array) ($receipt['target_files'] ?? []) as $file) {
            $io->writeln('    - ' . $file);
        }
        if ($failed) {
            $io->error('预检不合格（见上 FAIL 项）');
        } else {
            $io->success('预检通过：可以去掉 --check 正式生成');
        }
        return $failed;
    }

    /**
     * 宿主门禁（存在即跑，缺则跳过并如实标注；--no-gates 可整体跳过）
     *
     * 与宿主既有门禁同源（bin/lint.php / check-menu-icons.mjs / web typecheck）；宿主无这些脚本时
     * 只跳过并如实标注，绝不臆造通过；--no-gates 可整体跳过。
     *
     * @param string[] $written 已落盘文件（相对宿主根）
     * @return array<int, array{name:string,ran:bool,ok:bool,output_tail?:string}>
     */
    protected function runGates(CrudDesignGenerator $generator, array $written): array
    {
        $base = $this->basePath();
        $php  = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $gates = [];

        $phpFiles = array_values(array_filter($written, static fn ($f) => str_ends_with((string) $f, '.php') && is_file($base . '/' . $f)));
        if ($phpFiles && is_file($base . '/bin/lint.php')) {
            $gates[] = $this->runProc('语法门禁（bin/lint.php）', [$php, 'bin/lint.php'], $phpFiles, $base);
        } elseif ($phpFiles) {
            $gates[] = ['name' => '语法门禁（php -l）', 'ran' => true, 'ok' => $this->lintEach($php, $phpFiles, $base)];
        }

        if (is_file($base . '/scripts/check-menu-icons.mjs')) {
            $gates[] = $this->runProc('菜单图标门禁（check-menu-icons.mjs）', ['node', 'scripts/check-menu-icons.mjs'], [], $base);
        }

        if (is_file($base . '/web/package.json') && is_dir($base . '/web/node_modules')) {
            $gates[] = $this->runProc('前端 typecheck（vue-tsc）', ['npm', '--prefix', 'web', 'run', 'typecheck'], [], $base, 600);
        }

        return $gates;
    }

    /** 子进程门禁（退出码 0 = 通过；输出尾部进回执） */
    protected function runProc(string $name, array $argv, array $tailArgs, string $cwd, int $timeout = 300): array
    {
        $cmd = implode(' ', array_map('escapeshellarg', array_merge($argv, $tailArgs)));
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, $cwd);
        if (!is_resource($proc)) {
            return ['name' => $name, 'ran' => true, 'ok' => false, 'output_tail' => '无法启动子进程：' . $cmd];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [
            'name'        => $name,
            'ran'         => true,
            'ok'          => $code === 0,
            'output_tail' => mb_substr(trim($out . ($err !== '' ? "\n" . $err : '')), -3000),
        ];
    }

    /** 无宿主 lint 工具时的兜底：逐个 php -l */
    protected function lintEach(string $php, array $files, string $base): bool
    {
        foreach ($files as $file) {
            $out = [];
            $code = 0;
            exec(escapeshellarg($php) . ' -l ' . escapeshellarg($base . '/' . $file) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * 运行工程质量审计（子进程 rocareer:audit），返回结构化结果
     *
     * @param string $pkg 限定的包名（空 = 全工作区默认包集）
     */
    protected function runAudit(string $pkg = ''): array
    {
        $base = $this->basePath();
        $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' webman rocareer:audit';
        if ($pkg !== '') {
            $cmd .= ' --pkg=' . escapeshellarg($pkg);
        }
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base);
        if (!is_resource($proc)) {
            return ['pass' => false, 'output' => '无法启动 rocareer:audit 子进程'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $output = trim($out . ($err !== '' ? "\n" . $err : ''));
        return ['pass' => $code === 0, 'output' => mb_substr($output, -4000)];
    }

    /**
     * 成功回执（--json 输出结构化 JSON，否则静默——人类摘要由调用方已打印）
     */
    protected function ok(SymfonyStyle $io, bool $asJson, array $receipt, int $code = self::SUCCESS): int
    {
        if ($asJson) {
            $io->writeln(json_unicode($receipt, JSON_PRETTY_PRINT));
        } elseif (!empty($receipt['dry_run'])) {
            $io->success('设计校验通过（dry-run，未写盘）');
            $io->writeln('  表：' . $receipt['table'] . '，表单字段 ' . count($receipt['form_fields']) . ' 项');
        }
        return $code;
    }

    /**
     * 失败回执（--json 输出结构化 JSON 错误，否则人类可读错误）
     *
     * @param int $code 退出码：1 失败（默认）｜2 待人工裁决（冲突清单/预检不合格/门禁红）
     */
    protected function fail(SymfonyStyle $io, bool $asJson, string $code, string $message, array $extra = [], int $exitCode = self::FAILURE): int
    {
        if ($asJson) {
            $io->writeln(json_unicode(
                array_merge(['ok' => false, 'error_code' => $code, 'message' => $message], $extra),
                JSON_PRETTY_PRINT
            ));
        } else {
            $io->error($message);
            if (!empty($extra['errors'])) {
                foreach ($extra['errors'] as $e) {
                    $io->writeln('  - [' . ($e['code'] ?? '') . '] ' . ($e['field'] ?? '') . '：' . ($e['message'] ?? ''));
                }
            }
            if (!empty($extra['conflicts'])) {
                foreach ($extra['conflicts'] as $conflict) {
                    $io->writeln('  - 冲突文件：' . $conflict);
                }
                $io->writeln('  处置：改表名/目录段，或确认可覆盖时加 --force');
            }
        }
        return $exitCode;
    }

    /**
     * 宿主根目录
     */
    protected function basePath(): string
    {
        return function_exists('base_path') ? (string) base_path() : (defined('BASE_PATH') ? BASE_PATH : (string) getcwd());
    }
}
