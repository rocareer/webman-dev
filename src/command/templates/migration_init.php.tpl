<?php
/**
 * rocareer/{{NAME}} 初始迁移：建表 + 菜单/权限幂等注册
 *
 * 幂等：hasTable 防重建表；菜单/按钮 INSERT 前先查重，重复执行安全。
 * 菜单约定：目录 name={{NAME}}；页面菜单 name/path={{NAME}}/{{LCC}}（path 必须等于 name，
 * 前端 auth() 以 /admin/ + name 为权限 key）；按钮 name 必须等于后端 routePath
 * （控制器类名末两段小写 + '/' + 方法名小写，全小写无连字符）。
 */

use Phinx\Migration\AbstractMigration;

class Radmin{{UC}}Init extends AbstractMigration
{
    public function up()
    {
        $this->createTables();
        $this->seedMenu();
    }

    public function down()
    {
        $prefix = getDbPrefix();
        $this->execute('DROP TABLE IF EXISTS ' . $prefix . '{{NAME}}');
        $this->execute("DELETE FROM {$prefix}admin_rule WHERE name = '{{NAME}}' OR name LIKE '{{NAME}}/%'");
    }

    protected function createTables(): void
    {
        $prefix = getDbPrefix();
        if ($this->hasTable($prefix . '{{NAME}}')) {
            return;
        }
        $table = $this->table($prefix . '{{NAME}}', ['id' => false, 'primary_key' => ['id']]);
        $table
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'comment' => '名称'])
            ->addColumn('status', 'string', ['limit' => 30, 'default' => 'enabled', 'comment' => '状态'])
            ->addColumn('create_time', 'biginteger', ['comment' => '创建时间'])
            ->addColumn('update_time', 'biginteger', ['comment' => '更新时间'])
            ->create();
    }

    protected function seedMenu(): void
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'admin_rule';
        $now = time();

        // 目录
        $dir = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{{NAME}}' AND type = 'menu_dir' LIMIT 1");
        $dirId = (int) ($dir['id'] ?? 0);
        if ($dirId <= 0) {
            $this->table($table)->insert([
                [
                    'pid' => 0, 'type' => 'menu_dir', 'title' => '{{TITLE}}', 'name' => '{{NAME}}',
                    'path' => '{{NAME}}', 'icon' => 'fa fa-cube', 'status' => '1', 'weigh' => 5,
                    'create_time' => $now, 'update_time' => $now,
                ],
            ])->saveData();
            $dir = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{{NAME}}' AND type = 'menu_dir' LIMIT 1");
            $dirId = (int) ($dir['id'] ?? 0);
        }
        if ($dirId <= 0) {
            return;
        }

        // 页面菜单（name/path 对齐 routePath：{{NAME}}/{{LCC}}）
        $menuName = '{{NAME}}/{{LCC}}';
        $menu = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{$menuName}' AND type = 'menu' LIMIT 1");
        if (!$menu) {
            $this->table($table)->insert([
                [
                    'pid' => $dirId, 'type' => 'menu', 'title' => '{{TITLE}}管理', 'name' => $menuName,
                    'path' => $menuName, 'icon' => 'fa fa-cube', 'menu_type' => 'tab',
                    'component' => '/src/views/backend/{{NAME}}/{{LCC}}/index.vue', 'keepalive' => 1,
                    'status' => '1', 'weigh' => 10,
                    'create_time' => $now, 'update_time' => $now,
                ],
            ])->saveData();
            $menu = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{$menuName}' AND type = 'menu' LIMIT 1");
        }
        if (!$menu) {
            return;
        }

        // 按钮（name = routePath，全小写无连字符）
        $buttons = [
            ['title' => '{{TITLE}}列表', 'name' => $menuName . '/index'],
            ['title' => '新增{{TITLE}}', 'name' => $menuName . '/add'],
            ['title' => '编辑{{TITLE}}', 'name' => $menuName . '/edit'],
            ['title' => '删除{{TITLE}}', 'name' => $menuName . '/del'],
        ];
        foreach ($buttons as $btn) {
            $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{$btn['name']}' LIMIT 1");
            if ($exist) {
                $this->execute("UPDATE {$table} SET pid = " . (int) $menu['id'] . ", status = '1', update_time = {$now} WHERE id = " . (int) $exist['id']);
                continue;
            }
            $this->table($table)->insert([
                [
                    'pid' => (int) $menu['id'], 'type' => 'button', 'title' => $btn['title'],
                    'name' => $btn['name'], 'status' => '1', 'weigh' => 0,
                    'create_time' => $now, 'update_time' => $now,
                ],
            ])->saveData();
        }
    }
}
