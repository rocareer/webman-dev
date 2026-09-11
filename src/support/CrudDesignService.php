<?php

namespace Rocareer\WebmanDev\support;

/**
 * CRUD 设计态服务（sanitize 净化 / validate 结构化校验 / export 反向导出）
 *
 * 与 CrudDesigner 的分工：
 *   - CrudDesigner::parse()   = 严格执行器，校验失败抛 InvalidArgumentException（供 CLI/MCP 出码）
 *   - CrudDesignService       = 友好预检层，规则与 parse 同一套，但不抛异常——
 *     validate() 返回结构化错误数组（字段 + 错误码 + 说明），供 AI 自校验 / 前端逐条提示 /
 *     自修复回灌；sanitize() 做反幻觉白名单净化（未知键丢弃、非法值降级而非放行）；
 *     export() 从已生成记录反向导出设计 JSON，供 AI 参考先例与可视化再编辑。
 *
 * 设计态契约版本（design.version）：
 *   - 缺省或 1 = 旧版简化设计（17 种 design_type，字段键极简）
 *   - 2 = 增强版：字段支持 options/remote/form/table/group，
 *         表支持 default_sort/is_common_model/form_layout
 *   新增键全部可选，v1 输入行为完全不变（逐字节回归由 test:crud-designer 守护）。
 */

use InvalidArgumentException;

class CrudDesignService
{
    /** 当前设计态契约版本 */
    public const VERSION = 2;

    /** table 级允许键（白名单，其余丢弃并告警） */
    protected const TABLE_KEYS = [
        'name', 'comment', 'quick_search', 'form_fields', 'column_fields',
        'default_sort', 'is_common_model', 'form_layout',
    ];

    /** field 级允许键（白名单，其余丢弃并告警） */
    protected const FIELD_KEYS = [
        'name', 'comment', 'design_type', 'length', 'required', 'default',
        'primary_key', 'options', 'remote', 'form', 'table', 'group',
    ];

    /** 需要 options/comment 字典的控件类型 */
    protected const DICT_TYPES = ['select', 'radio', 'checkbox', 'selects'];

    /** remote 关联字段类型 */
    public const REMOTE_TYPES = ['remote_select', 'remote_selects'];

    /** 字段级 form 属性白名单（引擎 getFormField/parseSundryData 实际消费的键） */
    protected const FORM_ATTRS = [
        'rows', 'step', 'placeholder', 'select-multi', 'remote-pk', 'remote-field',
        'remote-table', 'remote-controller', 'remote-model', 'relation-fields',
        'remote-url', 'remote-primary-table-alias',
    ];

    /** 字段级 table 属性白名单（引擎 getTableColumn merge 的键） */
    protected const TABLE_ATTRS = [
        'render', 'operator', 'width', 'sortable', 'show', 'comSearchRender',
        'comSearchInputAttr', 'comSearch',
    ];

