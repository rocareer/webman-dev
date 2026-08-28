<?php
/**
 * webman-dev 审计规则种子：DTO 分层规范（v3.7.0）
 *
 * 新增 1 条规则（code 与 AuditService::RULES 内置规则一致）：
 * - dto_contract：公开 API 控制器手拼多字段数组输出 = 契约未固化，应引入 app/<模块>/dto/ typed DTO
 *   或 Model accessor；dto/ 目录内纯搬运类 = 过度设计；目录命名用 dto 不用 data。
 *
 * 幂等：按 name 去重插入 radmin_dev_audit_rule；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditDtoContractRule extends AbstractMigration
{
    public function up()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();

        $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'dto_contract' LIMIT 1");
        if (!$exist) {
            $this->table($table)->insert([
                [
                    'name' => 'dto_contract',
                    'title' => 'DTO 分层规范',
                    'description' => 'DTO 分层门禁：公开 API 控制器（非 admin）手拼多字段数组输出 = 契约未固化，应引入 app/<模块>/dto/ typed DTO 或 Model accessor；dto/ 目录内纯搬运类（toArray 原样返回入参、无整形/强转/脱敏）= 过度设计，直接用数组；目录命名用 dto 不用 data（data 与"数据/数据库"歧义）；文件标注 @audit-ignore dto_contract 显式豁免',
                    'status' => 'enabled',
                    'weigh' => 70,
                    'remark' => '',
                    'create_time' => $now,
                    'update_time' => $now,
                ],
            ])->saveData();
        }
    }

    public function down()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $this->execute("DELETE FROM {$table} WHERE name = 'dto_contract'");
    }
}
