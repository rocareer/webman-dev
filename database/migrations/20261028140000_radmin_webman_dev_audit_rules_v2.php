<?php
/**
 * webman-dev 审计规则种子 v2（v3.3.0）：异步铁律/代码质量审计规则（2026-08-28 工程质量审计实战沉淀）
 *
 * 新增 5 条规则（code 与 AuditService::RULES 内置规则一致）：
 * - async_blocking：常驻进程内 BRPOP 长拉/同步 Guzzle/同步 SMTP/curl_exec/usleep/sleep 阻塞扫描
 * - fqcn_dup：全工作区同名类冲突（同一 FQCN 多文件定义）
 * - superglobal：worker 内直读 $_COOKIE/$_SERVER 超全局
 * - dead_code：全工作区零引用的死类候选
 * - cross_copy：跨包逐字重复文件（复制粘贴实现）
 *
 * 幂等：按 name 去重插入 radmin_dev_audit_rule；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditRulesV2 extends AbstractMigration
{
    /** 新增规则：code => [title, description, weigh] */
    protected const RULE_SEEDS = [
        'async_blocking' => ['异步阻塞扫描', '异步铁律：常驻进程代码（src/app，排除 CLI command/）内禁止 BRPOP 长拉、同步 Guzzle HTTP、同步 SMTP、curl_exec、usleep/sleep 阻塞事件循环', 20],
        'fqcn_dup' => ['同名类冲突', '全工作区 namespace+class 对去重：同一 FQCN 被多文件定义（含 PSR-4 加载不到的死副本）即报', 30],
        'superglobal' => ['超全局直读', 'webman worker 内直读 $_COOKIE/$_SERVER 不可靠（不自动填充/命名不可配），应走 support\\Context + Request（CLI 场景除外，命中需人工确认）', 40],
        'dead_code' => ['死类检测', '全工作区零引用（无 new/静态调用/::class/配置字符串引用）的非框架类 = 死代码候选', 50],
        'cross_copy' => ['跨包文件重复', '不同包内容逐字节相同的 .php 文件 = 复制粘贴实现（应下沉共享，防接口漂移）', 60],
    ];

    public function up()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();

        foreach (self::RULE_SEEDS as $code => [$title, $description, $weigh]) {
            $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{$code}' LIMIT 1");
            if (!$exist) {
                $this->table($table)->insert([
                    [
                        'name' => $code,
                        'title' => $title,
                        'description' => $description,
                        'status' => 'enabled',
                        'weigh' => $weigh,
                        'remark' => '',
                        'create_time' => $now,
                        'update_time' => $now,
                    ],
                ])->saveData();
            }
        }
    }

    public function down()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $codes = implode("','", array_keys(self::RULE_SEEDS));
        $this->execute("DELETE FROM {$table} WHERE name IN ('{$codes}')");
    }
}