    /**
     * 净化：反幻觉白名单（未知键丢弃、类型强转、非法值降级而非放行）
     *
     * 与 dataio MappingAgentService 的反幻觉白名单同一思路——LLM 产出的设计里
     * 引用不存在的表/字段/控件类型时降级为安全值，绝不直接放行到执行器。
     *
     * @param array $raw 原始设计（可能含未知键/脏值）
     * @return array{design: array, warnings: string[]}
     */
    public function sanitize(array $raw): array
    {
        $warnings = [];
        $design = [];

        $table = is_array($raw['table'] ?? null) ? $raw['table'] : [];
        $cleanTable = [];
        foreach ($table as $k => $v) {
            if (!in_array($k, self::TABLE_KEYS, true)) {
                $warnings[] = "table 未知键已丢弃：{$k}";
                continue;
            }
            $cleanTable[$k] = $v;
        }
        // default_sort 形态归一：{field,type} 或 "field:type"
        if (isset($cleanTable['default_sort'])) {
            $ds = $cleanTable['default_sort'];
            if (is_string($ds) && str_contains($ds, ':')) {
                [$f, $t] = explode(':', $ds, 2);
                $ds = ['field' => trim($f), 'type' => strtolower(trim($t))];
            }
            if (!is_array($ds) || empty($ds['field'])) {
                $warnings[] = 'table.default_sort 形态不合法已忽略（应为 {field,type}）';
                unset($cleanTable['default_sort']);
            } else {
                $type = strtolower((string) ($ds['type'] ?? 'desc'));
                $cleanTable['default_sort'] = [
                    'field' => strtolower(trim((string) $ds['field'])),
                    'type' => in_array($type, ['asc', 'desc'], true) ? $type : 'desc',
                ];
            }
        }
        // form_layout 归一：[{group,fields,span}]
        if (isset($cleanTable['form_layout'])) {
            $layout = [];
            foreach ((array) $cleanTable['form_layout'] as $g) {
                if (!is_array($g) || trim((string) ($g['group'] ?? '')) === '') {
                    $warnings[] = 'form_layout 项缺 group 已丢弃';
                    continue;
                }
                $fields = [];
                foreach ((array) ($g['fields'] ?? []) as $f) {
                    $f = strtolower(trim((string) $f));
                    if ($f !== '') {
                        $fields[] = $f;
                    }
                }
                $item = ['group' => trim((string) $g['group']), 'fields' => $fields];
                if (isset($g['span'])) {
                    $span = (int) $g['span'];
                    $item['span'] = ($span >= 1 && $span <= 24) ? $span : 12;
                }
                $layout[] = $item;
            }
            $cleanTable['form_layout'] = $layout;
        }
        if (isset($cleanTable['is_common_model'])) {
            $cleanTable['is_common_model'] = (bool) $cleanTable['is_common_model'];
        }

        $cleanFields = [];
        foreach ((array) ($raw['fields'] ?? []) as $i => $f) {
            if (!is_array($f)) {
                $warnings[] = '第 ' . ($i + 1) . ' 个字段非对象已丢弃';
                continue;
            }
            $cf = [];
            foreach ($f as $k => $v) {
                if (!in_array($k, self::FIELD_KEYS, true)) {
                    $warnings[] = '字段 ' . ($f['name'] ?? ('#' . ($i + 1))) . " 未知键已丢弃：{$k}";
                    continue;
                }
                $cf[$k] = $v;
            }
            if (isset($cf['name'])) {
                $cf['name'] = strtolower(trim((string) $cf['name']));
            }
            // options 归一：仅留 {label,value}，value 空则丢弃该项
            if (isset($cf['options'])) {
                $opts = [];
                foreach ((array) $cf['options'] as $o) {
                    if (is_array($o)) {
                        $val = isset($o['value']) ? (string) $o['value'] : '';
                        $label = (string) ($o['label'] ?? $val);
                    } elseif (is_string($o) || is_numeric($o)) {
                        $val = (string) $o;
                        $label = (string) $o;
                    } else {
                        continue;
                    }
                    if ($val === '') {
                        continue;
                    }
                    $opts[] = ['label' => $label, 'value' => $val];
                }
                $cf['options'] = $opts;
                if (!$opts) {
                    $warnings[] = '字段 ' . ($cf['name'] ?? '') . ' 的 options 全非法已清空';
                }
            }
            // remote 归一：缺 table 则降级（反幻觉：引用不明关联表不放过）
            if (isset($cf['remote'])) {
                $r = is_array($cf['remote']) ? $cf['remote'] : [];
                $remoteTable = strtolower(trim((string) ($r['table'] ?? '')));
                if ($remoteTable === '') {
                    $warnings[] = '字段 ' . ($cf['name'] ?? '') . ' 的 remote 缺 table 已降级为普通输入';
                    unset($cf['remote']);
                } else {
                    $cf['remote'] = [
                        'table' => $remoteTable,
                        'pk' => strtolower(trim((string) ($r['pk'] ?? 'id'))),
                        'field' => strtolower(trim((string) ($r['field'] ?? 'name'))),
                        'controller' => trim((string) ($r['controller'] ?? '')),
                        'model' => trim((string) ($r['model'] ?? '')),
                        'relation_fields' => trim((string) ($r['relation_fields'] ?? '')),
                        'alias' => trim((string) ($r['alias'] ?? '')),
                    ];
                }
            }
            // form/table 属性白名单过滤
            foreach (['form' => self::FORM_ATTRS, 'table' => self::TABLE_ATTRS] as $scope => $allowed) {
                if (!isset($cf[$scope])) {
                    continue;
                }
                if (!is_array($cf[$scope])) {
                    unset($cf[$scope]);
                    continue;
                }
                $keep = [];
                foreach ($cf[$scope] as $k => $v) {
                    if (in_array($k, $allowed, true)) {
                        $keep[$k] = $v;
                    } else {
                        $warnings[] = "字段 " . ($cf['name'] ?? '') . " {$scope}.{$k} 非法属性已丢弃";
                    }
                }
                $cf[$scope] = $keep;
            }
            $cleanFields[] = $cf;
        }

        $design['table'] = $cleanTable;
        $design['fields'] = $cleanFields;
        if (isset($raw['version'])) {
            $design['version'] = (int) $raw['version'];
        }

        return ['design' => $design, 'warnings' => $warnings];
    }

