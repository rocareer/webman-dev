# rocareer/{{NAME}}

{{DESC}}

## 安装

1. demo/full/composer.json 的 repositories 增加 path 仓库并钉版：

json: { "type": "path", "url": "../../{{NAME}}", "options": { "versions": { "rocareer/{{NAME}}": "0.1.0" } } }

2. require 增加 "rocareer/{{NAME}}": "^0.1"，然后：

bash:
cd demo/full
composer update --no-dev
php webman migrate:run   # 建表 + 菜单权限
php start.php restart

## 开发规范

- 控制器继承 app\common\controller\Backend，位于 src/app/admin/controller/{{NAME}}/，
  五件套 index/add/edit/del + 特殊方法，签名 : Response，统一 $this->success()/error()
- 权限按钮 name 必须等于 routePath（控制器类名末两段小写 + '/' + 方法名小写，全小写无连字符），
  用 php webman rocareer:audit 自检
- 模型继承 app\common\model\BaseModel，状态用常量
- 表结构/菜单权限改走 database/migrations/ 幂等迁移
