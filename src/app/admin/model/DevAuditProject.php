<?php

namespace app\admin\model;

use app\common\model\BaseModel;

/**
 * 审计项目（webman-dev）
 *
 * 一个项目 = 工作区 src/ 下的一个包（name 为包目录名，如 radmin/ai/OIDC），
 * name 唯一；last_* 三列为最近一轮审计的快照汇总（运行审计时由控制器回写），
 * 列表页直接展示，避免每次列表都聚合 result 表。
 */
class DevAuditProject extends BaseModel
{
    protected $name = 'radmin_dev_audit_project';

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    protected $type = [
        'last_run_at' => 'integer',
        'last_issue_count' => 'integer',
        'create_time' => 'integer',
        'update_time' => 'integer',
    ];
}