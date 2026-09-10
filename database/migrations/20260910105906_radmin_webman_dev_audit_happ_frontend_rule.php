<?php
/**
 * webman-dev 审计规则种子：happ 前端接入规范（happ_frontend）
 *
 * 新增 1 条规则（name 与 AuditService::RULES 内置规则一致）：
 * - happ_frontend：全域 happ 前端接入门禁——浏览器实时接入只认 happ-client SDK
 *   （JS 版 useHapp / Vue 版 useHappConnection），各包 web/src 内手写 new WebSocket(
 *   或裸 ws://、wss:// 地址即报错；SDK 真源文件与 @audit-ignore happ_frontend 豁免；
 *   radmin web 汇聚树跳过。规范来源：AGENTS.md 异步铁律 5/6 + happ-client v0.3.5。
 *
 * 幂等：按 name 去重插入 radmin_dev_audit_rule；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditHappFrontendRule extends AbstractMigration
{
    public function up()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();

        $exist = $this->fetchRow("SELECT id FROM " . $table . " WHERE name = '" . 'happ_frontend' . "' LIMIT 1");
        if (!$exist) {
            $this->table($table)->insert([
                [
                    'name' => 'happ_frontend',
                    'title' => 'happ 前端接入规范',
                    'description' => '全域 happ 前端接入门禁（见 AGENTS.md 异步铁律 5/6 与 rocareer/happ-client README）：浏览器实时接入只认 happ-client SDK 两条路径——JS 版 /@/utils/happClient（useHapp 单例）与 Vue 版 /@/composables/useHappConnection（响应式状态 + 组件作用域订阅自动退订）；各包 web/src 内出现 new WebSocket( 或裸 ws://、wss:// 地址字面量即手写连接（绕过 HMAC 凭证认证/心跳/指数退避重连，硬编码地址与服务端下发 endpoint 冲突），全部报错；SDK 真源文件（utils/happClient.ts、composables/useHappConnection.ts）与标注 @audit-ignore happ_frontend 的文件豁免；radmin web 树为同步汇聚区跳过',
                    'status' => 'enabled',
                    'weigh' => 79,
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
        $this->execute("DELETE FROM " . $table . " WHERE name = '" . 'happ_frontend' . "'");
    }
}
