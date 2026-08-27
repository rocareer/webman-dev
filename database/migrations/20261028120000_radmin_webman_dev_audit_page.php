<?php
/**
 * webman-dev 工程质量审计后台页初始迁移（v3.1.0）
 *
 * 3 张表 + 「开发运维」菜单（挂到 radmin「开发和调试」目录 name=dev 下）+ 权限按钮 + 种子数据。
 * 幂等：表已存在跳过、菜单/按钮存在则纠正挂载、种子按 name 去重；重复执行安全。
 *
 * 表结构：
 * - radmin_dev_audit_rule：审计规则（code 与 AuditService::RULES 内置规则对应）
 * - radmin_dev_audit_project：审计项目（name = src 根下包目录名；last_* 为最近一轮快照）
 * - radmin_dev_audit_result：审计结果明细（每项目每规则每轮一行，detail 为问题明细 JSON）
 */

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

class RadminWebmanDevAuditPage extends AbstractMigration
{
    /** 规则种子：code => [title, description]（与 AuditService::RULES 语义一致） */
    protected const RULE_SEEDS = [
        'php_syntax' => ['PHP 语法检查', 'php -l 全量语法校验（批量子进程，单次调用）'],
        'controller' => ['控制器规范', '继承 Backend、: Response 签名、initialize 调 parent::initialize()、public 方法返回类型'],
        'permission' => ['权限节点匹配', '控制器方法 routePath 与迁移注册的按钮名比对：缺失/错名/孤儿按钮全部报出'],
        'migration' => ['迁移时间戳查重', 'Phinx 迁移文件时间戳冲突（撞号会阻断全家桶 migrate:run）'],
        'residue' => ['残留扫描', 'CRUD 脚手架死代码（Test 控制器/模型/验证器）+ TODO/FIXME 计数'],
        'version' => ['版本同步', 'CHANGELOG 头部版本 vs dev/full composer.json path 钉版'],
    ];

    /** 项目种子：包目录名 => 中文名 */
    protected const PROJECT_SEEDS = [
        'radmin' => '基础平台（radmin）',
        'ai' => 'AI 路由',
        'memory' => '语义记忆',
        'chat' => '对话/RAG',
        'agent' => '智能体',
        'knowledge' => '知识库',
        'asset' => '资源管理',
        'OIDC' => '统一认证',
        'oidc-client' => 'OIDC 客户端 SDK',
        'channel' => '推送订阅通道',
        'channel-client' => 'channel 客户端 SDK',
        'happ' => '实时通讯（WS）',
        'webman-migration' => '迁移工具',
    ];

    public function up()
    {
        $this->createRules();
        $this->createProjects();
        $this->createResults();
        $this->seedMenuRules();
    }

    public function down()
    {
        $this->removeMenuRules();
        foreach (['radmin_dev_audit_result', 'radmin_dev_audit_project', 'radmin_dev_audit_rule'] as $t) {
            $name = getDbPrefix() . $t;
            if ($this->hasTable($name)) {
                $this->table($name)->drop()->save();
            }
        }
    }

    // ==================== 建表 ====================

    protected function createRules(): void
    {
        $name = getDbPrefix() . 'radmin_dev_audit_rule';
        if ($this->hasTable($name)) {
            return;
        }
        $table = $this->table($name, [
            'id' => false, 'comment' => '审计规则', 'row_format' => 'DYNAMIC',
            'primary_key' => 'id', 'collation' => 'utf8mb4_unicode_ci',
        ]);
        $table->addColumn('id', 'biginteger', ['comment' => 'ID', 'signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 60, 'default' => '', 'comment' => '规则标识(与引擎内置 code 一致)', 'null' => false])
            ->addColumn('title', 'string', ['limit' => 100, 'default' => '', 'comment' => '规则名称', 'null' => false])
            ->addColumn('description', 'string', ['limit' => 500, 'default' => '', 'comment' => '规则说明', 'null' => false])
            ->addColumn('status', 'enum', ['values' => 'enabled,disabled', 'default' => 'enabled', 'comment' => '状态', 'null' => false])
            ->addColumn('weigh', 'integer', ['signed' => false, 'default' => 0, 'comment' => '排序(小者优先)', 'null' => false])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => '备注', 'null' => false])
            ->addColumn('create_time', 'biginteger', ['signed' => false, 'null' => true, 'default' => null, 'comment' => '创建时间'])
            ->addColumn('update_time', 'biginteger', ['signed' => false, 'null' => true, 'default' => null, 'comment' => '更新时间'])
            ->addIndex(['name'], ['type' => 'BTREE', 'unique' => true])
            ->addIndex(['status'], ['type' => 'BTREE'])
            ->create();
    }

