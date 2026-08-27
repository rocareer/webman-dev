<?php
/**
 * webman-dev 审计规则种子：新增「前端页面规范」规则（v3.2.0）
 *
 * 幂等：按 name=web_page 去重插入 radmin_dev_audit_rule；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditWebPageRule extends AbstractMigration
{
    public function up()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();

        $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'web_page' LIMIT 1");
        if (!$exist) {
            $this->table($table)->insert([
                [
                    'name' => 'web_page',
                    'title' => '前端页面规范',
                    'description' => 'Vue 页面模板一致性：禁止自创依赖注入/裸 axios//src/ 导入、baTable 体系页面必须经 baTable、弹窗提交走 onSubmit、表单字段与后端入参一致（radmin 同步树跳过）',
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
        $this->execute("DELETE FROM {$table} WHERE name = 'web_page'");
    }
}