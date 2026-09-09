<?php
/**
 * update_audit_migration_rule（webman-dev 审计规则种子同步：migration 规则升级为「迁移命名与查重（精确到秒）」）
 *
 * 背景：迁移文件命名铁律收口（版本号务必精确到秒，禁止「年月日+000000」），webman-migration
 * v2.4.0 运行时强检同步扩展（非 14 位前缀/静默忽略/裸版本号/类名重复拦截）。本迁移把
 * radmin_dev_audit_rule 里 migration 规则行的标题/描述更新为 AuditService::RULES 同款口径
 * （种子行是旧版「迁移时间戳查重」，后台展示与实际检查范围不一致）。
 *
 * 幂等迁移：按 name 定位行，缺行补插（新环境按顺序跑旧种子后再被本迁移更新，结果一致）。
 * 版本号 20260909131325 由 migrate:create 按真实时间签发并全局查重，禁止手改（改号 = 撞车/重跑事故源）。
 */

use Phinx\Migration\AbstractMigration;

class UpdateAuditMigrationRule extends AbstractMigration
{
    /** 规则行目标口径（与 AuditService::RULES['migration'] 保持一致） */
    private const TITLE = '迁移命名与查重（精确到秒）';
    private const DESCRIPTION = '全工作区 migrations/pg-migrations 迁移文件形态门禁（与 webman-migration v2.4.0 运行时强检同口径）：版本号撞号（撞号会阻断全家桶 migrate:run）、数字前缀非 14 位时间戳（8 位「年月日就完了」风会被 Phinx 照常加载且前缀即版本号，撞号高危）、14 位裸版本号缺名字段、不匹配 Phinx 正则的静默忽略文件（永不执行，造成已迁移假象）全部报错；「年月日+000000」存量只计数进 note 不报错（新建禁止）。新建迁移一律 php webman migrate:create 生成（真实时间戳精确到秒 + 全局查重自动顺延）';

    public function up(): void
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'radmin_dev_audit_rule';
        $now = time();

        $row = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'migration' LIMIT 1");
        if ($row) {
            $this->execute("UPDATE {$table} SET title = '" . self::TITLE . "', description = '" . self::DESCRIPTION . "', update_time = {$now} WHERE name = 'migration'");
            return;
        }
        $this->table($table)->insert([
            [
                'name' => 'migration',
                'title' => self::TITLE,
                'description' => self::DESCRIPTION,
                'status' => 'enabled',
                'weigh' => 90,
                'remark' => '',
                'create_time' => $now,
                'update_time' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        // 规则行回滚到旧口径无意义（title/description 非结构数据），不做任何操作
    }
}
