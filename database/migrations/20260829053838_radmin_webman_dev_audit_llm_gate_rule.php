<?php
/**
 * webman-dev 审计规则种子：全域 LLM 门禁（v3.8.0）
 *
 * 新增 1 条规则（code 与 AuditService::RULES 内置规则一致）：
 * - llm_gate：业务代码禁止直接实例化 AiRouterService 调用 LLM/向量化——
 *   全域 LLM 业务统一经 agent 包 AgentGateway（智能体门禁，无智能体不开工）；
 *   ai（提供者）与 agent（网关）豁免；文件标注 @audit-ignore llm_gate 显式豁免。
 *
 * 幂等：按 name 去重插入 radmin_dev_audit_rule；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditLlmGateRule extends AbstractMigration
{
    public function up()
    {
        // 全新库全量重放守卫：本迁移版本号早于建表迁移（20261028120000，未来时间戳风格），全新库按版本号排序会先跑本条而表尚未建——直接跳过（规则行由建表迁移种子收口）；存量库 phinx 已记录不重跑。
        if (!$this->hasTable(getDbPrefix() . 'radmin_dev_audit_rule')) {
            return;
        }
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();

        $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'llm_gate' LIMIT 1");
        if (!$exist) {
            $this->table($table)->insert([
                [
                    'name' => 'llm_gate',
                    'title' => '全域 LLM 门禁（智能体出口）',
                    'description' => '全域 LLM 业务必须经 agent 包 AgentGateway（无智能体不开工）：业务代码禁止直接实例化 AiRouterService 调用 LLM/向量化；ai（底层提供者）与 agent（网关）豁免；文件标注 @audit-ignore llm_gate 显式豁免（如 ai 调试/开放 API 运维接口）',
                    'status' => 'enabled',
                    'weigh' => 80,
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
        $this->execute("DELETE FROM {$table} WHERE name = 'llm_gate'");
    }
}
