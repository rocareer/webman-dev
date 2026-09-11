<?php
/**
 * rocareer:make-crud — 用系统 CRUD 引擎快速建立标准模块（数据表 + 五件套 + 页面 + 菜单）
 *
 * 用法：
 *   php webman rocareer:make-crud --design=/path/design.json     # 按设计 JSON 生成
 *   php webman rocareer:make-crud --demo                         # 打印示例设计 JSON
 *
 * 选项：
 *   --design=路径     简化设计 JSON（格式见 --demo；AI 可直接产出；支持 version=2 增强契约）
 *   --no-migration    不写幂等迁移文件（表已自行准备好时用）
 *   --force           目标文件已存在时仍覆盖（默认冲突即中止）
 *   --json            以结构化 JSON 回执输出（供 AI/脚本消费；成功=设计+文件+迁移，失败=错误码）
 *   --dry-run         只做净化+校验+预览，不写盘不生成（配合 --json 供 AI 自校验）
 *   --migrate         生成后自动执行 php webman migrate:run 建表（闭环编排）
 *   --audit           生成后自动跑工程质量审计（rocareer:audit；缺省全工作区门禁）
 *   --audit-pkg=包名  配合 --audit：只审计指定包（如 webman-dev），不传则全工作区
 *
 * 流程：设计 JSON -> 净化(sanitize)/校验(validate) -> 渲染 database/migrations/<ts>_<table>_crud.php（PG 幂等建表）
 *       -> 调 radmin app\admin\service\CrudService（v5.1.0+，后台 /admin/crud 同一引擎）
 *       生成 控制器/模型/验证器 + 前端 index.vue/popupForm.vue + 语言包 + 菜单（幂等）
 *       -> [--migrate] 建表 -> [--audit] 审计。
 * 注意：生成目标是「运行本命令的宿主工程」（app/ 与 web/src/ 相对宿主根）；
 *       需宿主安装 rocareer/radmin v5.1.0+（缺 class 时给出升级提示）。
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
    "table": {
        "name": "cc_student",
        "comment": "学员管理",
        "quick_search": ["name", "mobile"]
    },
    "fields": [
        { "name": "name", "comment": "姓名", "design_type": "input", "length": 50, "required": true },
        { "name": "mobile", "comment": "手机号", "design_type": "input", "length": 20 },
        { "name": "level", "comment": "等级: 1=初级,2=中级,3=高级", "design_type": "select", "default": "1" },
        { "name": "status", "comment": "状态: 0=禁用,1=启用", "design_type": "switch", "default": "1" },
        { "name": "remark", "comment": "备注", "design_type": "textarea" },
        { "name": "weigh", "comment": "排序", "design_type": "weigh" }
    ]
}
JSON;

    protected function configure(): void
    {
        $this->addOption('design', null, InputOption::VALUE_REQUIRED, 'Path to design JSON file');
        $this->addOption('no-migration', null, InputOption::VALUE_NONE, 'Skip writing migration file (table already prepared)');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing target files');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output a structured JSON receipt (for AI/scripts)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sanitize+validate only, no files written');
        $this->addOption('migrate', null, InputOption::VALUE_NONE, 'Run migrate:run after generation (close the loop)');
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
        $receipt = $generator->generate(
            $design,
            (bool) $input->getOption('no-migration'),
            (bool) $input->getOption('force'),
            (bool) $input->getOption('dry-run')
        );

        if (empty($receipt['ok'])) {
            return $this->fail($io, $asJson, (string) ($receipt['error_code'] ?? 'failed'),
                (string) ($receipt['message'] ?? '生成失败'), $receipt);
        }

        $isDryRun = (bool) ($receipt['dry_run'] ?? false);
        // 进度行在 --json 模式写 stderr，避免污染 stdout 的 JSON 回执
        $progress = function (string $msg) use ($io, $asJson, $output): void {
            $asJson ? $output->getErrorOutput()->writeln($msg) : $io->text($msg);
        };

        // 闭环编排：建表 → 审计（仅真正生成时执行）
        if (!$isDryRun && $input->getOption('migrate') && ($receipt['migration'] ?? '') !== '') {
            $progress('执行 migrate:run 建表……');
            $migrate = $generator->migrate();
            $receipt['migrate'] = $migrate;
            if ($migrate['ok']) {
                $receipt['next_step'] = '';
            }
        }
        if (!$isDryRun && $input->getOption('audit')) {
            $progress('执行工程质量审计（rocareer:audit）……');
            $receipt['audit'] = $this->runAudit((string) $input->getOption('audit-pkg'));
        }

        // 输出
        if ($asJson) {
            return $this->ok($io, true, $receipt);
        }

        foreach (($receipt['warnings'] ?? []) as $warn) {
            $io->warning($warn);
        }
        if ($isDryRun) {
            $io->success('设计校验通过（dry-run，未写盘）');
            $io->writeln('  表：' . $receipt['table'] . '，表单字段 ' . count($receipt['form_fields'] ?? []) . ' 项');
            return self::SUCCESS;
        }
        $io->success('标准模块生成完成');
        $io->writeln('  表：' . $receipt['table'] . '（comment=' . $receipt['comment'] . '）');
        if (($receipt['migration'] ?? '') !== '') {
            $io->writeln('  迁移：' . $receipt['migration'] . ($receipt['migration_reused'] ?? false ? '（同名迁移已存在，跳过写）' : ''));
        }
        $io->writeln('  菜单：' . ($receipt['menu'] ?? '') . '（含 index/add/edit/del/sortable 权限，幂等种入）');
        if (!empty($receipt['crud_log_id'])) {
            $io->writeln('  生成记录：#' . $receipt['crud_log_id'] . '（后台 CRUD 代码生成页可回溯/删除）');
        }
        if (isset($receipt['migrate'])) {
            $io->writeln('  migrate:run：' . ($receipt['migrate']['ok'] ? '已建表' : '失败'));
            if (!$receipt['migrate']['ok']) {
                $io->writeln('    ' . mb_substr($receipt['migrate']['output'], 0, 500));
            }
        }
        if (isset($receipt['audit'])) {
            $io->writeln('  审计：' . ($receipt['audit']['pass'] ? '全部通过' : '存在问题（见上）'));
        }
        if (!empty($receipt['next_step'])) {
            $io->writeln('  > ' . $receipt['next_step']);
        }
        return self::SUCCESS;
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
    protected function ok(SymfonyStyle $io, bool $asJson, array $receipt): int
    {
        if ($asJson) {
            $io->writeln(json_unicode($receipt, JSON_PRETTY_PRINT));
        } elseif (!empty($receipt['dry_run'])) {
            $io->success('设计校验通过（dry-run，未写盘）');
            $io->writeln('  表：' . $receipt['table'] . '，表单字段 ' . count($receipt['form_fields']) . ' 项');
        }
        return self::SUCCESS;
    }

    /**
     * 失败回执（--json 输出结构化 JSON 错误，否则人类可读错误）
     */
    protected function fail(SymfonyStyle $io, bool $asJson, string $code, string $message, array $extra = []): int
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
        }
        return self::FAILURE;
    }

    /**
     * 宿主根目录
     */
    protected function basePath(): string
    {
        return function_exists('base_path') ? (string) base_path() : (defined('BASE_PATH') ? BASE_PATH : (string) getcwd());
    }
}