    /**
     * 校验：返回结构化错误数组（不抛异常）
     *
     * 规则与 CrudDesigner::parse() 同一套，但收集全部错误而非遇错即抛——
     * 供 AI 一轮内拿到「哪一字段错、错在哪」的机器可读反馈并自修复。
     *
     * @param array $design 净化后的设计
     * @return array<int, array{field: string, code: string, message: string}>
     */
    public function validate(array $design): array
    {
        $errors = [];
        $table = $design['table'] ?? null;
        if (!is_array($table)) {
            return [['field' => 'table', 'code' => 'TABLE_MISSING', 'message' => '设计必须有 table 对象']];
        }
        $name = strtolower(trim((string) ($table['name'] ?? '')));
        if ($name === '') {
            $errors[] = ['field' => 'table.name', 'code' => 'TABLE_NAME_REQUIRED', 'message' => 'table.name 必填（小写蛇形表名，如 cc_student）'];
        } elseif (!preg_match(CrudDesigner::NAME_RULE, $name)) {
            $errors[] = ['field' => 'table.name', 'code' => 'TABLE_NAME_INVALID', 'message' => "table.name 不合法：{$name}（仅小写字母/数字/下划线，首字符为字母）"];
        }
        if (trim((string) ($table['comment'] ?? '')) === '') {
            $errors[] = ['field' => 'table.comment', 'code' => 'TABLE_COMMENT_REQUIRED', 'message' => 'table.comment 必填（中文表名/菜单标题）'];
        }

        $fields = $design['fields'] ?? null;
        if (!is_array($fields) || !$fields) {
            $errors[] = ['field' => 'fields', 'code' => 'FIELDS_REQUIRED', 'message' => '设计必须有非空 fields 数组'];
            return $errors;
        }

        $seen = [];
        $declared = [];
        foreach ($fields as $i => $f) {
            $label = 'fields[' . $i . ']';
            if (!is_array($f)) {
                $errors[] = ['field' => $label, 'code' => 'FIELD_NOT_OBJECT', 'message' => "第 " . ($i + 1) . ' 个字段必须是对象'];
                continue;
            }
            $fname = strtolower(trim((string) ($f['name'] ?? '')));
            if ($fname === '' || !preg_match(CrudDesigner::NAME_RULE, $fname)) {
                $errors[] = ['field' => $label . '.name', 'code' => 'FIELD_NAME_INVALID', 'message' => "第 " . ($i + 1) . " 个字段 name 不合法：{$fname}"];
                continue;
            }
            if (isset($seen[$fname])) {
                $errors[] = ['field' => $fname, 'code' => 'FIELD_NAME_DUPLICATE', 'message' => "字段名重复：{$fname}"];
            }
            $seen[$fname] = true;
            $declared[$fname] = true;

            if (trim((string) ($f['comment'] ?? '')) === '') {
                $errors[] = ['field' => $fname, 'code' => 'FIELD_COMMENT_REQUIRED', 'message' => "字段 {$fname} 的 comment 必填"];
            }
            $dt = strtolower(trim((string) ($f['design_type'] ?? '')));
            if (!isset(CrudDesigner::DESIGN_TYPES[$dt])) {
                $errors[] = ['field' => $fname, 'code' => 'FIELD_DESIGN_TYPE_INVALID', 'message' => "字段 {$fname} 的 design_type 不支持：{$dt}"];
            }
            if (!empty($f['primary_key']) && $fname !== 'id') {
                $errors[] = ['field' => $fname, 'code' => 'FIELD_PRIMARY_KEY_UNSUPPORTED', 'message' => "字段 {$fname}：自定义主键暂不支持（主键固定 id）"];
            }
            if (in_array($dt, self::REMOTE_TYPES, true) && empty($f['remote']['table'])) {
                $errors[] = ['field' => $fname, 'code' => 'FIELD_REMOTE_TABLE_REQUIRED', 'message' => "字段 {$fname}（{$dt}）必须声明 remote.table 关联表"];
            }
            if (in_array($dt, self::DICT_TYPES, true)
                && empty($f['options'])
                && !str_contains((string) ($f['comment'] ?? ''), '=')) {
                $errors[] = ['field' => $fname, 'code' => 'FIELD_OPTIONS_REQUIRED', 'message' => "字段 {$fname}（{$dt}）需 options 数组或 comment 字典「标题: 键=值,键=值」"];
            }
        }

        // 字段名单引用校验（quick_search/form_fields/column_fields）
        foreach (['quick_search', 'form_fields', 'column_fields'] as $key) {
            foreach ((array) ($table[$key] ?? []) as $ref) {
                $ref = strtolower(trim((string) $ref));
                if ($ref === '' || in_array($ref, ['id', 'create_time', 'update_time'], true)) {
                    continue;
                }
                if (!isset($declared[$ref])) {
                    $errors[] = ['field' => "table.{$key}", 'code' => 'LIST_FIELD_UNDEFINED', 'message' => "table.{$key} 含未声明字段：{$ref}"];
                }
            }
        }
        // 布局引用校验
        foreach ((array) ($table['form_layout'] ?? []) as $g) {
            foreach ((array) ($g['fields'] ?? []) as $ref) {
                $ref = strtolower(trim((string) $ref));
                if ($ref !== '' && !isset($declared[$ref]) && !in_array($ref, ['id', 'create_time', 'update_time'], true)) {
                    $errors[] = ['field' => 'table.form_layout', 'code' => 'LAYOUT_FIELD_UNDEFINED', 'message' => 'form_layout 含未声明字段：' . $ref];
                }
            }
        }
        return $errors;
    }

