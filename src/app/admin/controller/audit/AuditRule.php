<?php

namespace app\admin\controller\audit;

use app\admin\model\DevAuditRule;
use app\common\controller\Backend;
use support\Response;
use Throwable;

/**
 * 审计规则管理（webman-dev 工程质量审计）
 *
 * 路由：/admin/audit/auditrule/{index|add|edit|del|switch}
 * 参数：index 分页（page/limit/quickSearch）；add/edit 提交表单字段；del DELETE ids/a + POST id；
 *       switch POST id/status。
 * 说明：规则 code 与 AuditService 内置规则对应（引擎只识别内置 code），停用(disabled)的规则在
 *       运行审计时跳过；新增自定义 code 不会生效，页面有提示。
 */
class AuditRule extends Backend
{
    /** 快速搜索字段：规则标识/规则名 */
    protected array|string $quickSearchField = 'name,title';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new DevAuditRule();
    }

    /**
     * 规则列表（分页 + 搜索）
     */
    public function index(): Response
    {
        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->from($this->queryFromTable($alias))
            ->where(function ($query) use ($where) {
                $this->applyWhereArray($query, $where);
            })
            ->paginate($limit);
        $this->applyOrderBy($res, $order);
        return $this->success('', [
            'list' => $res->items(),
            'total' => $res->total(),
        ]);
    }

    /**
     * 新增规则（name/title 必填；name 需与引擎内置 code 一致才生效）
     */
    public function add(): Response
    {
        $data = $this->request->post();
        if (empty($data['name']) || empty($data['title'])) {
            return $this->error('name/title 均不能为空');
        }
        try {
            $data = $this->excludeFields($data);
            $row = new DevAuditRule();
            $this->fill($row, $data);
            $row->save();
        } catch (Throwable $e) {
            return $this->error('添加失败: ' . $e->getMessage());
        }
        return $this->success('', ['id' => (int) $row->id]);
    }

    /**
     * 编辑规则（GET：回填单行；POST：保存）
     */
    public function edit(): Response
    {
        $id = (int) ($this->request->method() === 'GET' ? $this->request->input('id', 0) : $this->request->post('id', 0));
        $row = DevAuditRule::find($id);
        if (!$row) {
            return $this->error('规则不存在');
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
     * 删除规则（DELETE：ids 批量；POST：单条 id）
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
                    DevAuditRule::where('id', $id)->delete();
                }
            }
            return $this->success('');
        }
        $id = (int) $this->request->post('id', 0);
        $row = DevAuditRule::find($id);
        if (!$row) {
            return $this->error('规则不存在');
        }
        $row->delete();
        return $this->success('');
    }

    /**
     * 启停规则（停用的规则不参与审计运行）
     */
    public function switch(): Response
    {
        $id = (int) $this->request->post('id', 0);
        $status = (string) $this->request->post('status', '');
        if (!in_array($status, [DevAuditRule::STATUS_ENABLED, DevAuditRule::STATUS_DISABLED], true)) {
            return $this->error('status 无效');
        }
        $row = DevAuditRule::find($id);
        if (!$row) {
            return $this->error('规则不存在');
        }
        $row->status = $status;
        $row->save();
        return $this->success('');
    }

    /**
     * 表单字段落库（白名单 + 类型规整）
     */
    protected function fill(DevAuditRule $row, array $data): void
    {
        foreach (['name', 'title', 'description', 'remark'] as $field) {
            if (isset($data[$field])) {
                $row->$field = (string) $data[$field];
            }
        }
        if (isset($data['weigh'])) {
            $row->weigh = max(0, (int) $data['weigh']);
        }
        if (isset($data['status'])) {
            $row->status = in_array($data['status'], [DevAuditRule::STATUS_ENABLED, DevAuditRule::STATUS_DISABLED], true)
                ? $data['status'] : DevAuditRule::STATUS_ENABLED;
        }
    }
}