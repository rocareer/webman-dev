<?php

namespace Rocareer\WebmanDev\support;

use InvalidArgumentException;

/**
 * CRUD 简化设计解析器（rocareer:make-crud / MCP crud_generate 共用）
 *
 * 把「AI 友好」的简化设计 JSON 转换为 radmin CRUD 引擎（app\admin\service\CrudService）
 * 所需的完整 payload（table/fields 键同后台 /admin/crud 页面），并渲染可追溯的
 * PG 幂等迁移文件（webman-migration / Phinx 风格，表结构走迁移而非引擎即时 DDL）。
 *
 * 简化设计格式（JSON）：
 * {
 *   "table": { "name": "cc_student", "comment": "学员管理",
 *              "module": "cc",                      // 可选：代码落 <module> 目录
 *              "quick_search": ["name", "mobile"] } // 可选：搜索字段（缺省=全部 input/textarea）
 *   "fields": [
 *     { "name": "name", "comment": "姓名", "design_type": "input", "length": 50, "required": true },
 *     { "name": "status", "comment": "状态: 0=禁用,1=启用", "design_type": "switch", "default": "1" },
 *     ...
 *   ]
 * }
 *
 * 字段键（均可选）：name/comment 必填；design_type 必填（白名单见 DESIGN_TYPES）；
 * length（input/select 等 varchar 长度，缺省 255）；required（NOT NULL）；
 * default（INPUT 默认值，switch/weigh 默认按惯例）；primary_key（设为主键）。
 *
 * 字典/选项编码在 comment（radmin CRUD 惯例）：「标题: 键=值,键=值」——
 * select/radio/checkbox/selects 自动从 comment 提取枚举值并生成语言包，如
 * "状态: 0=禁用,1=启用"、"难度: easy=简单,hard=困难"。
 */
class CrudDesigner
{
    /**
     * 支持的简化 design_type -> radmin designType 映射（白名单，其余明确报错）
     */
    public const DESIGN_TYPES = [
        'input'     => 'input',
        'textarea'  => 'textarea',
        'editor'    => 'editor',
        'switch'    => 'switch',
        'select'    => 'select',
        'radio'     => 'radio',
        'selects'   => 'selects',
        'checkbox'  => 'checkbox',
        'number'    => 'number',
        'float'     => 'number',
        'datetime'  => 'datetime',
        'date'      => 'date',
        'image'     => 'image',
        'images'    => 'images',
        'file'      => 'file',
        'files'     => 'files',
        'weigh'     => 'weigh',
    ];

    /**
     * 表/模块目录名合法性（防路径穿越/注入）
     */
    protected const NAME_RULE = '/^[a-z][a-z0-9_]*$/';

