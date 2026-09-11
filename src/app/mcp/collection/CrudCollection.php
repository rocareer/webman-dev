<?php

namespace app\mcp\collection;

use app\admin\service\CrudDesignAgentService;
use app\admin\model\CrudLog;
use app\mcp\support\McpError;
use app\mcp\support\McpRegistry;
use app\mcp\support\McpToolCollectionInterface;
use Rocareer\WebmanDev\support\CrudDesignGenerator;
use Rocareer\WebmanDev\support\CrudDesignService;
use Rocareer\WebmanDev\support\CrudDesigner;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * 标准模块生成 MCP 工具集合（webman-dev 自动注册）
 *
 * 注册：本包 config/plugin/rocareer/webman-dev/event.php 监听 mcp.collections.register
 * （宿主装了 rocareer/mcp 即自动生效；未装 mcp 时本类不会被加载）。
 *
 * 工具（与 CLI rocareer:make-crud / 后台 /admin/crud 共用同一 radmin 引擎 CrudService）：
 *   - crud_generate         生成模块（迁移+五件套+页面+语言包+菜单），结构化回执
 *   - crud_design_suggest   AI 按需求产设计草稿（异步；需 rocareer/agent，人工确认才出码）
 *   - crud_design_export    从已有生成记录反推设计 JSON v2（AI 参考先例 / 可视化再编辑）
 *   - module_rollback       按 CRUD 记录回收已生成模块（删代码+菜单；可选删迁移/drop 表）
 *   - migrate_status        查询迁移台账状态（确认表是否就绪）
 *
 * risk_level 约定：safe=只读（export/migrate_status）；write=写盘/建表（generate/suggest/rollback，
 * 破坏性操作需显式 confirm=true）。子端点 /mcp/crud（scope 隔离）。
 */
class CrudCollection implements McpToolCollectionInterface
{
    public function key(): string
    {
        return 'crud';
    }

    public function title(): string
    {
        return '标准模块生成（webman-dev）';
    }

    public function endpoint(): ?string
    {
        return '/mcp/crud';
    }

