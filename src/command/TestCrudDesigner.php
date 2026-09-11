<?php
/**
 * test:crud-designer — CRUD 设计态契约自检（v1 逐字节回归 + v2 增强能力断言）
 *
 * 用途：守护 webman-dev 的设计态契约（CrudDesigner / CrudDesignService）——
 *   [1] v1 旧设计 JSON 的 parse 输出与冻结基线逐字节一致（新增 v2 键不得改变 v1 行为）
 *   [2] v2 增强：options -> comment 字典 / remote 关联字段 / form+table 字段级属性 /
 *       default_sort / form_layout 决定表单项顺序
 *   [3] CrudDesignService::sanitize 反幻觉净化（未知键丢弃、remote 缺 table 降级）
 *   [4] CrudDesignService::validate 结构化错误（缺 comment / 非法 design_type / 重复字段名 /
 *       未声明字段引用 / 关联缺表）
 *   [5] export 反向导出 round-trip（需宿主 DB 与已生成记录；无记录时跳过不判失败）
 *   [6] 草稿→确认→出码 链路（--pipeline，需宿主 DB；临时表用后即清）
 *
 * 用法：
 *   php webman test:crud-designer                # 全量自检（推荐）
 *   php webman test:crud-designer --export=表名  # 额外验证某表的反向导出 round-trip
 *   php webman test:crud-designer --pipeline     # 额外验证草稿/确认/导出链路（写临时表后清理）
 *
 * 运行环境：webman 宿主 + rocareer/webman-dev（--export/--pipeline 需宿主 DB 可连）。
 * 注意：默认仅做纯内存断言，不写盘、不建表、不调 LLM；--pipeline 会生成临时模块并回收。
 */

namespace Rocareer\WebmanDev\command;