    /**
     * 解析简化设计
     *
     * @param array $design 简化设计（JSON 解码 assoc）
     * @return array{table: array, fields: array, migration: ?string, ts: string, menu_name: string, table_name: string}
     * @throws InvalidArgumentException 设计不合法
     */
    public function parse(array $design): array
    {
        if (empty($design['table']) || empty($design['fields']) || !is_array($design['fields'])) {
            throw new InvalidArgumentException('设计 JSON 必须包含 table 与 fields 数组（可用 --demo 查看示例）');
        }
        $t = $design['table'];
        $name = strtolower(trim((string) ($t['name'] ?? '')));
        $comment = trim((string) ($t['comment'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('table.name 必填（小写蛇形表名，如 cc_student）');
        }
        if (!preg_match(self::NAME_RULE, $name)) {
            throw new InvalidArgumentException('table.name 不合法（仅小写字母/数字/下划线，如 cc_student）');
        }
        if ($comment === '') {
            throw new InvalidArgumentException('table.comment 必填（中文表名/菜单标题，如 学员管理）');
        }
        // 模块段（代码目录层级）白名单
        $module = strtolower(trim((string) ($t['module'] ?? '')));
        if ($module !== '' && !preg_match(self::NAME_RULE, $module)) {
            throw new InvalidArgumentException('table.module 不合法（仅小写字母/数字/下划线）');
        }

        // ---- 1. 字段解析 ----
        $fields = [];
        $hasPk = false;
        foreach ($design['fields'] as $i => $f) {
            $fields[] = $this->parseField($f, $i + 1, $hasPk);
        }
        // 自动补 id 主键（惯例：bigint unsigned identity）
        if (!$hasPk) {
            array_unshift($fields, [
                'name' => 'id', 'comment' => 'ID', 'designType' => 'pk',
                'type' => 'bigint', 'length' => 20, 'precision' => 0,
                'default' => '', 'defaultType' => 'NONE', 'null' => false,
                'primaryKey' => true, 'unsigned' => true, 'autoIncrement' => true,
                'table' => [], 'form' => [], 'formBuildExclude' => true, 'tableBuildExclude' => true,
            ]);
        }
        // 自动补 create_time/update_time（全家桶 BaseModel 惯例：bigint 整型时间戳，列表可见）
        if (!$this->fieldExists($fields, 'create_time')) {
            $fields[] = $this->timestampField('create_time');
        }
        if (!$this->fieldExists($fields, 'update_time')) {
            $fields[] = $this->timestampField('update_time');
        }

        // ---- 2. 业务字段名单（列表/表单缺省 = 除 id/时间戳外全部，遵循设计顺序） ----
        $businessNames = [];
        foreach ($fields as $f) {
            if (in_array($f['name'], ['id', 'create_time', 'update_time'], true)) {
                continue;
            }
            $businessNames[] = $f['name'];
        }
        // 系统字段（id/时间戳）也允许显式入选列表/搜索
        $allowedNames = array_merge($businessNames, ['id', 'create_time', 'update_time']);
        $quickSearch = $this->pickList($t['quick_search'] ?? [], 'quick_search', $allowedNames);
        if (!$quickSearch) {
            // 缺省：input/textarea 类字段全部进快捷搜索
            $quickSearch = [];
            foreach ($fields as $f) {
                if (!in_array($f['name'], ['id', 'create_time', 'update_time'], true)
                    && in_array($f['designType'], ['input', 'textarea', 'editor'], true)) {
                    $quickSearch[] = $f['name'];
                }
            }
        }
        // 空数组会由引擎自动补主键，业务表无搜索字段可接受
        $hasForm = array_key_exists('form_fields', $t);
        $hasColumn = array_key_exists('column_fields', $t);
        $formFields = $hasForm ? $this->pickList($t['form_fields'] ?? [], 'form_fields', $allowedNames) : $businessNames;
        $columnFields = $hasColumn ? $this->pickList($t['column_fields'] ?? [], 'column_fields', $allowedNames) : $businessNames;
        // 列表补 id 列（后台惯例首列 ID）
        if (!in_array('id', $columnFields, true)) {
            array_unshift($columnFields, 'id');
        }

        // ---- 3. 代码落盘位置（file 键留空 = 引擎按表名自动推导到 app/admin/controller 等） ----
        $tablePayload = [
            'name' => $name,
            'comment' => $comment,
            'rebuild' => 'No',
            'databaseConnection' => '',
            'quickSearchField' => $quickSearch,
            'formFields' => $formFields,
            'columnFields' => $columnFields,
            'defaultSortField' => 'id',
            'defaultSortType' => 'desc',
            'isCommonModel' => false,
        ];
        if ($module !== '') {
            // 模块目录：app/admin/controller/<module>/<Name>.php + web/src/views/backend/<module>/<name>/
            $tablePayload['controllerFile'] = "app/admin/controller/{$module}/" . self::camel($name) . '.php';
            $tablePayload['modelFile'] = "app/admin/model/" . self::camel($name) . '.php';
            $tablePayload['validateFile'] = "app/admin/validate/" . self::camel($name) . '.php';
            $tablePayload['webViewsDir'] = "web/src/views/backend/{$module}/{$name}";
        }
        // 菜单名 = 引擎 getMenuName（module 段 + 末段）
        $menuName = ($module !== '' ? "{$module}/{$name}" : $name);

        // ---- 4. 迁移文件渲染（PG 幂等；表结构先迁移建好，引擎只出代码） ----
        $ts = $this->nextTs($name);
        $migration = $this->renderMigration($ts, $name, $comment, $fields);

        return [
            'table' => $tablePayload,
            'fields' => $fields,
            'migration' => $migration,
            'ts' => $ts,
            'menu_name' => $menuName,
            'table_name' => $name,
            'module' => $module,
        ];
    }

    /**
     * 解析单个字段（简化键 -> 引擎 payload 全键）
     */
    protected function parseField(array $f, int $index, bool &$hasPk): array
    {
        $name = strtolower(trim((string) ($f['name'] ?? '')));
        $comment = trim((string) ($f['comment'] ?? ''));
        if ($name === '' || !preg_match(self::NAME_RULE, $name)) {
            throw new InvalidArgumentException("第 {$index} 个字段 name 不合法（仅小写字母/数字/下划线）");
        }
        if ($comment === '') {
            throw new InvalidArgumentException("字段 {$name} 的 comment 必填（中文列名；字典可写「标题: 键=值,键=值」）");
        }
        $dt = strtolower(trim((string) ($f['design_type'] ?? '')));
        if (!isset(self::DESIGN_TYPES[$dt])) {
            $supported = implode('/', array_keys(self::DESIGN_TYPES));
            throw new InvalidArgumentException("字段 {$name} 的 design_type 不支持：{$dt}（支持：{$supported}）");
        }
        $designType = self::DESIGN_TYPES[$dt];
        $required = !empty($f['required']);
        $default = isset($f['default']) && $f['default'] !== '' ? (string) $f['default'] : null;

        // 字典枚举值（select/radio/checkbox/selects 从 comment 提取）
        $dictValues = [];
        if (in_array($dt, ['select', 'radio', 'checkbox', 'selects'], true)) {
            $dictValues = $this->extractDict($comment, $name);
        }

        // 类型默认矩阵（对齐后台设计器 fieldData；显式赋值防 += 不覆盖初始默认键）
        $field = [
            'name' => $name,
            'comment' => $comment,
            'designType' => $designType,
            'length' => (int) ($f['length'] ?? 0),
            'precision' => 0,
            'default' => '',
            'defaultType' => 'NULL',
            'null' => true,
            'primaryKey' => false,
            'unsigned' => false,
            'autoIncrement' => false,
            'table' => [],
            'form' => [],
        ];
        $length = (int) ($f['length'] ?? 0);
        switch ($dt) {
            case 'input':
                $field['type'] = 'varchar';
                $field['defaultType'] = 'EMPTY STRING';
                $field['null'] = false;
                $field['length'] = $length ?: 255;
                break;
            case 'textarea':
                $field['type'] = 'text';
                $field['null'] = false;
                $field['defaultType'] = 'EMPTY STRING';
                break;
            case 'editor':
                $field['type'] = 'text';
                break;
            case 'switch':
                $field['type'] = 'tinyint';
                $field['unsigned'] = true;
                $field['null'] = false;
                $field['defaultType'] = 'INPUT';
                $field['default'] = $default ?? '0';
                break;
            case 'number':
                $field['type'] = 'int';
                $field['length'] = $length ?: 10;
                break;
            case 'float':
                $field['type'] = 'decimal';
                $field['length'] = $length ?: 10;
                $field['precision'] = 2;
                break;
            case 'datetime':
                $field['type'] = 'bigint';
                $field['unsigned'] = true;
                $field['length'] = $length ?: 16;
                break;
            case 'date':
                $field['type'] = 'date';
                break;
            case 'image':
            case 'file':
                $field['type'] = 'varchar';
                $field['defaultType'] = 'EMPTY STRING';
                $field['null'] = false;
                $field['length'] = $length ?: 255;
                break;
            case 'images':
            case 'files':
                // 多图/多文件：varchar(1500) 存逗号串，引擎自动生成存取器（dtStringToArray）
                $field['type'] = 'varchar';
                $field['defaultType'] = 'EMPTY STRING';
                $field['null'] = false;
                $field['length'] = $length ?: 1500;
                break;
            case 'select':
            case 'radio':
                $field['type'] = 'varchar';
                $field['null'] = false;
                $field['defaultType'] = 'EMPTY STRING';
                $field['length'] = $length ?: 50;
                break;
            case 'selects':
            case 'checkbox':
                // 多选：text 存 JSON 数组（引擎 dtStringToArray 存取器 json 化）
                $field['type'] = 'text';
                $field['null'] = false;
                $field['defaultType'] = 'EMPTY STRING';
                break;
            case 'weigh':
                $field['type'] = 'int';
                $field['unsigned'] = true;
                $field['null'] = false;
                $field['defaultType'] = 'INPUT';
                $field['default'] = $default ?? '0';
                break;
        }
        // 默认值（用户显式 default 非空：除主键（NONE）外一律 INPUT 落地）
        if ($default !== null && $default !== '' && $field['defaultType'] !== 'NONE') {
            $field['default'] = $default;
            if ($field['defaultType'] !== 'INPUT') {
                $field['defaultType'] = 'INPUT';
            }
        }
        // 必填 -> NOT NULL
        if ($required) {
            $field['null'] = false;
            if ($field['defaultType'] === 'NULL') {
                $field['defaultType'] = 'NONE';
            }
        }
        // 显式主键（如已有主键再标记则报错）
        if (!empty($f['primary_key'])) {
            if ($hasPk) {
                throw new InvalidArgumentException("字段 {$name}：主键只能有一个（id 或显式 primary_key）");
            }
            $hasPk = true;
            $field['primaryKey'] = true;
            $field['null'] = false;
            $field['autoIncrement'] = true;
            $field['unsigned'] = true;
            if ($field['defaultType'] === 'INPUT') {
                // 主键不允许默认值
                $field['default'] = '';
                $field['defaultType'] = 'NONE';
            }
        }
        // select 系枚举值（varchar 存键值，字典由 comment 驱动无需 dataType）
        return $field;
    }

    /**
     * 提取 comment 字典（标题: 键=值,键=值），返回键列表（校验用）
     */
    protected function extractDict(string $comment, string $fieldName): array
    {
        $comment = str_replace(['，', '：'], [',', ':'], $comment);
        if (stripos($comment, ':') === false || stripos($comment, ',') === false || stripos($comment, '=') === false) {
            return [];
        }
        [, $item] = explode(':', $comment, 2);
        $values = [];
        foreach (explode(',', $item) as $v) {
            $valArr = explode('=', $v, 2);
            if (count($valArr) == 2 && trim($valArr[0]) !== '') {
                $values[] = trim($valArr[0]);
            }
        }
        return $values;
    }

    /**
     * 时间戳字段（bigint，可为空，不建表单；列表可见由调用方控制）
     */
    protected function timestampField(string $name): array
    {
        return [
            'name' => $name, 'comment' => $name === 'create_time' ? '创建时间' : '更新时间',
            'designType' => 'timestamp', 'type' => 'bigint', 'length' => 16, 'precision' => 0,
            'default' => '', 'defaultType' => 'NULL', 'null' => true,
            'primaryKey' => false, 'unsigned' => true, 'autoIncrement' => false,
            'table' => [], 'form' => [], 'formBuildExclude' => true,
        ];
    }

    protected function fieldExists(array $fields, string $name): bool
    {
        foreach ($fields as $f) {
            if ($f['name'] === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * 取值列表（白名单校验：必须都是已声明字段）
     */
    protected function pickList($list, string $key, array $allowed = []): array
    {
        $out = [];
        foreach ((array) $list as $item) {
            $item = strtolower(trim((string) $item));
            if ($item === '') {
                continue;
            }
            if ($allowed && !in_array($item, $allowed, true)) {
                throw new InvalidArgumentException("table.{$key} 含未声明字段：{$item}");
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * 下一个迁移时间戳（防与已存在迁移文件撞号；database/migrations 目录不存在返回当前时间）
     */
    protected function nextTs(string $table): string
    {
        $dir = $this->migrationDir();
        $ts = date('YmdHis');
        if (is_dir($dir)) {
            $exists = [];
            foreach (glob($dir . '/*_*.php') ?: [] as $file) {
                if (preg_match('/^(\d{14})_/', basename($file), $m)) {
                    $exists[$m[1]] = true;
                }
            }
            while (isset($exists[$ts])) {
                $ts = date('YmdHis', strtotime($ts) + 1);
            }
        }
        return $ts;
    }

    /**
     * 渲染 PG 幂等迁移文件（Phinx Table API + hasTable 守卫；表结构真源=迁移，可 migrate:run 追溯）
     */
    protected function renderMigration(string $ts, string $table, string $comment, array $fields): string
    {
        $className = self::camel($table) . 'Crud';
        $commentSafe = str_replace(['*/', '/*', '<?', '?>', '`'], '', $comment);
        $commentSafe = trim(preg_replace('/[\r\n\t]+/', ' ', $commentSafe));

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = " * {$commentSafe}（rocareer:make-crud 自动生成的标准模块表）";
        $lines[] = ' *';
        $lines[] = ' * 幂等：hasTable 守卫，重复执行安全（migrate:run 可追溯）；菜单/代码由生成器即时落库落盘。';
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'use Phinx\\Migration\\AbstractMigration;';
        $lines[] = '';
        $lines[] = "class {$className} extends AbstractMigration";
        $lines[] = '{';
        $lines[] = '    public function up(): void';
        $lines[] = '    {';
        $lines[] = "        \$name = getDbPrefix() . '{$table}';";
        $lines[] = '        if ($this->hasTable($name)) {';
        $lines[] = '            return;';
        $lines[] = '        }';
        $lines[] = '        $table = $this->table($name, [';
        $lines[] = "            'id' => false, 'comment' => '{$commentSafe}', 'primary_key' => 'id',";
        $lines[] = '        ]);';
        foreach ($fields as $f) {
            $lines[] = '        $table->addColumn(' . $this->renderAddColumn($f) . ');';
        }
        $lines[] = '        $table->save();';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function down(): void';
        $lines[] = '    {';
        $lines[] = "        \$name = getDbPrefix() . '{$table}';";
        $lines[] = '        if ($this->hasTable($name)) {';
        $lines[] = '            $this->table($name)->drop()->save();';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * 渲染单列 addColumn 参数（PG 兼容 Phinx 类型；id 走 bigserial identity）
     */
    protected function renderAddColumn(array $f): string
    {
        $name = $f['name'];
        $opts = ['comment' => $f['comment']];
        $type = null;

        $isPk = !empty($f['primaryKey']);
        $tsName = in_array($name, ['create_time', 'update_time'], true);
        switch (true) {
            case $isPk && $name === 'id':
                // id 主键：bigint identity（PG bigserial）
                $type = 'biginteger';
                $opts += ['signed' => false, 'identity' => true, 'null' => false];
                break;
            case $f['designType'] === 'timestamp' || $tsName:
                // 整型时间戳（BaseModel dateFormat=U 惯例）
                $type = 'biginteger';
                $opts += ['signed' => false, 'null' => true, 'default' => null];
                break;
            case $f['type'] === 'varchar':
                $type = 'string';
                $opts += ['limit' => $f['length'] ?: 255];
                if (!$f['null']) {
                    $opts['null'] = false;
                    $opts['default'] = $f['defaultType'] === 'EMPTY STRING' || $f['defaultType'] === 'INPUT' ? ($f['default'] !== '' ? $f['default'] : '') : null;
                } else {
                    $opts['null'] = true;
                    if ($f['defaultType'] === 'INPUT' && $f['default'] !== '') {
                        $opts['default'] = $f['default'];
                    }
                }
                break;
            case $f['type'] === 'text':
                $type = 'text';
                $opts += ['null' => !$f['null'] ? false : true];
                break;
            case $f['type'] === 'int':
                $type = 'integer';
                $opts += ['signed' => !$f['unsigned']];
                if ($f['defaultType'] === 'INPUT' && $f['default'] !== '') {
                    $opts['default'] = (int) $f['default'];
                } else {
                    $opts['null'] = $f['null'];
                    if ($f['defaultType'] === 'INPUT') {
                        $opts['default'] = 0;
                    }
                }
                break;
            case $f['type'] === 'bigint' && !$isPk:
                $type = 'biginteger';
                $opts += ['signed' => !$f['unsigned']];
                $opts['null'] = $f['null'];
                break;
            case $f['type'] === 'decimal':
                $type = 'decimal';
                $opts += ['precision' => $f['length'] ?: 10, 'scale' => $f['precision'] ?: 2, 'null' => true];
                break;
            case $f['type'] === 'date':
                $type = 'date';
                $opts += ['null' => true];
                break;
            case $f['type'] === 'tinyint':
                // PG 无 tinyint：integer 兜底（switch 布尔语义用 0/1）
                $type = 'integer';
                $opts += ['signed' => !$f['unsigned'], 'null' => false];
                $opts['default'] = $f['defaultType'] === 'INPUT' ? (int) ($f['default'] === '' ? '0' : $f['default']) : 0;
                break;
            default:
                throw new InvalidArgumentException("字段 {$name} 类型渲染暂不支持：{$f['type']}");
        }
        $opts['comment'] = $f['comment'];
        // 无默认值字段移除 default 键（array_key_exists 判空，isset 对 null 恒 false）
        if (array_key_exists('default', $opts) && $opts['default'] === null) {
            unset($opts['default']);
        }
        return "'{$name}', '" . ($type ?? '') . "', " . $this->renderOpts($opts);
    }

    /**
     * 渲染选项数组（PHP 代码字符串）
     */
    protected function renderOpts(array $opts): string
    {
        $parts = [];
        foreach ($opts as $k => $v) {
            if (is_bool($v)) {
                $parts[] = "'{$k}' => " . ($v ? 'true' : 'false');
            } elseif ($v === null) {
                $parts[] = "'{$k}' => null";
            } elseif (is_int($v)) {
                $parts[] = "'{$k}' => {$v}";
            } else {
                $parts[] = "'{$k}' => '" . addslashes((string) $v) . "'";
            }
        }
        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * snake -> Camel（公开：命令/工具探测推导文件路径用）
     */
    public static function camel(string $snake): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $snake)));
    }

    /**
     * 宿主迁移目录（项目 database/migrations）
     */
    protected function migrationDir(): string
    {
        $base = function_exists('base_path') ? base_path() : (defined('BASE_PATH') ? BASE_PATH : getcwd());
        return rtrim((string) $base, '/') . '/database/migrations';
    }
}
