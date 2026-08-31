<?php

namespace app\admin\model;

use app\common\model\BaseModel;

/**
 * 审计规则（webman-dev）
 *
 * rocareer:audit 的规则元数据：code 与 AuditService::RULES 内置规则对应，
 * 停用(status=disabled) 的规则在运行审计（后台页/CLI）时跳过。
 */
class DevAuditRule extends BaseModel
{
    protected $table = 'radmin_dev_audit_rule';

    /**
     * 关闭时间字段自动格式化（think-orm v4 会把 create_time/update_time 的 int 值
     * 自动转成 'Y-m-d H:i:s' 字符串——本包时间列一律 int 时间戳）
     */
    protected $dateFormat = false;

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    protected $casts = [
        'create_time' => 'integer',
        'update_time' => 'integer',
    ];
}