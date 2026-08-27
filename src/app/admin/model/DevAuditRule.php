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
    protected $name = 'radmin_dev_audit_rule';

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    protected $type = [
        'create_time' => 'integer',
        'update_time' => 'integer',
    ];
}