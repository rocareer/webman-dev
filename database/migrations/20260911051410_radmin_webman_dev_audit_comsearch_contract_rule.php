<?php
/**
 * webman-dev 审计规则种子：高级检索契约门禁（v3.19.0）
 *
 * 新增 1 条规则（code 与 AuditService::RULES 内置规则一致）：
 * - comsearch_contract：admin 控制器禁止手写解析 comSearch 的 search 数组——
 *   高级检索/排序是 radmin 基础能力（Backend::applyListQueryContract 一行接入）；
 *   跨表字段别名等正当场景标注 @audit-ignore comsearch_contract 豁免并注释理由。
 *
 * 幂等：按 name 去重插入 radmin_dev_audit_rule；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditComsearchContractRule extends AbstractMigration
{
    public function up()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();

        $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'comsearch_contract' LIMIT 1");
        if (!$exist) {
            $this->table($table)->insert([
                [
                    'name' => 'comsearch_contract',
                    'title' => '高级检索契约门禁（comSearch 基础能力）',
                    'description' => '高级检索/排序是 radmin 基础能力（Backend::applyListQueryContract 一行接入）：admin 控制器禁止手写解析 comSearch 的 search 数组——各处自研解析是静默腐烂高发区（print-erp 16 控制器全灭、crontab Log val/value 键错位对标准 comSearch 无效且 int/enum 列收非法串 500、slides Deck $limit 未定义变量分页大小恒默认等实证）；文件标注 @audit-ignore comsearch_contract 豁免（跨表字段别名等正当映射场景，须注释理由）',
                    'status' => 'enabled',
                    'weigh' => 82,
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
        $this->execute("DELETE FROM {$table} WHERE name = 'comsearch_contract'");
    }
}
