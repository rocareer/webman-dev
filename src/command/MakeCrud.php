<?php
/**
 * rocareer:make-crud — 用系统 CRUD 引擎快速建立标准模块（数据表 + 五件套 + 页面 + 菜单）
 *
 * 用法：
 *   php webman rocareer:make-crud --design=/path/design.json     # 按设计 JSON 生成
 *   php webman rocareer:make-crud --demo                         # 打印示例设计 JSON
 *
 * 选项：
 *   --design=路径     简化设计 JSON（格式见 --demo；AI 可直接产出）
 *   --no-migration    不写幂等迁移文件（表已自行准备好时用）
 *   --force           目标文件已存在时仍覆盖（默认冲突即中止）
 *
 * 流程：设计 JSON -> 渲染 database/migrations/<ts>_<table>_crud.php（PG 幂等建表）
 *       -> 调 radmin app\admin\service\CrudService（v5.1.0+，后台 /admin/crud 同一引擎）
 *       生成 控制器/模型/验证器 + 前端 index.vue/popupForm.vue + 语言包 + 菜单（幂等）
 *       -> 提示执行 php webman migrate:run 建表（表结构真源=迁移，可追溯）。
 * 注意：生成目标是「运行本命令的宿主工程」（app/ 与 web/src/ 相对宿主根）；
 *       需宿主安装 rocareer/radmin v5.1.0+（缺 class 时给出升级提示）。
 */

namespace Rocareer\WebmanDev\command;

use Rocareer\WebmanDev\support\CrudDesigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

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
        $this->addOption('demo', null, InputOption::VALUE_NONE, 'Print example design JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('demo')) {
            $io->writeln(self::DEMO_JSON);
            return self::SUCCESS;
        }

        $designPath = (string) $input->getOption('design');
        if ($designPath === '' || !is_file($designPath)) {
            $io->error('缺少设计文件：--design=/path/design.json（先跑 --demo 看示例格式）');
            return self::FAILURE;
        }
        $design = json_decode((string) file_get_contents($designPath), true);
        if (!is_array($design)) {
            $io->error('设计 JSON 解析失败：' . json_last_error_msg());
            return self::FAILURE;
        }

        // 引擎可用性（radmin v5.1.0+ 才有 CrudService）
        if (!class_exists(\app\admin\service\CrudService::class)) {
            $io->error('宿主 rocareer/radmin 版本过低：CRUD 引擎 CrudService 不存在（需 v5.1.0+，请先 composer update rocareer/radmin）');
            return self::FAILURE;
        }

        try {
            $parsed = (new CrudDesigner())->parse($design);
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        $base = $this->basePath();
        $noMigration = (bool) $input->getOption('no-migration');

        // 1) 目标文件冲突预检（防覆盖已改代码；--force 跳过）——先于写迁移，失败零落盘
        if (!$input->getOption('force')) {
            $conflicts = $this->detectConflicts($base, $parsed);
            if ($conflicts) {
                $io->error('以下文件已存在（已生成过？用 --force 覆盖，或换表名/删旧 CRUD 记录）：');
                $io->listing($conflicts);
                return self::FAILURE;
            }
        }

        // 2) 幂等迁移文件落盘（默认；同名表迁移已存在则复用提示，不重复写）
        $migrationFile = '';
        if (!$noMigration) {
            $migrationDir = $base . '/database/migrations';
            $existing = glob($migrationDir . '/*_' . $parsed['table_name'] . '_crud.php');
            if ($existing) {
                $io->note('同名表迁移已存在：' . str_replace($base . '/', '', $existing[0]) . '（跳过写迁移；表已 migrate:run 则直接出代码）');
                $migrationFile = '';
            } else {
                $migrationFile = $migrationDir . '/' . $parsed['ts'] . '_' . $parsed['table_name'] . '_crud.php';
                $this->writeFile($migrationFile, $parsed['migration']);
            }
        }

        // 3) 引擎生成（type=update：表已存在（迁移建）则不动表只出代码；表不存在则由引擎按设计建表兜底）
        $io->text('调用 CRUD 引擎生成（radmin CrudService）……');
        $result = (new \app\admin\service\CrudService())->generate('update', $parsed['table'], $parsed['fields']);

        // 设计提示（字典缺失等，非阻断）
        foreach ($parsed['warnings'] ?? [] as $warn) {
            $io->warning($warn);
        }

        // 4) 摘要
        $io->success('标准模块生成完成');
        $io->writeln('  表：' . $parsed['table_name'] . '（comment=' . $parsed['table']['comment'] . '）');
        if ($migrationFile !== '') {
            $io->writeln('  迁移：' . str_replace($base . '/', '', $migrationFile));
            $io->writeln('  > 请执行 php webman migrate:run 建表（幂等可重复）；表就绪后重跑本命令即可补出代码（覆盖需 --force）');
        }
        $io->writeln('  菜单：/admin/' . $parsed['menu_name'] . '/index（含 index/add/edit/del/sortable 权限，幂等种入）');
        if (($result['crud_log'] ?? null)) {
            $log = $result['crud_log'];
            $io->writeln('  生成记录：#' . $log->id . '（后台 CRUD 代码生成页可回溯/删除）');
        }
        $io->writeln('  建议：编辑 index.vue/popupForm.vue 按需调列渲染（如 status 列 render=\'switch\'）后提交');
        return self::SUCCESS;
    }

    /**
     * 探测目标文件冲突（复用 CrudDesigner::targetFiles 同源推导）
     */
    protected function detectConflicts(string $base, array $parsed): array
    {
        $conflicts = [];
        foreach (CrudDesigner::targetFiles($parsed['table_name']) as $file) {
            if (is_file($base . '/' . $file)) {
                $conflicts[] = $file;
            }
        }
        return $conflicts;
    }

    /**
     * 宿主根目录
     */
    protected function basePath(): string
    {
        return function_exists('base_path') ? (string) base_path() : (defined('BASE_PATH') ? BASE_PATH : (string) getcwd());
    }

    /**
     * 写文件（目录自动创建）
     */
    protected function writeFile(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
    }
}
