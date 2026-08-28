<?php
/**
 * webman-dev 审计规则说明更新：web_page 规则新增「表头按钮标准样式」检查（v3.6.0）
 *
 * 幂等：仅当 web_page 规则存在且 description 与目标不同时执行 UPDATE；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditWebPageButtonRule extends AbstractMigration
{
    public function up()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();
        $description = 'Vue 页面模板一致性：禁止自创依赖注入/裸 axios//src/ 导入、baTable 体系页面必须经 baTable、弹窗提交走 onSubmit、TableHeader 顶部自定义按钮必须用标准样式类 table-header-operate（radmin 同步树跳过）';

        $row = $this->fetchRow("SELECT id, description FROM {$table} WHERE name = 'web_page' LIMIT 1");
        if ($row && (string) $row['description'] !== $description) {
            // execute($sql, $params) 走 PDO 预处理，避免手工转义中文/引号
            $this->execute(
                "UPDATE {$table} SET description = ?, update_time = ? WHERE name = 'web_page'",
                [$description, $now]
            );
        }
    }

    public function down()
    {
        // 说明为文案性数据，不随回滚还原（引擎检查不受影响）
    }
}
