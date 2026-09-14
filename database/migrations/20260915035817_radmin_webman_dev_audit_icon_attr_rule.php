<?php
/**
 * webman-dev 审计规则种子：el 组件 icon 属性禁传 CSS 类名
 *
 * 新增 1 条规则（code 与 AuditService::RULES 内置规则一致）：
 * - icon_attr：Element Plus 组件 icon 类属性（icon/:icon）按组件渲染，传 'fa fa-*'
 *   等类名字符串会 createElement(类名) 抛 InvalidCharacterError——页面白屏且此后
 *   所有菜单点击空白（dataio 导入向导 v1.0.5 与 print-erp 双实证）。
 *   合法形态 = <Icon name="fa fa-*" /> 子节点（Icon 全局注册）或已注册组件名；
 *   文件标注 @audit-ignore icon_attr 显式豁免。
 *
 * 幂等：按 name 去重插入 radmin_dev_audit_rule；重复执行安全。
 * 版本号 20260915035817 由 migrate:create 按真实时间签发并全局查重，禁止手改（改号 = 撞车/重跑事故源）。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditIconAttrRule extends AbstractMigration
{
    public function up(): void
    {
        // 全新库全量重放守卫：本迁移版本号早于建表迁移（20261028120000，未来时间戳风格），全新库按版本号排序会先跑本条而表尚未建——直接跳过（规则行由建表迁移种子收口）；存量库 phinx 已记录不重跑。
        if (!$this->hasTable(getDbPrefix() . 'radmin_dev_audit_rule')) {
            return;
        }
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();

        $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'icon_attr' LIMIT 1");
        if (!$exist) {
            $this->table($table)->insert([
                [
                    'name' => 'icon_attr',
                    'title' => 'el 组件 icon 属性禁传 CSS 类名',
                    'description' => 'Element Plus 组件 icon 类属性（icon/:icon）按组件渲染：传 fa fa-* 等类名字符串会 createElement(类名) 抛 InvalidCharacterError，页面白屏且此后所有菜单点击空白（dataio 导入向导与 print-erp 双实证）；.vue 内 icon="fa / :icon="fa / :icon="形式即报；合法形态 = Icon 子节点（Icon 全局注册）或已注册组件名；文件标注 @audit-ignore icon_attr 显式豁免',
                    'status' => 'enabled',
                    'weigh' => 97,
                    'remark' => '',
                    'create_time' => $now,
                    'update_time' => $now,
                ],
            ])->saveData();
        }
    }

    public function down(): void
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $this->execute("DELETE FROM {$table} WHERE name = 'icon_attr'");
    }
}
