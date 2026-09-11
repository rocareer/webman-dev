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
 *              "quick_search": ["name", "mobile"] } // 可选：搜索字段（缺省=全部 input/textarea）
 *   "fields": [
 *     { "name": "name", "comment": "姓名", "design_type": "input", "length": 50, "required": true },
 *     { "name": "status", "comment": "状态: 0=禁用,1=启用", "design_type": "switch", "default": "1" },
 *     ...
 *   ]
 * }
 *
 * 目录约定（对齐后台 /admin/crud 引擎）：表名下划线 = 页面/菜单目录层级——
 * cc_student → 菜单 cc/student、页面 web/src/views/backend/cc/student/、控制器
 * app/admin/controller/cc/Student.php（表名前缀即模块目录，无需额外 module 参数）。
 *
 * 字段键（均可选）：name/comment 必填；design_type 必填（白名单见 DESIGN_TYPES）；
 * length（input/select 等 varchar 长度，缺省 255）；required（NOT NULL）；
 * default（INPUT 默认值，switch/weigh 默认按惯例）；primary_key（设为主键）。
 *
 * 字典/选项编码在 comment（radmin CRUD 惯例）：「标题: 键=值,键=值」——
 * select/radio/checkbox/selects 自动从 comment 提取枚举值并生成语言包，如
 * "状态: 0=禁用,1=启用"、"难度: easy=简单,hard=困难"。
 *
 * ---- 设计态契约 v2（version=2；新增键全部可选，v1 输入行为逐字节不变） ----
 * 字段级增强：
 *   options  [{label,value}] 结构化选项（自动转 comment 字典，engine 无需改动）
 *   remote   {table,pk,field,controller,model,relation_fields,alias} 关联表字段
 *            （design_type 用 remote_select/remote_selects；DB 列 remote_select=bigint、
 *             remote_selects=varchar(1500)；引擎自动生成 belongsTo/remoteSelectLabels）
 *   form     {rows,step,placeholder,...} 字段级表单属性（引擎 getFormField 消费）
 *   table    {render,operator,width,sortable,...} 字段级列表属性（引擎 getTableColumn 消费）
 *   group    "分组名"（配合 table.form_layout 表达表单分组）
 * 表级增强：
 *   default_sort   {field,type} 默认排序（缺省 id desc）
 *   is_common_model bool 通用模型
 *   form_layout    [{group,fields[],span}] 表单分组布局（决定表单项顺序；引擎不消费，元数据）
 *
 * 净化/校验/反导出见 CrudDesignService（sanitize/validate/export）；CLI/MCP 可直接吃 AI 产出。
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
        // v2 关联字段（需同时声明 remote 配置；引擎 designType 为驼峰 remoteSelect）
        'remote_select'  => 'remoteSelect',
        'remote_selects' => 'remoteSelects',
    ];

    /**
     * 表/模块目录名合法性（防路径穿越/注入）
     */
    public const NAME_RULE = '/^[a-z][a-z0-9_]*$/';

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

        // ---- 1. 字段解析 ----
        $fields = [];
        $seenNames = [];
        $warnings = [];
        foreach ($design['fields'] as $i => $f) {
            $parsed = $this->parseField($f, $i + 1);
            if (isset($seenNames[$parsed['name']])) {
                throw new InvalidArgumentException("字段名重复：{$parsed['name']}（第 {$i} 个字段与前面字段同名）");
            }
            $seenNames[$parsed['name']] = true;
            // select/radio/checkbox/selects 需 comment 字典（标题: 键=值,...）驱动选项
            if (in_array($parsed['designType'], ['select', 'radio', 'checkbox', 'selects'], true)
                && strpos($parsed['comment'], '=') === false) {
                $warnings[] = "字段 {$parsed['name']} 的 comment 未带字典（如「状态: 0=禁用,1=启用」），页面选项为空，需后续在 popupForm.vue 补 options";
            }
            $fields[] = $parsed;
        }
        // 主键：全家桶模型按 id 主键设计（引擎 getKeyName=id），仅支持 id 主键；
        // 用户未声明 id 字段时自动注入（bigint unsigned identity）
        $hasId = false;
        foreach ($fields as $f) {
            if ($f['name'] === 'id') {
                $hasId = true;
                break;
            }
        }
        if (!$hasId) {
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
        // v2：未显式给定 form_fields 时，表单分组布局（form_layout）决定表单项顺序
        $layoutOrder = $this->layoutFieldOrder($t['form_layout'] ?? [], $businessNames);
        $formFields = $hasForm
            ? $this->pickList($t['form_fields'] ?? [], 'form_fields', $allowedNames)
            : ($layoutOrder ?: $businessNames);
        $columnFields = $hasColumn ? $this->pickList($t['column_fields'] ?? [], 'column_fields', $allowedNames) : $businessNames;
        // 列表补 id 列（后台惯例首列 ID）
        if (!in_array('id', $columnFields, true)) {
            array_unshift($columnFields, 'id');
        }

        // ---- v2 表级增强（缺省 = v1 行为：id desc / 非通用模型） ----
        $defaultSortField = 'id';
        $defaultSortType = 'desc';
        if (!empty($t['default_sort']['field'])) {
            $defaultSortField = strtolower(trim((string) $t['default_sort']['field']));
            $defaultSortType = strtolower((string) ($t['default_sort']['type'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        }
        $isCommonModel = !empty($t['is_common_model']);

        // ---- 3. 代码落盘位置（file 键留空 = 引擎按表名自动推导到 app/admin/controller 等） ----
        $tablePayload = [
            'name' => $name,
            'comment' => $comment,
            'rebuild' => 'No',
            'databaseConnection' => '',
            'quickSearchField' => $quickSearch,
            'formFields' => $formFields,
            'columnFields' => $columnFields,
            'defaultSortField' => $defaultSortField,
            'defaultSortType' => $defaultSortType,
            'isCommonModel' => $isCommonModel,
        ];
        // 菜单名 = 引擎 getMenuName：表名下划线即页面/菜单目录层级（cc_student -> cc/student，
        // 代码落 app/admin/controller/cc/Student.php + web/src/views/backend/cc/student/）
        $menuName = str_replace('_', '/', $name);

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
            'module' => '',
            'warnings' => $warnings,
        ];
    }

    /**
     * 解析单个字段（简化键 -> 引擎 payload 全键）
     *
     * 主键约束：全家桶模型/控制器按 id 主键设计（Eloquent getKeyName=id），
     * 故仅允许 id 作为主键——字段名 id 自动提升为主键；primary_key=true 且非 id 报错。
     */
    protected function parseField(array $f, int $index): array
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

        // ---- v2：结构化 options 优先于 comment 字典（统一转 comment 字典，引擎无需改动） ----
        if (!empty($f['options']) && in_array($dt, ['select', 'radio', 'checkbox', 'selects'], true)) {
            $normalized = str_replace(['：'], [':'], $comment);
            $title = str_contains($normalized, ':') ? trim(explode(':', $normalized)[0]) : $comment;
            $pairs = [];
            foreach ((array) $f['options'] as $o) {
                $val = is_array($o) ? (string) ($o['value'] ?? '') : (string) $o;
                if ($val === '') {
                    continue;
                }
                $label = is_array($o) ? (string) ($o['label'] ?? $val) : (string) $o;
                $pairs[] = $val . '=' . $label;
            }
            if ($pairs) {
                $comment = $title . ': ' . implode(',', $pairs);
            }
        }
        // ---- v2：remote 关联字段（design_type=remote_select/remote_selects 或显式 remote 块） ----
        $remote = is_array($f['remote'] ?? null) ? $f['remote'] : [];
        if (in_array($dt, ['remote_select', 'remote_selects'], true) && empty($remote['table'])) {
            throw new InvalidArgumentException("字段 {$name}（{$dt}）必须声明 remote.table 关联表");
        }
        if ($remote && empty($remote['table'])) {
            // 显式 remote 块但缺 table：降级为普通字段（反幻觉，不放过来源不明的关联表）
            $remote = [];
        }

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
                // int 0/1（勿用 tinyint：radmin 引擎 default='1'+tinyint 会落 PG boolean，与迁移/字典 0/1 分叉）
                $field['type'] = 'int';
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
            case 'remote_select':
                // 关联 ID 列（bigint；与引擎 remoteSelect 搭配生成 belongsTo 关联）
                $field['type'] = 'bigint';
                $field['unsigned'] = true;
                $field['length'] = $length ?: 20;
                break;
            case 'remote_selects':
                // 多选关联：逗号串存 varchar(1500)（引擎 dtStringToArray 存取器）
                $field['type'] = 'varchar';
                $field['defaultType'] = 'EMPTY STRING';
                $field['null'] = false;
                $field['length'] = $length ?: 1500;
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
        // ---- v2：字段级 form/table 属性（引擎 getFormField / getTableColumn 消费） ----
        if (!empty($f['form']) && is_array($f['form'])) {
            $field['form'] = $this->normalizeAttrs($f['form'], 'form');
        }
        if (!empty($f['table']) && is_array($f['table'])) {
            $field['table'] = $this->normalizeAttrs($f['table'], 'table');
        }
        // ---- v2：remote 关联配置（键名与引擎 remoteSelect 契约对齐：下划线 -> camel 键） ----
        if ($remote) {
            $remoteForm = [
                'remote-table' => (string) $remote['table'],
                'remote-pk' => strtolower(trim((string) ($remote['pk'] ?? 'id'))),
                'remote-field' => strtolower(trim((string) ($remote['field'] ?? 'name'))),
                // 引擎 getRemoteSelectUrl 仅识别 remote-source-config-type=crud + remote-controller
                'remote-source-config-type' => 'crud',
                'remote-controller' => trim((string) ($remote['controller'] ?? '')) ?: $this->deriveRemoteController((string) $remote['table']),
                'remote-model' => trim((string) ($remote['model'] ?? '')),
                'relation-fields' => trim((string) ($remote['relation_fields'] ?? '')),
                'remote-primary-table-alias' => trim((string) ($remote['alias'] ?? '')),
                'select-multi' => $dt === 'remote_selects',
            ];
            // 字段级 form 属性并入（用户显式声明优先）
            $field['form'] = array_merge($remoteForm, $field['form']);
        }
        // 主键：仅支持 id（见方法头注释）——id 自动主键；显式 primary_key 非 id 报错
        $primaryKey = $name === 'id' ? true : (!empty($f['primary_key']) ? throw new InvalidArgumentException(
            "字段 {$name}：自定义主键暂不支持（引擎按 id 主键设计，主键固定为 id bigint identity；如确需业务主键请在迁移后自行调整）"
        ) : false);
        if ($primaryKey) {
            $field['primaryKey'] = true;
            $field['null'] = false;
            $field['autoIncrement'] = true;
            $field['unsigned'] = true;
            // id 主键类型固定 bigint（全家桶模型/序列惯例；用户显式声明的 id 同样强制）
            $field['type'] = 'bigint';
            $field['length'] = 20;
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
     * 表单分组布局（v2 form_layout）-> 表单项顺序
     *
     * 布局只决定顺序与分组元数据，不增减字段：未在布局中出现的业务字段按原顺序追加在末尾。
     */
    protected function layoutFieldOrder($layout, array $businessNames): array
    {
        if (!is_array($layout) || !$layout) {
            return [];
        }
        $seen = [];
        $order = [];
        foreach ($layout as $g) {
            foreach ((array) ($g['fields'] ?? []) as $fname) {
                $fname = strtolower(trim((string) $fname));
                if ($fname === '' || isset($seen[$fname]) || !in_array($fname, $businessNames, true)) {
                    continue;
                }
                $seen[$fname] = true;
                $order[] = $fname;
            }
        }
        foreach ($businessNames as $fname) {
            if (!isset($seen[$fname])) {
                $order[] = $fname;
            }
        }
        return $order;
    }

    /**
     * 字段级 form/table 属性归一（布尔/整型强转；空值剔除）
     */
    protected function normalizeAttrs(array $attrs, string $scope): array
    {
        $intKeys = $scope === 'form' ? ['rows', 'step'] : ['width'];
        $boolKeys = $scope === 'form' ? ['select-multi'] : ['sortable', 'comSearch'];
        $out = [];
        foreach ($attrs as $k => $v) {
            if ($v === '' || $v === null || (is_array($v) && !$v)) {
                continue;
            }
            if (in_array($k, $intKeys, true)) {
                $out[$k] = (int) $v;
            } elseif (in_array($k, $boolKeys, true)) {
                $out[$k] = (bool) $v;
            } else {
                $out[$k] = (string) $v;
            }
        }
        return $out;
    }

    /**
     * 由关联表名推导控制器路径（remote 下拉数据源；与引擎目录惯例同源）
     * cc_student -> cc/Student（引擎 getRemoteSelectUrl 会拼成 /admin/cc/Student/index）
     */
    protected function deriveRemoteController(string $remoteTable): string
    {
        $path = str_replace('_', '/', $remoteTable);
        $parts = explode('/', $path);
        $last = self::camel((string) array_pop($parts));
        return ($parts ? implode('/', $parts) . '/' : '') . $last;
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
        // PHP 单引号字面量转义（表 comment 会进入 'comment' => '...'，防引号/反斜杠断语法）
        $phpComment = addslashes($commentSafe);
        // 主键名动态化（固定 id，防御性推导）
        $pk = 'id';
        foreach ($fields as $f) {
            if (!empty($f['primaryKey'])) {
                $pk = $f['name'];
                break;
            }
        }

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
        $lines[] = "            'id' => false, 'comment' => '{$phpComment}', 'primary_key' => '{$pk}'";
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
                // 兼容历史（switch 已统一 int；tinyint 落 integer）
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
     * 预期落盘目标文件（相对宿主根；路径按引擎惯例：表名下划线=目录层级）
     *
     * 控制器/模型/验证器/前端 views/lang 与引擎 parseNameData 推导一致，
     * CLI/MCP 冲突预检与摘要共用，防重复生成覆盖已改代码。
     */
    public static function targetFiles(string $tableName): array
    {
        $path = str_replace('_', '/', $tableName);  // cc_student -> cc/student
        $parts = explode('/', $path);
        $uc = self::camel((string) array_pop($parts));
        $dir = implode('/', $parts);
        $prefix = $dir !== '' ? $dir . '/' : '';
        return [
            "app/admin/controller/{$prefix}{$uc}.php",
            "app/admin/model/{$prefix}{$uc}.php",
            "app/admin/validate/{$prefix}{$uc}.php",
            "web/src/views/backend/{$path}/index.vue",
            "web/src/views/backend/{$path}/popupForm.vue",
            // 语言包目录层级：backend/zh-cn|en/ + 表名路径（实证：web/src/lang/backend/zh-cn/demo/student.ts）
            "web/src/lang/backend/zh-cn/{$path}.ts",
            "web/src/lang/backend/en/{$path}.ts",
        ];
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
