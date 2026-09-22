<?php
/**
 * radmin_dev_audit_result 补 update_time 列（v3.28.2）
 *
 * 建表迁移（20260827090211）给 project/rule 两表都加了 update_time，唯独漏了 result 表——
 * 而 radmin BaseModel 自动时间戳在 save() 时会写 update_time，审计「运行」一落库即
 * SQLSTATE[42703] Undefined column（同步/异步路径同炸，异步作业化冒烟时暴露）。
 * 与姐妹表对齐：加 biginteger 可空 update_time。
 *
 * 幂等迁移：先查 information_schema.columns 再 ALTER，重复执行安全。
 * 版本号 20260922084602 由 migrate:create 按真实时间签发并全局查重，禁止手改（改号 = 撞车/重跑事故源）。
 */

use Phinx\Migration\AbstractMigration;

class RadminDevAuditResultAddUpdateTime extends AbstractMigration
{
    public function up(): void
    {
        $table = getDbPrefix() . 'radmin_dev_audit_result';
        if (!$this->hasTable($table)) {
            return;
        }
        $exists = $this->query(
            "SELECT count(*) AS c FROM information_schema.columns WHERE table_name = ? AND column_name = 'update_time'",
            [$table]
        )->fetch();
        if (is_array($exists) && (int) ($exists['c'] ?? 0) > 0) {
            return;
        }
        $this->execute(
            "ALTER TABLE \"{$table}\" ADD COLUMN \"update_time\" bigint NULL DEFAULT NULL"
        );
    }

    public function down(): void
    {
        $table = getDbPrefix() . 'radmin_dev_audit_result';
        if (!$this->hasTable($table)) {
            return;
        }
        $exists = $this->query(
            "SELECT count(*) AS c FROM information_schema.columns WHERE table_name = ? AND column_name = 'update_time'",
            [$table]
        )->fetch();
        if (is_array($exists) && (int) ($exists['c'] ?? 0) === 0) {
            return;
        }
        $this->execute("ALTER TABLE \"{$table}\" DROP COLUMN \"update_time\"");
    }
}
