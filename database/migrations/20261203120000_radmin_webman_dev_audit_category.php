<?php
/**
 * webman-dev 审计菜单归入「工程质量审计」目录（v3.5.0）
 *
 * 三个审计页面（审计项目/审计规则/审计结果）原先平铺在「开发和调试」（name=dev）下，
 * 现归入「开发和调试 → 工程质量审计」（name=dev/audit）二级目录；页面与按钮权限
 * （audit/ 前缀不变），前端路由路径不变（/admin/audit/...，目录 path 仅作分组，不进 URL）。
 * 幂等：目录不存在则创建；三个菜单存在则纠正 pid 挂载；重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditCategory extends AbstractMigration
{
    /** 三个审计菜单 name（页面行，按钮 name 为 audit/ 下带方法后缀，不会误伤） */
    protected const AUDIT_MENUS = ['audit/auditproject', 'audit/auditrule', 'audit/auditresult'];

    /** 按钮挂菜单 map（与初版迁移一致，双保险） */
    protected const BUTTON_MAP = [
        'audit/auditrule' => ['audit/auditrule/index', 'audit/auditrule/add', 'audit/auditrule/edit', 'audit/auditrule/del', 'audit/auditrule/switch'],
        'audit/auditproject' => ['audit/auditproject/index', 'audit/auditproject/add', 'audit/auditproject/edit', 'audit/auditproject/del', 'audit/auditproject/switch', 'audit/auditproject/run', 'audit/auditproject/stats'],
        'audit/auditresult' => ['audit/auditresult/index', 'audit/auditresult/del', 'audit/auditresult/runs'],
    ];

    public function up()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'admin_rule';
        $now = time();

        // 1) 确保「开发和调试」目录存在（与初版迁移语义一致）
        $devId = $this->ensureDevDir($table, $now);
        if ($devId <= 0) {
            return;
        }

        // 2) 创建「工程质量审计」二级目录（name/path 相同；type=menu_dir 无 component，仅分组）
        $catName = 'dev/audit';
        $cat = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{$catName}' AND type = 'menu_dir' LIMIT 1");
        if ($cat) {
            $this->execute(
                "UPDATE {$table} SET pid = {$devId}, type = 'menu_dir', title = '工程质量审计', "
                . "path = '{$catName}', icon = 'fa fa-check-square-o', status = '1', weigh = 78, update_time = {$now} "
                . "WHERE id = " . (int) $cat['id']
            );
            $catId = (int) $cat['id'];
        } else {
            $this->table($table)->insert([
                [
                    'pid' => $devId, 'type' => 'menu_dir', 'title' => '工程质量审计', 'name' => $catName,
                    'path' => $catName, 'icon' => 'fa fa-check-square-o', 'status' => '1', 'weigh' => 78,
                    'create_time' => $now, 'update_time' => $now,
                ],
            ])->saveData();
            $cat = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{$catName}' AND type = 'menu_dir' LIMIT 1");
            $catId = (int) ($cat['id'] ?? 0);
        }
        if ($catId <= 0) {
            return;
        }

        // 3) 三个审计菜单挂到工程质量审计目录下（title/weigh/component 不动，仅迁 pid）
        $menus = implode("','", self::AUDIT_MENUS);
        $this->execute("UPDATE {$table} SET pid = {$catId}, update_time = {$now} WHERE name IN ('{$menus}') AND type = 'menu'");

        // 4) 按钮仍挂各自菜单下（双保险）
        foreach (self::BUTTON_MAP as $menuName => $buttonNames) {
            $menu = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{$menuName}' AND type = 'menu' LIMIT 1");
            if (!$menu) {
                continue;
            }
            $list = implode("','", $buttonNames);
            $this->execute("UPDATE {$table} SET pid = " . (int) $menu['id'] . " WHERE name IN ('{$list}')");
        }
    }

    public function down()
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'admin_rule';

        // 三个审计菜单迁回「开发和调试」目录
        $dev = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'dev' AND type = 'menu_dir' LIMIT 1");
        if ($dev) {
            $menus = implode("','", self::AUDIT_MENUS);
            $this->execute("UPDATE {$table} SET pid = " . (int) $dev['id'] . " WHERE name IN ('{$menus}') AND type = 'menu'");
        }

        // 工程质量审计目录无子级则删除
        $cat = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'dev/audit' AND type = 'menu_dir' LIMIT 1");
        if ($cat) {
            $children = (int) ($this->fetchRow("SELECT COUNT(*) AS c FROM {$table} WHERE pid = " . (int) $cat['id'])['c'] ?? 0);
            if ($children <= 0) {
                $this->execute("DELETE FROM {$table} WHERE id = " . (int) $cat['id']);
            }
        }
    }

    /**
     * 确保「开发和调试」目录存在并返回其 id（不存在则创建，语义与初版迁移一致）
     */
    protected function ensureDevDir(string $table, int $now): int
    {
        $dir = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'dev' AND type = 'menu_dir' LIMIT 1");
        $devId = (int) ($dir['id'] ?? 0);
        if ($devId <= 0) {
            $this->table($table)->insert([
                [
                    'pid' => 0, 'type' => 'menu_dir', 'title' => '开发和调试', 'name' => 'dev',
                    'path' => 'dev', 'icon' => 'fa fa-terminal', 'status' => '1', 'weigh' => 82,
                    'create_time' => $now, 'update_time' => $now,
                ],
            ])->saveData();
            $dir = $this->fetchRow("SELECT id FROM {$table} WHERE name = 'dev' AND type = 'menu_dir' LIMIT 1");
            $devId = (int) ($dir['id'] ?? 0);
        }
        return $devId;
    }
}
