<?php
/**
 * {{TITLE}}服务（可复用的业务逻辑才抽服务层；简单逻辑直接写控制器）
 */

namespace app\admin\service;

class {{UC}}Service
{
    /**
     * 示例：业务方法（直接 new 调用，不经过容器）
     */
    public function doSomething(array $params): array
    {
        return ['ok' => true];
    }
}
