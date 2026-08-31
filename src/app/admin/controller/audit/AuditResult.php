<?php

namespace app\admin\controller\audit;

use app\admin\model\DevAuditResult;
use app\common\controller\Backend;
use support\Response;

/**
 * 审计结果明细（webman-dev 工程质量审计）
 *
 * 路由：/admin/audit/auditresult/{index|del|runs}
 * 参数：index 分页（page/limit/quickSearch + project_id/rule_code/is_pass/run_at 筛选；
 *       latest=1 只看最近一轮）；del DELETE ids/a；runs GET 历史轮次列表。
 * 说明：每一行 = 某项目某规则一次审计的结果（问题明细在 detail JSON，完整数量在 issue_count）。
 */
class AuditResult extends Backend
{
    /** 快速搜索字段：项目名/规则名 */
    protected array|string $quickSearchField = 'project_name,rule_title';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new DevAuditResult();
    }

    /**
     * 结果列表（默认按审计时间倒序；latest=1 只看最近一轮）
     */
    public function index(): Response
    {
        $query = DevAuditResult::orderBy('run_at', 'desc')->orderBy('id', 'desc');
        $latest = (int) $this->request->input('latest', 0);
        if ($latest) {
            $max = (int) DevAuditResult::max('run_at');
            if ($max > 0) {
                $query = $query->where('run_at', $max);
            }
        }
        $projectId = (int) $this->request->input('project_id', 0);
        if ($projectId > 0) {
            $query = $query->where('project_id', $projectId);
        }
        $ruleCode = (string) $this->request->input('rule_code', '');
        if ($ruleCode !== '') {
            $query = $query->where('rule_code', $ruleCode);
        }
        $isPass = (string) $this->request->input('is_pass', '');
        if ($isPass !== '') {
            $query = $query->where('is_pass', (int) $isPass);
        }
        $runAt = (int) $this->request->input('run_at', 0);
        if ($runAt > 0) {
            $query = $query->where('run_at', $runAt);
        }
        $keyword = (string) $this->request->input('quickSearch', '');
        if ($keyword !== '') {
            $query = keyword_like($query, ['project_name', 'rule_title'], $keyword);
        }
        $limit = clamp_limit((int) $this->request->input('limit', 10));
        $page = clamp_page((int) $this->request->input('page', 1));
        $paginator = $query->paginate($limit, ['*'], 'page', $page);
        return $this->success('', [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * 删除结果（DELETE：ids 批量；POST：单条 id）
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
                    DevAuditResult::where('id', $id)->delete();
                }
            }
            return $this->success('');
        }
        $id = (int) $this->request->post('id', 0);
        $row = DevAuditResult::find($id);
        if (!$row) {
            return $this->error('记录不存在');
        }
        $row->delete();
        return $this->success('');
    }

    /**
     * 历史审计轮次（结果页「审计轮次」下拉：distinct run_at 倒序）
     */
    public function runs(): Response
    {
        $rows = DevAuditResult::select(array (
  0 => 'run_at',
))->groupBy('run_at')->orderBy('run_at', 'desc')->limit(30)->get();
        $list = [];
        foreach ($rows as $row) {
            $list[] = (int) $row->run_at;
        }
        return $this->success('', ['list' => $list]);
    }
}