    protected function createProjects(): void
    {
        $name = getDbPrefix() . 'radmin_dev_audit_project';
        if ($this->hasTable($name)) {
            return;
        }
        $table = $this->table($name, [
            'id' => false, 'comment' => '审计项目', 'row_format' => 'DYNAMIC',
            'primary_key' => 'id', 'collation' => 'utf8mb4_unicode_ci',
        ]);
        $table->addColumn('id', 'biginteger', ['comment' => 'ID', 'signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 60, 'default' => '', 'comment' => '包目录名(唯一)', 'null' => false])
            ->addColumn('title', 'string', ['limit' => 100, 'default' => '', 'comment' => '项目名称', 'null' => false])
            ->addColumn('status', 'enum', ['values' => 'enabled,disabled', 'default' => 'enabled', 'comment' => '状态', 'null' => false])
            ->addColumn('weigh', 'integer', ['signed' => false, 'default' => 0, 'comment' => '排序(小者优先)', 'null' => false])
            ->addColumn('last_run_at', 'biginteger', ['signed' => false, 'null' => true, 'default' => null, 'comment' => '最近一次审计时间'])
            ->addColumn('last_issue_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => '最近一轮问题总数', 'null' => false])
            ->addColumn('last_fail_rules', 'string', ['limit' => 500, 'default' => '', 'comment' => '最近一轮未通过规则(JSON)', 'null' => false])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => '备注', 'null' => false])
            ->addColumn('create_time', 'biginteger', ['signed' => false, 'null' => true, 'default' => null, 'comment' => '创建时间'])
            ->addColumn('update_time', 'biginteger', ['signed' => false, 'null' => true, 'default' => null, 'comment' => '更新时间'])
            ->addIndex(['name'], ['type' => 'BTREE', 'unique' => true])
            ->addIndex(['status'], ['type' => 'BTREE'])
            ->addIndex(['last_run_at'], ['type' => 'BTREE'])
            ->create();
    }

    protected function createResults(): void
    {
        $name = getDbPrefix() . 'radmin_dev_audit_result';
        if ($this->hasTable($name)) {
            return;
        }
        $table = $this->table($name, [
            'id' => false, 'comment' => '审计结果明细', 'row_format' => 'DYNAMIC',
            'primary_key' => 'id', 'collation' => 'utf8mb4_unicode_ci',
        ]);
        $table->addColumn('id', 'biginteger', ['comment' => 'ID', 'signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('project_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '项目ID', 'null' => false])
            ->addColumn('project_name', 'string', ['limit' => 60, 'default' => '', 'comment' => '项目包名(快照)', 'null' => false])
            ->addColumn('rule_code', 'string', ['limit' => 60, 'default' => '', 'comment' => '规则标识', 'null' => false])
            ->addColumn('rule_title', 'string', ['limit' => 100, 'default' => '', 'comment' => '规则名称(快照)', 'null' => false])
            ->addColumn('is_pass', 'integer', ['signed' => false, 'default' => 0, 'comment' => '是否通过:1=通过,0=未通过/未执行', 'null' => false])
            ->addColumn('issue_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => '问题总数', 'null' => false])
            ->addColumn('detail', 'text', ['null' => true, 'default' => null, 'comment' => '问题明细(JSON 数组)'])
            ->addColumn('run_at', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '审计轮次时间', 'null' => false])
            ->addColumn('create_time', 'biginteger', ['signed' => false, 'null' => true, 'default' => null, 'comment' => '创建时间'])
            ->addIndex(['project_id'], ['type' => 'BTREE'])
            ->addIndex(['run_at'], ['type' => 'BTREE'])
            ->addIndex(['is_pass'], ['type' => 'BTREE'])
            ->create();
    }

    // ==================== 种子 ====================

    protected function seedMenuRules(): void
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'admin_rule';
        $now = time();

        // 1) 确保「开发和调试」目录（name=dev，radmin/happ/channel 同语义）
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
        if ($devId <= 0) {
            return;
        }

        // 2) 三个 tab 菜单挂到 dev 目录下（path 必须等于 name：前端 auth() 以路由路径为权限节点 key）
        $menus = [
            [
                'title' => '审计项目', 'name' => 'audit/auditproject', 'path' => 'audit/auditproject',
                'component' => '/src/views/backend/audit/projects/index.vue',
                'icon' => 'fa fa-cubes', 'weigh' => 79,
            ],
            [
                'title' => '审计规则', 'name' => 'audit/auditrule', 'path' => 'audit/auditrule',
                'component' => '/src/views/backend/audit/rules/index.vue',
                'icon' => 'fa fa-list-alt', 'weigh' => 78,
            ],
            [
                'title' => '审计结果', 'name' => 'audit/auditresult', 'path' => 'audit/auditresult',
                'component' => '/src/views/backend/audit/results/index.vue',
                'icon' => 'fa fa-file-text-o', 'weigh' => 77,
            ],
        ];
        foreach ($menus as $menu) {
            $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{$menu['name']}' LIMIT 1");
            if ($exist) {
                $this->execute(
                    "UPDATE {$table} SET pid = {$devId}, type = 'menu', title = '{$menu['title']}', "
                    . "path = '{$menu['path']}', icon = '{$menu['icon']}', menu_type = 'tab', "
                    . "component = '{$menu['component']}', keepalive = 1, status = '1', weigh = {$menu['weigh']}, update_time = {$now} "
                    . "WHERE id = " . (int) $exist['id']
                );
                continue;
            }
            $this->table($table)->insert([
                [
                    'pid' => $devId, 'type' => 'menu', 'title' => $menu['title'],
                    'name' => $menu['name'], 'path' => $menu['path'], 'icon' => $menu['icon'],
                    'menu_type' => 'tab', 'component' => $menu['component'], 'keepalive' => 1,
                    'status' => '1', 'weigh' => $menu['weigh'],
                    'create_time' => $now, 'update_time' => $now,
                ],
            ])->saveData();
        }

        // 3) 按钮（name 必须与控制器 routePath 精确一致：audit/auditrule/index 等）
        $buttons = [
            ['title' => '规则列表', 'name' => 'audit/auditrule/index'],
            ['title' => '新增规则', 'name' => 'audit/auditrule/add'],
            ['title' => '编辑规则', 'name' => 'audit/auditrule/edit'],
            ['title' => '删除规则', 'name' => 'audit/auditrule/del'],
            ['title' => '启停规则', 'name' => 'audit/auditrule/switch'],
            ['title' => '项目列表', 'name' => 'audit/auditproject/index'],
            ['title' => '新增项目', 'name' => 'audit/auditproject/add'],
            ['title' => '编辑项目', 'name' => 'audit/auditproject/edit'],
            ['title' => '删除项目', 'name' => 'audit/auditproject/del'],
            ['title' => '启停项目', 'name' => 'audit/auditproject/switch'],
            ['title' => '运行审计', 'name' => 'audit/auditproject/run'],
            ['title' => '审计统计', 'name' => 'audit/auditproject/stats'],
            ['title' => '结果列表', 'name' => 'audit/auditresult/index'],
            ['title' => '删除结果', 'name' => 'audit/auditresult/del'],
            ['title' => '审计轮次', 'name' => 'audit/auditresult/runs'],
        ];
        foreach ($buttons as $btn) {
            $exist = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{$btn['name']}' LIMIT 1");
            if (!$exist) {
                $this->table($table)->insert([
                    [
                        'pid' => $devId, 'type' => 'button', 'title' => $btn['title'],
                        'name' => $btn['name'], 'status' => '1', 'weigh' => 0,
                        'create_time' => $now, 'update_time' => $now,
                    ],
                ])->saveData();
            }
        }

        // 4) 按钮挂到各自菜单下
        $map = [
            'audit/auditrule' => ['audit/auditrule/index', 'audit/auditrule/add', 'audit/auditrule/edit', 'audit/auditrule/del', 'audit/auditrule/switch'],
            'audit/auditproject' => ['audit/auditproject/index', 'audit/auditproject/add', 'audit/auditproject/edit', 'audit/auditproject/del', 'audit/auditproject/switch', 'audit/auditproject/run', 'audit/auditproject/stats'],
            'audit/auditresult' => ['audit/auditresult/index', 'audit/auditresult/del', 'audit/auditresult/runs'],
        ];
        foreach ($map as $menuName => $buttonNames) {
            $menu = $this->fetchRow("SELECT id FROM {$table} WHERE name = '{$menuName}' AND type = 'menu' LIMIT 1");
            if (!$menu) {
                continue;
            }
            $list = implode("','", $buttonNames);
            $this->execute("UPDATE {$table} SET pid = " . (int) $menu['id'] . " WHERE name IN ('{$list}')");
        }

        // 5) 规则种子（name 去重）
        $ruleTable = $prefix . 'radmin_dev_audit_rule';
        $weigh = 10;
        foreach (self::RULE_SEEDS as $code => [$title, $description]) {
            $exist = $this->fetchRow("SELECT id FROM {$ruleTable} WHERE name = '{$code}' LIMIT 1");
            if (!$exist) {
                $this->table($ruleTable)->insert([
                    [
                        'name' => $code, 'title' => $title, 'description' => $description,
                        'status' => 'enabled', 'weigh' => $weigh, 'remark' => '',
                        'create_time' => $now, 'update_time' => $now,
                    ],
                ])->saveData();
            }
            $weigh += 10;
        }

        // 6) 项目种子（name 去重）
        $projectTable = $prefix . 'radmin_dev_audit_project';
        $weigh = 10;
        foreach (self::PROJECT_SEEDS as $name => $title) {
            $exist = $this->fetchRow("SELECT id FROM {$projectTable} WHERE name = '{$name}' LIMIT 1");
            if (!$exist) {
                $this->table($projectTable)->insert([
                    [
                        'name' => $name, 'title' => $title, 'status' => 'enabled', 'weigh' => $weigh,
                        'last_run_at' => null, 'last_issue_count' => 0, 'last_fail_rules' => '', 'remark' => '',
                        'create_time' => $now, 'update_time' => $now,
                    ],
                ])->saveData();
            }
            $weigh += 10;
        }
    }

    protected function removeMenuRules(): void
    {
        $prefix = getDbPrefix();
        $table = $prefix . 'admin_rule';
        $names = implode("','", [
            'audit/auditrule', 'audit/auditproject', 'audit/auditresult',
            'audit/auditrule/index', 'audit/auditrule/add', 'audit/auditrule/edit', 'audit/auditrule/del', 'audit/auditrule/switch',
            'audit/auditproject/index', 'audit/auditproject/add', 'audit/auditproject/edit', 'audit/auditproject/del', 'audit/auditproject/switch',
            'audit/auditproject/run', 'audit/auditproject/stats',
            'audit/auditresult/index', 'audit/auditresult/del', 'audit/auditresult/runs',
        ]);
        $this->execute("DELETE FROM {$table} WHERE name IN ('{$names}')");
    }
}