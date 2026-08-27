<?php

namespace app\admin\model;

use app\common\model\BaseModel;

/**
 * 审计结果明细（webman-dev）
 *
 * 每行 = 某项目某规则在一次审计轮次(run_at)中的结果：
 * project_name/rule_title 为快照（项目改名/删除后仍可读），
 * detail 为问题明细 JSON 数组（最多 50 条，完整数量在 issue_count）。
 */
class DevAuditResult extends BaseModel
{
    protected $name = 'radmin_dev_audit_result';

    /**
     * 关闭时间字段自动格式化（think-orm v4 会把 create_time/update_time 的 int 值
     * 自动转成 'Y-m-d H:i:s' 字符串——本包时间列一律 int 时间戳）
     */
    protected $dateFormat = false;

    protected $type = [
        'project_id' => 'integer',
        'is_pass' => 'integer',
        'issue_count' => 'integer',
        'run_at' => 'integer',
        'create_time' => 'integer',
    ];

    /** 是否通过：通过 */
    public const PASS_YES = 1;
    /** 是否通过：未通过 */
    public const PASS_NO = 0;
}