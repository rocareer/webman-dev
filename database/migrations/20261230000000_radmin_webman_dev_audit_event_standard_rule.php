<?php
/**
 * webman-dev 审计规则种子：事件规范（webman/event）
 *
 * 新增 1 条规则（code 与 AuditService::RULES 内置规则一致）：
 * - event_standard：webman/event 使用规范门禁（见 docs/webman-event-standard.md）——
 *   事件名必须「提供方.领域.动作」全小写点分（禁驼峰/连字符/下划线分隔/无前缀裸名）；
 *   业务代码禁止散落 Event::on()（监听器集中 config/plugin/包名/event.php 或 config/event.php
 *   声明，唯一例外 radmin EventRegister 内置 member.*）；静态 Event::emit 事件名应在本包/
 *   跨包/宿主有对应监听器（孤儿事件=发射即空转，纯日志应直写日志）；app/listener 监听器
 *   方法签名 (array $data): void + 自身 try/catch；文件标注 @audit-ignore event_standard
 *   显式豁免。
 *
 * 幂等：按 name 去重插入 radmin_dev_audit_rule；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditEventStandardRule extends AbstractMigration
{
    public function up()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();

        $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'event_standard' LIMIT 1");
        if (!$exist) {
            $this->table($table)->insert([
                [
                    'name' => 'event_standard',
                    'title' => '事件规范（webman/event）',
                    'description' => 'webman/event 使用规范门禁（见 docs/webman-event-standard.md）：事件名必须 <提供方>.<领域>.<动作> 全小写点分（禁驼峰/连字符/下划线分隔/无前缀裸名）；业务代码禁止散落 Event::on()（监听器集中 config/plugin/*/event.php 或 config/event.php 声明，唯一例外 radmin EventRegister 内置 member.*）；静态 Event::emit 事件名应在本包/跨包/宿主有对应监听器（孤儿事件=发射即空转，纯日志应直写日志）；app/listener 监听器方法签名 (array $data): void + 自身 try/catch；文件标注 @audit-ignore event_standard 显式豁免',
                    'status' => 'enabled',
                    'weigh' => 95,
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
        $this->execute("DELETE FROM {$table} WHERE name = 'event_standard'");
    }
}
