<?php
/**
 * webman-dev 审计规则种子：ORM 迁移门禁（Rocareer ORM 迁移 v4.0.0）
 *
 * 新增 1 条规则（code 与 AuditService::RULES 内置规则一致）：
 * - orm_migrated：think-orm 残留反向扫描——src 内禁止 think-orm 类引用
 *   （think\facade\Db / think\db\exception / think\model\relation / think\Paginator /
 *   think\File / think\Exception）与 config('think-orm...') 调用、composer 依赖
 *   webman/think-orm；白名单保留 think-validate / think-helper / think-container 类
 *   （think\Validate / think\exception\ValidateException / think\facade\Validate /
 *   think\helper\* / think\Facade / think\Container / think\Event）。
 *   文件标注 @audit-ignore orm_migrated 显式豁免（如 webman-dev 迁移历史种子、CLI 迁移脚本）。
 *
 * 幂等：按 name 去重插入 radmin_dev_audit_rule；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditOrmMigratedRule extends AbstractMigration
{
    public function up()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();

        $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'orm_migrated' LIMIT 1");
        if (!$exist) {
            $this->table($table)->insert([
                [
                    'name' => 'orm_migrated',
                    'title' => 'ORM 迁移门禁（think-orm 残留）',
                    'description' => 'ORM 迁移门禁：src 内禁止 think-orm 类引用（think\\facade\\Db / think\\db\\exception / think\\model\\relation / think\\Paginator / think\\File / think\\Exception）与 config(\'think-orm...\') 调用、composer 依赖 webman/think-orm；白名单保留 think-validate / think-helper / think-container 类；文件标注 @audit-ignore orm_migrated 显式豁免',
                    'status' => 'enabled',
                    'weigh' => 90,
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
        $this->execute("DELETE FROM {$table} WHERE name = 'orm_migrated'");
    }
}
