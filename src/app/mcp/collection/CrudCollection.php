<?php

namespace app\mcp\collection;

use app\admin\service\CrudService;
use app\mcp\support\McpError;
use app\mcp\support\McpRegistry;
use app\mcp\support\McpToolCollectionInterface;
use Rocareer\WebmanDev\support\CrudDesigner;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * 标准 CRUD 模块生成 MCP 工具集合（webman-dev 自动注册）
 *
 * 注册：本包 config/plugin/rocareer/webman-dev/event.php 监听 mcp.collections.register
 * （宿主装了 rocareer/mcp 即自动生效；未装 mcp 时本类不会被加载）。
 *
 * 提供 1 个工具 crud_generate（与 CLI rocareer:make-crud / 后台 /admin/crud 共用同一
 * radmin 引擎 CrudService + 简化设计解析 CrudDesigner）：
 *   - 输入简化表设计（AI 友好，字段键极简）-> 渲染 PG 幂等迁移文件（可 migrate:run 追溯）
 *     + 生成 控制器/模型/验证器 + 前端 index.vue/popupForm.vue + 语言包 + 菜单（幂等）
 *   - 表结构真源=迁移文件；菜单即时幂等种入；目标=运行 MCP 的宿主工程代码树。
 *
 * 子端点：/mcp/crud（只服务本集合工具，scope 隔离）。
 *
 * 注意：引擎依赖 radmin v5.1.0+ 的 app\admin\service\CrudService；缺失时工具报错提示升级。
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
                'description' => '标准 CRUD 模块生成（radmin 全家桶，复用后台 /admin/crud 同一引擎）：按简化表设计生成 PG 幂等迁移文件'
                    . '（表结构真源，需 migrate:run 后生效）+ 控制器/模型/验证器五件套 + 前端 index.vue/popupForm.vue + 语言包 + 菜单权限'
                    . '（幂等种入）。字段注释可带字典「标题: 键=值,键=值」（如 状态: 0=禁用,1=启用）；主键/时间戳自动注入；'
                    . '生成目标=当前宿主工程 app/ 与 web/src/。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'table_name' => ['type' => 'string', 'description' => '表名（小写蛇形，下划线即目录层级：cc_student → 菜单 cc/student、页面 backend/cc/student/、控制器 cc/Student.php）'],
                        'table_comment' => ['type' => 'string', 'description' => '表中文名/菜单标题（如 学员管理）'],
                        'quick_search' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '快捷搜索字段（缺省=全部 input/textarea）'],
                        'fields' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string', 'description' => '字段名（小写蛇形）'],
                                    'comment' => ['type' => 'string', 'description' => '中文列名；可带字典：状态: 0=禁用,1=启用'],
                                    'design_type' => ['type' => 'string', 'description' => "控件类型：{$designTypes}"],
                                    'length' => ['type' => 'integer', 'description' => '长度（varchar 缺省 255 / int 缺省 10 / decimal 总长）'],
                                    'required' => ['type' => 'boolean', 'description' => 'NOT NULL（缺省 false）'],
                                    'default' => ['type' => 'string', 'description' => '默认值（switch 缺省 0、weigh 缺省 0）'],
                                ],
                            ],
                        ],
                        'no_migration' => ['type' => 'boolean', 'description' => '跳过迁移文件（表已自行准备）'],
                        'force' => ['type' => 'boolean', 'description' => '目标代码文件已存在时仍覆盖（缺省 false 保护已改代码，冲突即报错）'],
                    ],
                ],
            ],
        ];
    }

    /**
     * 执行工具调用
     */
    public function call(string $name, array $arguments, array $context): array
    {
        if ($name !== 'crud_generate') {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => "unknown tool: {$name}"]]];
        }

        // 引擎可用性（radmin v5.1.0+）
        if (!class_exists(CrudService::class)) {
            throw new RuntimeException('宿主 rocareer/radmin 版本过低：CRUD 引擎 CrudService 不存在（需 v5.1.0+，先升级 radmin 再调用）');
        }

        try {
            return $this->doGenerate($arguments);
        } catch (InvalidArgumentException $e) {
            // 设计/参数不合法：返回校验错误（可读文案），不落任何文件
            return $this->fail(McpError::VALIDATION_ERROR, $e->getMessage());
        } catch (RuntimeException $e) {
            // 业务拒绝（冲突/环境）：返回校验错误（可读文案）
            return $this->fail(McpError::VALIDATION_ERROR, $e->getMessage());
        } catch (Throwable $e) {
            // 引擎等未知异常：内部错误（详细原因进 Radmin 日志，McpRegistry 已记）
            return $this->fail(McpError::INTERNAL_ERROR, '生成执行失败：' . $e->getMessage());
        }
    }

    /**
     * 执行生成主流程（校验/冲突/迁移/引擎；异常上抛由 call() 分类归一）
     */
    protected function doGenerate(array $arguments): array
    {
        $tableName = strtolower(trim((string) ($arguments['table_name'] ?? '')));
        $tableComment = trim((string) ($arguments['table_comment'] ?? ''));
        $quickSearch = array_values(array_filter(array_map('strval', (array) ($arguments['quick_search'] ?? [])), 'strlen'));
        $noMigration = (bool) ($arguments['no_migration'] ?? false);

        // 简化设计组装（兼容键名缩写与完整两种输入）
        $design = [
            'table' => [
                'name' => $tableName,
                'comment' => $tableComment,
                'quick_search' => $quickSearch,
            ],
            'fields' => [],
        ];
        foreach ((array) ($arguments['fields'] ?? []) as $f) {
            if (!is_array($f) || empty($f['name'])) {
                throw new \InvalidArgumentException('fields 每项必须含 name/comment/design_type');
            }
            $design['fields'][] = [
                'name' => (string) $f['name'],
                'comment' => (string) ($f['comment'] ?? ''),
                'design_type' => (string) ($f['design_type'] ?? ''),
                'length' => isset($f['length']) ? (int) $f['length'] : null,
                'required' => !empty($f['required']),
                'default' => isset($f['default']) ? (string) $f['default'] : null,
                'primary_key' => !empty($f['primary_key']),
            ];
        }

        try {
            $parsed = (new CrudDesigner())->parse($design);
        } catch (Throwable $e) {
            throw new \InvalidArgumentException('设计不合法：' . $e->getMessage(), 0, $e);
        }

        // 冲突预检（防覆盖已改代码；force=true 跳过）——先于写迁移，失败零落盘
        $base = $this->basePath();
        $force = (bool) ($arguments['force'] ?? false);
        if (!$force) {
            $conflicts = [];
            foreach (CrudDesigner::targetFiles($parsed['table_name']) as $file) {
                if (is_file($base . '/' . $file)) {
                    $conflicts[] = $file;
                }
            }
            if ($conflicts) {
                throw new \RuntimeException('目标文件已存在（已生成过/已改代码）：' . implode(', ', $conflicts)
                    . '——如需覆盖重生成请传 force=true；或先删除旧文件/后台 CRUD 记录');
            }
        }

        // 1) 迁移文件落盘（默认；同名表迁移已存在则复用，不重复写）
        $migrationFile = '';
        if (!$noMigration) {
            $existing = glob($base . '/database/migrations/*_' . $parsed['table_name'] . '_crud.php');
            if (!$existing) {
                $migrationFile = $base . '/database/migrations/' . $parsed['ts'] . '_' . $parsed['table_name'] . '_crud.php';
                $this->writeFile($migrationFile, $parsed['migration']);
            }
        }

        // 2) 引擎生成（表已由迁移建 -> 只出代码；表不存在引擎按设计建表兜底）
        $result = (new CrudService())->generate('update', $parsed['table'], $parsed['fields']);

        $logId = ($result['crud_log'] ?? null) ? (int) $result['crud_log']->id : 0;
        $lines = [];
        $lines[] = '标准 CRUD 模块生成完成';
        $lines[] = '----------';
        foreach ($parsed['warnings'] ?? [] as $warn) {
            $lines[] = '注意：' . $warn;
        }
        $lines[] = '表：' . $parsed['table_name'] . '（' . $parsed['table']['comment'] . '）';
        if ($migrationFile !== '') {
            $lines[] = '迁移：' . str_replace($this->basePath() . '/', '', $migrationFile);
            $lines[] = '> 需执行 php webman migrate:run 建表（幂等可重复），随后重跑本工具可补出代码（覆盖需先删旧文件）';
        }
        $lines[] = '菜单：/admin/' . $parsed['menu_name'] . '/index（index/add/edit/del/sortable 权限，幂等）';
        $lines[] = '文件：控制器/模型/验证器 -> 宿主 app/admin/controller|model|validate/；前端 -> 宿主 web/src/views/backend/ + lang/';
        $lines[] = '生成记录：#' . $logId . '（后台 CRUD 代码生成页可删除/回溯）';

        return [
            'result' => [
                'text' => implode("\n", $lines),
                'table' => $parsed['table_name'],
                'comment' => $parsed['table']['comment'],
                'menu' => '/admin/' . $parsed['menu_name'] . '/index',
                'migration' => $migrationFile !== '' ? str_replace($this->basePath() . '/', '', $migrationFile) : '',
                'crud_log_id' => $logId,
                'generated_files' => $this->generatedFiles($parsed),
            ],
            'display_message' => '标准模块生成完成：' . $parsed['table_name'] . '（菜单 /admin/' . $parsed['menu_name'] . '/index）',
        ];
    }

    /**
     * 预期生成文件清单（摘要用；与 CrudDesigner::targetFiles 同源，路径按引擎惯例）
     */
    protected function generatedFiles(array $parsed): array
    {
        return CrudDesigner::targetFiles($parsed['table_name']);
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

    /**
     * 业务失败返回（McpRegistry 约定：error_code + display_message 透传，result 带详情）
     */
    protected function fail(string $code, string $message): array
    {
        return [
            'error_code' => $code,
            'display_message' => mb_substr($message, 0, 60),
            'result' => ['error' => true, 'error_code' => $code, 'message' => $message],
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
