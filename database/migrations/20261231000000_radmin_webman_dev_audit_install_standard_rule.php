<?php
/**
 * webman-dev 审计规则种子：Install.php 标准化
 *
 * 新增 1 条规则（code 与 AuditService::RULES 内置规则一致）：
 * - install_standard：Install.php 标准化门禁（见 docs/install-standard.md）——
 *   WEBMAN_PLUGIN 常量、install/update/uninstall 三钩子齐全、install 签名兼容官方
 *   Install::install(true)（禁强类型参数，官方传 bool 会 TypeError）、禁官方骨架
 *   残留 copy_dir/remove_dir（显式 overwrite=true 的 copy_dir 除外）与 array() 语法、
 *   类前中文头注释；文件标注 @audit-ignore install_standard 显式豁免。
 *
 * 幂等：按 name 去重插入 radmin_dev_audit_rule；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditInstallStandardRule extends AbstractMigration
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

        $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'install_standard' LIMIT 1");
        if (!$exist) {
            $this->table($table)->insert([
                [
                    'name' => 'install_standard',
                    'title' => 'Install.php 标准化',
                    'description' => 'Install.php 标准化门禁（见 docs/install-standard.md）：WEBMAN_PLUGIN 常量、install/update/uninstall 三钩子齐全、install 签名兼容官方 Install::install(true)（禁强类型参数）、禁官方骨架残留 copy_dir/remove_dir（显式 overwrite=true 的 copy_dir 除外）与 array() 语法、类前中文头注释；文件标注 @audit-ignore install_standard 显式豁免',
                    'status' => 'enabled',
                    'weigh' => 96,
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
        $this->execute("DELETE FROM {$table} WHERE name = 'install_standard'");
    }
}