    /**
     * MCP 工具定义
     */
    public function tools(): array
    {
        $designTypes = implode('/', array_keys(CrudDesigner::DESIGN_TYPES));
        return [
            [
                'name' => 'crud_generate',
                'description' => '标准 CRUD 模块生成（radmin 全家桶，复用后台 /admin/crud 同一引擎）：按设计 JSON（v1 简版或 v2 增强）'
                    . '生成 PG 幂等迁移文件（表结构真源，需 migrate:run 后生效）+ 控制器/模型/验证器五件套 + 前端 index.vue/popupForm.vue + 语言包 + 菜单权限。'
                    . 'v2 支持 options/remote(关联)/form/table/group/default_sort/form_layout。主键/时间戳自动注入；生成目标=当前宿主工程。'
                    . '返回结构化回执（设计版本/字段名单/目标文件/迁移路径/crud_log_id）。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'design' => ['type' => 'object', 'description' => '设计 JSON（v1 简版含 table.name/comment + fields[]；或 v2 增强，见 docs/radmin-dev-factory-plan.md）。与下面扁平参数二选一。'],
                        'table_name' => ['type' => 'string', 'description' => '表名（小写蛇形，下划线即目录层级：cc_student → 菜单 cc/student）'],
                        'table_comment' => ['type' => 'string', 'description' => '表中文名/菜单标题'],
                        'quick_search' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '快捷搜索字段（缺省=全部 input/textarea）'],
                        'fields' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string', 'description' => '字段名（小写蛇形）'],
                                    'comment' => ['type' => 'string', 'description' => '中文列名'],
                                    'design_type' => ['type' => 'string', 'description' => "控件类型：{$designTypes}"],
                                    'length' => ['type' => 'integer', 'description' => '长度'],
                                    'required' => ['type' => 'boolean', 'description' => 'NOT NULL'],
                                    'default' => ['type' => 'string', 'description' => '默认值'],
                                    'options' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'v2 选项 [{label,value}]'],
                                    'remote' => ['type' => 'object', 'description' => 'v2 关联 {table,pk,field,controller,relation_fields}'],
                                ],
                            ],
                        ],
                        'no_migration' => ['type' => 'boolean', 'description' => '跳过迁移文件（表已自行准备）'],
                        'force' => ['type' => 'boolean', 'description' => '目标文件已存在时仍覆盖（缺省 false 保护已改代码）'],
                        'run_migrate' => ['type' => 'boolean', 'description' => '生成后自动执行 migrate:run 建表'],
                    ],
                ],
            ],
            [
                'name' => 'crud_design_suggest',
                'description' => 'AI 按自然语言需求产出模块设计草稿（异步）：落草稿 + 投递队列 + LLM 生成设计 JSON'
                    . '（经净化/校验/一轮自修复）→ happ 推送。⚠️ 红线：LLM 只产草稿，必须人工审阅确认后才出码'
                    . '——人工在后台审阅后调用 crud_generate（本工具不提供自动确认入口，防 AI 绕过人工）。'
                    . '需宿主安装 rocareer/agent。返回 request_id 供查询草稿状态。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => '模块需求（自然语言，如「客户档案：名称/等级/负责人/年度金额」）'],
                        'table_name' => ['type' => 'string', 'description' => '期望表名（小写蛇形；可空由 AI 决定）'],
                        'agent_key' => ['type' => 'string', 'description' => '使用的智能体标识名（缺省 chat）'],
                    ],
                    'required' => ['prompt'],
                ],
            ],
            [
                'name' => 'crud_design_export',
                'description' => '从已有 CRUD 生成记录反向导出设计 JSON v2（含 options/remote 还原）：供 AI 参考同包先例、'
                    . '或在设计器中再编辑。只读。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'table_name' => ['type' => 'string', 'description' => '表名（不含前缀），如 erp_customer'],
                    ],
                    'required' => ['table_name'],
                ],
            ],
            [
                'name' => 'module_rollback',
                'description' => '按 CRUD 生成记录回收已生成模块：删除代码文件（控制器/模型/验证器/页面/语言包）+ 菜单权限。'
                    . '可选删除迁移文件（drop_migration）与 DROP 数据表（drop_table，破坏性，必须 confirm=true）。write 类操作。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'table_name' => ['type' => 'string', 'description' => '表名（不含前缀）'],
                        'drop_migration' => ['type' => 'boolean', 'description' => '同时删除对应迁移文件'],
                        'drop_table' => ['type' => 'boolean', 'description' => '同时 DROP 数据表（破坏性）'],
                        'confirm' => ['type' => 'boolean', 'description' => 'drop_table=true 时必须为 true'],
                    ],
                    'required' => ['table_name'],
                ],
            ],
            [
                'name' => 'migrate_status',
                'description' => '查询迁移台账状态（migrate:status --json）：确认目标表对应迁移是否已执行、表是否就绪。只读。',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ];
    }

    /**
     * 执行工具调用
     */
    public function call(string $name, array $arguments, array $context): array
    {
        $handlers = [
            'crud_generate' => 'doGenerate',
            'crud_design_suggest' => 'doSuggest',
            'crud_design_export' => 'doExport',
            'module_rollback' => 'doRollback',
            'migrate_status' => 'doMigrateStatus',
        ];
        if (!isset($handlers[$name])) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => "unknown tool: {$name}"]]];
        }
        try {
            return $this->{$handlers[$name]}($arguments);
        } catch (InvalidArgumentException $e) {
            return $this->fail(McpError::VALIDATION_ERROR, $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->fail(McpError::VALIDATION_ERROR, $e->getMessage());
        } catch (Throwable $e) {
            return $this->fail(McpError::INTERNAL_ERROR, '执行失败：' . $e->getMessage());
        }
    }

    /**
     * crud_generate：按设计生成模块（结构化回执）
     */
    protected function doGenerate(array $arguments): array
    {
        if (!class_exists(\app\admin\service\CrudService::class)) {
            throw new RuntimeException('宿主 rocareer/radmin 版本过低：CRUD 引擎 CrudService 不存在（需 v5.1.0+）');
        }
        $design = is_array($arguments['design'] ?? null) ? $arguments['design'] : $this->assembleDesign($arguments);
        $receipt = (new CrudDesignGenerator())->generate(
            $design,
            (bool) ($arguments['no_migration'] ?? false),
            (bool) ($arguments['force'] ?? false),
            false
        );
        if (empty($receipt['ok'])) {
            return $this->fail(
                (string) ($receipt['error_code'] ?? McpError::VALIDATION_ERROR),
                (string) ($receipt['message'] ?? '生成失败'),
                $receipt
            );
        }
        if (!empty($arguments['run_migrate']) && ($receipt['migration'] ?? '') !== '') {
            $receipt['migrate'] = (new CrudDesignGenerator())->migrate();
        }

        $lines = [
            '标准 CRUD 模块生成完成',
            '----------',
            '表：' . $receipt['table'] . '（' . $receipt['comment'] . '）',
        ];
        foreach (($receipt['warnings'] ?? []) as $w) {
            $lines[] = '注意：' . $w;
        }
        if (($receipt['migration'] ?? '') !== '') {
            $lines[] = '迁移：' . $receipt['migration'] . ($receipt['migration_reused'] ?? false ? '（同名已存在，复用）' : '');
            if (empty($receipt['migrate']['ok'])) {
                $lines[] = '> 需执行 php webman migrate:run 建表（幂等），随后重跑本工具补出代码（覆盖需 force=true）';
            }
        }
        if (isset($receipt['migrate'])) {
            $lines[] = 'migrate:run：' . ($receipt['migrate']['ok'] ? '已建表' : '失败');
        }
        $lines[] = '菜单：' . $receipt['menu'];
        $lines[] = '生成记录：#' . ($receipt['crud_log_id'] ?? 0);

        return [
            'result' => array_merge(['text' => implode("\n", $lines)], $receipt),
            'display_message' => '标准模块生成完成：' . $receipt['table'] . '（菜单 ' . $receipt['menu'] . '）',
        ];
    }

    /**
     * crud_design_suggest：AI 产设计草稿（异步）
     */
    protected function doSuggest(array $arguments): array
    {
        if (!CrudDesignAgentService::gatewayExists()) {
            throw new RuntimeException('AI 生成设计需要宿主安装 rocareer/agent');
        }
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt === '') {
            throw new InvalidArgumentException('prompt 必填（模块需求的自然语言描述）');
        }
        $adminId = (int) ($arguments['admin_id'] ?? ($GLOBALS['__mcp_admin_id'] ?? 0));
        $r = (new CrudDesignAgentService())->suggest(
            $prompt,
            (string) ($arguments['table_name'] ?? ''),
            $adminId,
            (string) ($arguments['agent_key'] ?? '')
        );
        return [
            'result' => [
                'text' => 'AI 设计草稿生成已受理（异步）：request_id=' . $r['request_id']
                    . "\n⚠️ LLM 只产草稿，需人工确认后才出码（后台设计器确认，或人工审阅后调用 crud_generate）。",
                'request_id' => $r['request_id'],
                'status' => $r['status'],
            ],
            'display_message' => 'AI 设计草稿已受理：' . $r['request_id'],
        ];
    }

    /**
     * crud_design_export：反导出设计 JSON
     */
    protected function doExport(array $arguments): array
    {
        $tableName = strtolower(trim((string) ($arguments['table_name'] ?? '')));
        if ($tableName === '') {
            throw new InvalidArgumentException('table_name 必填');
        }
        $design = (new CrudDesignService())->export($tableName);
        if ($design === null) {
            return $this->fail(McpError::VALIDATION_ERROR, "未找到表 {$tableName} 的成功生成记录（ra_admin_crud_log）", ['table' => $tableName]);
        }
        return [
            'result' => [
                'text' => '已导出设计 JSON v2（表 ' . $tableName . '，字段 ' . count($design['fields'] ?? []) . ' 项）',
                'design' => $design,
            ],
            'display_message' => '设计导出完成：' . $tableName,
        ];
    }

    /**
     * module_rollback：回收模块（删代码+菜单，可选删迁移/drop 表）
     */
    protected function doRollback(array $arguments): array
    {
        if (!class_exists(CrudLog::class)) {
            throw new RuntimeException('宿主缺少 radmin CrudLog 模型');
        }
        $tableName = strtolower(trim((string) ($arguments['table_name'] ?? '')));
        if ($tableName === '') {
            throw new InvalidArgumentException('table_name 必填');
        }
        $dropMigration = (bool) ($arguments['drop_migration'] ?? false);
        $dropTable = (bool) ($arguments['drop_table'] ?? false);
        if ($dropTable && empty($arguments['confirm'])) {
            throw new InvalidArgumentException('drop_table=true 属破坏性操作，必须显式传 confirm=true');
        }

        $log = CrudLog::where('table_name', $tableName)->orderBy('id', 'desc')->first();
        if (!$log) {
            throw new RuntimeException("未找到表 {$tableName} 的生成记录");
        }

        $base = $this->basePath();
        $removed = [];
        foreach (CrudDesigner::targetFiles($tableName) as $file) {
            $abs = $base . '/' . $file;
            if (is_file($abs)) {
                unlink($abs);
                $removed[] = $file;
            }
        }
        // 清理空目录（代码目录 + 前端页面/语言目录，逐一向上回退）
        foreach (['app/admin/controller', 'app/admin/model', 'app/admin/validate', 'web/src/views/backend', 'web/src/lang/backend/zh-cn', 'web/src/lang/backend/en'] as $dir) {
            $this->pruneEmptyDirs($base . '/' . $dir);
        }

        $migrationRemoved = '';
        if ($dropMigration) {
            $hits = glob($base . '/database/migrations/*_' . $tableName . '_crud.php') ?: [];
            foreach ($hits as $f) {
                unlink($f);
                $migrationRemoved = str_replace($base . '/', '', $f);
            }
        }

        $dropped = false;
        if ($dropTable) {
            try {
                \support\Db::statement('DROP TABLE IF EXISTS ' . getDbPrefix() . $tableName . ' CASCADE');
                $dropped = true;
            } catch (Throwable $e) {
                throw new RuntimeException('DROP TABLE 失败：' . $e->getMessage());
            }
        }

        // 菜单与记录状态
        $this->removeMenu($tableName);
        $log->update(['status' => 'delete']);

        $lines = [
            '模块回收完成：' . $tableName,
            '----------',
            '删除代码文件 ' . count($removed) . ' 个' . ($migrationRemoved !== '' ? '，删除迁移 ' . $migrationRemoved : ''),
            $dropped ? '已 DROP 数据表' : '数据表保留（如需删除传 drop_table=true, confirm=true）',
            '菜单权限已清理；生成记录已标记 delete',
        ];
        return [
            'result' => [
                'text' => implode("\n", $lines),
                'removed_files' => $removed,
                'migration_removed' => $migrationRemoved,
                'table_dropped' => $dropped,
            ],
            'display_message' => '模块回收完成：' . $tableName,
        ];
    }

    /**
     * migrate_status：迁移台账状态
     */
    protected function doMigrateStatus(array $arguments): array
    {
        $base = $this->basePath();
        $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' webman migrate:status --json';
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base);
        if (!is_resource($proc)) {
            throw new RuntimeException('无法启动 migrate:status 子进程');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $text = trim($out . ($err !== '' ? "\n" . $err : ''));
        return [
            'result' => ['text' => mb_substr($text, 0, 6000), 'exit_code' => $code],
            'display_message' => '迁移状态查询' . ($code === 0 ? '完成' : '异常（退出码 ' . $code . '）'),
        ];
    }

    /**
     * 由扁平参数组装简化设计（兼容键名缩写与完整两种输入）
     */
    protected function assembleDesign(array $arguments): array
    {
        $tableName = strtolower(trim((string) ($arguments['table_name'] ?? '')));
        $tableComment = trim((string) ($arguments['table_comment'] ?? ''));
        $quickSearch = array_values(array_filter(array_map('strval', (array) ($arguments['quick_search'] ?? [])), 'strlen'));
        $design = [
            'table' => ['name' => $tableName, 'comment' => $tableComment, 'quick_search' => $quickSearch],
            'fields' => [],
        ];
        foreach ((array) ($arguments['fields'] ?? []) as $f) {
            if (!is_array($f) || empty($f['name'])) {
                throw new InvalidArgumentException('fields 每项必须含 name/comment/design_type');
            }
            $item = [
                'name' => (string) $f['name'],
                'comment' => (string) ($f['comment'] ?? ''),
                'design_type' => (string) ($f['design_type'] ?? ''),
                'length' => isset($f['length']) ? (int) $f['length'] : null,
                'required' => !empty($f['required']),
                'default' => isset($f['default']) ? (string) $f['default'] : null,
                'primary_key' => !empty($f['primary_key']),
            ];
            if (!empty($f['options'])) {
                $item['options'] = $f['options'];
            }
            if (!empty($f['remote'])) {
                $item['remote'] = $f['remote'];
            }
            foreach (['form', 'table', 'group'] as $k) {
                if (!empty($f[$k])) {
                    $item[$k] = $f[$k];
                }
            }
            $design['fields'][] = $item;
        }
        return $design;
    }

    protected function pruneEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->pruneEmptyDirs($path);
                if (($this->countEntries($path)) === 0) {
                    @rmdir($path);
                }
            }
        }
    }

    protected function countEntries(string $dir): int
    {
        $n = 0;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $n++;
            }
        }
        return $n;
    }

    /**
     * 删除菜单（按表名推导 name=cc/student，含子按钮与空父目录）
     */
    protected function removeMenu(string $tableName): void
    {
        $menuName = str_replace('_', '/', $tableName);
        try {
            // radmin 菜单库真源：app\common\library\Menu（recursion=true 连带子按钮与空父目录）
            if (class_exists(\app\common\library\Menu::class)) {
                \app\common\library\Menu::delete($menuName, true);
            }
        } catch (Throwable $e) {
            // 菜单删除尽力而为（记录已标 delete，后台可再清）
        }
    }

    /**
     * 宿主根目录
     */
    protected function basePath(): string
    {
        return function_exists('base_path') ? (string) base_path() : (defined('BASE_PATH') ? BASE_PATH : (string) getcwd());
    }

    /**
     * 业务失败返回（McpRegistry 约定：error_code + display_message 透传，result 带详情）
     */
    protected function fail(string $code, string $message, array $extra = []): array
    {
        return [
            'error_code' => $code,
            'display_message' => mb_substr($message, 0, 60),
            'result' => array_merge(['error' => true, 'error_code' => $code, 'message' => $message], $extra),
        ];
    }

    /**
     * 事件监听：mcp.collections.register（mcp 存在才注册；重复注册幂等）
     */
    public static function onRegister(array $payload, string $eventName): void
    {
        if (class_exists(McpRegistry::class)) {
            McpRegistry::register(new self());
        }
    }
}
