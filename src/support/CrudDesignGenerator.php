<?php

namespace Rocareer\WebmanDev\support;

use Throwable;

/**
 * CRUD 设计出码执行器（FACTORY P1）
 *
 * 把「净化的设计 JSON」执行成落盘产物：写幂等迁移 → 冲突预检 → 调 radmin CrudService
 * 生成五件套/页面/语言包/菜单。CLI（rocareer:make-crud）与 AI 确认出码
 * （CrudDesignAgentService::confirm）共用本执行器，保证两条路径产物一致、单点维护。
 *
 * 与 CrudDesigner/CrudDesignService 的分工：
 *   - CrudDesigner::parse()       = 设计 → 引擎 payload + 迁移源码（纯内存，不落盘）
 *   - CrudDesignService           = 净化 / 结构化校验 / 反导出（不落盘）
 *   - CrudDesignGenerator         = 落盘执行（写迁移 + 调引擎），返回结构化回执
 *
 * 返回回执（数组）键：ok / error_code / message / dry_run / design_version / table /
 *   comment / menu / form_fields / column_fields / target_files / migration /
 *   migration_reused / crud_log_id / next_step / warnings / errors。
 */
class CrudDesignGenerator
{
    /**
     * 执行出码
     *
     * 管线（v3）：净化/校验 → 冲突预检（含路由文件/后端语言包等插件形态产物）→ 写建表迁移 →
     * 【迁移先行】migrate:run 建表 → 引擎出码（含路由块/菜单种子迁移/后端语言包）→ 回执。
     * 迁移先行（target.ddl=migration-first，插件形态缺省）：表必须由迁移建好再出码——迁移是
     * DDL 唯一真源，避免「引擎即时建表 + 另写迁移」导致 ra_migrations 台账与实际出处不符。
     *
     * @param array       $design   设计 JSON（建议先经 CrudDesignService::sanitize|validate）
     * @param bool        $noMigration 跳过写迁移（表已自行准备）
     * @param bool        $force       目标文件冲突时覆盖
     * @param bool        $dryRun      只校验预览不落盘
     * @param string|null $menu       菜单写入方式覆盖：migration（种子迁移）| now（即时写库）| skip（不写菜单）| null（按设计 JSON target）
     * @return array 结构化回执
     */
    public function generate(array $design, bool $noMigration = false, bool $force = false, bool $dryRun = false, ?string $menu = null): array
    {
        $svc = new CrudDesignService();
        $sanitized = $svc->sanitize($design);
        $errors = $svc->validate($sanitized['design']);
        $version = (int) ($sanitized['design']['version'] ?? 1);

        if ($errors) {
            return $this->fail('validation_failed', '设计校验未通过（' . count($errors) . ' 项）', [
                'errors' => $errors,
                'warnings' => $sanitized['warnings'],
            ]);
        }

        // 菜单写入方式覆盖（CLI --menu）：只对落位目标（target）生效——app/ 形态恒为即时写库
        $warnings0 = $sanitized['warnings'];
        if ($menu !== null && $menu !== '') {
            if (!is_array($sanitized['design']['target'] ?? null)) {
                $warnings0[] = '--menu 覆盖需设计 JSON 带 target 块（app/ 形态恒即时写库），本次忽略';
            } else {
                switch ($menu) {
                    case 'migration':
                        $sanitized['design']['target']['menu_migration'] = true;
                        break;
                    case 'now':
                        $sanitized['design']['target']['menu_migration'] = false;
                        break;
                    case 'skip':
                        $sanitized['design']['target']['menu'] = array_merge(
                            (array) ($sanitized['design']['target']['menu'] ?? []),
                            ['enabled' => false]
                        );
                        break;
                    default:
                        return $this->fail('bad_menu_mode', '--menu 取值非法：' . $menu . '（可选 migration|now|skip）');
                }
            }
        }

        try {
            $parsed = (new CrudDesigner())->parse($sanitized['design']);
        } catch (Throwable $e) {
            return $this->fail('parse_failed', $e->getMessage(), ['warnings' => $warnings0]);
        }
        $warnings = array_merge($warnings0, $parsed['warnings'] ?? []);
        $base = $this->basePath();
        // 落位目标归一：以 radmin Target 为唯一真源（补齐 ddl/menu_migration/header 等缺省值），
        // 使回执与引擎实际行为一致（插件形态缺省 = migration-first，不显式声明也生效）
        $targetRaw = is_array($parsed['target'] ?? null) ? $parsed['target'] : null;
        $target    = $this->normalizeTarget($targetRaw);
        $targetFiles = CrudDesigner::targetFiles($parsed['table_name'], $target);
        // 幂等 merge 的文件（路由块按标记块追加/替换、菜单种子迁移按名复用、后端语言包可覆盖）不算冲突
        $mergeFiles = array_filter([(string) ($target['route_file'] ?? '')]);
        $ddlFirst = $target && ($target['ddl'] ?? 'engine') === 'migration-first';

        $common = [
            'dry_run' => $dryRun,
            'design_version' => $version,
            'table' => $parsed['table_name'],
            'comment' => $parsed['table']['comment'],
            'menu' => '/admin/' . $parsed['menu_name'] . '/index',
            'menu_name' => $parsed['menu_name'],
            'target' => $target,
            'ddl' => $ddlFirst ? 'migration-first' : 'engine',
            'form_fields' => $parsed['table']['formFields'],
            'column_fields' => $parsed['table']['columnFields'],
            'target_files' => $targetFiles,
            'warnings' => $warnings,
        ];

        if ($dryRun) {
            return array_merge(['ok' => true], $common, ['next_step' => '去掉 --dry-run 执行落盘']);
        }

        // 引擎可用性（radmin v5.1.0+ 才有 CrudService；target 落位目标另需 v5.10.0+，由 CrudDesigner::parse 校验）
        if (!class_exists(\app\admin\service\CrudService::class)) {
            return $this->fail('radmin_too_old',
                '宿主 rocareer/radmin 版本过低：CRUD 引擎 CrudService 不存在（需 v5.1.0+）',
                ['warnings' => $warnings]);
        }

        // 冲突预检（先于写迁移，失败零落盘；幂等 merge 的路由文件不计入）
        if (!$force) {
            $conflicts = [];
            foreach ($targetFiles as $file) {
                if (in_array($file, $mergeFiles, true)) {
                    continue;
                }
                if (is_file($base . '/' . $file)) {
                    $conflicts[] = $file;
                }
            }
            if ($conflicts) {
                return $this->fail('file_conflict',
                    '目标文件已存在（已生成过/已改代码）：' . implode(', ', $conflicts),
                    ['conflicts' => $conflicts, 'warnings' => $warnings]);
            }
        }

        // 迁移落盘（同名表迁移已存在则复用，不重复写）
        $migrationFile = '';
        $migrationReused = false;
        if (!$noMigration) {
            $migrationDir = $base . '/database/migrations';
            $existing = glob($migrationDir . '/*_' . $parsed['table_name'] . '_crud.php');
            if ($existing) {
                $migrationReused = true;
                $migrationFile = str_replace($base . '/', '', $existing[0]);
            } else {
                $full = $migrationDir . '/' . $parsed['ts'] . '_' . $parsed['table_name'] . '_crud.php';
                $this->writeFile($full, $parsed['migration']);
                $migrationFile = str_replace($base . '/', '', $full);
            }
        }

        // 迁移先行：表先建好再出码（缺表时引擎 fail fast，本步骤保证正常路径一次成型）
        if ($ddlFirst && !$noMigration) {
            $migrate = $this->migrate();
            if (!$migrate['ok']) {
                return $this->fail('migrate_failed', '迁移先行失败：migrate:run 非 0 退出', [
                    'migration' => $migrationFile,
                    'migrate' => $migrate,
                    'warnings' => $warnings,
                ]);
            }
        }

        // 引擎生成（含插件形态的路由块/菜单种子迁移/后端语言包）
        try {
            $result = (new \app\admin\service\CrudService())->generate('update', $parsed['table'], $parsed['fields']);
        } catch (Throwable $e) {
            return $this->fail('generate_failed', 'CRUD 引擎生成失败：' . $e->getMessage(), [
                'migration' => $migrationFile,
                'warnings' => $warnings,
            ]);
        }

        $logId = ($result['crud_log'] ?? null) ? (int) $result['crud_log']->id : 0;
        $engineWarnings = (array) ($result['target_warnings'] ?? []);

        // 菜单种子迁移由引擎在出码阶段写入（晚于建表迁移），此处补跑一次 migrate:run 应用它——
        // 生成结束时模块应当「即可用」（菜单/权限已种入），而不是留一个 pending 迁移等人记得跑
        $migrateMenu = null;
        if (!$noMigration && !empty($result['menu_migration']) && empty($result['menu_migration_reused'])) {
            $migrateMenu = $this->migrate();
            if (empty($migrateMenu['ok'])) {
                $warnings[] = '菜单种子迁移已写入但未执行成功：' . mb_substr((string) ($migrateMenu['output'] ?? ''), -300);
            }
        }

        $written = [];
        foreach (array_merge(
            [$migrationFile],
            [(string) ($result['route_file'] ?? ''), (string) ($result['menu_migration'] ?? '')],
            $this->flattenPaths((array) ($result['files'] ?? [])),
            $this->flattenPaths((array) ($result['backend_langs'] ?? []))
        ) as $path) {
            $path = ltrim((string) $path, '/');
            if ($path !== '' && !in_array($path, $written, true)) {
                $written[] = $path;
            }
        }

        $nextStep = '';
        if ($ddlFirst && $noMigration) {
            $nextStep = '表须已存在（--no-migration + migration-first）：缺表时请先建表迁移';
        } elseif (!$ddlFirst && $migrationFile !== '' && !$migrationReused) {
            $nextStep = '迁移是 DDL 唯一真源：请执行 php webman migrate:run 建表（当前 target.ddl=engine，引擎生成时已直接建表）';
        }

        return array_merge(['ok' => true], $common, [
            'dry_run' => false,
            'migration' => $migrationFile,
            'migration_reused' => $migrationReused,
            'route_file' => (string) ($result['route_file'] ?? ''),
            'menu_migration' => (string) ($result['menu_migration'] ?? ''),
            'menu_migration_reused' => (bool) ($result['menu_migration_reused'] ?? false),
            'migrate_menu' => $migrateMenu,
            'backend_langs' => array_values((array) ($result['backend_langs'] ?? [])),
            'written_files' => $written,
            'crud_log_id' => $logId,
            'target_warnings' => $engineWarnings,
            'warnings' => array_values(array_unique(array_merge($warnings, $engineWarnings))),
            'next_step' => $nextStep,
        ]);
    }

