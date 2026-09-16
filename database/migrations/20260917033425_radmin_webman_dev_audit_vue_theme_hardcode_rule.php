<?php
/**
 * webman-dev 审计规则种子：Vue 主题色硬编码门禁（v3.28.0）
 *
 * 新增 1 条规则（code 与 AuditService::RULES 内置规则一致）：
 * - vue_theme_hardcode：.vue 的 <style>/<template> 段写死 Element Plus 官方默认调色板
 *   （#409eff/#67c23a/#e6a23c/#f56c6c/#909399/#ecf5ff/#d9ecff）即报——主题语义色被固化，
 *   用户切换主题色/暗色模式后与全站脱节（print-erp flow 节点状态色实证）。
 *   合法形态 = var(--el-color-*, 色值) 带 fallback 双写；<script> 段画布色板不扫；
 *   打印纸张预览区白底语义属有意设计，注释声明即可。
 *
 * 幂等：按 name 去重插入 radmin_dev_audit_rule；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditVueThemeHardcodeRule extends AbstractMigration
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

        $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'vue_theme_hardcode' LIMIT 1");
        if (!$exist) {
            $this->table($table)->insert([
                [
                    'name' => 'vue_theme_hardcode',
                    'title' => 'Vue 主题色硬编码门禁（EP 调色板）',
                    'description' => 'Element Plus 官方默认调色板色值（#409eff/#67c23a/#e6a23c/#f56c6c/#909399/#ecf5ff/#d9ecff）写死在 .vue 的 <style>/<template> 段 = 本该走主题变量的语义色被固化——用户切换主题色/暗色模式后与全站脱节（print-erp flow 节点状态色实证，2026-09-17 前端全域样式审计）；合法形态 = var(--el-color-*, 色值) 带 fallback 双写（扫描前剔除再匹配）；<script> 段不扫（ECharts/SVG 画布色板属运行时配置，主题跟随可选 getComputedStyle 快照）；打印纸张预览区白底语义属有意设计，注释声明即可；扫描范围 = dev 宿主工程 web 树（radmin 条目承载 sweep），相对路径在 radmin/web/src 存在同路径文件的「全家桶继承页」跳过（真源在 radmin，上游存量不由宿主修），src 各包 web 树存量待自行收口后开启；文件标注 @audit-ignore vue_theme_hardcode 显式豁免',
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
        $this->execute("DELETE FROM {$table} WHERE name = 'vue_theme_hardcode'");
    }
}