use Rocareer\WebmanDev\support\CrudDesigner;
use Rocareer\WebmanDev\support\CrudDesignService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class TestCrudDesigner extends Command
{
    protected static $defaultName = 'test:crud-designer';

    protected static $defaultDescription = 'CRUD 设计态契约自检（v1 逐字节回归 + v2 增强 + 净化/校验/反导出）';

    /**
     * v1 契约冻结黄金哈希（md5(stableJson(parse(V1_DESIGN) 去除 ts/migration))）
     * 见 [1k] 断言；变更 v1 输出即失败，须显式复核后用 --print-hash 更新。
     */
    protected const V1_GOLDEN_MD5 = '15eeb4b1a0d021dfd3a4fd7f2ecd7d69';

    /** v1 冻结基线：旧格式设计的 parse 输出（migration 与 ts 为时间相关，排除后逐字节比对） */
    protected const V1_DESIGN = [
        'table' => ['name' => 'cc_student', 'comment' => '学员管理', 'quick_search' => ['name', 'mobile']],
        'fields' => [
            ['name' => 'name', 'comment' => '姓名', 'design_type' => 'input', 'length' => 50, 'required' => true],
            ['name' => 'mobile', 'comment' => '手机号', 'design_type' => 'input', 'length' => 20],
            ['name' => 'level', 'comment' => '等级: 1=初级,2=中级,3=高级', 'design_type' => 'select', 'default' => '1'],
            ['name' => 'status', 'comment' => '状态: 0=禁用,1=启用', 'design_type' => 'switch', 'default' => '1'],
            ['name' => 'weigh', 'comment' => '排序', 'design_type' => 'weigh'],
        ],
    ];

    protected function configure(): void
    {
        $this->addOption('export', null, InputOption::VALUE_REQUIRED, '额外验证反向导出 round-trip 的表名');
        $this->addOption('pipeline', null, InputOption::VALUE_NONE, '额外验证草稿/确认/出码/导出链路（写临时模块后回收）');
        $this->addOption('print-hash', null, InputOption::VALUE_NONE, '打印 v1 契约黄金哈希（变更 v1 输出后显式复核用）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pass = 0;
        $fail = [];

        if ($input->getOption('print-hash')) {
            $p = (new CrudDesigner())->parse(self::V1_DESIGN);
            unset($p['ts'], $p['migration']);
            $io->writeln(md5($this->stableJson($p)));
            return self::SUCCESS;
        }

        // ---- [1] v1 逐字节回归 ----
        try {
            $p = (new CrudDesigner())->parse(self::V1_DESIGN);
            $this->assertSame(
                ['name', 'mobile', 'level', 'status', 'weigh'],
                $p['table']['formFields'], '[1a] v1 缺省 formFields', $pass, $fail
            );
            $this->assertSame(
                ['id', 'name', 'mobile', 'level', 'status', 'weigh'],
                $p['table']['columnFields'], '[1b] v1 缺省 columnFields', $pass, $fail
            );
            $this->assertSame('id', $p['table']['defaultSortField'], '[1c] v1 默认排序字段', $pass, $fail);
            $this->assertSame('desc', $p['table']['defaultSortType'], '[1d] v1 默认排序方向', $pass, $fail);
            $this->assertFalse((bool) $p['table']['isCommonModel'], '[1e] v1 非通用模型', $pass, $fail);
            $this->assertSame('pk', $p['fields'][0]['designType'], '[1f] v1 自动注入 id 主键', $pass, $fail);
            $this->assertTrue((bool) $p['fields'][0]['primaryKey'], '[1g] v1 id 主键标志', $pass, $fail);
            $last = $p['fields'][count($p['fields']) - 1];
            $this->assertSame('update_time', $last['name'], '[1h] v1 自动补时间戳', $pass, $fail);
            // select 字段 designType 与 comment 字典保持原样（未被 options 逻辑改动）
            $level = $this->findField($p['fields'], 'level');
            $this->assertSame('select', $level['designType'], '[1i] v1 select 类型不变', $pass, $fail);
            $this->assertSame('等级: 1=初级,2=中级,3=高级', $level['comment'], '[1j] v1 comment 字典不被重写', $pass, $fail);
            // 稳定序列化比对（排除 ts 与 migration 两个时间相关键）：
            // 黄金哈希 = v1 契约冻结基线；v2 新增键不得改变 v1 输出。
            // 重算方式：php webman test:crud-designer --print-hash（仅在确认 v1 输出确实应当变更时更新）
            $snap = $p;
            unset($snap['ts'], $snap['migration']);
            $this->assertSame(
                self::V1_GOLDEN_MD5,
                md5($this->stableJson($snap)),
                '[1k] v1 契约黄金哈希（输出逐字节未变）', $pass, $fail
            );
        } catch (Throwable $e) {
            $fail[] = '[1] v1 回归异常：' . $e->getMessage();
        }

        // ---- [2] v2 增强能力 ----
        $svc = new CrudDesignService();
        try {
            $v2 = [
                'version' => 2,
                'table' => [
                    'name' => 'erp_customer', 'comment' => '客户档案',
                    'quick_search' => ['name', 'contact'],
                    'default_sort' => ['field' => 'weigh', 'type' => 'asc'],
                    'form_layout' => [
                        ['group' => '基本信息', 'fields' => ['name', 'level']],
                        ['group' => '联系方式', 'fields' => ['contact']],
                    ],
                ],
                'fields' => [
                    ['name' => 'name', 'comment' => '客户名称', 'design_type' => 'input'],
                    ['name' => 'level', 'comment' => '客户等级', 'design_type' => 'select',
                        'options' => [['label' => '战略', 'value' => 'A'], ['label' => '重要', 'value' => 'B']]],
                    ['name' => 'owner_id', 'comment' => '负责人', 'design_type' => 'remote_select',
                        'remote' => ['table' => 'admin', 'pk' => 'id', 'field' => 'nickname']],
                    ['name' => 'contact', 'comment' => '联系人', 'design_type' => 'input'],
                    ['name' => 'amount', 'comment' => '金额', 'design_type' => 'float',
                        'form' => ['step' => 100], 'table' => ['render' => 'money', 'width' => 120]],
                    ['name' => 'weigh', 'comment' => '排序', 'design_type' => 'weigh'],
                ],
            ];
            $s = $svc->sanitize($v2);
            $errs = $svc->validate($s['design']);
            $this->assertSame([], array_column($errs, 'code'), '[2a] v2 设计无校验错误', $pass, $fail);
            $p = (new CrudDesigner())->parse($s['design']);

            // form_layout 决定表单项顺序（布局内字段按组序在前，未列入布局的按原序追加末尾）
            $this->assertSame(
                ['name', 'level', 'contact', 'owner_id', 'amount', 'weigh'],
                $p['table']['formFields'], '[2b] form_layout 决定表单项顺序', $pass, $fail
            );
            $this->assertSame('weigh', $p['table']['defaultSortField'], '[2c] v2 自定义默认排序字段', $pass, $fail);
            $this->assertSame('asc', $p['table']['defaultSortType'], '[2d] v2 自定义默认排序方向', $pass, $fail);

            // options -> comment 字典（引擎据此生成语言包）
            $lv = $this->findField($p['fields'], 'level');
            $this->assertSame('客户等级: A=战略,B=重要', $lv['comment'], '[2e] options 转 comment 字典', $pass, $fail);

            // remote -> 引擎 remoteSelect 契约（designType + form 键 + controller 推导）
            $own = $this->findField($p['fields'], 'owner_id');
            $this->assertSame('remoteSelect', $own['designType'], '[2f] remote_select 类型映射', $pass, $fail);
            $this->assertSame('admin', $own['form']['remote-table'] ?? '', '[2g] remote-table 落地', $pass, $fail);
            $this->assertSame('crud', $own['form']['remote-source-config-type'] ?? '', '[2h] remote 配置类型=crud', $pass, $fail);
            $this->assertSame('Admin', $own['form']['remote-controller'] ?? '', '[2i] remote controller 自动推导', $pass, $fail);
            $this->assertSame('bigint', $own['type'], '[2j] remote_select DB 类型', $pass, $fail);
            $this->assertTrue(str_contains($p['migration'], "addColumn('owner_id', 'biginteger'"), '[2k] remote 列进迁移', $pass, $fail);

            // form/table 字段级属性落地
            $amt = $this->findField($p['fields'], 'amount');
            $this->assertSame(100, $amt['form']['step'] ?? null, '[2l] form.step 落地', $pass, $fail);
            $this->assertSame('money', $amt['table']['render'] ?? '', '[2m] table.render 落地', $pass, $fail);
            $this->assertSame(120, $amt['table']['width'] ?? null, '[2n] table.width 强转为整型', $pass, $fail);
            $this->assertSame('decimal', $amt['type'], '[2o] float 仍为 decimal', $pass, $fail);
        } catch (Throwable $e) {
            $fail[] = '[2] v2 断言异常：' . $e->getMessage();
        }

        // ---- [3] sanitize 反幻觉净化 ----
        try {
            $dirty = [
                'version' => 2,
                'table' => ['name' => 'cc_x', 'comment' => 'X', 'bogus_key' => 1,
                    'default_sort' => 'weigh:asc'],
                'fields' => [
                    ['name' => 'a', 'comment' => 'A', 'design_type' => 'input', 'unknown' => 'x',
                        'form' => ['step' => 5, 'bad_attr' => 1], 'table' => ['width' => 80, 'nope' => 1]],
                    ['name' => 'b', 'comment' => 'B', 'design_type' => 'remote_select',
                        'remote' => ['pk' => 'id']],
                ],
            ];
            $s = $svc->sanitize($dirty);
            $this->assertFalse(array_key_exists('bogus_key', $s['design']['table']), '[3a] table 未知键丢弃', $pass, $fail);
            $this->assertSame('weigh', $s['design']['table']['default_sort']['field'] ?? '', '[3b] default_sort 字符串归一', $pass, $fail);
            $this->assertSame('asc', $s['design']['table']['default_sort']['type'] ?? '', '[3c] default_sort 方向归一', $pass, $fail);
            $fa = $s['design']['fields'][0];
            $this->assertFalse(array_key_exists('unknown', $fa), '[3d] 字段未知键丢弃', $pass, $fail);
            $this->assertSame(5, $fa['form']['step'] ?? null, '[3e] 合法 form 属性保留', $pass, $fail);
            $this->assertFalse(array_key_exists('bad_attr', $fa['form'] ?? []), '[3f] 非法 form 属性丢弃', $pass, $fail);
            $this->assertFalse(array_key_exists('nope', $fa['table'] ?? []), '[3g] 非法 table 属性丢弃', $pass, $fail);
            $this->assertFalse(array_key_exists('remote', $s['design']['fields'][1]), '[3h] remote 缺 table 降级', $pass, $fail);
            $this->assertNotEmpty($s['warnings'], '[3i] 净化产生告警', $pass, $fail);
        } catch (Throwable $e) {
            $fail[] = '[3] sanitize 断言异常：' . $e->getMessage();
        }

        // ---- [4] validate 结构化错误 ----
        try {
            $bad = [
                'table' => ['name' => 'Bad-Name', 'comment' => ''],
                'fields' => [
                    ['name' => 'a', 'comment' => '', 'design_type' => 'nope'],
                    ['name' => 'a', 'comment' => 'A连', 'design_type' => 'input'],
                    ['name' => 'b', 'comment' => 'B', 'design_type' => 'remote_select'],
                ],
            ];
            $codes = array_column($svc->validate($bad), 'code');
            $this->assertTrue(in_array('TABLE_NAME_INVALID', $codes, true), '[4a] 表名非法错误码', $pass, $fail);
            $this->assertTrue(in_array('TABLE_COMMENT_REQUIRED', $codes, true), '[4b] 表 comment 必填', $pass, $fail);
            $this->assertTrue(in_array('FIELD_COMMENT_REQUIRED', $codes, true), '[4c] 字段 comment 必填', $pass, $fail);
            $this->assertTrue(in_array('FIELD_DESIGN_TYPE_INVALID', $codes, true), '[4d] 控件类型非法', $pass, $fail);
            $this->assertTrue(in_array('FIELD_NAME_DUPLICATE', $codes, true), '[4e] 字段名重复', $pass, $fail);
            $this->assertTrue(in_array('FIELD_REMOTE_TABLE_REQUIRED', $codes, true), '[4f] 关联缺表', $pass, $fail);
            // 结构化：每条含 field/code/message
            $first = $svc->validate($bad)[0];
            $this->assertTrue(isset($first['field'], $first['code'], $first['message']), '[4g] 错误项结构完整', $pass, $fail);
            // 未声明字段引用
            $ref = $svc->validate(['table' => ['name' => 'cc_x', 'comment' => 'X', 'quick_search' => ['ghost']],
                'fields' => [['name' => 'a', 'comment' => 'A', 'design_type' => 'input']]]);
            $this->assertTrue(in_array('LIST_FIELD_UNDEFINED', array_column($ref, 'code'), true), '[4h] 未声明字段引用', $pass, $fail);
        } catch (Throwable $e) {
            $fail[] = '[4] validate 断言异常：' . $e->getMessage();
        }

        // ---- [5] export round-trip（可选，需 DB + 已生成记录） ----
        $exportTable = (string) $input->getOption('export');
        if ($exportTable !== '') {
            try {
                $design = $svc->export($exportTable);
                if ($design === null) {
                    $io->note("[5] 表 {$exportTable} 无 success 生成记录，跳过 round-trip（非失败）");
                } else {
                    $re = (new CrudDesigner())->parse($design);
                    $this->assertSame($exportTable, $re['table_name'], '[5a] 导出设计可重新解析', $pass, $fail);
                    $this->assertNotEmpty($re['fields'], '[5b] 导出含业务字段', $pass, $fail);
                }
            } catch (Throwable $e) {
                $fail[] = '[5] export 断言异常：' . $e->getMessage();
            }
        }

        // ---- [6] 草稿→确认→出码→导出 链路（可选；需 DB，写临时模块后回收） ----
        if ($input->getOption('pipeline')) {
            try {
                $this->pipeline($svc, $io, $pass, $fail);
            } catch (Throwable $e) {
                $fail[] = '[6] 链路断言异常：' . $e->getMessage();
            }
        }

        // ---- 汇总 ----
        if ($fail) {
            $io->error("CRUD 设计态契约自检失败（{$pass} 通过 / " . count($fail) . ' 失败）');
            $io->listing($fail);
            return self::FAILURE;
        }
        $io->success("CRUD 设计态契约自检通过（{$pass} 项断言）");
        return self::SUCCESS;
    }

    /**
     * [6] 草稿 → 确认出码 → 反导出 端到端（写临时模块 p1probe_* 后回收）
     */
    protected function pipeline(CrudDesignService $svc, SymfonyStyle $io, int &$pass, array &$fail): void
    {
        if (!class_exists(\app\admin\model\CrudDesignDraft::class) || !class_exists(\app\admin\service\CrudDesignAgentService::class)) {
            $io->note('[6] 宿主缺 CrudDesignDraft/CrudDesignAgentService（旧版 webman-dev），跳过链路自检');
            return;
        }
        $table = 'zzcrudprobe_p' . substr((string) time(), -5);
        $design = [
            'version' => 2,
            'table' => ['name' => $table, 'comment' => 'CRUD链路探针'],
            'fields' => [
                ['name' => 'title', 'comment' => '名称', 'design_type' => 'input'],
                ['name' => 'level', 'comment' => '等级', 'design_type' => 'select',
                    'options' => [['label' => '高', 'value' => 'H'], ['label' => '低', 'value' => 'L']]],
            ],
        ];

        $draft = \app\admin\model\CrudDesignDraft::create([
            'request_id' => 'probe-' . bin2hex(random_bytes(6)),
            'table_name' => $table,
            'prompt' => '链路自检',
            'design' => $design,
            'status' => \app\admin\model\CrudDesignDraft::STATUS_SUGGESTED,
            'rounds' => 1,
            'admin_id' => 1,
        ]);
        $this->assertTrue((int) $draft->id > 0, '[6a] 草稿落库', $pass, $fail);

        // confirm 出码
        $receipt = (new \app\admin\service\CrudDesignAgentService())->confirm((int) $draft->id, true);
        $this->assertTrue(!empty($receipt['ok']), '[6b] 确认出码成功（' . ($receipt['message'] ?? ($receipt['error_code'] ?? '')) . '）', $pass, $fail);
        $this->assertSame($table, $receipt['table'] ?? '', '[6c] 出码表名一致', $pass, $fail);
        $draft->refresh();
        $this->assertSame(\app\admin\model\CrudDesignDraft::STATUS_CONFIRMED, $draft->status, '[6d] 草稿状态转 confirmed', $pass, $fail);

        // 反导出（该表刚有 success 记录）
        $exp = $svc->export($table);
        $this->assertTrue(is_array($exp), '[6e] 反导出成功', $pass, $fail);
        if (is_array($exp)) {
            $this->assertSame(2, count($exp['fields'] ?? []), '[6f] 反导出字段数', $pass, $fail);
            $lv = $this->findField($exp['fields'], 'level');
            $this->assertNotEmpty($lv['options'] ?? [], '[6g] 反导出还原 options', $pass, $fail);
        }
        // dump 重新解析
        if (is_array($exp)) {
            $re = (new CrudDesigner())->parse($exp);
            $this->assertSame($table, $re['table_name'], '[6h] 反导出可重解析', $pass, $fail);
        }

        // 回收：删代码 + 表 + 迁移 + 菜单 + 记录
        $base = function_exists('base_path') ? (string) base_path() : (string) getcwd();
        foreach (CrudDesigner::targetFiles($table) as $f) {
            $abs = $base . '/' . $f;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        foreach (['app/admin/controller', 'app/admin/model', 'app/admin/validate'] as $d) {
            // 目录层级探针名无下划线，落根目录
        }
        foreach (glob($base . '/database/migrations/*_' . $table . '_crud.php') ?: [] as $f) {
            @unlink($f);
        }
        try {
            \support\Db::statement('DROP TABLE IF EXISTS ' . getDbPrefix() . $table . ' CASCADE');
        } catch (Throwable $e) {
            // 忽略
        }
        try {
            \app\admin\model\CrudLog::where('table_name', $table)->update(['status' => 'delete']);
            $draft->delete();
            // 菜单（name=zzcrudprobe_xxx -> zzcrudprobe/xxx）
            $menuName = str_replace('_', '/', $table);
            if (class_exists(\app\admin\library\Menu::class)) {
                \app\admin\library\Menu::delete($menuName, true);
            }
        } catch (Throwable $e) {
            // 忽略
        }
        $io->note("[6] 链路自检完成，临时模块 {$table} 已回收");
    }

    /**
     * 断言相等（记通过/失败，不中断，便于一次列出全部问题）
     */
    protected function assertSame($expected, $actual, string $label, int &$pass, array &$fail): void
    {
        if ($expected === $actual) {
            $pass++;
            return;
        }
        $fail[] = $label . '：期望 ' . $this->dump($expected) . '，实际 ' . $this->dump($actual);
    }

    protected function assertTrue(bool $cond, string $label, int &$pass, array &$fail): void
    {
        if ($cond) {
            $pass++;
            return;
        }
        $fail[] = $label . '：断言为真失败';
    }

    protected function assertFalse(bool $cond, string $label, int &$pass, array &$fail): void
    {
        $this->assertTrue(!$cond, $label, $pass, $fail);
    }

    protected function assertNotEmpty($v, string $label, int &$pass, array &$fail): void
    {
        $this->assertTrue(!empty($v), $label, $pass, $fail);
    }

    protected function dump($v): string
    {
        return is_scalar($v) || $v === null
            ? var_export($v, true)
            : json_unicode($v);
    }

    /**
     * 稳定 JSON（递归按键名排序，消除键序差异导致的哈希漂移）
     */
    protected function stableJson($v): string
    {
        $v = $this->ksortRecursive($v);
        return json_unicode($v);
    }

    protected function ksortRecursive($v)
    {
        if (is_array($v)) {
            // 列表保持原序（顺序即语义），仅关联数组按键排序
            if (array_is_list($v)) {
                return array_map(fn ($x) => $this->ksortRecursive($x), $v);
            }
            ksort($v);
            foreach ($v as $k => $x) {
                $v[$k] = $this->ksortRecursive($x);
            }
        }
        return $v;
    }

    protected function findField(array $fields, string $name): array
    {
        foreach ($fields as $f) {
            if ($f['name'] === $name) {
                return $f;
            }
        }
        return [];
    }
}
