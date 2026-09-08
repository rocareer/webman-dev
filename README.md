# rocareer/webman-dev

Radmin 全家桶开发工具包：代码规范审计 + 插件脚手架 + 标准 CRUD 模块生成（复用后台 /admin/crud 引擎）+「工程质量审计」后台管理页。

## 安装

dev/full/composer.json 注册 path 仓库并钉版（versions: rocareer/webman-dev = 当前发布版），
然后 composer update --no-dev；`php webman migrate:run` 建表并注册菜单（开发运维 → 审计项目/审计规则/审计结果）。
后台页面在 src/radmin/web 构建树中的 `web/src/views/backend/audit/`（由本包 web/ 同步，需重建前端）。

## 命令

### rocareer:audit — 基础设施包代码规范审计

    php webman rocareer:audit              # 审计全部包（自动探测源码根）
    php webman rocareer:audit --pkg=ai     # 只审计 ai
    php webman rocareer:audit --root=/path/to/src

检查项包括：php -l、控制器规范（Backend / : Response / initialize / 注释）、
权限按钮 name 与 routePath 匹配、全工作区迁移时间戳查重（同时覆盖各包
`database/migrations` 与 `database/pg-migrations`）、脚手架残留、版本钉版同步、
前端页面规范、异步阻塞、同名类/死代码/跨包重复、DTO/LLM/ORM/event/通用工具与
Install.php 规范等门禁。
任一 FAIL 时 exit code 非 0，可用于 CI。规则实现在 `app\admin\service\AuditService`，
与后台管理页共用同一引擎；`--root` 接受含 radmin 的 src 根或工作区根（内部落到 src）。

## MCP 工程质量审计工具（自动注册）

宿主装了 rocareer/mcp 后，本包自动注册 MCP 工具集合（事件 mcp.collections.register），
外部 MCP 客户端（DSH / Claude Desktop 等）可直接调用 **quality_audit**：

- 参数：`root`（包目录根，缺省自动探测）、`pkg`（单包，缺省全部默认包）、`codes`（规则子集）、`detail`（是否附明细）；
- 返回：包/规则级摘要（通过/失败/跳过/问题数）+ 可选问题明细；
- 子端点 `/mcp/dev` 只服务本集合工具；重量级操作（秒级，低频使用）；
- 工具与 CLI/后台共用同一 AuditService 引擎，php -l 在常驻进程内非阻塞执行（不阻塞 MCP 端口其他请求）。

## 后台管理页（开发运维 → 工程质量审计）

- 审计项目：项目 CRUD + 一键运行审计（全部/单项目）+ 最近一轮问题数/未通过规则 + 顶部统计条
- 审计规则：规则 CRUD + 启停（停用不参与运行）
- 审计结果：结果明细 + 问题详情 + 轮次/项目/规则/结果筛选
- 源码根定位：插件配置 `audit_root`（留空自动探测：dev/full 宿主取 上级目录/src）；探测失败时页面会提示配置。

## 命令：rocareer:make-plugin — 插件脚手架

    php webman rocareer:make-plugin dev --title=演示 --description="..." [--out=/tmp/dev]

生成标准插件骨架后按提示接入 dev 即可。

## 命令：rocareer:make-crud — 标准 CRUD 模块生成（AI 友好）

    php webman rocareer:make-crud --demo                          # 打印简化设计 JSON 示例
    php webman rocareer:make-crud --design=/path/design.json      # 按设计 JSON 生成模块

设计 JSON 极简（字段键：name/comment/design_type/length/required/default/primary_key；
字典编码在 comment：`状态: 0=禁用,1=启用`）：

```json
{
  "table": { "name": "cc_student", "comment": "学员管理", "quick_search": ["name"] },
  "fields": [
    { "name": "name", "comment": "姓名", "design_type": "input", "length": 50, "required": true },
    { "name": "status", "comment": "状态: 0=禁用,1=启用", "design_type": "switch", "default": "1" },
    { "name": "remark", "comment": "备注", "design_type": "textarea" }
  ]
}
```

流程：渲染 PG 幂等迁移（`database/migrations/<ts>_<table>_crud.php`，hasTable 守卫可 migrate:run 追溯）
→ 调 radmin `app\admin\service\CrudService`（v5.1.0+，与后台 /admin/crud 同一引擎）生成
控制器/模型/验证器 + 前端 index.vue/popupForm.vue + 语言包 + 菜单（幂等）；主键/时间戳列自动注入；
菜单即时幂等种入（不写进迁移）。生成目标 = 运行命令的宿主工程（app/ 与 web/src/）。

- 依赖：宿主需 radmin v5.1.0+（引擎服务化后）；表结构真源 = 迁移文件，请执行 `php webman migrate:run` 建表
- 冲突保护：目标文件已存在默认中止（重复生成需 --force 覆盖，或后台 CRUD 记录删除后重生成）
- 选项：`--no-migration`（表已自行准备）、`--force`、`--demo`

## MCP crud_generate 工具（自动注册）

同上 MCP 集合机制，本包自动注册 **crud_generate**（集合 key=`crud`，子端点 `/mcp/crud`）：

- 参数平铺：`table_name` / `table_comment` / `quick_search` / `fields`（嵌套对象数组）/ `no_migration`；
- 与 CLI `rocareer:make-crud`、后台 `/admin/crud` 共用同一引擎（CrudService + CrudDesigner），AI 客户端
（DSH / Claude Desktop / agent）可直接「给字段设计 → 拿标准模块」；字段白名单：
  input/textarea/editor/switch/select/radio/selects/checkbox/number/float/datetime/date/image/images/file/files/weigh；
- 引擎依赖 radmin v5.1.0+，缺失时报错提示升级。
