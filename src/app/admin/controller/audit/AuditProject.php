<?php

namespace app\admin\controller\audit;

use app\admin\model\DevAuditProject;
use app\admin\model\DevAuditResult;
use app\admin\model\DevAuditRule;
use app\admin\service\AuditService;
use app\common\controller\Backend;
use support\Response;
use Throwable;

/**
 * 审计项目管理（webman-dev 工程质量审计）
 *
 * 路由：/admin/audit/auditproject/{index|add|edit|del|switch|run|stats}
 * 参数：index 分页（page/limit/quickSearch）；run POST ids/a（空=全部启用项目）；
 *       stats GET 顶部统计条。
 * 说明：项目表 name 为 src 根下的包目录名（radmin/ai/...）；run 执行整轮审计并落库
 *       dev_audit_result，同时回写项目快照（last_run_at/last_issue_count/last_fail_rules）。
 *       run 为管理员手动触发（php -l 批量子进程），非 LLM 链路；缺省同步返回汇总，
 *       async=1 时经 rocareer/queue 作业层投宿主 audit-run 队列异步执行（返回 job_id）。
 */
class AuditProject extends Backend
{
    /** 快速搜索字段：包名/项目名 */
    protected array|string $quickSearchField = 'name,title';

    /** 快照字段不允许表单写入 */
    protected array|string $preExcludeFields = 'id,create_time,update_time,last_run_at,last_issue_count,last_fail_rules';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new DevAuditProject();
    }

    /**
     * 项目列表（分页 + 搜索；自带最近一轮审计快照列）
     */
    public function index(): Response
    {
        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $query = $this->model
            ->from($this->queryFromTable($alias))
            ->where(function ($query) use ($where) {
                $this->applyWhereArray($query, $where);
            });
        // 排序必须先于 paginate 应用（LengthAwarePaginator 无 orderBy；与 trait queryList 顺序一致）
        $this->applyOrderBy($query, $order);
        $res = $query->paginate($limit);
        $items = [];
        foreach ($res->items() as $row) {
            $item = $row->toArray();
            // 未通过规则列表（JSON）转数组供前端展示
            $failRules = $item['last_fail_rules'] ?? '';
            $item['last_fail_rules_arr'] = $failRules === '' ? [] : (json_decode((string) $failRules, true) ?: []);
            $items[] = $item;
        }
        return $this->success('', [
            'list' => $items,
            'total' => $res->total(),
        ]);
    }

    /**
     * 新增项目（name/title 必填；name 需与 src 根下包目录名一致）
     */
    public function add(): Response
    {
        $data = $this->request->post();
        if (empty($data['name']) || empty($data['title'])) {
            return $this->error('name/title 均不能为空');
        }
        try {
            $data = $this->excludeFields($data);
            $row = new DevAuditProject();
            $this->fill($row, $data);
            $row->save();
        } catch (Throwable $e) {
            return $this->error('添加失败: ' . $e->getMessage());
        }
        return $this->success('', ['id' => (int) $row->id]);
    }

    /**
     * 编辑项目（GET：回填单行；POST：保存）
     */
    public function edit(): Response
    {
        $id = (int) ($this->request->method() === 'GET' ? $this->request->input('id', 0) : $this->request->post('id', 0));
        $row = DevAuditProject::find($id);
        if (!$row) {
            return $this->error('项目不存在');
        }
        if ($this->request->method() === 'GET') {
            return $this->success('', ['row' => $row]);
        }
        try {
            $this->fill($row, $this->excludeFields($this->request->post()));
            $row->save();
        } catch (Throwable $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }
        return $this->success('');
    }

    /**
     * 删除项目（DELETE：ids 批量；POST：单条 id；连带删除其结果明细）
     */
    public function del(): Response
    {
        if ($this->request->method() === 'DELETE') {
            $ids = $this->request->input('ids/a', []);
            if (!is_array($ids)) {
                return $this->error('ids 无效');
            }
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    DevAuditResult::where('project_id', $id)->delete();
                    DevAuditProject::where('id', $id)->delete();
                }
            }
            return $this->success('');
        }
        $id = (int) $this->request->post('id', 0);
        $row = DevAuditProject::find($id);
        if (!$row) {
            return $this->error('项目不存在');
        }
        DevAuditResult::where('project_id', $id)->delete();
        $row->delete();
        return $this->success('');
    }

    /**
     * 启停项目（停用的项目不参与运行审计）
     */
    public function switch(): Response
    {
        $id = (int) $this->request->post('id', 0);
        $status = (string) $this->request->post('status', '');
        if (!in_array($status, [DevAuditProject::STATUS_ENABLED, DevAuditProject::STATUS_DISABLED], true)) {
            return $this->error('status 无效');
        }
        $row = DevAuditProject::find($id);
        if (!$row) {
            return $this->error('项目不存在');
        }
        $row->status = $status;
        $row->save();
        return $this->success('');
    }

    /**
     * 运行审计（核心）
     *
     * POST /admin/audit/auditproject/run
     * 参数：ids/a 可选（空 = 全部启用项目）；async=1 可选（作业化运行）。
     * 流程：同步（缺省）= 取启用的规则 code + 项目包名 -> AuditService::runProjects 全量审计
     *       -> 结果写入 dev_audit_result（每项目每规则一行，run_at 为轮次）+ 回写项目快照列，
     *       返回本轮汇总供前端提示；
     *       异步（async=1，2026-09-22 加，Rolling「长任务交互全走 WS 回流」）= 校验后经作业层
     *       （rocareer/queue 的 Job）投宿主 `audit-run` 队列立即返回 job_id——执行与收口在队列
     *       消费（宿主 AuditRunConsumer 调同一 runProjects），终态随 `job.settled` 帧定向推给
     *       提交者（受众=作业层提交时解析器），前端 waitJob 零轮询收尾。作业层未安装时不静默
     *       降级，显式报错。
     */
    public function run(): Response
    {
        // ids 兼容数组/逗号分隔串（webman post 不支持 /a 修饰符，这里显式归一化）
        $raw = $this->request->post('ids');
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        $ids = is_array($raw) ? array_values(array_filter(array_map('intval', $raw))) : [];

        if (filter_var($this->request->post('async'), FILTER_VALIDATE_BOOL)) {
            if (!class_exists(\app\queue\service\Job::class)) {
                return $this->error('当前环境未安装 rocareer/queue 作业层，不支持异步审计（可去掉 async 参数走同步）');
            }
            try {
                $jobId = \app\queue\service\Job::submit('audit-run', ['biz_kind' => 'audit_run', 'ids' => $ids], 'audit_run');
            } catch (Throwable $e) {
                return $this->error('异步审计投递失败: ' . $e->getMessage());
            }
            return $this->success('已投递后台审计', ['job_id' => $jobId, 'async' => true]);
        }

        try {
            return $this->success('审计完成', (new AuditService())->runProjects($ids));
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 顶部统计（审计项目页总览条）
     *
     * GET /admin/audit/auditproject/stats
     * 返回：项目/规则数量 + 最近一轮汇总（时间/问题总数/通过与未通过项目数）。
     */
    public function stats(): Response
    {
        $lastRunAt = (int) DevAuditResult::max('run_at');
        $stats = [
            'project_total' => (int) DevAuditProject::count(),
            'project_enabled' => (int) DevAuditProject::where('status', DevAuditProject::STATUS_ENABLED)->count(),
            'rule_total' => (int) DevAuditRule::count(),
            'rule_enabled' => (int) DevAuditRule::where('status', DevAuditRule::STATUS_ENABLED)->count(),
            'last_run_at' => $lastRunAt,
            'last_issue_total' => 0,
            'last_pass_projects' => 0,
            'last_fail_projects' => 0,
        ];
        if ($lastRunAt > 0) {
            $stats['last_issue_total'] = (int) DevAuditProject::where('last_run_at', $lastRunAt)->sum('last_issue_count');
            $stats['last_fail_projects'] = (int) DevAuditProject
                ::where('last_run_at', $lastRunAt)->where('last_issue_count', '>', 0)->count();
            $stats['last_pass_projects'] = (int) DevAuditProject
                ::where('last_run_at', $lastRunAt)->where('last_issue_count', 0)->count();
        }
        return $this->success('', $stats);
    }

    /**
     * 表单字段落库（白名单 + 类型规整）
     */
    protected function fill(DevAuditProject $row, array $data): void
    {
        foreach (['name', 'title', 'remark'] as $field) {
            if (isset($data[$field])) {
                $row->$field = (string) $data[$field];
            }
        }
        if (isset($data['weigh'])) {
            $row->weigh = max(0, (int) $data['weigh']);
        }
        if (isset($data['status'])) {
            $row->status = in_array($data['status'], [DevAuditProject::STATUS_ENABLED, DevAuditProject::STATUS_DISABLED], true)
                ? $data['status'] : DevAuditProject::STATUS_ENABLED;
        }
    }
}