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

    /**
     * 关闭时间字段自动格式化（think-orm v4 会把 create_time/update_time 的 int 值
     * 自动转成 'Y-m-d H:i:s' 字符串再参与 (int) 强转出错——本包时间列一律 int 时间戳）
     */
    protected $dateFormat = false;

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    protected $type = [
        'last_run_at' => 'integer',
        'last_issue_count' => 'integer',
        'create_time' => 'integer',
        'update_time' => 'integer',
    ];
}