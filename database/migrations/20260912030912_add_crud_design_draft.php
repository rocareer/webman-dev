<?php
/**
 * CRUD 设计草稿表（FACTORY P1：AI 生成模块设计的草稿存储）
 *
 * 用途：AI 产出的模块设计 JSON 先落本表（status=pending→suggested→confirmed/rejected），
 * 人工确认后才出码（红线：LLM 只产草稿，不直接生成）。一张草稿一行，含：
 *   prompt（用户需求原文）/ design（AI 产 & 净化后的设计 JSON）/ errors（校验错误 JSON）/
 *   warnings（净化告警）/ request_id（AI 可据此查状态）/ agent_key（使用的智能体）。
 * 幂等：hasTable 守卫，重复执行安全；卸载不删数据表（由卸载清单管理）。
 * 版本号 20260912030912 由 migrate:create 按真实时间签发并全局查重，禁止手改。
 */

use Phinx\Migration\AbstractMigration;

class AddCrudDesignDraft extends AbstractMigration
{
    public function up(): void
    {
        $name = getDbPrefix() . 'radmin_crud_design_draft';
        if ($this->hasTable($name)) {
            return;
        }
        $table = $this->table($name, [
            'id' => false, 'comment' => 'CRUD 设计草稿（AI 生成）', 'row_format' => 'DYNAMIC',
            'primary_key' => 'id', 'collation' => 'utf8mb4_unicode_ci',
        ]);
        $table->addColumn('id', 'biginteger', ['comment' => 'ID', 'signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => '', 'comment' => '请求ID(uuid7，AI 查状态用)', 'null' => false])
            ->addColumn('table_name', 'string', ['limit' => 100, 'default' => '', 'comment' => '目标表名（净化后）', 'null' => false])
            ->addColumn('prompt', 'text', ['null' => true, 'default' => null, 'comment' => '用户需求原文'])
            ->addColumn('design', 'text', ['null' => true, 'default' => null, 'comment' => '设计 JSON（净化后）'])
            ->addColumn('errors', 'text', ['null' => true, 'default' => null, 'comment' => '校验错误 JSON 数组'])
            ->addColumn('warnings', 'text', ['null' => true, 'default' => null, 'comment' => '净化/解析告警 JSON 数组'])
            ->addColumn('agent_key', 'string', ['limit' => 60, 'default' => '', 'comment' => '使用的智能体标识名', 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending', 'comment' => '状态:pending/suggested/failed/confirmed/rejected', 'null' => false])
            ->addColumn('rounds', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'LLM 自修复轮数', 'null' => false])
            ->addColumn('admin_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '发起管理员ID', 'null' => false])
            ->addColumn('confirmed_at', 'biginteger', ['signed' => false, 'null' => true, 'default' => null, 'comment' => '确认出码时间'])
            ->addColumn('create_time', 'biginteger', ['signed' => false, 'null' => true, 'default' => null, 'comment' => '创建时间'])
            ->addColumn('update_time', 'biginteger', ['signed' => false, 'null' => true, 'default' => null, 'comment' => '更新时间'])
            ->addIndex(['request_id'], ['type' => 'BTREE', 'unique' => true])
            ->addIndex(['status'], ['type' => 'BTREE'])
            ->addIndex(['table_name'], ['type' => 'BTREE'])
            ->create();
    }

    public function down(): void
    {
        $name = getDbPrefix() . 'radmin_crud_design_draft';
        if ($this->hasTable($name)) {
            $this->table($name)->drop()->save();
        }
    }
}