    /**
     * 预检报告（--check / dry-run 用；只读，不落盘）
     *
     * 覆盖：落位目标可判性、目标文件冲突面、菜单图标实存性、引擎可用性、表结构现状（可判时）。
     * 图标校验直击历史事故（助手菜单写 FA5 的 fa-robot，宿主打包 FA4 → 侧栏空框，
     * 直到人眼发现）：fa fa-X 查宿主 font-awesome.css 的 `.fa-X:before`；el-icon-X 查
     * @element-plus/icons-vue；local-X 查 web/src/assets/icons/X.svg。
     * 资源缺失（未装依赖）时如实标 ran=false 跳过，绝不臆造通过。
     *
     * @param array $receipt generate() 的（dry-run）回执
     * @return array<int, array{name:string,ran:bool,ok:bool,message:string}>
     */
    public function preflight(array $receipt): array
    {
        $base = $this->basePath();
        $checks = [];
        $target = is_array($receipt['target'] ?? null) ? $receipt['target'] : null;

        // 1. 落位目标
        $checks[] = [
            'name'    => '落位目标',
            'ran'     => true,
            'ok'      => true,
            'message' => $target
                ? 'profile=' . ($target['profile'] ?? 'host-app')
                    . '，后端落 ' . (($target['profile'] ?? '') === 'rolling-plugin' ? 'plugin/' . ($target['plugin'] ?? '') . '/app/**' : 'app/**')
                    . '，DDL=' . ($receipt['ddl'] ?? 'engine')
                : '未给 target：按历史行为落 app/**（DDL 由引擎即时建表）',
        ];

        // 2. 引擎可用性
        $engineOk = class_exists(\app\admin\service\CrudService::class);
        $targetOk = !$target || class_exists(\app\admin\library\crud\Target::class);
        $checks[] = [
            'name'    => '引擎可用性',
            'ran'     => true,
            'ok'      => $engineOk && $targetOk,
            'message' => $engineOk
                ? ($targetOk ? 'radmin CrudService + Target 均可用' : '宿主 radmin 版本过低：target 落位需 v5.10.0+')
                : '宿主缺 radmin CrudService（需 v5.1.0+）',
        ];

        // 3. 文件冲突面（幂等 merge 的路由文件不计入）
        $mergeFiles = array_filter([(string) ($target['route_file'] ?? '')]);
        $conflicts = [];
        foreach ((array) ($receipt['target_files'] ?? []) as $file) {
            if (in_array($file, $mergeFiles, true)) {
                continue;
            }
            if (is_file($base . '/' . $file)) {
                $conflicts[] = $file;
            }
        }
        $checks[] = [
            'name'    => '文件冲突面',
            'ran'     => true,
            'ok'      => !$conflicts,
            'message' => $conflicts
                ? (count($conflicts) . ' 个目标文件已存在：' . implode(', ', array_slice($conflicts, 0, 3)) . (count($conflicts) > 3 ? ' …' : ''))
                : '无冲突（幂等 merge 的路由文件不计入；其余目标文件均不存在）',
        ];

        // 4. 菜单图标实存性
        $icon = (string) ($target['menu']['icon'] ?? '');
        if ($icon === '') {
            $checks[] = ['name' => '菜单图标', 'ran' => false, 'ok' => true, 'message' => '未指定图标（不校验）'];
        } else {
            $checks[] = $this->checkIcon($icon);
        }

        // 5. 表结构现状（DB 可连时判定）
        try {
            $tableName = \extend\ba\TableManager::tableName((string) $receipt['table'], true);
            $exists    = \extend\ba\TableManager::phinxAdapter(false)->hasTable($tableName);
            $expectExists = ($receipt['ddl'] ?? 'engine') === 'migration-first' && empty($receipt['dry_run']);
            $checks[] = [
                'name'    => '表结构现状',
                'ran'     => true,
                // 判定口径按「本次是否真的出码」：dry-run/--check 时表未建属预期（管线会先跑 migrate:run）
                'ok'      => $expectExists ? $exists : true,
                'message' => $tableName . ($exists ? ' 已存在' : ' 不存在')
                    . (($receipt['ddl'] ?? 'engine') === 'migration-first'
                        ? ($exists ? '（migration-first：就绪）' : '（migration-first：生成管线将先跑 migrate:run 建表）')
                        : '（engine：生成时由引擎建表）'),
            ];
        } catch (Throwable $e) {
            $checks[] = ['name' => '表结构现状', 'ran' => false, 'ok' => true, 'message' => 'DB 不可达，跳过：' . mb_substr($e->getMessage(), 0, 120)];
        }

        return $checks;
    }

