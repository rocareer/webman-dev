<?php

namespace app\admin\controller\{{NAME}};

use app\admin\model\{{UC}} as {{UC}}Model;
use app\common\controller\Backend;
use support\Response;
use Throwable;

/**
 * {{TITLE}}管理（{{NAME}}）
 *
 * 路由：/admin/{{NAME}}/{{LCC}}/{index|add|edit|del}
 * 权限规则：{{NAME}}/{{LCC}}/{index|add|edit|del}（按钮 name 必须等于 routePath，
 * 全小写无连字符；新增接口记得在菜单迁移里补按钮节点）
 */
class {{UC}} extends Backend
{
    /**
     * {{UC}} 模型对象
     * @var object
     * @phpstan-var \app\admin\model\{{UC}}Model
     */
    protected object $model;

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new {{UC}}Model();
    }

    /**
     * 列表（分页 + quickSearch/keyword 搜索）
     */
    public function index(): Response
    {
        $limit = max(1, min(100, (int) $this->request->input('limit', 10)));
        $page = max(1, (int) $this->request->input('page', 1));
        $keyword = (string) $this->request->input('keyword', $this->request->input('quickSearch', ''));

        $query = $this->model->orderBy('id', 'desc');
        if ($keyword !== '') {
            $query = $query->where('name', 'like', '%' . $keyword . '%');
        }
        $paginator = $query->paginate($limit, ['*'], 'page', $page);

        return $this->success('', [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * 添加（POST；字段判空校验）
     */
    public function add(): Response
    {
        $data = $this->request->post();
        if (empty($data['name'])) {
            return $this->error('name 不能为空');
        }
        try {
            $row = new {{UC}}Model();
            $this->fill($row, $data);
            $row->save();
        } catch (Throwable $e) {
            return $this->error('添加失败: ' . $e->getMessage());
        }
        return $this->success('', ['id' => (int) $row->id]);
    }

    /**
     * 编辑（GET：回填单行；POST：保存）
     */
    public function edit(): Response
    {
        $id = (int) ($this->request->method() === 'GET' ? $this->request->input('id', 0) : $this->request->post('id', 0));
        $row = $this->model->find($id);
        if (!$row) {
            return $this->error('记录不存在');
        }
        if ($this->request->method() === 'GET') {
            return $this->success('', ['row' => $row]);
        }
        try {
            $this->fill($row, $this->request->post());
            $row->save();
        } catch (Throwable $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }
        return $this->success('');
    }

    /**
     * 删除（DELETE：ids/a 批量；POST：单条 id 兼容）
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
                    $this->model->where('id', $id)->delete();
                }
            }
            return $this->success('');
        }
        $id = (int) $this->request->post('id', 0);
        $row = $this->model->find($id);
        if (!$row) {
            return $this->error('记录不存在');
        }
        $row->delete();
        return $this->success('');
    }

    protected function fill({{UC}}Model $row, array $data): void
    {
        if (isset($data['name'])) {
            $row->name = trim((string) $data['name']);
        }
    }
}
