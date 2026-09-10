<?php
/**
 * update_audit_happ_frontend_blind_rule（审计规则种子同步：happ_frontend 纳入全域前端盲区扫描）
 *
 * 背景：老板拍板「检查全域 happ 应用，确保全部都是标准的，包括前端」——dev 宿主工程 web 源码树、
 * super/web/src、skyline 小程序不属于任何 src 包，包级规则扫不到（migration 规则纳入 dev 各工程
 * 迁移目录同款盲区先例）。本迁移把 radmin_dev_audit_rule 的 happ_frontend 行描述更新为
 * AuditService::RULES 同款口径（radmin 条目承载 sweepHappFrontendBlind 每轮一次盲区扫描）。
 *
 * 幂等迁移：按 name 定位行，缺行补插；表未建时跳过（新库排序守卫，同 update_audit_migration_rule）。
 */

use Phinx\Migration\AbstractMigration;

class UpdateAuditHappFrontendBlindRule extends AbstractMigration
{
    private const NAME = 'happ_frontend';
    private const DESCRIPTION = '全域 happ 前端接入门禁（见 AGENTS.md 异步铁律 5/6 与 rocareer/happ-client README）：浏览器实时接入只认 happ-client SDK 两条路径——JS 版 /@/utils/happClient（useHapp 单例）与 Vue 版 /@/composables/useHappConnection（响应式状态+组件作用域订阅自动退订）；各包 web/src 与全域盲区（dev 宿主工程 web 树、super/web/src、skyline 小程序——radmin 条目承载 sweepHappFrontendBlind 每轮一次）内出现 new WebSocket(、wx.connectSocket 或裸 ws://、wss:// 字面量即手写连接（绕过 HMAC 凭证认证/心跳/指数退避重连），全部报错；SDK 真源文件（utils/happClient.ts、composables/useHappConnection.ts）与标注 @audit-ignore happ_frontend 豁免';

    public function up(): void
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        if (!$this->hasTable($table)) {
            return;
        }
        $now = time();

        $row = $this->fetchRow("SELECT id FROM {$table} WHERE name = '" . self::NAME . "' LIMIT 1");
        if ($row) {
            $this->execute("UPDATE {$table} SET description = '" . self::DESCRIPTION . "', update_time = {$now} WHERE name = '" . self::NAME . "'");

            return;
        }
        $this->table($table)->insert([
            [
                'name' => self::NAME,
                'title' => 'happ 前端接入规范',
                'description' => self::DESCRIPTION,
                'status' => 'enabled',
                'weigh' => 79,
                'remark' => '',
                'create_time' => $now,
                'update_time' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        // 描述回滚无意义（非结构数据），不做任何操作
    }
}