    /**
     * 归一 target：优先走 radmin Target（唯一真源，补齐缺省值），不可用时退回原数组
     */
    protected function normalizeTarget(?array $target): ?array
    {
        if (!$target) {
            return null;
        }
        if (class_exists(\app\admin\library\crud\Target::class)) {
            try {
                $resolved = \app\admin\library\crud\Target::fromArray($target);
                if ($resolved) {
                    return $resolved->toArray();
                }
            } catch (Throwable $e) {
                return $target; // 非法值由 validate() 报错，这里不重复抛
            }
        }
        return $target;
    }

    /**
     * 图标实存性检查（宿主资源可判时；不可判则 ran=false 跳过）
     *
     * @return array{name:string,ran:bool,ok:bool,message:string}
     */
    protected function checkIcon(string $icon): array
    {
        $base = $this->basePath();
        if (str_starts_with($icon, 'fa fa-')) {
            $css = $base . '/web/node_modules/font-awesome/css/font-awesome.css';
            if (!is_file($css)) {
                return ['name' => '菜单图标', 'ran' => false, 'ok' => true, 'message' => $icon . '：宿主未见 font-awesome 资源，跳过实存校验（图标门禁会兜底）'];
            }
            $name = substr($icon, strlen('fa fa-'));
            $hit  = str_contains((string) file_get_contents($css), '.fa-' . $name . ':before');
            return [
                'name'    => '菜单图标',
                'ran'     => true,
                'ok'      => $hit,
                'message' => $icon . ($hit ? ' 在宿主打包的 Font Awesome 4.7 中存在' : ' 在宿主打包的 FA4 中无字形（会渲染成空框；换 FA4 名或 el-icon-*/local-*）'),
            ];
        }
        if (str_starts_with($icon, 'el-icon-')) {
            $bundle = $base . '/web/node_modules/@element-plus/icons-vue/dist/index.js';
            $name   = substr($icon, strlen('el-icon-'));
            if (!is_file($bundle)) {
                return ['name' => '菜单图标', 'ran' => false, 'ok' => true, 'message' => $icon . '：宿主未见 EP 图标集，跳过实存校验（图标门禁会兜底）'];
            }
            $hit = str_contains((string) file_get_contents($bundle), 'name: "' . $name . '"');
            return [
                'name'    => '菜单图标',
                'ran'     => true,
                'ok'      => $hit,
                'message' => $icon . ($hit ? ' 在 @element-plus/icons-vue 中存在' : ' 在 EP 图标集不存在（Icon 组件注册失败 → 图标缺失）'),
            ];
        }
        if (str_starts_with($icon, 'local-')) {
            $svg = $base . '/web/src/assets/icons/' . substr($icon, strlen('local-')) . '.svg';
            return [
                'name'    => '菜单图标',
                'ran'     => true,
                'ok'      => is_file($svg),
                'message' => $icon . (is_file($svg) ? ' 对应 svg 存在' : ' 对应 web/src/assets/icons/*.svg 不存在'),
            ];
        }
        return ['name' => '菜单图标', 'ran' => true, 'ok' => false, 'message' => $icon . '：未识别的图标形态（只支持 fa fa-* / el-icon-* / local-*）'];
    }