    /**
     * 反向导出：从已生成记录（ra_admin_crud_log）反推设计 JSON v2
     *
     * 用途：① AI 参考同包先例（导出既有模块设计再改字段）；② 已有模块载入可视化设计台再编辑。
     * 取最近一条 status=success 的记录；含 options（从 comment 字典还原）/remote（从 form 属性还原）。
     *
     * @param string $tableName 表名（不含前缀）
     * @return array|null 设计 JSON；无记录返回 null
     */
    public function export(string $tableName): ?array
    {
        if (!class_exists(\app\admin\model\CrudLog::class)) {
            return null;
        }
        $tableName = strtolower(trim($tableName));
        $log = \app\admin\model\CrudLog::where('table_name', $tableName)
            ->where('status', 'success')
            ->orderBy('id', 'desc')
            ->first();
        if (!$log) {
            return null;
        }
        $t = is_array($log->table) ? $log->table : json_decode((string) $log->table, true);
        $fields = is_array($log->fields) ? $log->fields : json_decode((string) $log->fields, true);
        if (!is_array($t) || !is_array($fields)) {
            return null;
        }

        $out = [
            'version' => self::VERSION,
            'table' => [
                'name' => (string) ($t['name'] ?? $tableName),
                'comment' => (string) ($t['comment'] ?? ''),
            ],
        ];
        if (!empty($t['quickSearchField'])) {
            $out['table']['quick_search'] = array_values($t['quickSearchField']);
        }
        if (!empty($t['defaultSortField']) && $t['defaultSortField'] !== 'id') {
            $out['table']['default_sort'] = ['field' => $t['defaultSortField'], 'type' => $t['defaultSortType'] ?? 'desc'];
        }
        if (!empty($t['isCommonModel'])) {
            $out['table']['is_common_model'] = true;
        }

        $reverse = self::reverseTypeMap();
        $out['fields'] = [];
        foreach ($fields as $f) {
            $fname = (string) ($f['name'] ?? '');
            if (in_array($fname, ['id', 'create_time', 'update_time'], true)) {
                continue;  // 系统字段由生成器自动注入，导出时不回写
            }
            $designType = (string) ($f['designType'] ?? '');
            $dt = $reverse[$designType] ?? 'input';
            // number + precision>0 还原为 float
            if ($designType === 'number' && !empty($f['precision'])) {
                $dt = 'float';
            }
            $item = [
                'name' => $fname,
                'comment' => (string) ($f['comment'] ?? ''),
                'design_type' => $dt,
            ];
            if (!empty($f['length']) && !in_array($dt, ['textarea', 'editor'], true)) {
                $item['length'] = (int) $f['length'];
            }
            if (empty($f['null'])) {
                $item['required'] = true;
            }
            if (isset($f['default']) && $f['default'] !== '' && ($f['defaultType'] ?? '') === 'INPUT') {
                $item['default'] = (string) $f['default'];
            }
            // options 从 comment 字典还原
            $options = $this->dictToOptions((string) ($f['comment'] ?? ''));
            if ($options) {
                $item['options'] = $options;
            }
            // remote 从 form 属性还原
            if (in_array($designType, ['remoteSelect', 'remoteSelects'], true)) {
                $form = is_array($f['form'] ?? null) ? $f['form'] : [];
                $item['remote'] = [
                    'table' => (string) ($form['remote-table'] ?? ''),
                    'pk' => (string) ($form['remote-pk'] ?? 'id'),
                    'field' => (string) ($form['remote-field'] ?? 'name'),
                    'controller' => (string) ($form['remote-controller'] ?? ''),
                    'model' => (string) ($form['remote-model'] ?? ''),
                    'relation_fields' => (string) ($form['relation-fields'] ?? ''),
                ];
                unset($form['remote-table'], $form['remote-pk'], $form['remote-field'],
                    $form['remote-controller'], $form['remote-model'], $form['relation-fields'],
                    $form['remote-source-config-type'], $form['select-multi'], $form['remote-primary-table-alias'],
                    $form['remote-url']);
            }
            // 其余字段级 form/table 属性透传
            $form = is_array($f['form'] ?? null) ? $f['form'] : [];
            $form = array_filter($form, static fn ($v) => $v !== '' && $v !== []);
            if ($form) {
                $item['form'] = $form;
            }
            $tbl = is_array($f['table'] ?? null) ? $f['table'] : [];
            $tbl = array_filter($tbl, static fn ($v) => $v !== '' && $v !== []);
            if ($tbl) {
                $item['table'] = $tbl;
            }
            $out['fields'][] = $item;
        }
        return $out;
    }

    /**
     * comment 字典 -> options 数组（「标题: 键=值,键=值」）
     */
    protected function dictToOptions(string $comment): array
    {
        $comment = str_replace(['，', '：'], [',', ':'], $comment);
        if (stripos($comment, ':') === false || stripos($comment, ',') === false || stripos($comment, '=') === false) {
            return [];
        }
        [, $item] = explode(':', $comment, 2);
        $options = [];
        foreach (explode(',', $item) as $v) {
            $pair = explode('=', $v, 2);
            if (count($pair) === 2 && trim($pair[0]) !== '') {
                $options[] = ['label' => trim($pair[1]), 'value' => trim($pair[0])];
            }
        }
        return $options;
    }

    /**
     * designType -> 简化 design_type 反向映射（export 用）
     */
    protected static function reverseTypeMap(): array
    {
        $map = array_flip(CrudDesigner::DESIGN_TYPES);  // designType -> design_type（后到者覆盖）
        // array_flip 后 float 会被 number 覆盖，显式补回常见项
        return $map + [
            'input' => 'input',
            'remoteSelect' => 'remote_select',
            'remoteSelects' => 'remote_selects',
        ];
    }
}