    /**
     * 运行迁移（闭环编排 / target.ddl=migration-first 用）
     *
     * 以子进程执行 `php webman migrate:run`（cwd = 宿主根），与手工执行完全等价；
     * 不进程内直调 Phinx（避免污染常驻进程状态）。仅 CLI 一次性上下文调用。
     *
     * @return array{ok: bool, output: string}
     */
    public function migrate(): array
    {
        $base = $this->basePath();
        $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' webman migrate:run';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, $base);
        if (!is_resource($proc)) {
            return ['ok' => false, 'output' => '无法启动 migrate:run 子进程'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $output = trim($out . ($err !== '' ? "\n" . $err : ''));
        return ['ok' => $code === 0, 'output' => $output];
    }

    protected function fail(string $code, string $message, array $extra = []): array
    {
        return array_merge(['ok' => false, 'error_code' => $code, 'message' => $message], $extra);
    }

    /**
     * 拍平路径结构（引擎回执里的 files 是 assoc，且 lang 为数组——逐层取值，避免数组转字符串）
     *
     * @return string[]
     */
    protected function flattenPaths(array $data): array
    {
        $out = [];
        foreach ($data as $value) {
            if (is_array($value)) {
                foreach ($this->flattenPaths($value) as $item) {
                    $out[] = $item;
                }
            } elseif (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    protected function basePath(): string
    {
        return function_exists('base_path') ? (string) base_path() : (defined('BASE_PATH') ? BASE_PATH : (string) getcwd());
    }

    protected function writeFile(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
    }
}
